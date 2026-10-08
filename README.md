# Auto Snapshot

Take automatic snapshots of a project's data dictionary while it's still in Development
status, so early-stage design work isn't lost between the times someone remembers to click
"Create Snapshot" by hand.

REDCap already takes a data dictionary snapshot automatically whenever a Production (or
Analysis/Cleanup) project publishes a draft change -- but Development-status projects, where
the dictionary is often edited directly and repeatedly during initial build-out, have no such
safety net. This module fills that gap with a background cron: it watches enabled Development
projects for design changes and takes a snapshot when it finds one, including an unconditional
first/baseline snapshot for a project that's never had one at all (which matters for projects
whose fields arrived via Copy Project, a template, or a bulk Data Dictionary upload, rather
than incremental edits in Online Designer).

## Installation

### From Module Repository

Install the module from the REDCap module repository and enable in the Control Center, then
enable on projects as needed.

### From GitHub

Clone the repository and rename the directory to include a version number, e.g.,
`AutoSnapshot_v1.0.0`, and copy to your modules directory, then enable in Control Center and
on projects as needed.

## Usage

Once enabled system-wide, the module registers two crons:

- **`auto_snapshot`** (every 30 minutes by default -- see `cron_frequency` in `config.json`)
  loops over every project the module is enabled on. For each one, it:

  1. Skips anything that isn't in Development status (Production and Analysis/Cleanup projects
     are already covered by REDCap's own publish-time snapshotting).
  2. Skips any project whose purpose isn't enabled in the system settings (see Configuration).
  3. Takes an immediate baseline snapshot if the project has never had one *and* has no design
     history to measure against (e.g. its fields arrived via Copy Project or a bulk upload).
  4. Otherwise, takes a snapshot once design-change activity has gone quiet for the configured
     settle window -- so a multi-edit Online Designer session is captured once at its clean
     end-state, not mid-edit -- or once a pending change has been waiting longer than the
     maximum-staleness safety valve, whichever comes first (see Configuration).

  On REDCap versions that have it (the `comments` column on data dictionary snapshots, added in a
  recent core release), each automated snapshot gets a short comment explaining why it was taken
  (baseline, settled, or forced by staleness) -- visible inline in Project Setup's "Project
  Revision History" page, the same place core's own manual snapshots show theirs. Sites on an
  older version without that column are unaffected; this is feature-detected, not assumed.

- **`prune_old_snapshots`** (once a day by default) deletes automated snapshots beyond each
  project's configured retention count. Snapshots taken manually, via the Design page's own
  "Create Snapshot" button, are never touched by this, regardless of count or age.

No end-user action is needed per project beyond the standard "enable this module" step --
everything else is automatic, governed by the system settings below (with one project-overridable
exception).

## Configuration

Mostly configured at the system level, in Control Center:

- **Enable for \<Purpose\> projects** -- five checkboxes, one per REDCap project purpose
  (Practice/Just for fun, Other, Research, Quality Improvement, Operational Support). All
  default to **off**: at least one must be checked for the module to snapshot anything. This
  lets an admin exclude high-churn, low-value categories (e.g. Practice projects) from
  automated snapshotting without disabling the module entirely.
- **Design Change Settle Window (in minutes)** -- how long design-change activity must be quiet
  before a snapshot is taken, long enough to tolerate a normal build-test-iterate rhythm (add a
  field, go test it, come back and adjust it) without mistaking a natural pause for "done."
  Optional; defaults to 60 minutes if left blank.
- **Maximum Staleness Before Forced Snapshot (in hours)** -- safety valve for a project edited
  near-continuously, which might otherwise never go quiet long enough to satisfy the settle
  window above. Optional; defaults to 12 hours if left blank -- long enough to cover a genuine
  continuous working stretch, short enough that it's hard to imagine active development going
  that long without a real break.
- **Snapshot Retention Count** -- the *only* project-overridable setting. Sets the system-wide
  default number of automated snapshots to keep per project; leave blank or enter `0` to disable
  pruning entirely (no automated snapshot is ever deleted -- the safe default, and a deliberate
  option for anyone uneasy about automated row deletion). Each project can override this from
  its own module-configure dialog, falling back to the system default when left blank there.

## AI Involvement Declaration

Anthropic's Claude Sonnet 5 model, via Claude Code, was used extensively throughout this
module's development, executing tasks end-to-end on request -- including diagnosis and fixes
for a core REDCap defect around cross-project data scoping, project-status/purpose filtering,
a fix for projects with no prior snapshot being silently skipped, a performance optimisation,
a security review (including running REDCap's own module scanner and resolving a flagged SQL
taint issue), the settle-window/staleness-valve debounce and automated snapshot-retention
pruning described above, and feature-detected support for the "comments" column REDCap added to
data dictionary snapshots in a later core release. All product decisions -- including the
retention semantics, the manual-vs-automated snapshot distinction, choosing debounce over a
fuller backup-rotation scheme, and the settle-window/staleness defaults -- were made by Aidan
Wilson, who reviewed, corrected, and redirected the work throughout, including live-testing
changes directly against a running REDCap instance before they were accepted. See
`AI-DECLARATION.md` for the structured version of this statement.

## Changelog

| Version | Description |
| --- | --- |
| v1.0.0 | Initial release |
