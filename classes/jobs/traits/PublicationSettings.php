<?php
namespace APP\plugins\generic\swordv3\classes\jobs\traits;

use APP\facades\Repo;
use APP\plugins\generic\swordv3\swordv3Client\StatusDocument;
use APP\publication\Publication;
use DateTime;
use Illuminate\Support\Facades\DB;
use PKP\services\PKPSchemaService;

/**
 * Helper functions for getting and saving the custom swordv3
 * settings attached to Publication objects
 *
 * These functions supplement the default Repository methods for
 * reading/writing Publication data. This is done to ensure that
 * these settings are available when the job is run in a context
 * without the plugin enabled. In these circumstances, the
 * Publication settings that the plugin adds are not read from or
 * written to the database by the default Repository methods.
 *
 * @see https://github.com/pkp/pkp-lib/issues/9345
 */
trait PublicationSettings
{
    /**
     * Get the Publication entity schema definition
     */
    protected function getPublicationSchema(): object
    {
        /** @var PKPSchemaService $schemaService */
        $schemaService = app()->get('schema');
        return $schemaService->get(Repo::publication()->dao->schema);
    }

    /**
     * Get a publication with swordv3 settings
     *
     * Adds swordv3 plugin settings to the publication if they
     * weren't already retrieved from the database.
     */
    protected function getPublication(int $id): ?Publication
    {
        $publication = Repo::publication()->get($id);
        $publicationSchema = $this->getPublicationSchema();

        // If the swordv3State is identified as a property in the schema,
        // we can assume that all swordv3 properties were registered in
        // this request. We don't need to retrieve extra data.
        if (!property_exists($publicationSchema->properties, 'swordv3State')) {
            DB::table(Repo::publication()->dao->settingsTable)
                ->where(Repo::publication()->dao->getPrimaryKeyName(), '=', $id)
                ->whereIn('setting_name', ['swordv3DateDeposited', 'swordv3State', 'swordv3StatusDocument'])
                ->select(['setting_name', 'setting_value'])
                ->get()
                ->each(function($row) use ($publication) {
                    $publication->setData($row->setting_name, $row->setting_value);
                });
        }

        return $publication;
    }

    /**
     * Store swordv3 status in the publication settings
     *
     * @return Publication The new publication after the settings have been saved
     */
    protected function savePublicationStatus(int $id, StatusDocument $statusDocument): Publication
    {
        $settings = [
                'swordv3DateDeposited' => (new DateTime())->format('Y-m-d h:i:s'),
                'swordv3State' => $statusDocument->getSwordStateId(),
                'swordv3StatusDocument' => json_encode($statusDocument->getStatusDocument()),
        ];

        foreach ($settings as $name => $value) {
            DB::table(Repo::publication()->dao->settingsTable)
                ->updateOrInsert(
                    [
                        Repo::publication()->dao->getPrimaryKeyName() => $id,
                        'setting_name' => $name,
                    ],
                    [
                        'setting_value' => $value,
                    ]
                );
        }

        return $this->getPublication($id);
    }
}