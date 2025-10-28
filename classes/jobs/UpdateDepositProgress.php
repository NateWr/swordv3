<?php
namespace APP\plugins\generic\swordv3\classes\jobs;

use APP\core\Application;
use APP\plugins\generic\swordv3\classes\jobs\traits\PublicationSettings;
use APP\plugins\generic\swordv3\classes\jobs\traits\ServiceHelper;
use APP\plugins\generic\swordv3\classes\OJSService;
use APP\plugins\generic\swordv3\swordv3Client\Client;
use APP\plugins\generic\swordv3\swordv3Client\exceptions\AuthenticationFailed;
use APP\plugins\generic\swordv3\swordv3Client\exceptions\AuthenticationRequired;
use APP\plugins\generic\swordv3\swordv3Client\exceptions\AuthenticationUnsupported;
use APP\plugins\generic\swordv3\swordv3Client\StatusDocument;
use DateTime;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use PKP\config\Config;
use PKP\jobs\BaseJob;
use PKP\plugins\PluginRegistry;
use Throwable;

class UpdateDepositProgress extends BaseJob
{
    use PublicationSettings;
    use ServiceHelper;

    protected ?OJSService $service = null;

    public function __construct(
        protected int $publicationId,
        protected int $submissionId,
        protected int $contextId,
        protected string $serviceUrl,
    ) {
        parent::__construct();
    }

    public function handle(): void
    {
        $this->log("Preparing to update deposit state of publication {$this->publicationId} from {$this->serviceUrl}.");

        $publication = $this->getPublication($this->publicationId);

        if (!$publication->getData('swordv3StatusDocument')) {
            $this->log("Aborting because the publication does not have a SWORDv3 status document to update.");
            return;
        }

        $this->service = $this->getServiceByUrl($this->contextId, $this->serviceUrl);

        if (!$this->service || !$this->service->enabled) {
            $this->log("Aborting because the deposit service is disabled or is no longer configured.");
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
            $this->log("Updated deposit state to {$newStatusDocument->getSwordStateId()} and saved a new StatusDocument.");
        } catch (AuthenticationUnsupported|AuthenticationRequired|AuthenticationFailed $exception) {
            $this->log("Authentication error encountered: {$exception->getMessage()}");
            return;
        } catch (RequestException|ConnectException $exception) {
            $this->log("HTTP error encountered at {$exception->getRequest()->getUri()}\n  {$exception->getMessage()}");
            return;
        } catch (Throwable $exception) {
            throw $exception;
        }
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

    protected function disableService(string $reason): void
    {
        /** @var Swordv3Plugin $plugin */
        $plugin = PluginRegistry::getPlugin('generic', 'swordv3plugin');
        $data = $plugin->getSetting($this->contextId, 'services');
        if (!is_array($data) || !count($data)) {
            $data = [];
        }

        $newData = collect($data)
            ->map(function(array $service) use ($reason) {
                if ($this->service->url === $service['url']) {
                    $service['enabled'] = false;
                    $service['statusMessage'] = $reason;
                }
                return $service;
            });

        $plugin->updateSetting(
            $this->contextId,
            'services',
            $newData
        );
    }
}