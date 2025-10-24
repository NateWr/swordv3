<?php
namespace APP\plugins\generic\swordv3\classes\jobs;

use APP\core\Application;
use APP\facades\Repo;
use APP\journal\Journal;
use APP\journal\JournalDAO;
use APP\plugins\generic\swordv3\classes\exceptions\DepositsNotAccepted;
use APP\plugins\generic\swordv3\classes\exceptions\FilesNotSupported;
use APP\plugins\generic\swordv3\classes\exceptions\RecipientsNotFound;
use APP\plugins\generic\swordv3\classes\OJSDepositObject;
use APP\plugins\generic\swordv3\classes\OJSService;
use APP\plugins\generic\swordv3\swordv3Client\Client;
use APP\plugins\generic\swordv3\swordv3Client\exceptions\AuthenticationFailed;
use APP\plugins\generic\swordv3\swordv3Client\exceptions\AuthenticationRequired;
use APP\plugins\generic\swordv3\swordv3Client\exceptions\AuthenticationUnsupported;
use APP\plugins\generic\swordv3\swordv3Client\exceptions\DigestFormatNotFound;
use APP\plugins\generic\swordv3\swordv3Client\exceptions\Swordv3ConnectException;
use APP\plugins\generic\swordv3\swordv3Client\exceptions\Swordv3RequestException;
use APP\plugins\generic\swordv3\swordv3Client\StatusDocument;
use APP\plugins\generic\swordv3\Swordv3Plugin;
use APP\publication\Publication;
use APP\submission\Submission;
use DateTime;
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
    protected OJSService $service;

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

        $this->service = $this->getService($this->serviceUrl);

        if (!$this->service) {
            $this->log("Aborting deposit because deposit service settings can not be found.");
        }

        if (!$this->service->enabled) {
            $this->log("Aborting deposit because deposit service has been disabled.");
            return;
        }

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
            $this->log("Authentication error encountered: {$exception->getMessage()}");
            $error = $this->getAuthErrorMessage($exception);
            $this->disableService($error);
            $this->notify($error, $depositObject->context);
            return;
        } catch (DepositsNotAccepted $exception) {
            $this->log("Deposit aborted: {$exception->getMessage()}");
            $error = __('plugins.generic.swordv3.service.depositsNotAccepted');
            $this->disableService($error);
            $this->notify($error, $depositObject->context);
            return;
        } catch (DigestFormatNotFound $exception) {
            $this->log("Deposit aborted: {$exception->getMessage()}");
            $error = __('plugins.generic.swordv3.service.digestNotAccepted');
            $this->disableService($error);
            $this->notify($error, $depositObject->context);
            return;
        } catch (FilesNotSupported $exception) {
            // TODO: send email to admin
            // TODO: update status to reflect incomplete deposit
            $this->log("Deposit aborted: {$exception->getMessage()}");
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
                $error = $exception->client->service->authMode->getMode() === 'Basic'
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
     * Send a notification email to managers, admins, or the tech support
     * contact when there is a problem with a deposit
     */
    protected function notify(string $error, Context $context): void
    {
        $recipients = $this->getErrorEmailRecipients($context);

        if (!$recipients) {
            throw new RecipientsNotFound("Unable to send a notification email about a failed deposit from the swordv3 plugin. No valid recipients were found.");
        }

        $subject = __('plugins.generic.swordv3.notification.depositError.subject', [
            'context' => $context->getLocalizedName(),
            'service' => $this->service->name,
        ]);
        $body = $this->getCommonEmailBody($error, $context, $this->service);

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
    protected function getCommonEmailBody(string $error, Context $context, OJSService $service): string
    {
        $body = collect([
            __('plugins.generic.swordv3.notification.serviceStatus.intro', [
                'context' => $context->getLocalizedName(),
                'contextUrl' => $this->getContextUrl($context),
                'service' => $service->name,
                'serviceUrl' => $service->url,
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

    protected function getService(string $url): ?OJSService
    {
        /** @var Swordv3Plugin $plugin */
        $plugin = PluginRegistry::getPlugin('generic', 'swordv3plugin');
        $services = $plugin->getServices($this->contextId);
        foreach ($services as $service) {
            if ($service->url === $url) {
                return $service;
            }
        }
        return null;
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
