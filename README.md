# contenir/maintenance

Framework-agnostic maintenance-mode toggle for [Contenir CMS](https://github.com/contenir).

The CMS writes a maintenance flag (and optional message); the consuming
Site (Mezzio, Laminas MVC, anything else) reads it on every request and
returns 503 with the message until the flag is cleared.

This package provides the *domain* — a small immutable state value plus a
repository interface, with file-based and in-memory implementations.
Framework-specific middleware/listeners come from sibling packages.

## Install

```bash
composer require contenir/maintenance
```

Zero runtime dependencies — pure PHP 8.1+.

## Usage

### Reading state

```php
use Contenir\Maintenance\Repository\FileRepository;

$repo = new FileRepository('/var/www/shared/maintenance.local.php');
$state = $repo->get();

if ($state->active) {
    // Render 503 with $state->message
}
```

### Writing state (admin)

```php
use Contenir\Maintenance\MaintenanceState;

$repo->save(MaintenanceState::active('Back online by 5pm AEST.'));

// ... later ...
$repo->save(MaintenanceState::inactive());
```

### File format

`FileRepository` reads/writes a PHP file that returns an array:

```php
<?php

return [
    'active' => true,
    'message' => 'Back online by 5pm AEST.',
    'since' => '2026-05-05T03:14:15+00:00',
];
```

A missing or unreadable file resolves to inactive state, so first-run and
permission edge cases don't crash the consumer.

### Testing

`InMemoryRepository` is shipped in `src/` so consumers can use it in
their own test suites:

```php
use Contenir\Maintenance\Repository\InMemoryRepository;
use Contenir\Maintenance\MaintenanceState;

$repo = new InMemoryRepository(MaintenanceState::active('Down'));
```

## License

MIT