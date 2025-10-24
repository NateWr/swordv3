<?php

namespace APP\plugins\generic\swordv3\swordv3Client\exceptions;

use APP\plugins\generic\swordv3\swordv3Client\Service;
use APP\plugins\generic\swordv3\swordv3Client\ServiceDocument;
use Exception;

class AuthenticationUnsupported extends Exception
{
    public function __construct(
        public Service $service,
        public ServiceDocument $serviceDocument,
    ) {
        $supportedModes = join(', ', $serviceDocument->getAuthModes());
        parent::__construct(
            "SWORDv3 service {$service->url} does not support {$service->authMode->getMode()}. Supported modes: {$supportedModes}"
        );
    }
}