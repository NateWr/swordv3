<?php

namespace APP\plugins\generic\swordv3\swordv3Client\exceptions;

use APP\plugins\generic\swordv3\swordv3Client\ServiceDocument;
use Exception;

class DigestFormatNotFound extends Exception
{
    public function __construct(
        public ServiceDocument $serviceDocument,
    ) {
        $serviceFormats = join(', ', $serviceDocument->getDigestFormats());
        $clientFormats = [];
        foreach (hash_algos() as $hashAlgo) {
            $format = $serviceDocument->getDigestFormatByAlgorithm($hashAlgo);
            if ($format) {
                $clientFormats[] = $format;
            }
        }
        $clientFormatsList = join(', ', $clientFormats);

        parent::__construct(
            "No compatible DIGEST format found for {$serviceDocument->getServiceId()}. Service supports {$serviceFormats}, but this server only supports {$clientFormatsList}."
        );
    }
}