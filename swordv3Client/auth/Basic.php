<?php
/**
 * Authentication mode for authenticating with username/password
 */
namespace APP\plugins\generic\swordv3\swordv3Client\auth;

use APP\plugins\generic\swordv3\swordv3Client\AuthMode;

class Basic implements AuthMode
{
    public function __construct(
      protected string $username,
      protected string $password,
    ) {
        //
    }

    public function getMode(): string
    {
        return 'Basic';
    }

    public function getAuthorizationHeader(): string
    {
        $encoded = base64_encode("{$this->username}:{$this->password}");
        return "Basic {$encoded}";
    }
}