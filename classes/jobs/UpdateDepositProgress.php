<?php
namespace APP\plugins\generic\swordv3\classes\jobs;

use APP\core\Application;
use APP\plugins\generic\swordv3\classes\jobs\traits\ErrorNotification;
use APP\plugins\generic\swordv3\classes\jobs\traits\PublicationSettings;
use APP\plugins\generic\swordv3\classes\jobs\traits\ServiceHelper;
use APP\plugins\generic\swordv3\classes\Logger;
use APP\plugins\generic\swordv3\classes\OJSService;
use APP\plugins\generic\swordv3\swordv3Client\Client;
use APP\plugins\generic\swordv3\swordv3Client\exceptions\AuthenticationFailed;
use APP\plugins\generic\swordv3\swordv3Client\exceptions\AuthenticationRequired;
use APP\plugins\generic\swordv3\swordv3Client\exceptions\AuthenticationUnsupported;
use APP\plugins\generic\swordv3\swordv3Client\StatusDocument;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use PKP\context\Context;
use PKP\db\DAORegistry;
use PKP\jobs\BaseJob;
use Throwable;

class UpdateDepositProgress extends BaseJob
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
        $this->log->notice("Preparing to update deposit state of publication {$this->publicationId} from {$this->serviceUrl}.");

        $publication = $this->getPublication($this->publicationId);

        if (!$publication->getData('swordv3StatusDocument')) {
            $this->log->warning("Aborting because the publication does not have a SWORDv3 status document to update.");
            return;
        }

        $this->service = $this->getServiceByUrl($this->contextId, $this->serviceUrl);

        if (!$this->service || !$this->service->enabled) {
            $this->log->warning("Aborting because the deposit service is disabled or is no longer configured.");
            return;
        }

        $client = new Client(
            httpClient: Application::get()->getHttpClient(),
            service: $this->service,
        );

        try {
            $statusDocument = new StatusDocument($publication->getData('swordv3StatusDocument'));
            $response = $client->getStatusDocument($statusDocument->getObjectId());
            $newStatusDocument = new StatusDocument($response->getBody());
            $publication = $this->savePublicationStatus($this->publicationId, $newStatusDocument);
            $this->log->notice("Updated deposit state to {$newStatusDocument->getSwordStateId()} and saved a new StatusDocument.");
        } catch (AuthenticationUnsupported|AuthenticationRequired|AuthenticationFailed $exception) {
            $error = $this->getAuthErrorMessage($exception);
            $this->log->critical("Authentication error encountered: {error}", ['error' => $error]);
            $this->disableService($error, $this->service->url, $this->contextId);
            $this->notifyServiceDisabled($error, $this->service, $this->getContext());
            return;
        } catch (RequestException|ConnectException $exception) {
            $this->log->critical(
                "HTTP error encountered at {url}: {exception}",
                [
                    'url' => $exception->getRequest()->getUri(),
                    'exception' => $exception->getMessage(),
                ]
            );
            return;
        } catch (Throwable $exception) {
            throw $exception;
        }
    }

    protected function getContext(): Context
    {
        /** @var JournalDAO $contextDao */
        $contextDao = DAORegistry::getDAO('JournalDAO');
        return $contextDao->getById($this->contextId);
    }
}