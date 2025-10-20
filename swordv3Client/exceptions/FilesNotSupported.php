<?php

namespace APP\plugins\generic\swordv3\swordv3Client\exceptions;
use APP\plugins\generic\swordv3\swordv3Client\DepositObject;
use APP\plugins\generic\swordv3\swordv3Client\Service;
use APP\plugins\generic\swordv3\swordv3Client\StatusDocument;
use Exception;

class FilesNotSupported extends Exception
{
    public function __construct(
        public StatusDocument $statusDocument,
        public Service $service,
    ) {
        parent::__construct(
            "Unable to deposit fileset for {$statusDocument->getObjectId()} in service {$service->name}.\n\n"
            . "SWORDv3 service {$service->name} has returned a StatusDocument for this object that does not support file actions or is missing fileSet.@id."
        );
    }
}