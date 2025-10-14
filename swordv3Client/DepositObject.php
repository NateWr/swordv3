<?php

namespace APP\plugins\generic\swordv3\swordv3Client;

class DepositObject
{
    public function __construct(
        public MetadataDocument $metadata,
        /** @var File[] */
        public array $fileset
    ) {
        //
    }
}
