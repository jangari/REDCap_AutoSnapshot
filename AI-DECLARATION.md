---
version: "1.0.0"
level: copilot
---

## Notes

Anthropic's Claude Sonnet 5 model, via Claude Code, was used extensively throughout this
module's development: diagnosing and fixing a core REDCap defect that caused snapshots to be
attributed to the wrong project's data, implementing project-status and project-purpose
filtering, fixing a case where projects with no prior snapshot were silently never backed up,
a performance optimisation to the per-project cron loop, a security review (including running
REDCap's own Psalm-based module scanner and resolving a flagged SQL taint issue), a
debounce/settle-window mechanism plus automated snapshot-retention pruning (a second cron that
deletes old automated snapshots beyond a configurable count, while never touching manually-taken
ones), and feature-detected support for the "comments" column REDCap added to data dictionary
snapshots in a later core release (confirmed present/absent across the two REDCap versions
available in this dev instance before relying on it). Claude executed these tasks end-to-end on
request -- including live diagnosis and testing against a real REDCap instance (database
queries, timing benchmarks, log inspection, and live test runs -- a soft-delete/prune cycle, and
a direct invocation of the snapshot-creation method to confirm the new comment text -- before
each was trusted) -- checking in with findings and proposed designs at each step. All product
decisions (feature scope, default behaviour, naming conventions, retention semantics,
settle-window/staleness defaults and the reasoning behind revising them, when a finding
warranted a fix, and choosing debounce over a fuller backup-rotation scheme) were made by Aidan
Wilson, who reviewed, corrected, and redirected the work throughout, including catching at least
one incorrect claim Claude made about the snapshot-detection logic before it shipped, and
pushing back on an initial settle-window default that didn't hold up against how REDCap building
actually happens.
