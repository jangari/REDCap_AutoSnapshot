<?php

namespace INTERSECT\AutoSnapshot;

use \REDCap as REDCap;
use \MetaData as MetaData;
use \Files as Files;

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
     * Whether automated snapshotting is enabled for a given project purpose, per the
     * "Enable for ... projects" system-setting checkboxes.
     *
     * An unset setting (getSystemSetting() returns null) is treated as disabled: this module has
     * never been published, so there's no existing install whose behavior needs preserving --
     * an admin enabling the module opts each purpose in explicitly rather than getting everything
     * by default.
     *
     * @param int|string $purpose
     * @return bool
     */
    private function isPurposeEnabled($purpose)
    {
        $settingKeyByPurpose = [
            0 => 'enable-purpose-practice',
            1 => 'enable-purpose-other',
            2 => 'enable-purpose-research',
            3 => 'enable-purpose-quality',
            4 => 'enable-purpose-ops',
        ];

        $key = $settingKeyByPurpose[(int)$purpose] ?? null;
        if ($key === null) {
            // Unrecognized purpose value (e.g. a future core addition) -- fail closed, matching
            // the opt-in default for every known purpose.
            return false;
        }

        return (bool)$this->getSystemSetting($key);
    }

    /**
     * Creates a data dictionary snapshot for an arbitrary project, correctly scoped
     * to $project_id.
     *
     * Reimplements MetaData::createDataDictionarySnapshot() rather than calling it directly:
     * that method takes a $project_id argument but never forwards it into its internal
     * getDataDictionary() call, which falls back to the PROJECT_ID constant instead
     * undefined (or stuck on one project) for the life of a cron request that loops over many.
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

        // Ensure the project's local-storage edocs subfolder exists before attempting the upload.
        // Confirmed the hard way: when local_storage_use_project_subfolder is enabled (it is on
        // this instance), Files::uploadFile()'s LOCAL-storage branch tries move_uploaded_file(),
        // then rename(), then file_put_contents() against EDOC_PATH/pid<N>/... -- none of which
        // create a missing parent directory. A real browser-based upload always goes through a
        // page/AJAX handler that calls Files::detectProjectLevelLocalStorage() first (it creates
        // the "pid<N>" subfolder via mkdir() if missing and verifies it's writable); our simulated
        // upload skipped that step entirely, so it silently failed (returned 0, source file left
        // untouched) for every project whose subfolder hadn't already been created by unrelated
        // prior activity. Verified against this dev instance: pid43's subfolder didn't exist and
        // every snapshot attempt failed; calling this first creates it and the same upload succeeds.
        Files::detectProjectLevelLocalStorage($project_id);

        // Simulate a file upload for storing in edocs table (same approach as core)
        $ddfile = [
            'name' => basename($dd_snapshot_filename),
            'type' => 'application/csv',
            'size' => filesize($dd_snapshot_filename),
            'tmp_name' => $dd_snapshot_filename,
        ];
        $dd_edoc_id = Files::uploadFile($ddfile, $project_id);

        if (file_exists($dd_snapshot_filename)) unlink($dd_snapshot_filename);

        // Files::uploadFile() returns 0 on failure (disallowed file type, storage write failure, etc.).
        // Inserting doc_id=0 would violate redcap_data_dictionaries' FK to redcap_edocs_metadata, so
        // bail out here instead of letting that exception propagate and abort the whole cron run.
        if (empty($dd_edoc_id)) {
            $this->log("AutoSnapshot: snapshot upload failed, skipping insert", [
                'project_id' => $project_id,
            ]);
            return false;
        }

        $sql = "INSERT INTO redcap_data_dictionaries (doc_id, project_id, ui_id) VALUES (?, ?, ?)";
        return $this->query($sql, [
            $dd_edoc_id,
            $project_id,
            // ui_id always NULL: this snapshot is automated, not a user action, even when a logged-in
            // admin manually triggers the cron via "Run Cron" for testing. Project Revision History
            // shows no "Snapshot by ..." text at all for a NULL ui_id (it can't show a custom label
            // like "System" -- that column is a straight join against redcap_user_information).
            null,
        ]);
    }

    /**
     * Validates that a project's log_event_table value is really one of REDCap's own log-event
     * tables before it's ever used as a SQL identifier.
     *
     * @param mixed $log_table
     * @return string|null the validated table name, or null if it doesn't match
     */
    private function sanitizeLogEventTableName($log_table)
    {
        static $validTableNames = null;
        if ($validTableNames === null) {
            $validTableNames = ['redcap_log_event' => 'redcap_log_event'];
            for ($i = 2; $i <= 99; $i++) {
                $validTableNames['redcap_log_event' . $i] = 'redcap_log_event' . $i;
            }
        }

        return is_string($log_table) ? ($validTableNames[$log_table] ?? null) : null;
    }

    public function autoSnapshot($cronInfo) {
        foreach ($this->getProjectsWithModuleEnabled() as $localProjectId) {
            try {
                $this->setProjectId($localProjectId);

                // 1. Get the project's status/purpose/log table via a lightweight column read.
                // NOT new Project($localProjectId): core's Project::__construct() unconditionally
                // loads the entire project (loadMetadata/loadEvents/loadEventsForms/loadSurveys) --
                // benchmarked in this dev instance at ~14x slower per project than reading these
                // three plain columns directly. At scale (looping over every enabled project on
                // every cron tick) that difference is the dominant cost of this whole method.
                $projectRow = $this->query(
                    "SELECT status, purpose, log_event_table FROM redcap_projects WHERE project_id = ?",
                    [$localProjectId]
                )->fetch_assoc();

                if (!$projectRow) {
                    continue;
                }

                // Production (status 1) and Analysis/Cleanup (status 2) projects already get a data
                // dictionary snapshot automatically whenever a draft is published (see Project::status
                // usage in core, e.g. Classes/Project.php's "$status > 0 && $draft_mode > 0" checks).
                // This module exists to cover Development (status 0) projects, which have no such
                // built-in snapshotting, so skip anything not in development.
                if ($projectRow['status'] != 0) {
                    continue;
                }

                // Let admins opt whole project-purpose categories out (e.g. Practice/Just-for-fun
                // projects, which can churn through design changes without being worth backing up).
                if (!$this->isPurposeEnabled($projectRow['purpose'])) {
                    continue;
                }

                // $projectRow['log_event_table'] gets interpolated into a SQL identifier below (table
                // names can't be bound as query parameters the way values can), so it's validated
                // against REDCap's own naming contract for these tables first, rather than trusted
                // as-is just because it's DB-sourced. A project row that fails this is corrupt/
                // unexpected; skip it.
                $log_table = $this->sanitizeLogEventTableName($projectRow['log_event_table']);
                if ($log_table === null) {
                    continue;
                }

                // 2. Fetch the last snapshot timestamp (using our core SQL method)
                $last_snapshot_time = $this->getLastSnapshotTimestamp($localProjectId);

                if ($last_snapshot_time === false) {
                    // No snapshot has ever been taken for this project. Don't gate the very first
                    // snapshot on finding a MANAGE/redcap_metadata log row: a project whose fields
                    // arrived via Copy Project, a template/XML import, or a bulk Data Dictionary
                    // upload never logs that event/object_type combination, so it would otherwise
                    // never get backed up no matter how many times the cron runs. Confirmed against
                    // a real project in this dev instance ("Declarations Template Project", copied
                    // from another project): it has MANAGE log rows for redcap_projects/redcap_surveys/
                    // redcap_econsent from the copy, but none for redcap_metadata, ever. Take an
                    // unconditional baseline snapshot instead.
                    $needsSnapshot = true;
                } else {
                    // 3. Otherwise, only snapshot again if something has changed since that baseline.
                    $sql = "SELECT ts
                        FROM `$log_table`
                        WHERE project_id = ?
                        AND event = 'MANAGE'
                        AND object_type = 'redcap_metadata'
                        AND ts > ?
                        LIMIT 1";

                    $result = $this->query($sql, [$localProjectId, date("YmdHis", strtotime($last_snapshot_time))]);
                    $needsSnapshot = $result && $result->num_rows > 0;
                }

                if ($needsSnapshot) {
                    // A structural dictionary change happened (or this is the first-ever snapshot).
                    $snapshotCreated = $this->createProjectDataDictionarySnapshot($localProjectId);

                    $this->log("AutoSnapshot: design change detected", [
                        'project_id' => $localProjectId,
                        'snapshot_created' => $snapshotCreated ? 1 : 0,
                    ]);

                    if ($snapshotCreated) {
                        // Log it
                        REDCap::logEvent(
                            "Automated Data Dictionary Snapshot Created",
                            "The snapshot module automatically backed up project ID $localProjectId's data dictionary due to detected design modifications.",
                            null,  // Action/SQL
                            null,  // Record ID
                            null,  // Event ID
                            null   // Project ID (Pass null so it logs globally to System, not Project)
                        );
                    }
                }
            } catch (\Throwable $e) {
                // Don't let one project's failure (e.g. a snapshot upload error) stop the rest
                // of the loop from being checked.
                $this->log("AutoSnapshot: error while processing project", [
                    'project_id' => $localProjectId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return "The \"{$cronInfo['cron_description']}\" cron job completed successfully.";
    }

}
