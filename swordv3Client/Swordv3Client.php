<?php

namespace APP\plugins\generic\swordv3\swordv3Client;

use APP\core\Application;
use GuzzleHttp\Exception\ClientException;

class Swordv3Client
{
    public function send(): bool
    {
        $httpClient = Application::get()->getHttpClient();
        $apiKey = 'Te8#eFYLmIvOIy9&^K!0PvT@JeIw@C&G';

        try {
            $response = $httpClient->request(
                'POST',
                'http://host.docker.internal:3000/service-url',
                [
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json',
                        'Authorization' => 'APIKey ' . $apiKey,
                    ],
                    'json' => [
                        '@context' => 'https://swordapp.github.io/swordv3/swordv3.jsonld',
                        '@id' => 'http://example.com/metadata/1',
                        '@type' => 'Metadata',
                        'dc:title' => 'My Document',
                        'dc:creator' => 'John Doe',
                    ],
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
