<?php
/**
 * Class representing a specific SWORDv3 service
 *
 * Encapsulates the service URL, authentication details,
 * and any service-specific configuration.
 */
namespace APP\plugins\generic\swordv3\swordv3Client;

use Exception;

class Service
{
    public function __construct(
        public string $name,
        public string $url,
        public AuthMode $authMode,
        public ?ServiceDocument $serviceDocument = null,
    ) {
        //
    }

    /**
     * Whether or not this service supports the selected authentication mode
     */
    public function supportsAuth(): bool
    {
        if (!$this->serviceDocument) {
            throw new Exception("Can't check supported authentication modes because a serviceDocument has not been set on this service.");
        }
        return in_array($this->authMode->getMode(), $this->serviceDocument->getAuthModes());
    }
}