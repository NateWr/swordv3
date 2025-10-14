<?php

namespace APP\plugins\generic\swordv3\classes;

use APP\plugins\generic\swordv3\classes\swordv3\Swordv3;
use Exception;
use PKP\jobs\BaseJob;

class Deposit extends BaseJob
{
	protected int $publicationId;

	public function __construct(int $publicationId)
	{
		parent::__construct();

		$this->publicationId = $publicationId;
	}

	public function handle(): void
	{
		error_log('run job for publication: ' . $this->publicationId);

		$deposit = new Swordv3();

		if (!$deposit->send()) {
			throw new Exception('failed to deposit');
		}
	}
}
