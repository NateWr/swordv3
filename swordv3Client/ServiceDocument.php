<?php
/**
 * Service Document
 *
 * @see https://swordapp.github.io/swordv3/swordv3.html#9.2
 */
namespace APP\plugins\generic\swordv3\swordv3Client;

class ServiceDocument
{
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

    public function getAuthModes(): array
    {
        return $this->serviceDocument?->authentication;
    }
}