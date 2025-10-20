<?php
/**
 * Authentication mode for authenticating with an API key
 */
namespace APP\plugins\generic\swordv3\swordv3Client\auth;

use APP\plugins\generic\swordv3\swordv3Client\AuthMode;

class APIKey implements AuthMode
{
    public function __construct(protected string $apiKey)
    {
        //
    }

    public function getMode(): string
    {
        return 'APIKey';
    }

    public function getAuthorizationHeader(): string
    {
        return "APIKey {$this->apiKey}";
    }
}