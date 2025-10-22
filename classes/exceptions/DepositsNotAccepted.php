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
            "{$service->name} does not accept deposits."
        );
    }
}