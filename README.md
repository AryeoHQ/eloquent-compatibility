# eloquent-compatibility
Serve a previous Eloquent model from its current models, inside the application

## Status
The package is under redesign. This release serves a legacy model's reads through a read-only view over its successors. A legacy model declares its map, and `Compatibility::prepare()` and `Compatibility::install()` replace its table with the view. `php artisan compatibility:sql` prints the SQL each operation runs. Writes through the legacy model translate into saves of its successors, and a legacy write of a `transitions()` attribute runs the successor's state-machine trigger.

The new design writes compatibly in the application, and the database holds no function for it. Later releases add it one part at a time.

A redesign of the public interface is specified, with the status Proposed, before any of its code ships. The [interface specification](docs/specification/README.md) states it, and a later 0.x minor release implements it.

## Installation
```bash
composer require aryeo/eloquent-compatibility
```

The package supports PostgreSQL 14, 16 and 18, on Laravel 12 and 13.
