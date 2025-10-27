<?php

namespace APP\plugins\generic\swordv3\classes\exceptions;

use APP\plugins\generic\swordv3\swordv3Client\Service;
use APP\plugins\generic\swordv3\swordv3Client\StatusDocument;
use Exception;

class DepositRejectedStatus extends Exception
{
    public function __construct(
        public StatusDocument $statusDocument,
        public Service $service,
    ) {
        parent::__construct(
            "The service at {$service->url} has rejected the deposit at {$statusDocument->getObjectId()}."
        );
    }
}