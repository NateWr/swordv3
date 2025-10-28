<?php

namespace APP\plugins\generic\swordv3\classes\listeners;

use APP\plugins\generic\swordv3\classes\jobs\Deposit;
use APP\plugins\generic\swordv3\Swordv3Plugin;
use PKP\observers\events\PublicationPublished;
use PKP\plugins\PluginRegistry;

class DepositPublication
{
    public function handle(PublicationPublished $publishedEvent): void
    {
        /** @var Swordv3Plugin $plugin */
        $plugin = PluginRegistry::getPlugin('generic', 'swordv3plugin');
        $services = $plugin->getServices($publishedEvent->context->getId());

        foreach ($services as $service) {
            dispatch(
                new Deposit(
                    $publishedEvent->submission->getCurrentPublication()->getId(),
                    $publishedEvent->submission->getId(),
                    $publishedEvent->context->getId(),
                    $service->url
                )
            );
        }
    }
}
