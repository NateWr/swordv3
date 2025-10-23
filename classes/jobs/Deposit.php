<?php
namespace APP\plugins\generic\swordv3\classes\jobs;

use APP\core\Application;
use APP\facades\Repo;
use APP\journal\Journal;
use APP\journal\JournalDAO;
use APP\plugins\generic\swordv3\classes\exceptions\DepositsNotAccepted;
use APP\plugins\generic\swordv3\classes\exceptions\FilesNotSupported;
use APP\plugins\generic\swordv3\classes\OJSDepositObject;
use APP\plugins\generic\swordv3\swordv3Client\Client;
use APP\plugins\generic\swordv3\swordv3Client\exceptions\AuthenticationFailed;
use APP\plugins\generic\swordv3\swordv3Client\exceptions\AuthenticationRequired;
use APP\plugins\generic\swordv3\swordv3Client\exceptions\AuthenticationUnsupported;
use APP\plugins\generic\swordv3\swordv3Client\exceptions\DigestFormatNotFound;
use APP\plugins\generic\swordv3\swordv3Client\exceptions\Swordv3ConnectException;
use APP\plugins\generic\swordv3\swordv3Client\exceptions\Swordv3RequestException;
use APP\plugins\generic\swordv3\swordv3Client\Service;
use APP\plugins\generic\swordv3\swordv3Client\ServiceDocument;
use APP\plugins\generic\swordv3\swordv3Client\StatusDocument;
use APP\publication\Publication;
use APP\submission\Submission;
use DateTime;
use PKP\config\Config;
use PKP\db\DAORegistry;
use PKP\jobs\BaseJob;
use Throwable;

class Deposit extends BaseJob
{
    public function __construct(
        protected int $publicationId,
        protected int $submissionId,
        protected int $contextId,
        protected Service $service,
    ) {
        parent::__construct();
    }

    public function handle(): void
    {
        $this->log("Preparing to deposit Publication {$this->publicationId}, Submission {$this->submissionId}, Context {$this->contextId}.");

        $depositObject = $this->getDepositObject();
        if (!$depositObject) {
            $this->log("Aborting deposit because no valid deposit object could be created. This could be because the publication has been deleted, is not published, or because publication, submission or context IDs do not match.");
            return;
        }

        $client = new Client(
            httpClient: Application::get()->getHttpClient(),
            service: $this->service,
        );

        $countGalleys = count($depositObject->fileset);
        $this->log("Ready to deposit metadata and {$countGalleys} galleys in SWORDv3 service \"{$this->service->name}\".");

        try {
            $serviceDocument = $client->getServiceDocument();
            if (!$serviceDocument->supportsAuthMode($this->service->authMode->getMode())) {
                throw new AuthenticationUnsupported($this->service, $serviceDocument);
            }
            if (!$serviceDocument->acceptDeposits()) {
                throw new DepositsNotAccepted($this->service, $serviceDocument);
            }

            $response = $depositObject->statusDocument
                ? $client->replaceObjectWithMetadata(
                    $depositObject->statusDocument->getObjectId(),
                    $depositObject->metadata,
                    $serviceDocument
                )
                : $client->createObjectWithMetadata(
                    $depositObject->metadata,
                    $serviceDocument
                );

            $statusDocument = new StatusDocument($response->getBody());

            $actionLanguage = $depositObject->statusDocument
                ? 'Replaced deposit object and metadata'
                : 'Created deposit object with metadata';
            $this->log("{$actionLanguage} for publication {$this->publicationId} at {$statusDocument->getObjectId()}.");

            $this->savePublicationStatus($depositObject->publication, $statusDocument);

            if (count($depositObject->fileset)) {
                if (!$statusDocument->getFileSetUrl()) {
                    throw new FilesNotSupported($statusDocument, $this->service);
                }
                foreach ($depositObject->fileset as $file) {
                    $response = $client->appendObjectFile($statusDocument->getObjectId(), $file, $serviceDocument);
                }
            }

            $statusDocument = new StatusDocument($client->getStatusDocument($statusDocument->getObjectId())->getBody());
            $this->savePublicationStatus($depositObject->publication, $statusDocument);

            foreach ($statusDocument->getLinks() as $link) {
                $this->log("Linked resource created at {$link->{'@id'}}.");
            }
            $this->log("Deposit Complete");

        } catch (AuthenticationUnsupported|AuthenticationRequired|AuthenticationFailed $exception) {
            // TODO: send email to admin
            // TODO: disable all sending to this service for now.
            $this->log("Authentication error encountered at {$exception->requestException->getRequest()->getUri()}\n  {$exception->getMessage()}");
            return;
        } catch (Swordv3RequestException $exception) {
            // TODO: send email to admin
            $this->log("HTTP error encountered at {$exception->requestException->getRequest()->getUri()}\n  {$exception->getMessage()}");
            return;
        } catch (Swordv3ConnectException $exception) {
            // TODO: send email to admin
            // TODO: re-schedule job and track repeated failures?
            $this->log("HTTP error encountered at {$exception->connectException->getRequest()->getUri()}\n  {$exception->getMessage()}");
            return;
        } catch (DigestFormatNotFound $exception) {
            // TODO: send email to admin
            $this->log($exception->getMessage());
        } catch (FilesNotSupported $exception) {
            // TODO: send email to admin
            // TODO: update status to reflect incomplete deposit
            $this->log("Deposit aborted: {$exception->getMessage()}");
        } catch (Throwable $exception) {
            throw $exception;
        }
    }

