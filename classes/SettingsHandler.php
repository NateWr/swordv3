<?php

namespace APP\plugins\generic\swordv3\classes;

use APP\core\Application;
use APP\core\Request;
use APP\handler\Handler;
use APP\plugins\generic\swordv3\classes\exceptions\DepositsNotAccepted;
use APP\plugins\generic\swordv3\classes\jobs\Deposit;
use APP\plugins\generic\swordv3\swordv3Client\Client;
use APP\plugins\generic\swordv3\swordv3Client\exceptions\AuthenticationFailed;
use APP\plugins\generic\swordv3\swordv3Client\exceptions\AuthenticationUnsupported;
use APP\plugins\generic\swordv3\swordv3Client\exceptions\Swordv3ConnectException;
use APP\plugins\generic\swordv3\swordv3Client\exceptions\Swordv3RequestException;
use APP\plugins\generic\swordv3\swordv3Client\ServiceDocument;
use APP\plugins\generic\swordv3\Swordv3Plugin;
use Exception;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use PKP\security\authorization\CanAccessSettingsPolicy;
use PKP\security\authorization\ContextAccessPolicy;
use PKP\security\Role;
use Throwable;

class SettingsHandler extends Handler
{
    public Swordv3Plugin $plugin;

    public function __construct(Swordv3Plugin $plugin)
    {
        $this->plugin = $plugin;

        $this->addRoleAssignment(
            [Role::ROLE_ID_SITE_ADMIN, Role::ROLE_ID_MANAGER],
            ['add', 'deposit', 'reset'],
        );
    }

    /**
     * @copydoc PKPHandler::authorize()
     */
    public function authorize($request, &$args, $roleAssignments)
    {
        $this->addPolicy(new ContextAccessPolicy($request, $roleAssignments));
        $this->addPolicy(new CanAccessSettingsPolicy());

        return parent::authorize($request, $args, $roleAssignments);
    }

