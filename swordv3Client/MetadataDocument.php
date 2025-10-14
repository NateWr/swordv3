<?php
/**
 * Metadata Document
 *
 * @see https://swordapp.github.io/swordv3/swordv3.html#9.3
 */
namespace APP\plugins\generic\swordv3\swordv3Client;

class MetadataDocument
{
    public function __construct(
        public string $_id,
        public string $_type = 'Metadata',
        public string $_context = 'https://swordapp.github.io/swordv3/swordv3.jsonld',
        /** URL of this metadata document in the SWORDv3 service, if previously deposited. */
        public ?string $_depositUrl = null,
        protected array $metadata = []
    ) {
        //
    }

    public function get(): array
    {
        return $this->metadata;
    }

    public function set(string $name, string $value)
    {
        $this->metadata[$name] = $value;
    }

    public function delete(string $name)
    {
        if (isset($this->metadata[$name])) {
            unset($this->metadata[$name]);
        }
    }

    public function toJson(): string
    {

        $doc = array_merge(
            [
                '@id' =>  $this->_id,
                '@type' =>  $this->_type,
                '@context' =>  $this->_context,
            ],
            $this->metadata
        );

        return json_encode($doc, JSON_PRETTY_PRINT);
    }
}