<?php
/**
 * Status Document
 *
 * @see https://swordapp.github.io/swordv3/swordv3.html#9.6
 */
namespace APP\plugins\generic\swordv3\swordv3Client;

class StatusDocument
{
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
}