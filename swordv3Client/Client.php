<?php

namespace APP\plugins\generic\swordv3\swordv3Client;

use APP\plugins\generic\swordv3\swordv3Client\exceptions\AuthenticationFailed;
use APP\plugins\generic\swordv3\swordv3Client\exceptions\AuthenticationRequired;
use APP\plugins\generic\swordv3\swordv3Client\exceptions\BadRequest;
use APP\plugins\generic\swordv3\swordv3Client\exceptions\HTTPException;
use APP\plugins\generic\swordv3\swordv3Client\exceptions\PageNotFound;
use GuzzleHttp\Client as GuzzleHttpClient;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\ResponseInterface;

class Client
{
    public const METHOD_GET = 'GET';
    public const METHOD_POST = 'POST';
    public const METHOD_PUT = 'PUT';

    public function __construct(
        public GuzzleHttpClient $httpClient,
        public Service $service,
    ) {
        //
    }

    /**
     * Get the ServiceDocument for this SWORDv3 service
     *
     * Attaches the ServiceDocument to the Service and returns
     * the ServiceDocument.
     */
    public function getServiceDocument(): ServiceDocument
    {
        $response = $this->send(new Request(
            self::METHOD_GET,
            $this->service->url,
        ));
        $this->service->serviceDocument = new ServiceDocument($response->getBody());
        return $this->service->serviceDocument;
    }

    /**
     * Get a StatusDocument for a particular object in a Swordv3 server
     *
     * @return ResponseInterface Response body should contain Swordv3 StatusDocument
     */
    public function getStatusDocument(string $objectUrl): ResponseInterface
    {
        return $this->send(new Request(
            self::METHOD_GET,
            $objectUrl,
        ));
    }

    /**
     * Create a new object on the Swordv3 server with Metadata
     *
     * @return ResponseInterface Response body should contain Swordv3 StatusDocument
     */
    public function createObjectWithMetadata(MetadataDocument $metadata): ResponseInterface
    {
        return $this->send(new Request(
            self::METHOD_POST,
            $this->service->url,
            $metadata->getHeaders(),
            $metadata->toBody(),
        ));
    }

    /**
     * Replace an existing object on the Swordv3 server with Metadata
     *
     * @return ResponseInterface Response body should contain Swordv3 StatusDocument
     */
    public function replaceObjectWithMetadata(string $objectUrl, MetadataDocument $metadata): ResponseInterface
    {
        return $this->send(new Request(
            self::METHOD_PUT,
            $objectUrl,
            $metadata->getHeaders(),
            $metadata->toBody(),
        ));
    }

    /**
     * Append metadata to an existing object on the Swordv3 service
     *
     * @return ResponseInterface Response body should contain Swordv3 StatusDocument
     */
    public function appendMetadata(string $objectUrl, MetadataDocument $metadata): ResponseInterface
    {
        return $this->send(new Request(
            self::METHOD_POST,
            $objectUrl,
            $metadata->getHeaders(),
            $metadata->toBody(),
        ));

        return $response;
    }

    /**
     * Append a file to an existing object on the Swordv3 server
     *
     * @return ResponseInterface Response body should contain Swordv3 StatusDocument
     */
    public function appendObjectFile(string $objectUrl, string $filepath): ResponseInterface
    {
        return $this->send(new Request(
            self::METHOD_POST,
            $objectUrl,
            $this->getPdfHeaders(),
            fopen($filepath, 'rb'),
        ));
    }

    /**
     * Send a HTTP request
     *
     * Reformats some HTTP exceptions emitted from Guzzle.
     *
     * @throws AuthenticationRequired
     * @throws AuthenticationFailed
     * @throws BadRequest
     * @throws PageNotFound
     * @throws HTTPException
     */
    public function send(Request $request): ResponseInterface
    {
        try {
            $response = $this->httpClient->send(
                $request->withAddedHeader('Authorization', $this->service->authMode->getAuthorizationHeader())
            );
        } catch (ClientException $exception) {
            $exceptionClass = $this->getHTTPException($exception);
            throw new $exceptionClass($exception, $exception->getResponse(), $this->service);
        }
        return $response;
    }

    protected function getPdfHeaders(): array
    {
        return [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename=document.pdf',
        ];
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
}
