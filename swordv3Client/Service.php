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
    public const AUTH_BASIC = 'Basic';
    public const AUTH_API_KEY = 'APIKey';
    public const AUTH_OAUTH = 'Oauth';
    public const AUTH_DIGEST = 'Digest';

    public function __construct(
        public string $name,
        public string $url,
        public string $apiKey,
        /** @var string One of the Service::AUTH_* constants */
        public string $authMode,
    ) {
        //
    }
}