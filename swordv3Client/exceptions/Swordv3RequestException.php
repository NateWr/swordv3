<?php

namespace APP\plugins\generic\swordv3\swordv3Client\exceptions;

use APP\plugins\generic\swordv3\swordv3Client\Client;
use Exception;
use GuzzleHttp\Exception\RequestException;

/**
 * Exceptions related to 4**, 5** response errors
 */
class Swordv3RequestException extends Exception
{
    public function __construct(
        public RequestException $exception,
        public Client $client,
    ) {
        parent::__construct($this->exception->getMessage());
    }
}
