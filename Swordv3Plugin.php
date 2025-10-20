<?php

namespace APP\plugins\generic\swordv3;

use APP\core\Application;
use APP\plugins\generic\swordv3\classes\ServiceForm;
use APP\plugins\generic\swordv3\classes\SettingsHandler;
use PKP\core\PKPApplication;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\RedirectAction;
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
                    'sword'
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

        $addForm = new ServiceForm(
            $dispatcher->url($request, PKPApplication::ROUTE_PAGE, $context->getPath(), 'swordv3', 'add'),
            [['key' => $primaryLocale, 'label' => $primaryLocale]],
            $context,
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
        $schema->properties->swordv3 = (object) [
            'type' => 'string',
            'validation' => ['nullable'],
        ];

        return false;
    }
}
