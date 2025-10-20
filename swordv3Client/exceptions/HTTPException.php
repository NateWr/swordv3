<?php

namespace APP\plugins\generic\swordv3\swordv3Client\exceptions;

use APP\plugins\generic\swordv3\swordv3Client\DepositObject;
use APP\plugins\generic\swordv3\swordv3Client\Service;
use Exception;
use GuzzleHttp\Exception\ClientException;
use Psr\Http\Message\ResponseInterface;

/**
 * Exceptions related to HTTP requests to a SWORDv3 server
 */
class HTTPException extends Exception
{
    public function __construct(
        public ClientException $clientException,
        public ResponseInterface $response,
        public Service $service,
        public DepositObject $depositObject,
    ) {
        parent::__construct($this->message());
    }

    public function message(): string
    {
        return $this->clientException->getMessage();
    }
}
