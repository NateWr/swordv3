<?php

namespace APP\plugins\generic\swordv3\classes;

use APP\core\Application;
use APP\core\Request;
use APP\handler\Handler;
use APP\plugins\generic\swordv3\classes\jobs\Deposit;
use APP\plugins\generic\swordv3\swordv3Client\auth\APIKey;
use APP\plugins\generic\swordv3\swordv3Client\auth\Basic;
use APP\plugins\generic\swordv3\swordv3Client\Service;
use APP\plugins\generic\swordv3\Swordv3Plugin;
use Exception;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class SettingsHandler extends Handler
{
    public Swordv3Plugin $plugin;

    public function __construct(Swordv3Plugin $plugin)
    {
        $this->plugin = $plugin;
    }

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
        ];

        // Encrypt the password or if it is the same as the already stored
        // password don't change it.
        if ($authMode === 'Basic') {
            $data['username'] = $username;
            $data['password'] = $service['password'] && $service['password'] === $password
                ? $service['password']
                : Crypt::encrypt($password);
        } else if ($authMode === 'APIKey') {
            $data['apiKey'] = $service['apiKey'] && $service['apiKey'] === $apiKey
                ? $service['apiKey']
                : Crypt::encrypt($apiKey);
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
        /** @var Service $service */
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
                        $service
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
}
