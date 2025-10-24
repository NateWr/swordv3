<?php

namespace APP\plugins\generic\swordv3\classes;

use APP\plugins\generic\swordv3\swordv3Client\auth\Basic;
use APP\plugins\generic\swordv3\swordv3Client\AuthMode;
use APP\plugins\generic\swordv3\swordv3Client\Service;

/**
 * Class to map an OJS Publication to a Swordv3Client/DepositObject
 *
 * This creates the MetadataDocument and gets a file path for all
 * supported galley files.
 */
class OJSService extends Service
{
    public function __construct(
        public string $name,
        public string $url,
        public AuthMode $authMode,
        public bool $enabled = true,
        public string $statusMessage = '',
    ) {
        parent::__construct(
          $url,
          $authMode
        );
    }
}
