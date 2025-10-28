<?php
namespace APP\plugins\generic\swordv3\classes\jobs\traits;

use APP\plugins\generic\swordv3\classes\OJSService;
use PKP\plugins\PluginRegistry;

/**
 * Helper functions for getting a service
 */
trait ServiceHelper
{
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
}