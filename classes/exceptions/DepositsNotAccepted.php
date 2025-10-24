<?php

namespace APP\plugins\generic\swordv3\classes\exceptions;
use APP\plugins\generic\swordv3\swordv3Client\Service;
use APP\plugins\generic\swordv3\swordv3Client\ServiceDocument;
use Exception;

class DepositsNotAccepted extends Exception
{
    public function __construct(
        public Service $service,
        public ServiceDocument $serviceDocument,
    ) {
        parent::__construct(
            "Deposit service at {$service->url} does not accept deposits."
        );
    }
}