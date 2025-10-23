<?php

namespace APP\plugins\generic\swordv3\swordv3Client;

use APP\plugins\generic\swordv3\swordv3Client\exceptions\AuthenticationFailed;
use APP\plugins\generic\swordv3\swordv3Client\exceptions\AuthenticationRequired;
use APP\plugins\generic\swordv3\swordv3Client\exceptions\BadRequest;
use APP\plugins\generic\swordv3\swordv3Client\exceptions\DigestFormatNotFound;
use APP\plugins\generic\swordv3\swordv3Client\exceptions\PageNotFound;
use APP\plugins\generic\swordv3\swordv3Client\exceptions\Swordv3ConnectException;
use APP\plugins\generic\swordv3\swordv3Client\exceptions\Swordv3RequestException;
use GuzzleHttp\Client as GuzzleHttpClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
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
        return new ServiceDocument($response->getBody());
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
    public function createObjectWithMetadata(MetadataDocument $metadata, ServiceDocument $serviceDocument): ResponseInterface
    {
        return $this->send(new Request(
            self::METHOD_POST,
            $this->service->url,
            $metadata->getHeaders($serviceDocument),
            $metadata->getRequestBody($serviceDocument),
        ));
    }

    /**
     * Replace an existing object on the Swordv3 server with Metadata
     *
     * @param string $digestFormat The hash algorithm to use to create the file digest
     * @return ResponseInterface Response body should contain Swordv3 StatusDocument
     */
    public function replaceObjectWithMetadata(string $objectUrl, MetadataDocument $metadata, ServiceDocument $serviceDocument): ResponseInterface
    {
        return $this->send(new Request(
            self::METHOD_PUT,
            $objectUrl,
            $metadata->getHeaders($serviceDocument),
            $metadata->getRequestBody($serviceDocument),
        ));
    }

    /**
     * Append metadata to an existing object on the Swordv3 service
     *
     * @return ResponseInterface Response body should contain Swordv3 StatusDocument
     */
    public function appendMetadata(string $objectUrl, MetadataDocument $metadata, ServiceDocument $serviceDocument): ResponseInterface
    {
        return $this->send(new Request(
            self::METHOD_POST,
            $objectUrl,
            $metadata->getHeaders($serviceDocument),
            $metadata->getRequestBody($serviceDocument),
        ));

        return $response;
    }

    /**
     * Append a file to an existing object on the Swordv3 server
     *
     * @return ResponseInterface Response body should contain Swordv3 StatusDocument
     */
    public function appendObjectFile(string $objectUrl, string $filepath, ServiceDocument $serviceDocument): ResponseInterface
    {
        return $this->send(new Request(
            self::METHOD_POST,
            $objectUrl,
            $this->getPdfHeaders($filepath, $serviceDocument),
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
     * @throws RequestException
     */
    public function send(Request $request): ResponseInterface
    {
        try {
            $response = $this->httpClient->send(
                $request->withAddedHeader('Authorization', $this->service->authMode->getAuthorizationHeader())
            );
        } catch (RequestException $exception) {
            $exceptionClass = $this->getRequestException($exception);
            throw new $exceptionClass($exception, $this);
        } catch (ConnectException $exception) {
            throw new Swordv3ConnectException($exception, $this);
        }
        return $response;
    }

    protected function getPdfHeaders(string $filepath, ServiceDocument $serviceDocument): array
    {
        return [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename=document.pdf',
            'Digest' => $this->getBinaryDigest($filepath, $serviceDocument),
        ];
    }

    protected function getRequestException(RequestException $exception): string
    {
        switch ($exception->getResponse()->getStatusCode()) {
            case 400: return BadRequest::class;
            case 401: return AuthenticationRequired::class;
            case 403: return AuthenticationFailed::class;
            case 404: return PageNotFound::class;
            default: return Swordv3RequestException::class;
        }
    }

    /**
     * Create a hash of a binary file for the Digest header
     *
     * Identifies a hash format compatible with the service, generates
     * the hash, and prepends the hash format.
     *
     * @see  https://swordapp.github.io/swordv3/swordv3.html#14
     */
    protected function getBinaryDigest(string $filepath, ServiceDocument $serviceDocument): string
    {
        $algos = hash_algos();

        $digests = [];
        foreach ($algos as $algo) {
            $digestFormat = $serviceDocument->getDigestFormatByAlgorithm($algo);
            if ($digestFormat && in_array($digestFormat, $serviceDocument->getDigestFormats())) {
                $digests[] = join('', [
                    $digestFormat,
                    '=',
                    hash_file($algo, $filepath)
                ]);
            }
        }

        if (!count($digests)) {
            throw new DigestFormatNotFound($serviceDocument);
        }

        return join(', ', $digests);
    }
}
