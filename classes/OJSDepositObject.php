<?php

namespace APP\plugins\generic\swordv3\classes;

use APP\core\Application;
use APP\facades\Repo;
use APP\journal\Journal;
use APP\plugins\generic\swordv3\swordv3Client\DepositObject;
use APP\plugins\generic\swordv3\swordv3Client\MetadataDocument;
use APP\plugins\generic\swordv3\swordv3Client\StatusDocument;
use APP\publication\Publication;
use APP\submission\Submission;
use Illuminate\Support\LazyCollection;
use PKP\config\Config;
use PKP\context\Context;
use PKP\controlledVocab\ControlledVocab;
use PKP\core\PKPString;
use PKP\galley\Galley;

class OJSDepositObject extends DepositObject
{
    public function __construct(
        public Publication $publication,
        /** LazyCollection<int,Galley> */
        LazyCollection $galleys,
        public Submission $submission,
        public Journal $context,
    ) {
        $statusDocument = null;
        if ($publication->getData('swordv3')) {
            $statusDocument = new StatusDocument($publication->getData('swordv3'));
        }
        parent::__construct(
            metadata: $this->createDCMetadataDocument($publication, $submission, $context),
            fileset:  $this->getFileset($galleys)->all(),
            statusDocument: $statusDocument,
        );
    }

    /**
     * Get a file path for each Galley in the supported format
     */
    protected function getFileset(LazyCollection $galleys): LazyCollection
    {
        return $galleys->map(function(Galley $galley) {
            $submissionFile = Repo::submissionFile()->get($galley->getData('submissionFileId'));
            if (!$submissionFile) {
                return;
            }
            $file = app()->get('file')->get($submissionFile->getData('fileId'));
            if (!$file || !in_array($file->mimetype, $this->getSupportedFileFormats())) {
                return;
            }
            return Config::getVar('files', 'files_dir') . '/' . $file->path;
        })
        ->whereNotNull();
    }

    /**
     * What file formats are supported
     */
    protected function getSupportedFileFormats(): array
    {
        return [
            'application/pdf',
        ];
    }

    protected function createDCMetadataDocument(
        Publication $publication,
        Submission $submission,
        Journal $context
    ): MetadataDocument {
        $request = Application::get()->getRequest();

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

        return $metadata;
    }

    protected function getDCSubject(Publication $publication): string
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

    protected function getDCPublisher(Context $context): string
    {
        if (!empty($context->getLocalizedData('publisherInstitution', $context->getDefaultLocale()))) {
            return $context->getLocalizedData('publisherInstitution', $context->getDefaultLocale());
        }
        return $context->getLocalizedName($context->getDefaultLocale());
    }

    protected function getDCSponsor(Publication $publication): string
    {
        $sponsors = $publication->getLocalizedData('supportingAgencies', $publication->getData('locale'));
        if ($sponsors) {
            return join(__('common.commaListSeparator'), $sponsors);
        }
        return '';
    }

    protected function getDCRights(Publication $publication): string
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