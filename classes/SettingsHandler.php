<?php

namespace APP\plugins\generic\swordv3\classes;

use APP\core\Application;
use APP\core\Request;
use APP\facades\Repo;
use APP\handler\Handler;
use APP\plugins\generic\swordv3\Swordv3Plugin;
use Illuminate\Http\Response;

class SettingsHandler extends Handler
{
    public Swordv3Plugin $plugin;

    public function __construct(Swordv3Plugin $plugin)
    {
        $this->plugin = $plugin;
    }

    public function add($args, Request $request): void
    {
        response()->json(['name' => 'test response received'], Response::HTTP_OK)->send();
    }

    public function deposit($args, Request $request): void
    {
        $submission = Repo::submission()->get(1);
        $context = Application::get()->getRequest()->getContext();

        dispatch(
            new Deposit(
                $submission->getCurrentPublication()->getId(),
                $submission->getId(),
                $context->getId()
            )
        );

        $request->redirect(null, 'management', 'settings', ['distribution'], null, 'swordv3');
    }
}
