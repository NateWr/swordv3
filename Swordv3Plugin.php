<?php

namespace APP\plugins\generic\swordv3;

use APP\core\Application;
use APP\plugins\generic\swordv3\classes\Collector;
use APP\plugins\generic\swordv3\classes\listeners\DepositPublication;
use APP\plugins\generic\swordv3\classes\OJSService;
use APP\plugins\generic\swordv3\classes\ServiceForm;
use APP\plugins\generic\swordv3\classes\SettingsHandler;
use APP\plugins\generic\swordv3\swordv3Client\auth\APIKey;
use APP\plugins\generic\swordv3\swordv3Client\auth\Basic;
use APP\plugins\generic\swordv3\swordv3Client\StatusDocument;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Event;
use PKP\core\PKPApplication;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\RedirectAction;
use PKP\observers\events\PublicationPublished;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;

class Swordv3Plugin extends GenericPlugin
{
    /**
     * @copydoc Plugin::register
     */
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
        }
        return $success;
    }

    /**
     * @copydoc Plugin::getDisplayName()
     */
    public function getDisplayName()
    {
        return __('plugins.generic.swordv3.name');
    }

    /**
     * @copydoc Plugin::getDescription()
     */
    public function getDescription()
    {
        return __('plugins.generic.swordv3.description');
    }

    /**
     * @copydoc Plugin::getActions()
     */
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
            $dispatcher->url($request, PKPApplication::ROUTE_PAGE, $context->getPath(), 'swordv3', 'add'),
            [['key' => $primaryLocale, 'label' => $primaryLocale]],
            $context,
            $service,
        );

        $components = $templateMgr->getState('components');
        $components[$addForm->id] = $addForm->getConfig();
        $templateMgr->setState(['components' => $components]);

        return false;
    }

    public function addSettingsPage(string $hookName, array $args): bool
    {
        $templateMgr = $args[1];
        $output = &$args[2];

        $request = Application::get()->getRequest();
        $context = $request->getContext();

        $services = $this->getServices($context->getId());
        if (!count($services)) {
            $templateMgr->assign([
                'swordv3Configured' => false,
            ]);
        } else {
            $collector = new Collector($context->getId());

            $countAll = $collector->getAllPublications()->count();
            $allStatuses = $collector->getWithDepositState(null);

            $deposited = $allStatuses->filter(fn($p) => in_array($p->setting_value, StatusDocument::SUCCESS_STATES))->count();
            $rejected = $allStatuses->filter(fn($p) => in_array($p->setting_value, [StatusDocument::STATE_REJECTED]))->count();
            $deleted = $allStatuses->filter(fn($p) => in_array($p->setting_value, [StatusDocument::STATE_DELETED]))->count();
            $unknown = $allStatuses->filter(fn($p) => in_array($p->setting_value, StatusDocument::STATES))->count();

            $templateMgr->assign([
                'swordv3Configured' => true,
                'notDeposited' => $countAll - $allStatuses->count(),
                'deposited' => $deposited,
                'rejected' => $rejected,
                'deleted' => $deleted,
                'unknown' => $unknown,
            ]);
        }

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
}
