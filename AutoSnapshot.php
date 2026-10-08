<?php

namespace INTERSECT\AutoSnapshot;

use \REDCap as REDCap;
use \MetaData as MetaData;
use \Files as Files;

class AutoSnapshot extends \ExternalModules\AbstractExternalModule {

    /**
     * Retrieves the timestamp of the most recent data dictionary snapshot for a project.
     * Derived directly from REDCap's core MetaData::getLastDataDictionarySnapshot() SQL.
     *
     * "AND e.delete_date IS NULL" added after a real test against this dev instance: soft-deleting
     * an edoc via Files::deleteFileByDocId() (as pruneOldSnapshots() now does) never touches
     * redcap_data_dictionaries at all, so without this filter a pruned "most recent" snapshot
     * would still be reported as the current last snapshot here -- invisible in Project Revision
     * History, counting down to permanent deletion, yet this module would think a valid backup
     * already exists and skip taking a new one.
     *
     * @param int $project_id
     * @return string|false YYYY-MM-DD HH:MM:SS format, or false if no (non-deleted) snapshot exists.
     */
    private function getLastSnapshotTimestamp($project_id)
    {
        $sql = "SELECT e.stored_date
            FROM redcap_data_dictionaries d, redcap_edocs_metadata e
            WHERE e.project_id = ?
            AND e.project_id = d.project_id
            AND d.doc_id = e.doc_id
            AND e.delete_date IS NULL
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
     * Read-only diagnostic for a Files::uploadFile() failure. Will log out for the admin to
     * investigate.
     *
     * @param int $project_id
     * @return bool true if local per-project subfolder storage is in use and the folder is absent
     */
    private function isMissingLocalStorageSubfolder($project_id)
    {
        if (!Files::detectProjectLevelLocalStorageGlobal()) {
            return false; // Not using local per-project subfolder storage; not applicable.
        }

        $subfolder = Files::getLocalStorageSubfolder($project_id, true);
        if ($subfolder === '') {
            return false; // No subfolder recorded for this project (e.g. predates the setting).
        }

        return !is_dir(EDOC_PATH . $subfolder);
    }

    /**
     * Whether this site's redcap_data_dictionaries table has the "comments" column REDCap added
     * in a recent release (confirmed live: present in 19.9.9, absent in 17.4.1 -- column name is
     * "comments", plural, not "comment"; there's also a comments_attachment_doc_id column for an
     * attached file, which we have no use for and always leave NULL). Not every site will be on
     * a version that has it, so this is feature-detected once per request rather than assumed --
     * omitting it entirely on older sites rather than failing the whole insert.
     *
     * @return bool
     */
    private function supportsSnapshotComments()
    {
        static $supported = null;
        if ($supported === null) {
            $result = $this->query("SHOW COLUMNS FROM redcap_data_dictionaries LIKE 'comments'", []);
            $supported = $result && $result->num_rows > 0;
        }
        return $supported;
    }

    /**
     * Comment text for a snapshot, tailored to why autoSnapshot() took it. Shown inline in core's
     * own Project Revision History page (confirmed: renderSnapshotComments() there truncates at
     * 25 words with a "show more" popover) -- kept under that so nothing gets cut off.
     *
     * @param string|null $reason one of autoSnapshot()'s $reason values, or null
     * @return string
     */
    private function getSnapshotComment($reason)
    {
        switch ($reason) {
            case 'no_prior_history':
                return 'Automated baseline snapshot (no prior snapshot or design-change history existed for this project).';
            case 'settled':
                return 'Automated snapshot: design changes detected and settled after the configured quiet period.';
            case 'max_staleness':
                return 'Automated snapshot: forced after design changes remained pending longer than the configured maximum staleness period.';
            default:
                return 'Automated snapshot created by the AutoSnapshot module.';
        }
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
     * @param string|null $reason why this snapshot is being taken (see autoSnapshot()) -- used
     *   only to tailor the comment text above; has no effect on sites without that column.
     * @return bool true on success
     */
    private function createProjectDataDictionarySnapshot($project_id, $reason = null)
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

        // Files::uploadFile() returns 0 on failure (disallowed file type, storage write failure, etc.).
        // Inserting doc_id=0 would violate redcap_data_dictionaries' FK to redcap_edocs_metadata, so
        // bail out here instead of letting that exception propagate and abort the whole cron run.
        //
        // Deliberately not creating the project's edocs subfolder ourselves if it's missing (an
        // earlier version of this method did, via Files::detectProjectLevelLocalStorage()) --
        // that's core's job (Project::setDefaults(), run once at project-creation time), not this
        // module's. We only call Files::uploadFile(), same as core's own
        // MetaData::createDataDictionarySnapshot(); if that fails because the subfolder never got
        // created (or went missing), we diagnose and log it explicitly instead of silently fixing
        // REDCap's own state out from under it.
        if (empty($dd_edoc_id)) {
            $this->log("AutoSnapshot: snapshot upload failed, skipping insert", [
                'project_id' => $project_id,
                'missing_local_storage_subfolder' => $this->isMissingLocalStorageSubfolder($project_id) ? 1 : 0,
            ]);
            return false;
        }

        if ($this->supportsSnapshotComments()) {
            $sql = "INSERT INTO redcap_data_dictionaries (doc_id, project_id, ui_id, comments) VALUES (?, ?, ?, ?)";
            $params = [$dd_edoc_id, $project_id, null, $this->getSnapshotComment($reason)];
        } else {
            $sql = "INSERT INTO redcap_data_dictionaries (doc_id, project_id, ui_id) VALUES (?, ?, ?)";
            $params = [$dd_edoc_id, $project_id, null]; // ui_id always NULL
        }

        return $this->query($sql, $params);
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

    /**
     * The earliest and most recent MANAGE/redcap_metadata log-event timestamps for a project,
     * optionally restricted to rows after $sinceTimestamp. The "most recent" timestamp is the
     * basis for the settle-window debounce below (how long ago did editing last touch the
     * dictionary); the "earliest" timestamp is the basis for the max-staleness safety valve (how
     * long has this project been sitting with an un-snapshotted change, whether or not it has a
     * prior snapshot at all to measure staleness from).
     *
     * $log_table must already be validated by sanitizeLogEventTableName() -- see that method's
     * docblock for why raw interpolation isn't an option here.
     *
     * @param int $project_id
     * @param string $log_table pre-validated table name (see sanitizeLogEventTableName())
     * @param string|null $sinceTimestamp YmdHis format, or null to consider all history
     * @return array{first: string, last: string}|false both in YmdHis format, or false if no
     *   matching rows exist
     */
    private function getMetadataChangeWindow($project_id, $log_table, $sinceTimestamp = null)
    {
        if ($sinceTimestamp === null) {
            $sql = "SELECT MIN(ts) AS first_change_ts, MAX(ts) AS last_change_ts
                FROM `$log_table`
                WHERE project_id = ?
                AND event = 'MANAGE'
                AND object_type = 'redcap_metadata'";
            $params = [$project_id];
        } else {
            $sql = "SELECT MIN(ts) AS first_change_ts, MAX(ts) AS last_change_ts
                FROM `$log_table`
                WHERE project_id = ?
                AND event = 'MANAGE'
                AND object_type = 'redcap_metadata'
                AND ts > ?";
            $params = [$project_id, $sinceTimestamp];
        }

        $result = $this->query($sql, $params);
        if ($result && ($row = $result->fetch_assoc()) && $row['last_change_ts'] !== null) {
            return ['first' => $row['first_change_ts'], 'last' => $row['last_change_ts']];
        }

        return false;
    }

    /**
     * Minutes of design-change quiet required before a detected change is snapshotted, per the
     * "Design Change Settle Window" system setting. Falls back to 60 minutes when unset/blank.
     *
     * Not derived from cron_frequency's default 30-minute interval (an earlier version of this
     * defaulted to 15 minutes specifically to land within one tick of that value -- a tick-
     * alignment convenience, not a model of real editing behaviour). Revised after recognising
     * that a genuine REDCap build session routinely includes gaps well past 15 minutes -- add a
     * field, go test it in the form/survey, come back and adjust branching logic or validation,
     * test again -- while the person is clearly still mid-task, not done. A window shorter than
     * those natural gaps would frequently declare "settled" mid-session and snapshot a half-wired
     * state, undermining the entire point of debouncing. 60 minutes tolerates that build-test-
     * iterate rhythm; the cost is a longer worst-case time-to-first-backup (roughly this value
     * plus up to one cron interval), a deliberate trade in favour of a clean snapshot over a
     * faster but more likely mid-task one. 0 is a deliberate, documented escape hatch back to
     * "snapshot on the very next tick that sees any change" -- no enforced floor, unlike the
     * retention count below (a numeric-magnitude setting going to its minimum is harmless here;
     * it isn't for deleting snapshots).
     *
     * @return int minutes, always >= 0
     */
    private function getSettleWindowMinutes()
    {
        $value = $this->getSystemSetting('design-settle-window-minutes');
        if ($value === null || $value === '') {
            return 60;
        }
        return max(0, (int)$value);
    }

    /**
     * Hours a design change can remain un-snapshotted before one is forced regardless of ongoing
     * edits -- the safety valve for a project edited near-continuously, which might otherwise
     * never go quiet for the full settle window above. Falls back to 12 hours when unset/blank:
     * long enough not to interrupt a genuine, continuous real-world build session, short enough
     * that it corresponds to a single normal working stretch -- it's hard to imagine someone
     * actively developing a project for longer than that without a real break, at which point the
     * settle-window check above would have already caught it anyway.
     *
     * @return int hours, always >= 0
     */
    private function getMaxStalenessHours()
    {
        $value = $this->getSystemSetting('max-staleness-hours');
        if ($value === null || $value === '') {
            return 12;
        }
        return max(0, (int)$value);
    }

    /**
     * Number of auto-generated snapshots to retain per project, per the project-overridable
     * "Snapshot Retention Count" setting. Manually-taken snapshots (a real ui_id recorded) are
     * never subject to this at all -- see pruneOldSnapshots(). getProjectSetting() itself already
     * resolves a project's own override if set, falling back to the system-wide default
     * otherwise (confirmed against ExternalModules::getProjectSetting()'s source) -- but that
     * resolution is keyed off whatever project $this->setProjectId() last set, so this must only
     * be called after that's been done for the project in question (the documented pattern for
     * reading a project-scoped setting from within a cron; see crons.md "Setting a Project
     * Context Within a Cron").
     *
     * @return int|null null if retention is disabled for this project (blank or 0 -- deliberately
     *   treated as the same "off" state, so an admin uneasy about automated row deletion has an
     *   explicit, unambiguous way to turn it off entirely rather than an implicit blank being the
     *   only option), else the number of auto-snapshots to keep (always >= 1).
     */
    private function getSnapshotRetentionCount()
    {
        $value = $this->getProjectSetting('snapshot-retention-count');
        if ($value === null || $value === '' || (int)$value <= 0) {
            return null;
        }
        return (int)$value;
    }

    /**
     * Deletes one old auto-generated snapshot: soft-deletes its edoc via core's own
     * Files::deleteFileByDocId() (project-scoped -- the only core method that exists for this),
     * then removes our own redcap_data_dictionaries row.
     *
     * @param int $project_id
     * @param int $dd_id redcap_data_dictionaries primary key
     * @param int $doc_id
     * @return bool true if the snapshot was pruned
     */
    private function pruneProjectSnapshot($project_id, $dd_id, $doc_id)
    {
        if (!Files::deleteFileByDocId($doc_id, $project_id)) {
            return false;
        }

        $this->query("DELETE FROM redcap_data_dictionaries WHERE dd_id = ? AND project_id = ?", [$dd_id, $project_id]);
        return true;
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
                $sinceTimestamp = $last_snapshot_time === false ? null : date("YmdHis", strtotime($last_snapshot_time));

                // 3. Find the window of design changes since that snapshot (or, if there's no
                // snapshot yet, since the project's metadata log history began). Using this window
                // instead of just "does at least one changed row exist" is what lets the
                // settle-window debounce and max-staleness safety valve below apply uniformly
                // whether or not a prior snapshot exists.
                $changeWindow = $this->getMetadataChangeWindow($localProjectId, $log_table, $sinceTimestamp);

                $needsSnapshot = false;
                $reason = null;

                if ($last_snapshot_time === false && $changeWindow === false) {
                    // No snapshot has ever been taken for this project, AND it has no
                    // MANAGE/redcap_metadata log history at all to measure "quiet" against -- a
                    // project whose fields arrived via Copy Project, a template/XML import, or a
                    // bulk Data Dictionary upload never logs that event/object_type combination,
                    // so it would otherwise never get backed up no matter how many times the cron
                    // runs, and there's no change-timestamp to debounce against anyway. Confirmed
                    // against a real project in this dev instance ("Declarations Template Project",
                    // copied from another project): it has MANAGE log rows for redcap_projects/
                    // redcap_surveys/redcap_econsent from the copy, but none for redcap_metadata,
                    // ever. Take an unconditional baseline snapshot instead.
                    $needsSnapshot = true;
                    $reason = 'no_prior_history';
                } elseif ($changeWindow !== false) {
                    // Something has changed since the last snapshot (or since this project's
                    // metadata history began, if there's no snapshot yet but there is metadata log
                    // history -- e.g. a brand-new project being actively built in Online Designer
                    // for the first time). Only snapshot once the most recent change has gone quiet
                    // for the configured settle window, so a multi-edit editing session is captured
                    // once at its clean end-state rather than repeatedly mid-session.
                    $settledThreshold = date("YmdHis", strtotime('-' . ($this->getSettleWindowMinutes() * 60) . ' seconds'));
                    if ($changeWindow['last'] <= $settledThreshold) {
                        $needsSnapshot = true;
                        $reason = 'settled';
                    } else {
                        // Starvation safety valve: force a snapshot if the *earliest* pending
                        // change is old enough, regardless of whether editing has gone quiet -- so
                        // a near-continuously edited project still gets some periodic backup rather
                        // than none until it finally pauses.
                        $staleThreshold = date("YmdHis", strtotime('-' . ($this->getMaxStalenessHours() * 3600) . ' seconds'));
                        if ($changeWindow['first'] <= $staleThreshold) {
                            $needsSnapshot = true;
                            $reason = 'max_staleness';
                        }
                    }
                }

                if ($needsSnapshot) {
                    // A structural dictionary change happened (or this is a baseline/forced snapshot).
                    $snapshotCreated = $this->createProjectDataDictionarySnapshot($localProjectId, $reason);

                    $this->log("AutoSnapshot: design change detected", [
                        'project_id' => $localProjectId,
                        'reason' => $reason,
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

    /**
     * Separate cron (deliberately not folded into autoSnapshot(): different concern, different
     * natural cadence -- housekeeping once a day rather than every 30 minutes) that prunes
     * auto-generated snapshots beyond each project's configured retention count.
     *
     * Deliberately does NOT replicate autoSnapshot()'s status/purpose filters: retention is an
     * independent policy question from "should new automated snapshots be created here" -- a
     * project that's since moved out of Development, or whose purpose got disabled, may still
     * have a pile of old auto-snapshots worth pruning down to a sane count. It's still gated
     * per-project by the retention-count setting itself (blank/0 = skip entirely), which an
     * admin can leave unset for any project they don't want this cron touching at all.
     */
    public function pruneOldSnapshots($cronInfo) {
        foreach ($this->getProjectsWithModuleEnabled() as $localProjectId) {
            try {
                $this->setProjectId($localProjectId);

                $retentionCount = $this->getSnapshotRetentionCount();
                if ($retentionCount === null) {
                    continue; // Retention disabled for this project (blank or 0, default or override).
                }

                // Only auto-generated snapshots (ui_id IS NULL) are ever eligible for pruning --
                // any snapshot with a real ui_id was taken by a human via core's own "Create
                // Snapshot" button, and is kept forever regardless of count or age. e.delete_date
                // IS NULL excludes anything an earlier run already pruned (idempotency: never
                // re-attempt an already-soft-deleted edoc). Ordered newest-first so the first
                // $retentionCount rows are the ones being kept; anything beyond that is a
                // candidate for pruning -- which is exactly what guarantees the current latest
                // snapshot can never be among them, closing the gap getLastSnapshotTimestamp()'s
                // delete_date fix (above) also guards against independently.
                $sql = "SELECT d.dd_id, d.doc_id
                    FROM redcap_data_dictionaries d, redcap_edocs_metadata e
                    WHERE d.project_id = ?
                    AND e.project_id = ?
                    AND d.doc_id = e.doc_id
                    AND d.ui_id IS NULL
                    AND e.delete_date IS NULL
                    ORDER BY d.dd_id DESC";

                $result = $this->query($sql, [$localProjectId, $localProjectId]);
                $rows = [];
                while ($result && ($row = $result->fetch_assoc())) {
                    $rows[] = $row;
                }

                foreach (array_slice($rows, $retentionCount) as $candidate) {
                    $pruned = $this->pruneProjectSnapshot($localProjectId, $candidate['dd_id'], $candidate['doc_id']);

                    $this->log("AutoSnapshot: pruned old snapshot", [
                        'project_id' => $localProjectId,
                        'dd_id' => $candidate['dd_id'],
                        'pruned' => $pruned ? 1 : 0,
                    ]);
                }
            } catch (\Throwable $e) {
                // Don't let one project's failure stop the rest of the loop from being processed.
                $this->log("AutoSnapshot: error while pruning project", [
                    'project_id' => $localProjectId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return "The \"{$cronInfo['cron_description']}\" cron job completed successfully.";
    }

}
