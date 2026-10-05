# Upgrading from 0.x to 2.0

2.0 has the same public API as 0.1. Only the platform requirement changes.

| | 0.x | 2.0 |
| --- | --- | --- |
| PHP | ^8.1 | 8.3, 8.4 or 8.5 |
| `contenir/config` (suggested, for `FileRepository`) | ^0.1 | ^0.2 or ^2.0 |

To upgrade, update the constraint:

```bash
composer require contenir/maintenance:^2.0
```

No code changes are needed. `MaintenanceState`,
`MaintenanceRepositoryInterface`, `Repository\FileRepository` and
`Repository\InMemoryRepository` keep their signatures and behaviour, and
the state file format is unchanged.

One read edge case changed: a non-scalar `maintenance.state.message` in the
state file now reads as `''` instead of `"Array"` with a PHP warning.

Projects that must stay on PHP 8.1 or 8.2 can keep using `^0.1`, which is
maintained on the `0.x` branch.
