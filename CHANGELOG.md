# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project
adheres to [Semantic Versioning](https://semver.org/).

## [2.0.0] - Unreleased

The public API is unchanged. The major version marks the move to PHP 8.3+
and the php-db QA toolchain shared by all Contenir 2.x packages. See
[UPGRADE-2.0.md](UPGRADE-2.0.md).

### Changed

- Requires PHP 8.3, 8.4 or 8.5. PHP 8.1 and 8.2 are no longer supported.
- `contenir/config` (`^0.2 || ^2.0`) is now a required dependency instead of a
  suggestion. `Repository\FileRepository` cannot work without it.

### Fixed

- `FileRepository::get()` reads a non-scalar `message` (for example an array
  left by a hand edit) as `''`. It previously raised an "Array to string
  conversion" warning and returned `"Array"`.

### Added

- Continuous integration on PHP 8.3, 8.4 and 8.5 against lowest, locked and
  latest dependencies, with coverage reported to Codecov.
- Separate unit (no I/O) and integration (real filesystem) test suites, with
  100% line and branch coverage.

### Removed

- `squizlabs/php_codesniffer` and `phpcs.xml`, replaced by Mago via
  `php-db/phpdb-qa-tools`.
- The `../config` path repository from `composer.json`.

## [0.1.1]

- `FileRepository` stores state under `maintenance.state`, preserving every
  other key in the file, and writes through `contenir/config`.
- Added the MIT `LICENSE` file.

## [0.1.0]

- Initial release: `MaintenanceState`, `MaintenanceRepositoryInterface`,
  `FileRepository` and `InMemoryRepository`.
