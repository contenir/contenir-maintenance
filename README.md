# contenir/maintenance

[![Continuous Integration](https://github.com/contenir/maintenance/actions/workflows/continuous-integration.yml/badge.svg)](https://github.com/contenir/maintenance/actions/workflows/continuous-integration.yml)
[![codecov](https://codecov.io/gh/contenir/maintenance/graph/badge.svg)](https://codecov.io/gh/contenir/maintenance)

Framework-agnostic maintenance-mode toggle for [Contenir CMS](https://github.com/contenir).

The CMS writes a maintenance flag (and optional message); the consuming
Site (Mezzio, Laminas MVC, anything else) reads it on every request and
returns 503 with the message until the flag is cleared.

This package provides the *domain*: a small immutable state value plus a
repository interface, with file-based and in-memory implementations.
Framework-specific middleware and listeners come from sibling packages such
as [`contenir/maintenance-laminas-mvc`](https://github.com/contenir/maintenance-laminas-mvc).

## Requirements

- PHP 8.3, 8.4 or 8.5
- [`contenir/config`](https://github.com/contenir/config) `^0.2 || ^2.0`, only if you use `Repository\FileRepository`

The 0.x releases, which support PHP 8.1, remain available from the `0.x`
branch and `v0.*` tags; see [UPGRADE-2.0.md](UPGRADE-2.0.md).

## Install

```bash
composer require contenir/maintenance
```

The package has no runtime dependencies. The admin side, which writes state
with `FileRepository`, also needs the config writer:

```bash
composer require contenir/config
```

## Usage

### `MaintenanceState`

An immutable value with three public readonly properties:

| Property | Type | Meaning |
| --- | --- | --- |
| `active` | `bool` | Whether the Site should serve 503 |
| `message` | `string` | Operator message shown on the 503 page; `''` when inactive |
| `since` | `?DateTimeImmutable` | When maintenance was switched on; always `null` when inactive |

```php
use Contenir\Maintenance\MaintenanceState;

$down = MaintenanceState::active('Back online by 5pm AEST.');            // since = now
$down = MaintenanceState::active('Back online by 5pm AEST.', $startedAt); // explicit since
$up   = MaintenanceState::inactive();

$custom = new MaintenanceState(active: true, message: 'Down', since: null);
```

### `MaintenanceRepositoryInterface`

```php
interface MaintenanceRepositoryInterface
{
    public function get(): MaintenanceState;

    /** @throws RuntimeException If the state cannot be persisted. */
    public function save(MaintenanceState $state): void;
}
```

Implementations treat a missing or unreadable backing store as inactive
rather than throwing; write failures must throw so the admin UI can show them.

### `Repository\FileRepository` (admin side)

```php
use Contenir\Maintenance\MaintenanceState;
use Contenir\Maintenance\Repository\FileRepository;

$repo = new FileRepository('/var/www/shared/config/autoload/maintenance.local.php');

$repo->save(MaintenanceState::active('Back online by 5pm AEST.'));
// ... later ...
$repo->save(MaintenanceState::inactive());

$state = $repo->get();
if ($state->active) {
    // Render 503 with $state->message
}
```

Writes are atomic and invalidate the file's opcache entry (via
`contenir/config`). A failed write throws
`Contenir\Config\Exception\WriteException`, a `RuntimeException`, with a
message such as `Cannot write maintenance state to "...".`

### File format

`FileRepository` reads and writes a Laminas/Mezzio-style config file, so
the Site can drop it into `config/autoload/` and have it merged into the
application config:

```php
<?php

return [
    'maintenance' => [
        'state' => [
            'active'  => true,
            'message' => 'Back online by 5pm AEST.',
            'since'   => '2026-05-05T03:14:15+00:00',
        ],
    ],
];
```

The repository owns only `maintenance.state`. Other top-level keys, and
sibling keys under `maintenance`, are preserved on save.

On read:

- a missing, unreadable or unparseable file, or one without
  `maintenance.state`, is inactive;
- a falsy `active` is inactive, and any lingering message is dropped;
- a non-scalar `message` reads as `''`;
- a missing or unparseable `since` reads as `null`.

### `Repository\InMemoryRepository`

Holds state in memory. It is shipped in `src/` so Sites can build state from
their merged config without touching the filesystem, and so consumers can use
it in their own tests:

```php
use Contenir\Maintenance\MaintenanceState;
use Contenir\Maintenance\Repository\InMemoryRepository;

$repo = new InMemoryRepository(MaintenanceState::active('Down')); // defaults to inactive
```

## Development

The QA toolchain is [php-db/phpdb-qa-tools](https://github.com/php-db/phpdb-qa-tools).
[Mago](https://mago.carthage.software/) is a standalone binary, installed
separately (`brew install mago`).

```bash
composer check             # everything below
composer cs-check          # mago format --check && mago lint
composer static-analysis   # mago analyze
composer test              # unit suite: state value and in-memory repository, no I/O
composer test-integration  # integration suite: FileRepository against a temp directory
composer test-coverage     # both suites, clover.xml for Codecov
```

## License

MIT. See [LICENSE](LICENSE).
