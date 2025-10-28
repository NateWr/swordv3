<?php

namespace APP\plugins\generic\swordv3\classes\task;

use APP\plugins\generic\swordv3\classes\Collector;
use APP\plugins\generic\swordv3\classes\jobs\UpdateDepositProgress;
use APP\plugins\generic\swordv3\classes\OJSService;
use APP\plugins\generic\swordv3\swordv3Client\StatusDocument;
use PKP\plugins\PluginRegistry;
use PKP\scheduledTask\ScheduledTask;

class CheckInProgressDeposits extends ScheduledTask
{
    public function getName(): string
    {
        return __('plugins.generic.swordv3.task.checkInProgressDeposits');
    }

    public function executeActions(): bool
    {
        $contextIds = app()->get('context')->getIds(['isEnabled' => true]);
        foreach ($contextIds as $contextId) {
            $rows = (new Collector($contextId))->getWithDepositState([
                StatusDocument::STATE_IN_PROGRESS,
                StatusDocument::STATE_IN_WORKFLOW,
                StatusDocument::STATE_ACCEPTED,
            ]);
            $services = $this->getServices($contextId);
            if (!$rows->count() || !count($services)) {
                continue;
            }
            $rows->each(function($row) use ($contextId, $services) {
                foreach ($services as $service) {
                    dispatch(
                        new UpdateDepositProgress(
                            $row->publication_id,
                            $row->submission_id,
                            $contextId,
                            $service->url
                        )
                    );
                }
            });
        }

        return true;
    }

    /**
     * @return OJSService[]
     */
    protected function getServices(int $contextId): ?OJSService
    {
        /** @var Swordv3Plugin $plugin */
        $plugin = PluginRegistry::getPlugin('generic', 'swordv3plugin');
        return $plugin->getServices($contextId) ?? [];
    }
}
