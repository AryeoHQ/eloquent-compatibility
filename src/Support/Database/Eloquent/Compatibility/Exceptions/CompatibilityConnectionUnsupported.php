<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Exceptions;

use Illuminate\Database\Connection;
use Support\Database\Eloquent\Compatibility\Connections\DispatchesQueryFailed;

final class CompatibilityConnectionUnsupported extends CompatibilityException
{
    /**
     * @param  list<string>  $maps
     */
    public static function byMissingTrait(array $maps, Connection $connection): self
    {
        $names = implode("', '", $maps);
        $name = (string) $connection->getName();
        $instead = str_starts_with($connection::class, 'Illuminate\\')
            ? "Register a connection class that uses the trait with Connection::resolverFor('{$connection->getDriverName()}', …), as docs/adopting-a-map.md shows."
            : 'Use the trait in '.$connection::class.'.';

        return new self(
            "Compatibility map '{$names}' refused this write, because connection '{$name}' is an instance of ".$connection::class.', which does not use the trait '.DispatchesQueryFailed::class.'. '
            .'Why: Outside a transaction the event bridge runs a mirrored write in a transaction of its own. '
            ."Only the trait tells the bridge the moment the write's statement fails, so that the bridge can roll that transaction back at once. "
            .'Without it, the next DB::transaction() or createOrFirst() on the connection fails with 25P02, and a DB::afterCommit() callback registered before the next statement is lost. '
            ."Instead: {$instead}",
        );
    }

    /**
     * @param  list<string>  $maps
     */
    public static function byOverride(array $maps, Connection $connection, string $method, string $class): self
    {
        $names = implode("', '", $maps);
        $name = (string) $connection->getName();

        return new self(
            "Compatibility map '{$names}' refused this write, because connection '{$name}' is an instance of ".$connection::class.", which resolves {$method}() to {$class}::{$method}(), not to the method of ".DispatchesQueryFailed::class.'. '
            .'Why: The event bridge works only through the methods of the trait. They open a mirrored write after every other beforeExecuting callback, and roll it back the moment its statement fails. '
            ."A declaration of {$method}() in a class replaces the method of the trait. "
            .'Instead: Remove that declaration.',
        );
    }
}
