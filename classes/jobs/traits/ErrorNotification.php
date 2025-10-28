<?php
namespace APP\plugins\generic\swordv3\classes\jobs\traits;

use APP\core\Application;
use APP\facades\Repo;
use APP\plugins\generic\swordv3\classes\OJSService;
use APP\plugins\generic\swordv3\swordv3Client\exceptions\AuthenticationFailed;
use APP\plugins\generic\swordv3\swordv3Client\exceptions\AuthenticationRequired;
use APP\plugins\generic\swordv3\swordv3Client\exceptions\AuthenticationUnsupported;
use PKP\context\Context;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;
use PKP\security\Role;
use PKP\user\User;
use Psr\Log\LoggerInterface;

/**
 * Helper functions for sending notification emails
 * when a deposit error occurs
 */
trait ErrorNotification
{
    /**
     * Send an email notification when there is a problem that
     * disables deposits for this service
     *
     * Emails managers, admins, or the tech support contact.
     */
    protected function notifyServiceDisabled(string $error, OJSService $service, Context $context): void
    {
        $recipients = $this->getErrorEmailRecipients($context);

        if (!$recipients) {
            if (property_exists($this, 'log') && is_a($this->log, LoggerInterface::class)) {
                $this->log->critical(__('plugins.generic.swordv3.notification.recipientsNotFound'));
            }
            return;
        }

        $subject = __('plugins.generic.swordv3.notification.depositError.subject', [
            'context' => $context->getLocalizedName(),
            'service' => $service->name,
        ]);
        $body = $this->getCommonEmailBody($error, $service, $context);

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
    protected function getCommonEmailBody(string $error, OJSService $service, Context $context): string
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
            ->filterByContextIds([$context->getId()])
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
                break;
        }

        return $error;
    }
}