<?php

namespace APP\plugins\generic\swordv3\swordv3Client\exceptions;

use APP\plugins\generic\swordv3\swordv3Client\Client;
use Exception;
use GuzzleHttp\Exception\ConnectException;

/**
 * Exceptions related to network request failures
 */
class Swordv3ConnectException extends Exception
{
    public function __construct(
        public ConnectException $connectException,
        public Client $client,
    ) {
        parent::__construct($this->message());
    }

    public function message(): string
    {
        return $this->connectException->getMessage();
    }
}
