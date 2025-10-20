<?php

namespace APP\plugins\generic\swordv3\swordv3Client;

use APP\plugins\generic\swordv3\swordv3Client\exceptions\AuthenticationFailed;
use APP\plugins\generic\swordv3\swordv3Client\exceptions\AuthenticationRequired;
use APP\plugins\generic\swordv3\swordv3Client\exceptions\BadRequest;
use APP\plugins\generic\swordv3\swordv3Client\exceptions\HTTPException;
use APP\plugins\generic\swordv3\swordv3Client\exceptions\PageNotFound;
use Exception;
use GuzzleHttp\Client as GuzzleHttpClient;
use GuzzleHttp\Exception\ClientException;
use Psr\Http\Message\ResponseInterface;

class Client
{
    public const METHOD_GET = 'GET';
    public const METHOD_POST = 'POST';

    public const DEFAULT_METADATA_FORMAT = 'http://purl.org/net/sword/3.0/types/Metadata';

    protected array $serviceDocument;

    public function __construct(
        public GuzzleHttpClient $httpClient,
        public Service $service,
        public DepositObject $object,
    ) {
        //
    }

    /**
     * @throws AuthenticationRequired
     * @throws AuthenticationFailed
     * @throws BadRequest
     * @throws PageNotFound
     * @throws HTTPException
     */
    public function getServiceDocument(): ResponseInterface
    {
        try {
            $response = $this->httpClient->request(
                'GET',
                $this->service->url,
                [
                    'headers' => [
                        'Authorization' => 'APIKey ' . $this->service->apiKey,
                    ],
                ]
            );
        } catch (ClientException $exception) {
            $exceptionClass = $this->getHTTPException($exception);
            throw new $exceptionClass($exception, $exception->getResponse(), $this->service, $this->object);
        }
        return $response;
    }

    /**
     * @throws AuthenticationRequired
     * @throws AuthenticationFailed
     * @throws BadRequest
     * @throws PageNotFound
     * @throws HTTPException
     */
    public function getStatusDocument(): ResponseInterface
    {
        try {
            $response = $this->httpClient->request(
                'GET',
                $this->object->statusDocument->getObjectId(),
                [
                    'headers' => [
                        'Authorization' => 'APIKey ' . $this->service->apiKey,
                    ],
                ]
            );
        } catch (ClientException $exception) {
            $exceptionClass = $this->getHTTPException($exception);
            throw new $exceptionClass($exception, $exception->getResponse(), $this->service, $this->object);
        }
        return $response;
    }

    /**
     * Create a new object with metadata.
     *
     * @throws AuthenticationRequired
     * @throws AuthenticationFailed
     * @throws BadRequest
     * @throws PageNotFound
     * @throws HTTPException
     *
     * @see https://swordapp.github.io/swordv3/swordv3.html#9.6
     */
    public function createObject(): ResponseInterface
    {
        try {
            $response = $this->httpClient->request(
                'POST',
                $this->service->url,
                [
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json',
                        'Authorization' => $this->getAuthorizationHeader(),
                        'Metadata-Format' => self::DEFAULT_METADATA_FORMAT,
                    ],
                    'body' => $this->object->metadata->toJson(),
                ]
            );
        } catch (ClientException $exception) {
            $exceptionClass = $this->getHTTPException($exception);
            throw new $exceptionClass($exception, $exception->getResponse(), $this->service, $this->object);
        }

        return $response;
    }

    /**
     * Deposit a file to an existing object
     *
     * @throws AuthenticationRequired
     * @throws AuthenticationFailed
     * @throws BadRequest
     * @throws PageNotFound
     * @throws HTTPException
     */
    public function createObjectFile(string $filepath): ResponseInterface
    {
        try {
            $response = $this->httpClient->request(
                'POST',
                $this->object->statusDocument->getObjectId(),
                [
                    'headers' => [
                        'Authorization' => $this->getAuthorizationHeader(),
                        'Content-Type' => 'application/pdf',
                        'Content-Disposition' => 'attachment; filename=document.pdf',
                    ],
                    'body' => fopen($filepath, 'rb'),
                ]
            );
        } catch (ClientException $exception) {
            $exceptionClass = $this->getHTTPException($exception);
            throw new $exceptionClass($exception, $exception->getResponse(), $this->service, $this->object);
        }

        return $response;
    }

    protected function getHTTPException(ClientException $exception): string
    {
        switch ($exception->getResponse()->getStatusCode()) {
            case 400: return BadRequest::class;
            case 401: return AuthenticationRequired::class;
            case 403: return AuthenticationFailed::class;
            case 404: return PageNotFound::class;
            default: return HTTPException::class;
        }
    }

    protected function getAuthorizationHeader(): string
    {
        return 'APIKey ' . $this->service->apiKey;
    }
}
