# eloquent-compatibility
Serve a previous Eloquent model from its current models, inside the application

## Status
The package is under redesign. This release installs nothing: its service provider binds nothing, and it ships no command, rule or database object.

The new design writes compatibly in the application, and the database holds no function for it. Later releases add it one part at a time.

## Installation
```bash
composer require aryeo/eloquent-compatibility
```

The package supports PostgreSQL 14, 16 and 18, on Laravel 12 and 13.
