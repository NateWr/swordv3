<?php
namespace APP\plugins\generic\swordv3\classes\jobs;

use APP\core\Application;
use APP\facades\Repo;
use APP\journal\Journal;
use APP\journal\JournalDAO;
use APP\plugins\generic\swordv3\classes\exceptions\DepositsNotAccepted;
use APP\plugins\generic\swordv3\classes\OJSDepositObject;
use APP\plugins\generic\swordv3\classes\OJSService;
use APP\plugins\generic\swordv3\classes\exceptions\DepositDeletedStatus;
use APP\plugins\generic\swordv3\classes\exceptions\DepositRejectedStatus;
use APP\plugins\generic\swordv3\classes\jobs\traits\ErrorNotification;
use APP\plugins\generic\swordv3\classes\jobs\traits\PublicationSettings;
use APP\plugins\generic\swordv3\classes\jobs\traits\ServiceHelper;
use APP\plugins\generic\swordv3\classes\Logger;
use APP\plugins\generic\swordv3\swordv3Client\Client;
use APP\plugins\generic\swordv3\swordv3Client\exceptions\AuthenticationFailed;
use APP\plugins\generic\swordv3\swordv3Client\exceptions\AuthenticationRequired;
use APP\plugins\generic\swordv3\swordv3Client\exceptions\AuthenticationUnsupported;
use APP\plugins\generic\swordv3\swordv3Client\exceptions\DigestFormatNotFound;
use APP\plugins\generic\swordv3\swordv3Client\StatusDocument;
use APP\submission\Submission;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use PKP\db\DAORegistry;
use PKP\jobs\BaseJob;
use Throwable;

class Deposit extends BaseJob
{
    use ErrorNotification;
    use PublicationSettings;
    use ServiceHelper;

    protected ?OJSService $service = null;
    protected Logger $log;

    public function __construct(
        protected int $publicationId,
        protected int $submissionId,
        protected int $contextId,
        protected string $serviceUrl,
    ) {
        $this->log = new Logger($this->contextId, $this->submissionId, $this->publicationId);
        parent::__construct();
    }

    public function handle(): void
    {
        $this->log->notice(
            "Preparing to deposit Publication {publicationId}, Submission {submissionId}, Context {contextId} to {serviceUrl}.",
            [
                'publicationId' => $this->publicationId,
                'submissionId' => $this->submissionId,
                'contextId' => $this->contextId,
                'serviceUrl' => $this->serviceUrl,
            ]
        );

        $this->service = $this->getServiceByUrl($this->contextId, $this->serviceUrl);

        if (!$this->service) {
            $this->log->warning("Aborting deposit because deposit service settings can not be found.");
        }

        if (!$this->service->enabled) {
            $this->log->warning("Aborting deposit because deposit service has been disabled.");
            return;
        }

        $depositObject = $this->getDepositObject();
        if (!$depositObject) {
            $this->log->error("Aborting deposit because no valid deposit object could be created. This could be because the publication has been deleted, is not published, or because the publication was not found in the expected submission or context.");
            return;
        }

        $client = new Client(
            httpClient: Application::get()->getHttpClient(),
            service: $this->service,
        );

        $this->log->notice(
            "Ready to deposit metadata and {countGalleys} galleys in SWORDv3 service \"{serviceName}\".",
            [
                'countGalleys' => count($depositObject->fileset),
                'serviceName' => $this->service->name,
            ]
        );

        try {
            $serviceDocument = $client->getServiceDocument();
            if (!$serviceDocument->supportsAuthMode($this->service->authMode->getMode())) {
                throw new AuthenticationUnsupported($this->service, $serviceDocument);
            }
            if (!$serviceDocument->acceptDeposits()) {
                throw new DepositsNotAccepted($this->service, $serviceDocument);
            }

            if ($depositObject?->statusDocument) {
                $this->log->notice(
                    "Replacing existing deposit object at {url}.",
                    ['url' => $depositObject->statusDocument->getObjectId()]
                );
                $response = $client->replaceObjectWithMetadata(
                    $depositObject->statusDocument->getObjectId(),
                    $depositObject->metadata,
                    $serviceDocument
                );
            } else {
                $this->log->notice("Creating new deposit object.");
                $response = $client->createObjectWithMetadata(
                    $depositObject->metadata,
                    $serviceDocument
                );
            }

            $statusDocument = new StatusDocument($response->getBody());
            $depositObject->publication = $this->savePublicationStatus($this->publicationId, $statusDocument);

            if ($statusDocument->getSwordStateId() === StatusDocument::STATE_REJECTED) {
                throw new DepositRejectedStatus($statusDocument, $this->service);
            }

            if ($statusDocument->getSwordStateId() === StatusDocument::STATE_DELETED) {
                throw new DepositDeletedStatus($statusDocument, $this->service);
            }

            if (count($depositObject->fileset)) {
                if ($statusDocument->canAppendFiles()) {
                    foreach ($depositObject->fileset as $file) {
                        $this->log->notice("Depositing {$file} to {$statusDocument->getObjectId()}.");
                        $response = $client->appendObjectFile($statusDocument->getObjectId(), $file, $serviceDocument);
                        $statusDocument = new StatusDocument($response->getBody());
                        $depositObject->publication = $this->savePublicationStatus($this->publicationId, $statusDocument);
                    }
                } else {
                    $this->log->notice("Skipping file deposits because the service has indicated that it does not support file deposits for this object.");
                }
            }

            $statusDocument = new StatusDocument($client->getStatusDocument($statusDocument->getObjectId())->getBody());
            $depositObject->publication = $this->savePublicationStatus($this->publicationId, $statusDocument);

            foreach ($statusDocument->getLinks() as $link) {
                $this->log->notice("Linked resource created at {$link->{'@id'}}.");
            }
            $this->log->notice("Deposit Complete");

        } catch (AuthenticationUnsupported|AuthenticationRequired|AuthenticationFailed $exception) {
            $error = $this->getAuthErrorMessage($exception);
            $this->log->critical("Authentication error encountered: {error}", ['error' => $error]);
            $this->disableService($error, $this->service->url, $this->contextId);
            $this->notifyServiceDisabled($error, $this->service, $depositObject->context);
            return;
        } catch (DepositsNotAccepted $exception) {
            $this->log->critical("Deposit aborted: {exception}", ['exception' => $exception->getMessage()]);
            $error = __('plugins.generic.swordv3.service.depositsNotAccepted');
            $this->disableService($error, $this->service->url, $this->contextId);
            $this->notifyServiceDisabled($error, $this->service, $depositObject->context);
            return;
        } catch (DigestFormatNotFound $exception) {
            $this->log->critical("Deposit aborted: {exception}", ['exception' => $exception->getMessage()]);
            $error = __('plugins.generic.swordv3.service.digestNotAccepted');
            $this->disableService($error, $this->service->url, $this->contextId);
            $this->notifyServiceDisabled($error, $this->service, $depositObject->context);
            return;
        } catch (DepositRejectedStatus|DepositDeletedStatus $exception) {
            $this->log->warning("Deposit failed: {exception}", ['exception' => $exception->getMessage()]);
            return;
        } catch (RequestException|ConnectException $exception) {
            $this->log->critical(
                "HTTP error encountered at {url}: {exception}",
                [
                    'url' => $exception->getRequest()->getUri(),
                    'exception' => $exception->getMessage(),
                ]
            );
            $this->disableService($exception->getMessage(), $this->service->url, $this->contextId);
            $this->notifyServiceDisabled($exception->getMessage(), $this->service, $depositObject->context);
            return;
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
        $publication = $this->getPublication($this->publicationId);
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
}
