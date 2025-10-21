<?php

namespace APP\plugins\generic\swordv3\classes;

use APP\core\Application;
use APP\core\Request;
use APP\handler\Handler;
use APP\plugins\generic\swordv3\classes\jobs\Deposit;
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

        $collector = new Collector($context->getId());
        $deposited = $collector->getWithDepositState(null);

        $collector->getAllPublications()
            ->filter(function($row) use ($deposited) {
                return !$deposited->contains(function($r) use ($row) {
                    return $r->publication_id === $row->publication_id;
                });
            })
            ->each(function($row) use ($context) {
                dispatch(
                    new Deposit(
                        $row->publication_id,
                        $row->submission_id,
                        $context->getId()
                    )
                );
            });

        $request->redirect(null, 'management', 'settings', ['distribution'], null, 'swordv3');
    }
}
