<?php
namespace APP\plugins\generic\swordv3\classes;

use APP\facades\Repo;
use APP\submission\Submission;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;

/**
 * Helper class to get publications based on their
 * deposit status
 */
class Collector
{
    /**
     * A job has been queued to deposit this publication,
     * but it has not yet started.
     */
    public const STATUS_QUEUED = 'queued';

    public function __construct(public int $contextId)
    {
        //
    }

    /**
     * Get all publications eligible for deposit (published)
     */
    public function getAllPublications(): Collection
    {
        return $this->getAllPublishedQueryBuilder()
            ->select(['p.publication_id', 'p.submission_id'])
            ->get();
    }

    /**
     * Get all publications with a deposit state of some kind
     *
     * This will get all publications that have been queued, deposited,
     * or otherwise an attempt to deposit has been recorded.
     *
     * @param string[] $states
     */
    public function getWithDepositState(?array $states = null): Collection
    {
        $qb = $this->getDepositStatusQueryBuilder();

        if (!is_null($states)) {
            $qb->whereIn('ps.setting_value', $states);
        }

        return $qb->get();
    }

    /**
     * Get the swordv3 deposit details for all publications that
     * have been deposited
     */
    public function getDepositDetails(): array
    {
        $publications = Repo::publication()->getCollector()
            ->filterByContextIds([$this->contextId])
            ->getQueryBuilder()
            ->join('publication_settings as ps', 'ps.publication_id', '=', 'p.publication_id')
            ->whereIn('ps.setting_name', ['swordv3DateDeposited', 'swordv3State', 'swordv3StatusDocument'])
            ->select(['p.publication_id', 'p.submission_id', 'ps.setting_name', 'ps.setting_value'])
            ->get()
            ->reduce(function (array $publications, object $row) {
                if (!isset($publications[$row->publication_id])) {
                    $publications[$row->publication_id] = [
                        'contextId' => $this->contextId,
                        'submissionId' => $row->submission_id,
                        'publicationId' => $row->publication_id,
                    ];
                }
                $publications[$row->publication_id][$row->setting_name] = $row->setting_value;
                return $publications;
            }, []);

        return $publications;
    }

    protected function getDepositStatusQueryBuilder(): Builder
    {
        return $this->getAllPublishedQueryBuilder()
            ->join('publication_settings as ps', 'ps.publication_id', '=', 'p.publication_id')
            ->where('ps.setting_name', 'swordv3State')
            ->select(['p.publication_id', 'p.submission_id', 'ps.setting_value']);
    }

    protected function getAllPublishedQueryBuilder(): Builder
    {
        return Repo::publication()->getCollector()
            ->filterByContextIds([$this->contextId])
            ->getQueryBuilder()
            ->where('p.status', Submission::STATUS_PUBLISHED);
    }
}