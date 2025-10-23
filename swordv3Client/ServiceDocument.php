<?php
/**
 * Service Document
 *
 * @see https://swordapp.github.io/swordv3/swordv3.html#9.2
 */
namespace APP\plugins\generic\swordv3\swordv3Client;

class ServiceDocument
{
    /**
     * Maps the IANA digest algorithm names to the PHP algorithm name
     *
     * Example:
     *
     * [SHA-256 => 'sha256']
     *
     * @see https://www.iana.org/assignments/http-dig-alg/http-dig-alg.xhtml
     */
    public const DIGEST_ALGORITHMS_MAP = [
        'SHA-512' => 'sha512',
        'SHA-256' => 'sha256',
        'SHA' => 'sha1',
        'MD5' => 'md5',
        'ADLER32' => 'adler32',
        'CRC32C' => 'crc32c',
    ];

    protected object $serviceDocument;

    public function __construct(
        string $serviceDocument,
    ) {
        $this->serviceDocument = json_decode($serviceDocument);
    }

    /**
     * Get the underlying Object representation of the
     * Service Document
     */
    public function getServiceDocument(): object
    {
        return $this->serviceDocument;
    }

    public function getServiceId(): string
    {
        return $this->serviceDocument?->{'@id'};
    }

    public function getAuthModes(): array
    {
        return $this->serviceDocument?->authentication;
    }

    public function supportsAuthMode(string $authMode): bool
    {
        return in_array($authMode, $this->getAuthModes());
    }

    public function acceptDeposits(): bool
    {
        return (bool) $this->serviceDocument?->acceptDeposits;
    }

    public function getDigestFormats(): array
    {
        return $this->serviceDocument?->digest ?? [];
    }

    /**
     * Check if a digest format is supported by this service documemnt
     *
     * @param string $algorithm Name of the algorithm as defined by PHP, for example `sha256`
     */
    public function supportsDigestFormat(string $algorithm): bool
    {
        $digestFormat = $this->getDigestFormatByAlgorithm($algorithm);
        if (!$digestFormat) {
            return false;
        }
        return in_array($digestFormat, $this->getDigestFormats());
    }

    /**
     * Get the IANA digest format name from the PHP algorithm name
     */
    public function getDigestFormatByAlgorithm(string $algorithm): ?string
    {
        $map = array_flip(self::DIGEST_ALGORITHMS_MAP);
        return isset($map[$algorithm]) ? $map[$algorithm] : null;
    }
}