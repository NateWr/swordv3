<?php

namespace APP\plugins\generic\swordv3\swordv3Client\exceptions;

use APP\plugins\generic\swordv3\swordv3Client\Client;
use Exception;
use GuzzleHttp\Exception\ClientException;

/**
 * Exceptions related to HTTP requests to a SWORDv3 server
 */
class HTTPException extends Exception
{
    public function __construct(
        public ClientException $clientException,
        public Client $client,
    ) {
        parent::__construct($this->message());
    }

    public function message(): string
    {
        return $this->clientException->getMessage();
    }
}
