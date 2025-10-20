<?php
/**
 * Interface for authentication modes
 */
namespace APP\plugins\generic\swordv3\swordv3Client;

interface AuthMode
{
    public function getMode(): string;
    public function getAuthorizationHeader(): string;
}