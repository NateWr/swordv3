<?php
/**
 * Class representing a specific SWORDv3 service
 *
 * Encapsulates the service URL, authentication details,
 * and any service-specific configuration.
 */
namespace APP\plugins\generic\swordv3\swordv3Client;

class Service
{
    public function __construct(
        public string $url,
        public AuthMode $authMode,
    ) {
        //
    }
}