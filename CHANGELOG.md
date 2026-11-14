# Changelog

All notable changes to PeakFlow are recorded here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

The changelog is generated from Conventional Commits and then **edited for
humans** - a generated changelog is a list of commits, not a description of a
release. Each release records the ASVS verification result, the restore-drill
result, and any schema change requiring operator action.

## [Unreleased]

### Added

- The complete product specification: architecture, domain model, standards
  register, design system, API conventions and operational doctrine.
- The delivery backlog: **246 tasks across 14 phases**, 1,188 points, each with
  acceptance criteria, dependencies, an estimate and the standard it answers
  to. IDs `PF-001`..`PF-246`.
- Architecture decision records for the decisions that shape everything after
  them: the tenancy model, money representation, the ledger's invariant
  enforcement, the semantic-first e-invoicing model, and the choice of a
  server-driven UI over a single-page application.

### Notes

- **No GitHub Actions workflow is installed, deliberately.** The pipeline is
  specified and kept as a non-executing reference at
  `docs/operations/ci-pipeline.yml.example`. Enabling it is tracked as
  `PF-013`, so it is a decision with a name on it rather than an oversight.
- No application code has been written yet. This repository is currently the
  contract the code will be written against.

[Unreleased]: https://github.com/mostfaswaisi93/peakflow/commits/main
