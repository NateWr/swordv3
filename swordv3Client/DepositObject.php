<?php

namespace APP\plugins\generic\swordv3\swordv3Client;

class DepositObject
{
    public function __construct(
        public MetadataDocument $metadata,
        /** @var File[] */
        public array $fileset,
        public ?StatusDocument $statusDocument = null
    ) {
        //
    }

    public function setStatusDocument(StatusDocument $statusDocument): void
    {
        $this->statusDocument = $statusDocument;
    }
}
