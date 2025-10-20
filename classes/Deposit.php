<?php

namespace APP\plugins\generic\swordv3\classes;

use APP\core\Application;
use APP\facades\Repo;
use APP\journal\Journal;
use APP\journal\JournalDAO;
use APP\plugins\generic\swordv3\swordv3Client\Client;
use APP\plugins\generic\swordv3\swordv3Client\exceptions\AuthenticationFailed;
use APP\plugins\generic\swordv3\swordv3Client\exceptions\AuthenticationRequired;
use APP\plugins\generic\swordv3\swordv3Client\exceptions\AuthenticationUnsupported;
use APP\plugins\generic\swordv3\swordv3Client\exceptions\BadRequest;
use APP\plugins\generic\swordv3\swordv3Client\exceptions\FilesNotSupported;
use APP\plugins\generic\swordv3\swordv3Client\exceptions\HTTPException;
use APP\plugins\generic\swordv3\swordv3Client\Service;
use APP\plugins\generic\swordv3\swordv3Client\StatusDocument;
use APP\publication\Publication;
use Exception;
use PKP\db\DAORegistry;
use PKP\jobs\BaseJob;
use Throwable;

class Deposit extends BaseJob
{
    public function __construct(
        protected int $publicationId,
        protected int $contextId,
    ) {
        parent::__construct();
    }

    public function handle(): void
    {
        $depositObject = $this->getDepositObject();
        $service = $this->getService();
        $client = new Client(
            httpClient: Application::get()->getHttpClient(),
            service: $service,
        );

        try {
            $client->getServiceDocument();
            if (!$service->supportsAuth()) {
                throw new AuthenticationUnsupported($service);
            }

            $response = $depositObject->statusDocument
                ? $client->replaceObjectWithMetadata($depositObject->statusDocument->getObjectId(), $depositObject->metadata)
                : $client->createObjectWithMetadata($depositObject->metadata);

            $statusDocument = new StatusDocument($response->getBody());
            $this->savePublicationStatusDocument($depositObject->publication, $statusDocument);

            if (count($depositObject->fileset)) {
                if (!$statusDocument->getFileSetUrl()) {
                    throw new FilesNotSupported($statusDocument, $service);
                }
                foreach ($depositObject->fileset as $file) {
                    $response = $client->appendObjectFile($statusDocument->getObjectId(), $file);
                }
            }

            $statusDocument = new StatusDocument($client->getStatusDocument($statusDocument->getObjectId())->getBody());
            $this->savePublicationStatusDocument($depositObject->publication, $statusDocument);

        } catch (AuthenticationUnsupported|AuthenticationRequired|AuthenticationFailed $exception) {
            // TODO: send email to admin
            // TODO: disable all sending to this service for now.
            error_log($exception->getFile() . '::' . $exception->getLine() . ' ' . $exception->getMessage());
            return;
        } catch (HTTPException $exception) {
            // TODO: send email to admin
            error_log($exception->getFile() . '::' . $exception->getLine() . ' ' . $exception->getMessage());
            return;
        } catch (FilesNotSupported $exception) {
            // TODO: send email to admin
            error_log($exception->getFile() . '::' . $exception->getLine() . ' ' . $exception->getMessage());
            return;
        } catch (Throwable $exception) {
            // TODO: log unexpected error
            error_log($exception->getFile() . '::' . $exception->getLine() . ' ' . $exception->getMessage());
            return;
        }
    }

    protected function getDepositObject(): OJSDepositObject
    {
        $publication = Repo::publication()->get($this->publicationId);
        $galleys = Repo::galley()->getCollector()
                ->filterByPublicationIds([$publication->getId()])
                ->getMany();
        $submission = Repo::submission()->get($publication->getData('submissionId'));
        /** @var JournalDAO $contextDao */
        $contextDao = DAORegistry::getDAO('JournalDAO');
        /** @var Journal $context */
        $context = $contextDao->getById($this->contextId);

        return new OJSDepositObject(
            $publication,
            $galleys,
            $submission,
            $context
        );
    }

    protected function getService(): Service
    {
        return new Service(
            name: 'local test',
            url: 'http://host.docker.internal:3000/service-url',
            apiKey: 'Te8#eFYLmIvOIy9&^K!0PvT@JeIw@C&G',
            authMode: Service::AUTH_API_KEY,
        );
    }

    protected function savePublicationStatusDocument(Publication $publication, StatusDocument $statusDocument): void
    {
        $newPublication = Repo::publication()->newDataObject(
            array_merge(
                $publication->_data, [
                    'swordv3' => json_encode($statusDocument->getStatusDocument()),
                ]
            )
        );
        Repo::publication()->dao->update($newPublication, $publication);
        $newPublication = Repo::publication()->get($newPublication->getId());

    }
}
