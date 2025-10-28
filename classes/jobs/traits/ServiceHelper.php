<?php
namespace APP\plugins\generic\swordv3\classes\jobs\traits;

use APP\plugins\generic\swordv3\classes\OJSService;
use PKP\plugins\PluginRegistry;

/**
 * Helper functions for getting a service
 */
trait ServiceHelper
{
    /**
     * Retrieve the service configuration based on the URL
     */
    protected function getServiceByUrl(int $contextId, string $url): ?OJSService
    {
        /** @var Swordv3Plugin $plugin */
        $plugin = PluginRegistry::getPlugin('generic', 'swordv3plugin');
        $services = $plugin->getServices($contextId);
        foreach ($services as $service) {
            if ($service->url === $url) {
                return $service;
            }
        }
        return null;
    }

    /**
     * Disable a service
     *
     * Updates the service configuration to disable the service
     * and store a reason it to be disabled.
     */
    protected function disableService(string $reason, string $serviceUrl, int $contextId): void
    {
        /** @var Swordv3Plugin $plugin */
        $plugin = PluginRegistry::getPlugin('generic', 'swordv3plugin');
        $data = $plugin->getSetting($contextId, 'services');
        if (!is_array($data) || !count($data)) {
            $data = [];
        }

        $newData = collect($data)
            ->map(function(array $service) use ($reason, $serviceUrl) {
                if ($serviceUrl === $service['url']) {
                    $service['enabled'] = false;
                    $service['statusMessage'] = $reason;
                }
                return $service;
            });

        $plugin->updateSetting(
            $contextId,
            'services',
            $newData
        );
    }
}