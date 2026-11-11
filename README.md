# eloquent-compatibility
Serve a previous Eloquent model from its current models, inside the application

## Status
This release implements the design that the [interface specification](docs/specification/README.md) states. Positions are the one exception: `position()` waits on [eloquent-ordering#14](https://github.com/AryeoHQ/eloquent-ordering/issues/14). The [public surface](docs/specification/public-surface.md) lists what a consumer may use.

A legacy model declares a map: the successors it is served from, how the view reads each attribute, and the class that writes each attribute. `Compatibility::prepare()` adds the package's storage, and `php artisan compatibility:backfill` writes each legacy row's successors. Until install, the legacy table stays the source of truth. `Compatibility::install()` then replaces the legacy table with a read-only view, in one transaction. The view reads the retained table, which is the renamed legacy table, joined to its successors.

After install, each write through the legacy model runs as one unit of work. Every changed attribute runs its operation, the package saves each changed successor once through its model, and the record must read back what was written. The writes run in the application, and the database holds no trigger or function for them.

`Compatibility::rollback()` reverses an install, and `Compatibility::retire()` drops the legacy storage once nothing reads it. `php artisan compatibility:sql` prints the SQL each operation runs, and `php artisan compatibility:status` prints each model's stage.

## Installation
```bash
composer require aryeo/eloquent-compatibility
```

The package supports PostgreSQL 14, 16 and 18, on Laravel 12 and 13.
