<?php
/**
 * Metadata Document
 *
 * This document represents the default DublinCore metadata format.
 * Extend the getHeaders() and getRequestBody() methods in a child class
 * to implement other metadata types.
 *
 * @see https://swordapp.github.io/swordv3/swordv3.html#9.3
 */
namespace APP\plugins\generic\swordv3\swordv3Client;

use APP\plugins\generic\swordv3\swordv3Client\exceptions\DigestFormatNotFound;

class MetadataDocument
{
    public const DEFAULT_METADATA_FORMAT = 'http://purl.org/net/sword/3.0/types/Metadata';

    public function __construct(
        public string $_id,
        public string $_type = 'Metadata',
        public string $_context = 'https://swordapp.github.io/swordv3/swordv3.jsonld',
        /** URL of this metadata document in the SWORDv3 service, if previously deposited. */
        public ?string $_depositUrl = null,
        public ?string $_metadataFormat = self::DEFAULT_METADATA_FORMAT,
        protected array $metadata = []
    ) {
        //
    }

    public function get(): array
    {
        return $this->metadata;
    }

    public function set(string $name, string $value)
    {
        $this->metadata[$name] = $value;
    }

    public function delete(string $name)
    {
        if (isset($this->metadata[$name])) {
            unset($this->metadata[$name]);
        }
    }

    /**
     * Convert metadata to a string to be included in the body
     * of a HTTP request
     *
     * Converts to JSON following Dublin Core, SWORDv3's default
     * metadata format.
     */
    public function getRequestBody(): string
    {

        $doc = array_merge(
            [
                '@id' =>  $this->_id,
                '@type' =>  $this->_type,
                '@context' =>  $this->_context,
            ],
            $this->metadata
        );

        return json_encode($doc);
    }

    /**
     * Get the appropriate HTTP headers for this metadata type
     */
    public function getHeaders(ServiceDocument $serviceDocument): array
    {
        return [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'Metadata-Format' => $this->_metadataFormat,
            'Digest' => $this->getDigest($serviceDocument),
        ];
    }

    /**
     * Create a hash of the metadata document for the Digest header
     *
     * Identifies a hash format compatible with the service, generates
     * the hash, and prepends the hash format.
     *
     * @see  https://swordapp.github.io/swordv3/swordv3.html#14
     */
    protected function getDigest(ServiceDocument $serviceDocument): string
    {
        $algos = hash_algos();

        $digests = [];
        foreach ($algos as $algo) {
            $digestFormat = $serviceDocument->getDigestFormatByAlgorithm($algo);
            if ($digestFormat && in_array($digestFormat, $serviceDocument->getDigestFormats())) {
                $digests[] = join('', [
                    $digestFormat,
                    '=',
                    hash($algo, $this->getRequestBody())
                ]);
            }
        }

        if (!count($digests)) {
            throw new DigestFormatNotFound($serviceDocument);
        }

        return join(', ', $digests);
    }
}