    /**
     * Save the service setup form
     */
    public function add($args, Request $request): void
    {
        $params = $request->getUserVars();

        $errors = [];

        $maxStringLength = 1024;
        $name = trim($params['name'] ?? '');
        $url = trim($params['url'] ?? '');
        $authMode = trim($params['authMode'] ?? '');
        $username = trim($params['username'] ?? '');
        $password = trim($params['password'] ?? '');
        $apiKey = trim($params['apiKey'] ?? '');

        if (!$name) $errors['name'] = [__('validator.required')];
        if (!$url) $errors['url'] = [__('validator.required')];
        if (!$authMode) $errors['authMode'] = [__('validator.required')];

        if (strlen($name) > $maxStringLength) $errors['name'] = [__('validator.max.string', $maxStringLength)];
        if (strlen($url) > $maxStringLength) $errors['url'] = [__('validator.max.string', $maxStringLength)];

        if (!in_array($authMode, ['Basic', 'APIKey'])) {
            $errors['authMode'] = [__('validation.invalidOption')];
        }

        if ($authMode === 'Basic') {
            if (!$username) $errors['username'] = [__('validator.required')];
            if (!$password) $errors['password'] = [__('validator.required')];
        } else if ($authMode === 'APIKey') {
            if (!$apiKey) $errors['apiKey'] = [__('validator.required')];
            if (strlen($apiKey) > $maxStringLength) $errors['apiKey'] = [__('validator.max.string', $maxStringLength)];
        }

        if (count($errors)) {
            response()->json($errors, Response::HTTP_BAD_REQUEST)->send();
            exit();
        }

        $services = $this->plugin->getSetting($request->getContext()->getId(), 'services');
        if (is_null($services) || !is_array($services)) {
            $services = [];
        }

        if (count($services)) {
            $service = $services[0];
        }

        $data = [
            'name' => $name,
            'url' => $url,
            'authMode' => $authMode,
            'enabled' => true,
            'statusMessage' => isset($service['statusMessage']) ? $service['statusMessage'] : '',
        ];

        // Encrypt a new password/api key if it has changed
        if ($authMode === 'Basic') {
            $data['username'] = $username;
            $data['password'] = isset($service['password']) && $service['password'] === $password
                ? $service['password']
                : Crypt::encrypt($password);
        } else if ($authMode === 'APIKey') {
            $data['apiKey'] = isset($service['apiKey']) && $service['apiKey'] === $apiKey
                ? $service['apiKey']
                : Crypt::encrypt($apiKey);
        }

        $service = $this->plugin->getServiceFromPluginSettings($data);

        try {
            $serviceDocument = $this->getServiceDocument($service);
            if (!$serviceDocument->supportsAuthMode($service->authMode->getMode())) {
                throw new AuthenticationUnsupported($service, $serviceDocument);
            }
            if (!$serviceDocument->acceptDeposits()) {
                throw new DepositsNotAccepted($service, $serviceDocument);
            }
        } catch (AuthenticationUnsupported $exception) {
            $nameAuthMode = $authMode === 'Basic'
                ? __('plugins.generic.swordv3.service.authMode.basic')
                : __('plugins.generic.swordv3.service.authMode.apiKey');
            $errors['authMode'] = [__(
                'plugins.generic.swordv3.service.authMode.unsupported',
                ['authMode' => $nameAuthMode]
            )];
        } catch (AuthenticationFailed $exception) {
            if ($authMode === 'Basic') {
                $errors['username'] = [__('plugins.generic.swordv3.service.authMode.userPassFailed')];
            } else {
                $errors['apiKey'] = [__('plugins.generic.swordv3.service.authMode.apiKeyFailed')];
            }
        } catch (Swordv3RequestException|Swordv3ConnectException $exception) {
            $errors['url'] = [__('plugins.generic.swordv3.service.setupFailed')];
        } catch (DepositsNotAccepted $exception) {
            $errors['url'] = [__('plugins.generic.swordv3.service.depositsNotAccepted')];
        } catch (Throwable $exception) {
            throw $exception;
        }

        if (count($errors)) {
            response()->json($errors, Response::HTTP_BAD_REQUEST)->send();
            exit();
        }

        $this->plugin->updateSetting(
            $request->getContext()->getId(),
            'services',
            [$data]
        );

        response()->json($data, Response::HTTP_OK)->send();
    }

    /**
     * Dispatch jobs to deposit all publications that have not yet
     * been deposited
     *
     * This does not create a job for publications witha  rejected,
     * deleted or unknown status.
     */
    public function deposit($args, Request $request): void
    {
        $context = Application::get()->getRequest()->getContext();

        $services = $this->plugin->getServices($context->getId());
        if (!count($services)) {
            throw new Exception('No SWORDv3 service configured for deposits.');
        }

        // TODO: support more than one service
        /** @var OJSService $service */
        $service = $services[0];

        $collector = new Collector($context->getId());
        $deposited = $collector->getWithDepositState(null);

        $collector->getAllPublications()
            ->filter(function($row) use ($deposited) {
                return !$deposited->contains(function($r) use ($row) {
                    return $r->publication_id === $row->publication_id;
                });
            })
            ->each(function($row) use ($context, $service) {
                dispatch(
                    new Deposit(
                        $row->publication_id,
                        $row->submission_id,
                        $context->getId(),
                        $service->url
                    )
                );
            });

        $request->redirect(null, 'management', 'settings', ['distribution'], null, 'swordv3');
    }

    /**
     * Temporary method to delete all swordv3 deposit data from submissions
     *
     * TODO: REMOVE THIS
     */
    public function reset($args, Request $request): void
    {
        DB::table('publication_settings')
            ->whereLike('setting_name', '%swordv3%')
            ->delete();
        $request->redirect(null, 'management', 'settings', ['distribution'], null, 'swordv3');
    }

    /**
     * Request a ServiceDocument from a service
     */
    protected function getServiceDocument(OJSService $service): ServiceDocument
    {
        $client = new Client(
            httpClient: Application::get()->getHttpClient(),
            service: $service,
        );
        return $client->getServiceDocument();
    }
}
