# Agent instructions

Notes for AI coding agents (and humans) working in this repository.

## Changelog

`CHANGELOG.md` must be updated with **every commit that adds new work** (features, fixes, behavior or configuration changes, dev-stack changes). Do not leave it for later.

- Add the entry in the same change set as the work, before suggesting the commit message.
- Follow [Keep a Changelog](https://keepachangelog.com/en/1.0.0/): group entries under `### Added`, `### Changed`, `### Fixed` (and `### Removed` / `### Security` when relevant).
- New work goes under the topmost version heading if it has not been released yet; otherwise start a new heading `## [x.y.z] - YYYY-MM-DD`. Use a minor bump for features and a patch bump for fixes ([Semantic Versioning](https://semver.org/spec/v2.0.0.html)).
- Describe what changed for users and developers, not how the code was written. Mention renamed environment variables, new migrations, new routes, and anything someone must do when upgrading.
- Pure housekeeping that changes nothing for users or developers (for example, marking a plan as implemented) does not need an entry.

## Commits

- Use conventional commit prefixes: `feat:`, `fix:`, `chore:`, `refactor:`, `docs:`, and so on.
- A commit that contains new work includes its `CHANGELOG.md` entry.

## Tests

- End-to-end tests live in `tests/e2e/` (see its README). Run them against the isolated test stack before committing changes that affect behavior.
- Add or update an e2e test when you change user-visible behavior.

## Language

- Code, comments and documentation: US English.
