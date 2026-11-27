# Auto Snapshot

This module automatically takes data dictionary snapshots during development phase of project building, so that changes can be reverted without the user needing to manually take a snapshot.

REDCap already takes a snapshot when the user uploads a data dictionary CSV, and whenever project modifications are made for production projects, but design changes during the development process while using the online designer have no automated snapshot method. This module fills that gap, by registering a cron task that triggers a snapshot when it detects that there have been changes since the previous.

## Usage

Once enabled system-wide, the module registers two crons:

- **`auto_snapshot`** (every 30 minutes by default -- see `cron_frequency` in `config.json`) loops over every project the module is enabled on. For each one, it:

  1. Skips anything that isn't in Development status (Production and Analysis/Cleanup projects are already covered by REDCap's own publish-time snapshotting).
  2. Skips any project whose purpose isn't enabled in the system settings (see Configuration).
  3. Takes an immediate baseline snapshot if the project has never had one *and* has no design history to measure against (e.g. its fields arrived via Copy Project, XML Import or Project Migration).
  4. Otherwise, takes a snapshot once design-change activity has gone quiet for the configured settle window - so a multi-edit Online Designer session is captured once at its clean end-state, not mid-edit - or once a pending change has been waiting longer than the maximum-staleness safety valve, whichever comes first (see Configuration).

  On REDCap versions that have it (the `comments` column on data dictionary snapshots, added in v17.5.0), each automated snapshot gets a short comment explaining why it was taken (baseline, settled, or forced by staleness) -- visible inline in Project Setup's "Project Revision History" page, the same place core's own manual snapshots show theirs. Sites on an older version without that column are unaffected; this is feature-detected, not assumed.

- **`prune_old_snapshots`** (once a day by default) deletes automated snapshots beyond each project's configured retention count. Snapshots taken manually, via the Design page's own "Create Snapshot" button, are never touched by this, regardless of count or age.

No end-user action is needed per project beyond the standard "enable this module" step; everything else is automatic, governed by the system settings below (with one project-overridable exception).

## Configuration

Mostly configured at the system level, in Control Center:

| Setting | Default value | Description |
|---|---|---|
|Enable for **purpose** projects | Unchecked | Five checkboxes, one per project purposes. At least one purpose must be enabled to snapshot anything. This allows administrators to exclude Practice projects from automated snapshotting. |
| Settle Window (minutes) | 60 | A project is considered 'active' if the last design change is within this window. If no activity within this window, the design activity is considered 'settled', allowing a snapshot to take place. This prevents snapshots every half hour during busy development times, and to minimise the potential for taking snapshots between related changes. |
| Maximum staleness (hours) | 12 | Safety valve for near-continuous development. If the settle window period setting has prevented snapshots for this long, force a snapshot. 12 hours as a default is long enough to cover a genuinely continuous development stretch, and short enough that it's hard to imagine a project being actively developed for this long without a break. |
| Snapshot retention count | 0 | (Project overridable) The number of previous snapshots to retain before being pruned. Blank or `0` (default) disables pruning entirely and thus retains every snapshot. |

Snapshots made by this module are differentiated from others in that they are not attributed to any user. This allows the pruning task to target only those snapshots created by the module. In effect, any snapshot made manually by the user is treated differently, and is left alone.

## AI Involvement Declaration

Anthropic's Claude Sonnet 5 model, via Claude Code, was used extensively throughout this module's development, executing tasks end-to-end on request - including diagnosis and fixes for a core REDCap defect around cross-project data scoping, project-status/purpose filtering, a fix for projects with no prior snapshot being silently skipped, a performance optimisation, a security review (including running REDCap's own module scanner and resolving a flagged SQL taint issue), the settle-window/staleness-valve debounce and automated snapshot-retention pruning described above, and feature-detected support for the "comments" column REDCap added to data dictionary snapshots in a later core release. All product decisions - including the retention semantics, the manual-vs-automated snapshot distinction, choosing debounce over a fuller backup-rotation scheme, and the settle-window/staleness defaults - were made by Aidan Wilson, who reviewed, corrected, and redirected the work throughout, including live-testing changes directly against a running REDCap instance before they were accepted. See `AI-DECLARATION.md` for the structured version of this statement.

## Changelog

| Version | Description |
| --- | --- |
| v1.0.0 | Initial release |
