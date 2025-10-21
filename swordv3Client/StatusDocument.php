<?php
/**
 * Status Document
 *
 * @see https://swordapp.github.io/swordv3/swordv3.html#9.6
 */
namespace APP\plugins\generic\swordv3\swordv3Client;

class StatusDocument
{
    public const STATE_ACCEPTED = 'http://purl.org/net/sword/3.0/state/accepted';
    public const STATE_IN_PROGRESS = 'http://purl.org/net/sword/3.0/state/inProgress';
    public const STATE_IN_WORKFLOW = 'http://purl.org/net/sword/3.0/state/inWorkflow';
    public const STATE_INGESTED = 'http://purl.org/net/sword/3.0/state/ingested';
    public const STATE_REJECTED = 'http://purl.org/net/sword/3.0/state/rejected';
    public const STATE_DELETED = 'http://purl.org/net/sword/3.0/state/deleted';

    /** @var string[] $states */
    public array $states = [
        self::STATE_ACCEPTED,
        self::STATE_IN_PROGRESS,
        self::STATE_IN_WORKFLOW,
        self::STATE_INGESTED,
        self::STATE_REJECTED,
        self::STATE_DELETED,
    ];

    protected object $statusDocument;

    public function __construct(
        string $statusDocument,
    ) {
        $this->statusDocument = json_decode($statusDocument);
    }

    /**
     * Get the underlying Object representation of the
     * full status document
     */
    public function getStatusDocument(): object
    {
        return $this->statusDocument;
    }

    /**
     * Get the deposit object's @id
     *
     * This should correspond to the Object-URL
     */
    public function getObjectId(): string
    {
        if (isset($this->statusDocument->{'@id'})) {
            return $this->statusDocument->{'@id'};
        }
        return '';
    }

    /**
     * Get the SWORDv3 state
     *
     * Servers may define custom states, but this method returns
     * the first state that matches the SWORD state vocabulary.
     *
     * @see https://swordapp.github.io/swordv3/swordv3.html#9.6.2
     *
     * @return string One of the self::STATE_* constants
     */
    public function getSwordStateId(): string
    {
        foreach ($this->statusDocument?->state ?? [] as $state) {
            if (in_array($state?->{'@id'} ?? '', $this->states)) {
                return $state->{'@id'};
            }
        }
        return '';
    }

    public function getFileSetUrl(): string
    {
        if (
            isset($this->statusDocument->actions)
                && $this->statusDocument->actions->appendFiles
                && isset($this->statusDocument->fileSet)
                && isset($this->statusDocument->fileSet->{'@id'})
        ) {
            return $this->statusDocument->fileSet->{'@id'};
        }
        return '';
    }

    public function getLinks(): array
    {
        return $this->statusDocument?->links ?? [];
    }
}