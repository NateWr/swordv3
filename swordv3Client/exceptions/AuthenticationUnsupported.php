<?php

namespace APP\plugins\generic\swordv3\swordv3Client\exceptions;

use APP\plugins\generic\swordv3\swordv3Client\Service;
use Exception;

class AuthenticationUnsupported extends Exception
{
    public function __construct(
        public Service $service,
    ) {

        $supportedModes = $service->serviceDocument?->getAuthModes() || [];

        parent::__construct("SWORDv3 service {$service->name} does not support {$service->authMode}. Supported modes: {$supportedModes}");
    }
}