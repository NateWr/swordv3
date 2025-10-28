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
use APP\plugins\generic\swordv3\classes\jobs\traits\PublicationSettings;
use APP\plugins\generic\swordv3\classes\jobs\traits\ServiceHelper;
use APP\plugins\generic\swordv3\swordv3Client\Client;
use APP\plugins\generic\swordv3\swordv3Client\exceptions\AuthenticationFailed;
use APP\plugins\generic\swordv3\swordv3Client\exceptions\AuthenticationRequired;
use APP\plugins\generic\swordv3\swordv3Client\exceptions\AuthenticationUnsupported;
use APP\plugins\generic\swordv3\swordv3Client\exceptions\DigestFormatNotFound;
use APP\plugins\generic\swordv3\swordv3Client\StatusDocument;
use APP\plugins\generic\swordv3\Swordv3Plugin;
use APP\submission\Submission;
use DateTime;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;
use PKP\config\Config;
use PKP\context\Context;
use PKP\db\DAORegistry;
use PKP\jobs\BaseJob;
use PKP\plugins\PluginRegistry;
use PKP\security\Role;
use PKP\user\User;
use Throwable;

class Deposit extends BaseJob
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
        $this->log("Preparing to deposit Publication {$this->publicationId}, Submission {$this->submissionId}, Context {$this->contextId} to {$this->serviceUrl}.");

        $this->service = $this->getServiceByUrl($this->contextId, $this->serviceUrl);

        if (!$this->service) {
            $this->log("Aborting deposit because deposit service settings can not be found.");
        }

        if (!$this->service->enabled) {
            $this->log("Aborting deposit because deposit service has been disabled.");
            return;
        }

        $depositObject = $this->getDepositObject();
        if (!$depositObject) {
            $this->log("Aborting deposit because no valid deposit object could be created. This could be because the publication has been deleted, is not published, or because the publication was not found in the expected submission or context.");
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

            if ($depositObject?->statusDocument) {
                $this->log("Replacing existing deposit object at {$depositObject->statusDocument->getObjectId()}.");
                $response = $client->replaceObjectWithMetadata(
                    $depositObject->statusDocument->getObjectId(),
                    $depositObject->metadata,
                    $serviceDocument
                );
            } else {
                $this->log("Creating new deposit object.");
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
                        $this->log("Depositing {$file} to {$statusDocument->getObjectId()}.");
                        $response = $client->appendObjectFile($statusDocument->getObjectId(), $file, $serviceDocument);
                        $statusDocument = new StatusDocument($response->getBody());
                        $depositObject->publication = $this->savePublicationStatus($this->publicationId, $statusDocument);
                    }
                } else {
                    $this->log("Skipping file deposits because the service has indicated that it does not support file deposits for this object.");
                }
            }

            $statusDocument = new StatusDocument($client->getStatusDocument($statusDocument->getObjectId())->getBody());
            $depositObject->publication = $this->savePublicationStatus($this->publicationId, $statusDocument);

            foreach ($statusDocument->getLinks() as $link) {
                $this->log("Linked resource created at {$link->{'@id'}}.");
            }
            $this->log("Deposit Complete");

        } catch (AuthenticationUnsupported|AuthenticationRequired|AuthenticationFailed $exception) {
            $this->log("Authentication error encountered: {$exception->getMessage()}");
            $error = $this->getAuthErrorMessage($exception);
            $this->disableService($error);
            $this->notifyServiceDisabled($error, $depositObject->context);
            return;
        } catch (DepositsNotAccepted $exception) {
            $this->log("Deposit aborted: {$exception->getMessage()}");
            $error = __('plugins.generic.swordv3.service.depositsNotAccepted');
            $this->disableService($error);
            $this->notifyServiceDisabled($error, $depositObject->context);
            return;
        } catch (DigestFormatNotFound $exception) {
            $this->log("Deposit aborted: {$exception->getMessage()}");
            $error = __('plugins.generic.swordv3.service.digestNotAccepted');
            $this->disableService($error);
            $this->notifyServiceDisabled($error, $depositObject->context);
            return;
        } catch (DepositRejectedStatus|DepositDeletedStatus $exception) {
            $this->log("Deposit aborted: {$exception->getMessage()}");
            return;
        } catch (RequestException|ConnectException $exception) {
            $this->log("HTTP error encountered at {$exception->getRequest()->getUri()}\n  {$exception->getMessage()}");
            $this->disableService($exception->getMessage());
            $this->notifyServiceDisabled($exception->getMessage(), $depositObject->context);
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

    /**
     * Get a user-facing error message for an authentication error
     */
    protected function getAuthErrorMessage(AuthenticationUnsupported|AuthenticationRequired|AuthenticationFailed $exception): string
    {
        $error = $exception->getMessage();
        switch (get_class($exception)) {
            case AuthenticationRequired::class:
                $error = __('plugins.generic.swordv3.authError.missingCredentials', [
                    'error' => $exception->getMessage(),
                ]);
                break;
            case AuthenticationFailed::class:
                $error = $this->service->authMode->getMode() === 'Basic'
                    ? __('plugins.generic.swordv3.service.authMode.userPassFailed')
                    : __('plugins.generic.swordv3.service.authMode.apiKeyFailed');
                break;
            case AuthenticationUnsupported::class:
                $error = __('plugins.generic.swordv3.service.authMode.unsupported', [
                    'authMode' => $exception->service->authMode->getMode(),
                    'supportedModes' => join(__('common.commaListSeparator'), $exception->serviceDocument->getAuthModes()),
                ]);
        }

        return $error;
    }

    /**
     * Send an email notification when there is a problem that
     * disables deposits for this service
     *
     * Emails managers, admins, or the tech support contact.
     */
    protected function notifyServiceDisabled(string $error, Context $context): void
    {
        $recipients = $this->getErrorEmailRecipients($context);

        if (!$recipients) {
            $this->log(__('plugins.generic.swordv3.notification.recipientsNotFound'));
            return;
        }

        $subject = __('plugins.generic.swordv3.notification.depositError.subject', [
            'context' => $context->getLocalizedName(),
            'service' => $this->service->name,
        ]);
        $body = $this->getCommonEmailBody($error, $context);

        $mailable = new Mailable();
        $mailable->to($recipients);
        $mailable->from($context->getData('contactEmail'), $context->getData('contactName'));
        $mailable->subject($subject);
        $mailable->html($body);

        Mail::send($mailable);
    }

    /**
     * Get the email notification message that is most commonly
     * used with depositing errors
     *
     * @return string HTML-formatted message
     */
    protected function getCommonEmailBody(string $error, Context $context): string
    {
        $body = collect([
            __('plugins.generic.swordv3.notification.serviceStatus.intro', [
                'context' => $context->getLocalizedName(),
                'contextUrl' => $this->getContextUrl($context),
                'service' => $this->service->name,
                'serviceUrl' => $this->service->url,
            ]),
            "<strong>{$error}</strong>",
            __('plugins.generic.swordv3.notification.depositsStopped', [
                'url' => $this->getSettingsUrl($context),
            ]),
            __('plugins.generic.swordv3.notification.recipientsStatement', [
                'url' => $this->getContextUrl($context),
                'context' => $context->getLocalizedName(),
            ]),
            __('plugins.generic.swordv3.notification.automated', [
                'plugin' => __('plugins.generic.swordv3.name'),
            ]),
        ]);

        return "<p>{$body->join('</p><p>')}</p>";
    }

    /**
     * Get the email and name of recipients who should be notified of
     * errors encountered while depositing
     *
     * @return string[]
     */
    protected function getErrorEmailRecipients(Context $context): array
    {
        $managers = Repo::user()
            ->getCollector()
            ->filterByContextIds([$this->contextId])
            ->filterByRoleIds([Role::ROLE_ID_MANAGER])
            ->getMany()
            ->map(fn(User $user) => ['email' =>$user->getEmail(), 'name' => $user->getFullName()]);

        if ($managers->count()) {
            return $managers->all();
        }

        $supportContact = $context->getData('supportEmail');

        if ($supportContact) {
            return [$supportContact];
        }

        $admins = Repo::user()
            ->getCollector()
            ->filterByRoleIds([Role::ROLE_ID_SITE_ADMIN])
            ->getMany()
            ->map(fn(User $user) => ['email' =>$user->getEmail(), 'name' => $user->getFullName()]);

        return $admins->all();
    }

    protected function getContextUrl(Context $context): string
    {
        $request = Application::get()->getRequest();
        return $request
            ->getDispatcher()
            ->url(
                $request,
                Application::ROUTE_PAGE,
                $context->getPath(),
            );
    }

    protected function getSettingsUrl(Context $context): string
    {
        $request = Application::get()->getRequest();
        return $request
            ->getDispatcher()
            ->url(
                $request,
                Application::ROUTE_PAGE,
                $context->getPath(),
                'management',
                'settings',
                ['distribution'],
                null,
                'swordv3'
            );
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
