<?php

namespace INTERSECT\AutoSnapshot;

use \REDCap as REDCap;
use \MetaData as MetaData;
use \Project as Project;

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
                MetaData::createDataDictionarySnapshot($localProjectId);
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
