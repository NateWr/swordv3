<?php

namespace APP\plugins\generic\swordv3\exceptions;

use APP\plugins\generic\swordv3\swordv3Client\Service;
use APP\plugins\generic\swordv3\swordv3Client\StatusDocument;
use Exception;

class DepositDeletedStatus extends Exception
{
    public function __construct(
        public StatusDocument $statusDocument,
        public Service $service,
    ) {
        parent::__construct(
            "The service at {$service->url} has deleted the deposit at {$statusDocument->getObjectId()}."
        );
    }
}