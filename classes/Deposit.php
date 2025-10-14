<?php

namespace APP\plugins\generic\swordv3\classes;

use APP\core\Application;
use APP\facades\Repo;
use APP\journal\Journal;
use APP\journal\JournalDAO;
use APP\plugins\generic\swordv3\classes\swordv3\Swordv3;
use APP\plugins\generic\swordv3\swordv3Client\Client;
use APP\plugins\generic\swordv3\swordv3Client\DepositObject;
use APP\plugins\generic\swordv3\swordv3Client\MetadataDocument;
use APP\publication\Publication;
use APP\submission\Submission;
use Exception;
use Illuminate\Support\LazyCollection;
use PKP\context\Context;
use PKP\controlledVocab\ControlledVocab;
use PKP\core\PKPString;
use PKP\db\DAORegistry;
use PKP\galley\Galley;
use PKP\jobs\BaseJob;

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
        error_log('run job for publication: ' . $this->publicationId);

        $publication = Repo::publication()->get($this->publicationId);
        $galleys = Repo::galley()->getCollector()
                ->filterByPublicationIds([$publication->getData('currentPublicationId')])
                ->getMany();
        $submission = Repo::submission()->get($publication->getData('submissionId'));

        $depositObject = $this->createDepositObject($publication, $galleys, $submission);

        $deposit = new Client(
            httpClient: Application::get()->getHttpClient(),
            serviceUrl: 'http://host.docker.internal:3000/service-url',
            apiKey: 'Te8#eFYLmIvOIy9&^K!0PvT@JeIw@C&G',
            object: $depositObject,
        );

        if (!$deposit->send()) {
            throw new Exception('failed to deposit');
        }
    }

    public function createDepositObject(
        Publication $publication,
        /** LazyCollection<int,Galley> */
        LazyCollection $galleys,
        Submission $submission
    ) {
        $request = Application::get()->getRequest();
        /** @var JournalDAO $contextDao */
        $contextDao = DAORegistry::getDAO('JournalDAO');
        /** @var Journal $context */
        $context = $contextDao->getById($this->contextId);


        $url = $request->url(
            $context->getPath(),
            'article',
            'view',
            [$publication->getData('submissionId')]
        );

        $metadata = new MetadataDocument(_id: $url);
        $metadata->set('dc:title', $publication->getLocalizedFullTitle($publication->getData('locale')));
        $metadata->set('dc:creator', $publication->getShortAuthorString($publication->getData('locale')));
        $metadata->set('dc:description', PKPString::html2text($publication->getData('abstract', $publication->getData('locale')) ?? ''));
        $metadata->set('dc:date', date('Y-m-d', strtotime($publication->getData('datePublished'))));
        $metadata->set('dcterms:dateSubmitted', date('Y-m-d H:i:s', strtotime($submission->getData('dateSubmitted'))));
        $metadata->set('dcterms:modified', date('Y-m-d H:i:s', strtotime($publication->getData('lastModified'))));
        $metadata->set('dc:identifier', $publication->getDoi() ?? '');
        $metadata->set('dcterms:publisher', $this->getDCPublisher($context));
        $metadata->set('dc:subject', $this->getDCSubject($publication));
        $metadata->set('dc:contributor.sponsor', $this->getDCSponsor($publication));
        $metadata->set('dc:coverage', $publication->getLocalizedData('coverage', $publication->getData('locale')) ?? '');
        $metadata->set('dc:type', $publication->getLocalizedData('type', $publication->getData('locale')) ?? '');
        $metadata->set('dc:source', $publication->getLocalizedData('source', $publication->getData('locale')) ?? '');
        $metadata->set('dc:language', $publication->getData('locale'));
        $metadata->set('dc:rights', $this->getDCRights($publication));
        $metadata->set('dcterms:license', $publication->getData('licenseUrl') ?? '');

        foreach ($metadata->get() as $key => $value) {
            $trimmed = trim($value);
            if (!$trimmed) {
                $metadata->delete($key);
            } else {
                $metadata->set($key, $trimmed);
            }
        }

        $files = [];

        return new DepositObject($metadata, $files);
    }

    public function getDCSubject(Publication $publication): string
    {
        $subjects = array_merge_recursive(
            Repo::controlledVocab()->getBySymbolic(
                ControlledVocab::CONTROLLED_VOCAB_SUBMISSION_KEYWORD,
                Application::ASSOC_TYPE_PUBLICATION,
                $publication->getId()
            ),
            Repo::controlledVocab()->getBySymbolic(
                ControlledVocab::CONTROLLED_VOCAB_SUBMISSION_SUBJECT,
                Application::ASSOC_TYPE_PUBLICATION,
                $publication->getId()
            )
        );
        if ($subjects[$publication->getData('locale')]) {
            return join(__('common.commaListSeparator'), $subjects[$publication->getData('locale')]);
        }
        return '';
    }

    public function getDCPublisher(Context $context): string
    {
        if (!empty($context->getLocalizedData('publisherInstitution', $context->getDefaultLocale()))) {
            return $context->getLocalizedData('publisherInstitution', $context->getDefaultLocale());
        }
        return $context->getLocalizedName($context->getDefaultLocale());
    }

    public function getDCSponsor(Publication $publication): string
    {
        $sponsors = $publication->getLocalizedData('supportingAgencies', $publication->getData('locale'));
        if ($sponsors) {
            return join(__('common.commaListSeparator'), $sponsors);
        }
        return '';
    }

    public function getDCRights(Publication $publication): string
    {
        $copyrightHolder = $publication->getLocalizedData('copyrightHolder', $publication->getData('locale'));
        $copyrightYear = $publication->getData('copyrightYear');
        if ($copyrightHolder && $copyrightYear) {
            return __('submission.copyrightStatement', [
                'copyrightHolder' => $copyrightHolder,
                'copyrightYear' => $copyrightYear,
            ]);
        }
        return $copyrightHolder;
    }
}
