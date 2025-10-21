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