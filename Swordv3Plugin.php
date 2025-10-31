<?php

namespace APP\plugins\generic\swordv3;

use APP\core\Application;
use APP\plugins\generic\swordv3\classes\listeners\DepositPublication;
use APP\plugins\generic\swordv3\classes\OJSService;
use APP\plugins\generic\swordv3\classes\ServiceForm;
use APP\plugins\generic\swordv3\classes\SettingsHandler;
use APP\plugins\generic\swordv3\classes\task\CheckInProgressDeposits;
use APP\plugins\generic\swordv3\swordv3Client\auth\APIKey;
use APP\plugins\generic\swordv3\swordv3Client\auth\Basic;
use APP\template\TemplateManager;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Event;
use PKP\core\PKPApplication;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\RedirectAction;
use PKP\observers\events\PublicationPublished;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use PKP\plugins\interfaces\HasTaskScheduler;
use PKP\scheduledTask\PKPScheduler;

class Swordv3Plugin extends GenericPlugin implements HasTaskScheduler
{
    public function register($category, $path, $mainContextId = NULL)
    {
        $success = parent::register($category, $path);
        if ($success && $this->getEnabled()) {
            Hook::add('TemplateManager::display', $this->getSettingsForm(...));
            Hook::add('Template::Settings::distribution', $this->addSettingsPage(...));
            Hook::add('LoadHandler', $this->setSettingsHandler(...));
            Hook::add('Schema::get::publication', $this->addToPublicationSchema(...));
            Event::listen(
                PublicationPublished::class,
                DepositPublication::class,
            );
            $this->loadScripts();
        }
        return $success;
    }

    public function getDisplayName()
    {
        return __('plugins.generic.swordv3.name');
    }

    public function getDescription()
    {
        return __('plugins.generic.swordv3.description');
    }

    public function getActions($request, $verb)
    {
        $actions = [];
        if ($this->getEnabled()) {
            $dispatcher = $request->getDispatcher();
            $actions[] = new LinkAction(
                'settings',
                new RedirectAction($dispatcher->url(
                    $request,
                    Application::ROUTE_PAGE,
                    null,
                    'management',
                    'settings',
                    ['distribution'],
                    null,
                    'swordv3'
                )),
                __('plugins.generic.swordv3.name'),
                null
            );
        }
        return array_merge($actions, parent::getActions($request, $verb));
    }

    public function getSettingsForm(string $hookName, array $args): bool
    {
        $templateMgr = $args[0];
        $template = $args[1];

        if ($template !== 'management/distribution.tpl') {
            return false;
        }

        $request = Application::get()->getRequest();
        $dispatcher = $request->getDispatcher();
        $context = $request->getContext();
        $primaryLocale = $context->getPrimaryLocale();

        $service = null;
        $services = $this->getSetting($context->getId(), 'services');
        if (is_array($services) && count($services)) {
            $service = $services[0];
        }

        $addForm = new ServiceForm(
            $dispatcher->url($request, PKPApplication::ROUTE_PAGE, $context->getPath(), 'swordv3', 'saveServiceForm'),
            [['key' => $primaryLocale, 'label' => $primaryLocale]],
            $context,
            $service,
        );

        $components = $templateMgr->getState('components');
        $components[$addForm->id] = $addForm->getConfig();
        $templateMgr->setState(['components' => $components]);

        $services = $this->getServices($context->getId());

        $state = [
            'enabled' => false,
        ];
        if (count($services)) {
            $state = array_merge(
                $state,
                [
                    'enabled' => true,
                    'exportCsvUrl' => $request
                        ->getDispatcher()
                        ->url(
                            $request,
                            PKPApplication::ROUTE_PAGE,
                            $context->getPath(),
                            'swordv3',
                            'csv'
                        ),
                    'itemsPerPage' => SettingsHandler::PER_PAGE,
                    'serviceName' => $services[0]->name,
                ]
            );
        }

        $templateMgr->setState(['swordv3' => $state]);

        return false;
    }

    public function addSettingsPage(string $hookName, array $args): bool
    {
        $templateMgr = $args[1];
        $output = &$args[2];

        $request = Application::get()->getRequest();
        $context = $request->getContext();

        $services = $this->getServices($context->getId());

        $templateMgr->assign([
            'swordv3Configured' => count($services) >= 1,
        ]);

        $output .= $templateMgr->fetch($this->getTemplateResource('settings.tpl'));

        return false;
    }

    public function setSettingsHandler(string $hookName, array $args): bool
    {
        $page = &$args[0];
        $handler = &$args[3];
        if ($this->getEnabled() && $page === 'swordv3') {
            $handler = new SettingsHandler($this);
            return true;
        }
        return false;
    }

    /**
     * Extend Publication entity schema to create properties for
     * storing deposit status information
     */
    public function addToPublicationSchema(string $hookName, array $args): bool
    {
        $schema = $args[0];
        $schema->properties->swordv3DateDeposited = (object) [
            'type' => 'string',
            'validation' => [
                'nullable',
                'date_format:Y-m-d h:i:s',
            ],
        ];
        $schema->properties->swordv3State = (object) [
            'type' => 'string',
            'validation' => ['nullable'],
        ];
        $schema->properties->swordv3StatusDocument = (object) [
            'type' => 'string',
            'validation' => ['nullable'],
        ];

        return false;
    }

    /**
     * @return OJSService[]
     */
    public function getServices(int $contextId): array
    {
        $data = $this->getSetting($contextId, 'services');
        if (!is_array($data) || !count($data)) {
            return [];
        }

        $services = [];
        foreach ($data as $service) {
            $services[] = $this->getServiceFromPluginSettings($service);
        }

        return $services;
    }

    public function getServiceFromPluginSettings(array $service): OJSService
    {
        return new OJSService(
            $service['name'],
            $service['url'],
            $service['authMode'] === 'APIKey'
                ? new APIKey(Crypt::decrypt($service['apiKey']))
                : new Basic($service['username'], Crypt::decrypt($service['password'])),
            $service['enabled'],
            $service['statusMessage']
        );
    }

    public function registerSchedules(PKPScheduler $scheduler): void
    {
        $scheduler
            ->addSchedule(new CheckInProgressDeposits())
            ->daily()
            ->name(CheckInProgressDeposits::class)
            ->withoutOverlapping();
    }

    protected function loadScripts(): void
    {
        $request = Application::get()->getRequest();
        $templateMgr = TemplateManager::getManager($request);

        $templateMgr->addJavaScript(
            'swordv3',
            "{$request->getBaseUrl()}/{$this->getPluginPath()}/public/build/build.iife.js",
            [
                'contexts' => ['backend-management-settings'],
                'priority' => TemplateManager::STYLE_SEQUENCE_LAST,
            ]
        );
        $templateMgr->addStyleSheet(
            'swordv3',
            "{$request->getBaseUrl()}/{$this->getPluginPath()}/public/build/build.css",
            [
                'contexts' => ['backend-management-settings'],
            ]
        );
    }
}