    /**
     * Get a DepositObject that can be passed to the Swordv3 client
     * for deposit.
     */
    protected function getDepositObject(): ?OJSDepositObject
    {
        $publication = Repo::publication()->get($this->publicationId);
        $galleys = Repo::galley()->getCollector()
                ->filterByPublicationIds([$publication->getId()])
                ->getMany();
        $submission = Repo::submission()->get($this->submissionId);
        /** @var JournalDAO $contextDao */
        $contextDao = DAORegistry::getDAO('JournalDAO');
        /** @var Journal $context */
        $context = $contextDao->getById($this->contextId);

        if (
            !$context
            || !$submission
            || !$publication
            || $publication->getData('status') !== Submission::STATUS_PUBLISHED
            || $publication->getData('submissionId') !== $submission->getId()
            || $submission->getData('contextId') !== $context->getId()
        ) {
            return null;
        }

        return new OJSDepositObject(
            $publication,
            $galleys,
            $submission,
            $context
        );
    }

    /**
     * Store the StatusDocument and other metadata related to the deposit
     * action in the Publication settings
     */
    protected function savePublicationStatus(Publication $publication, StatusDocument $statusDocument): void
    {
        $newPublication = Repo::publication()->newDataObject(
            array_merge(
                $publication->_data, [
                    'swordv3DateDeposited' => (new DateTime()->format('Y-m-d h:i:s')),
                    'swordv3State' => $statusDocument->getSwordStateId(),
                    'swordv3StatusDocument' => json_encode($statusDocument->getStatusDocument()),
                ]
            )
        );
        Repo::publication()->dao->update($newPublication, $publication);
        $newPublication = Repo::publication()->get($newPublication->getId());

    }

    /**
     * Write to a deposit log file
     */
    protected function log(string $msg): void
    {
        $filename = Config::getVar('files', 'files_dir') . '/swordv3.log';
        $time = (new DateTime())->format('Y-m-d h:i:s');
        $deposit = "{$this->contextId}-{$this->submissionId}-{$this->publicationId}";
        try {
            file_put_contents(
                $filename,
                "\n[{$time}] [{$deposit}] {$msg}",
                FILE_APPEND
            );
        } catch (Throwable $e) {
            error_log($e->getMessage());
        }
    }
}
