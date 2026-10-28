<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Schema;

enum TriggerFunction: string
{
    case Insert = 'insert';
    case Update = 'update';
    case Delete = 'delete';
    case PrimaryInsert = 'primary_insert';
    case PrimaryDelete = 'primary_delete';
}
