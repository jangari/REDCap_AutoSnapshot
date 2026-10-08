<?php

namespace INTERSECT\AutoSnapshot;

use \REDCap as REDCap;
use \MetaData as MetaData;
use \Project as Project;
use \Files as Files;
use \User as User;

class AutoSnapshot extends \ExternalModules\AbstractExternalModule {

    /**
     * Retrieves the timestamp of the most recent data dictionary snapshot for a project.
     * Derived directly from REDCap's core MetaData::getLastDataDictionarySnapshot() SQL.
     * @param int $project_id
     * @return string|false YYYY-MM-DD HH:MM:SS format, or false if no snapshot exists.
     */
    private function getLastSnapshotTimestamp($project_id)
    {
        $sql = "SELECT e.stored_date
            FROM redcap_data_dictionaries d, redcap_edocs_metadata e
            WHERE e.project_id = ?
            AND e.project_id = d.project_id
            AND d.doc_id = e.doc_id
            ORDER BY d.dd_id DESC
            LIMIT 1";

        // $this->query() is safe, parameterized, and automatically handles DB connections
        $result = $this->query($sql, [$project_id]);

        if ($result && $result->num_rows > 0) {
            $row = $result->fetch_assoc();
            return $row['stored_date'];
        }

        return false;
    }

    /**
     * Creates a data dictionary snapshot for an arbitrary project, correctly scoped
     * to $project_id.
     *
     * Reimplements MetaData::createDataDictionarySnapshot() rather than calling it directly:
     * that method takes a $project_id argument but never forwards it into its internal
     * MetaData::getDataDictionary() call, which falls back to the request-scoped PROJECT_ID
     * constant instead. Since a cron loops over many projects in one request, PROJECT_ID is
     * either undefined or stuck on the wrong project, so the exported CSV would not actually
     * match $project_id even though the resulting redcap_data_dictionaries row would claim it does.
     * getDataDictionary()'s $project_id_override parameter (8th) and Files::uploadFile()'s
     * $project_id parameter (2nd) are both correctly honored, so we use those directly instead.
     *
     * @param int $project_id
     * @return bool true on success
     */
    private function createProjectDataDictionarySnapshot($project_id)
    {
        $dd_snapshot_filename = APP_PATH_TEMP . date('YmdHis') . "_DataDictionary_" . substr(sha1(rand()), 0, 6) . ".csv";

        // draft_mode forced false: no online-designer "draft" state is meaningful for a
        // cron with no logged-in user, so always snapshot the live/production metadata.
        $dd_snapshot = MetaData::getDataDictionary('csv', true, [], [], false, false, null, $project_id);
        $dd_snapshot = addBOMtoUTF8($dd_snapshot);
        file_put_contents($dd_snapshot_filename, $dd_snapshot);

        // Simulate a file upload for storing in edocs table (same approach as core)
        $ddfile = [
            'name' => basename($dd_snapshot_filename),
            'type' => 'application/csv',
            'size' => filesize($dd_snapshot_filename),
            'tmp_name' => $dd_snapshot_filename,
        ];
        $dd_edoc_id = Files::uploadFile($ddfile, $project_id);

        if (file_exists($dd_snapshot_filename)) unlink($dd_snapshot_filename);

        $user_info = defined("USERID") ? User::getUserInfo(USERID) : [];

        $sql = "INSERT INTO redcap_data_dictionaries (doc_id, project_id, ui_id) VALUES (?, ?, ?)";
        return $this->query($sql, [
            $dd_edoc_id,
            $project_id,
            $user_info['ui_id'] ?? null, // Could be null on External Module importDataDictionary() calls from crons
        ]);
    }

    public function runDailySnapshotCheck($cronInfo) {
        foreach ($this->getProjectsWithModuleEnabled() as $localProjectId) {
            $this->setProjectId($localProjectId);

            // 1. Get the project-specific log table name dynamically
            $project = new Project($localProjectId);
            $log_table = $project->project['log_event_table'];

            // 2. Fetch the last snapshot timestamp (using our core SQL method)
            $last_snapshot_time = $this->getLastSnapshotTimestamp($localProjectId);

            // 3. Format timestamp to match REDCap log style (YYYYMMDDHHMMSS)
            // If false (no snapshot exists), default to 0 to capture ALL history
            $formatted_snapshot_ts = $last_snapshot_time ? date("YmdHis", strtotime($last_snapshot_time)) : 0;

            // 4. Query for metadata management modifications since the last snapshot
            $sql = "SELECT ts
                FROM `$log_table`
                WHERE project_id = ?
                AND event = 'MANAGE'
                AND object_type = 'redcap_metadata'
                AND ts > ?
                LIMIT 1";

            $result = $this->query($sql, [$localProjectId, $formatted_snapshot_ts]);

            if ($result && $result->num_rows > 0) {
                // A structural dictionary change happened! Take a new snapshot.
                $this->createProjectDataDictionarySnapshot($localProjectId);
                // Log it
                REDCap::logEvent(
                    "Automated Data Dictionary Snapshot Created",
                    "The snapshot module automatically backed up project ID $localProjectId's data dictionary due to detected design modifications.",
                    null,  // Action/SQL
                    null,  // Record ID
                    null,  // Event ID
                    null   // Project ID (CRITICAL: Pass null so it logs globally to System, not Project)
                );
            }
        }

        return "The \"{$cronInfo['cron_description']}\" cron job completed successfully.";
    }

}
