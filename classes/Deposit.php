<?php

namespace APP\plugins\generic\swordv3\classes;

use APP\core\Application;
use APP\facades\Repo;
use APP\journal\Journal;
use APP\journal\JournalDAO;
use APP\plugins\generic\swordv3\classes\exceptions\FilesNotSupported;
use APP\plugins\generic\swordv3\swordv3Client\auth\APIKey;
use APP\plugins\generic\swordv3\swordv3Client\auth\Basic;
use APP\plugins\generic\swordv3\swordv3Client\Client;
use APP\plugins\generic\swordv3\swordv3Client\exceptions\AuthenticationFailed;
use APP\plugins\generic\swordv3\swordv3Client\exceptions\AuthenticationRequired;
use APP\plugins\generic\swordv3\swordv3Client\exceptions\AuthenticationUnsupported;
use APP\plugins\generic\swordv3\swordv3Client\exceptions\BadRequest;
use APP\plugins\generic\swordv3\swordv3Client\exceptions\HTTPException;
use APP\plugins\generic\swordv3\swordv3Client\Service;
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

        $service = $this->getService();
        $client = new Client(
            httpClient: Application::get()->getHttpClient(),
            service: $service,
        );

        $countGalleys = count($depositObject->fileset);
        $this->log("Ready to deposit metadata and {$countGalleys} galleys in SWORDv3 service \"{$service->name}\".");

        try {
            $client->getServiceDocument();
            if (!$service->supportsAuth()) {
                throw new AuthenticationUnsupported($service);
            }

            $response = $depositObject->statusDocument
                ? $client->replaceObjectWithMetadata($depositObject->statusDocument->getObjectId(), $depositObject->metadata)
                : $client->createObjectWithMetadata($depositObject->metadata);

            $statusDocument = new StatusDocument($response->getBody());

            $actionLanguage = $depositObject->statusDocument
                ? 'Replaced deposit object and metadata'
                : 'Created deposit object with metadata';
            $this->log("{$actionLanguage} for publication {$this->publicationId} at {$statusDocument->getObjectId()}.");

            $this->savePublicationStatus($depositObject->publication, $statusDocument);

            if (count($depositObject->fileset)) {
                if (!$statusDocument->getFileSetUrl()) {
                    throw new FilesNotSupported($statusDocument, $service);
                }
                foreach ($depositObject->fileset as $file) {
                    $response = $client->appendObjectFile($statusDocument->getObjectId(), $file);
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
            $this->log("Authentication error encountered at {$exception->clientException->getRequest()->getUri()}\n  {$exception->getMessage()}");
            return;
        } catch (HTTPException $exception) {
            // TODO: send email to admin
            $this->log("HTTP error encountered at {$exception->clientException->getRequest()->getUri()}\n  {$exception->getMessage()}");
            return;
        } catch (FilesNotSupported $exception) {
            // TODO: send email to admin
            $this->log($exception->getMessage());
            return;
        } catch (Throwable $exception) {
            throw $exception;
        }
    }

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

    protected function getService(): Service
    {
        return new Service(
            name: 'local test',
            url: 'http://host.docker.internal:3000/service-url',
            // authMode: new Basic('swordv3', 'swordv3'),
            authMode: new APIKey('Te8#eFYLmIvOIy9&^K!0PvT@JeIw@C&G'),
        );
    }

    protected function savePublicationStatus(Publication $publication, StatusDocument $statusDocument): void
    {
        $newPublication = Repo::publication()->newDataObject(
            array_merge(
                $publication->_data, [
                    'swordv3DateDeposited' => (new DateTime()->format('Y-m-d h:i:s')),
                    'swordv3Status' => $statusDocument->getSwordStateId(),
                    'swordv3StatusDocument' => json_encode($statusDocument->getStatusDocument()),
                ]
            )
        );
        Repo::publication()->dao->update($newPublication, $publication);
        $newPublication = Repo::publication()->get($newPublication->getId());

    }

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
