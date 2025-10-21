<?php

namespace APP\plugins\generic\swordv3\classes\listeners;

use APP\plugins\generic\swordv3\classes\jobs\Deposit;
use PKP\observers\events\PublicationPublished;

class DepositPublication
{
    public function handle(PublicationPublished $publishedEvent): void
    {
        dispatch(
            new Deposit(
                $publishedEvent->submission->getCurrentPublication()->getId(),
                $publishedEvent->submission->getId(),
                $publishedEvent->context->getId()
            )
        );
    }
}
