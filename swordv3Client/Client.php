<?php

namespace APP\plugins\generic\swordv3\swordv3Client;

use GuzzleHttp\Client as GuzzleHttpClient;
use GuzzleHttp\Exception\ClientException;

class Client
{
    public function __construct(
        public GuzzleHttpClient $httpClient,
        public string $serviceUrl,
        public string $apiKey,
        public DepositObject $object,
    ) {

    }

    public function send(): bool
    {
        error_log($this->object->metadata->toJson());
        try {
            $response = $this->httpClient->request(
                'POST',
                $this->serviceUrl,
                [
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json',
                        'Authorization' => 'APIKey ' . $this->apiKey,
                    ],
                    'body' => $this->object->metadata->toJson(),
                ]
            );
            $data = json_decode($response->getBody(), true);
            error_log(print_r($data, true));
        } catch (ClientException $exception) {
            error_log($exception->getMessage());
            return false;
        }

        return true;
    }
}
