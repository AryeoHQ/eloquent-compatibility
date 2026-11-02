<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Write;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use Support\Database\Eloquent\Compatibility\Definition\ResolvedMap;
use Support\Database\Eloquent\Compatibility\Exceptions\CompatibilityWriteRefused;

final readonly class MorphMove
{
    public function __construct(private ResolvedMap $map, private Connection $connection) {}

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $values
     */
    public function run(Model $legacy, Model $primary, null|ResolvedMap $from, ResolvedMap $to, array $before, array $values, bool $deleting = false, bool $restoring = false): int
    {
        $old = $from?->target === null ? null : $this->locked($from, $primary);
        $updated = (new RecordWriter($to, $this->connection, $this->map->externals()))->update($legacy, self::moved($to, $before, $values), $deleting, $restoring);

        if ($old !== null && Applying::run($legacy, $old, fn () => $old->forceDelete()) === false) {
            throw CompatibilityWriteRefused::bySuccessorListener($legacy::class, $old);
        }

        return $updated;
    }

    private function locked(ResolvedMap $from, Model $primary): null|Model
    {
        $morph = $from->morph ?? throw new LogicException("Compatibility map '{$this->map->table}' moves a record between targets of no relatedMorph().");
        $target = $from->target ?? throw new LogicException("Compatibility map '{$this->map->table}' moves a record out of no relatedMorph() target.");
        $key = $primary->getAttributes()[$morph->idColumn] ?? null;

        return $key === null ? null : (new $target->model)->setConnection($this->connection->getName())->newQuery()->whereKey($key)->lockForUpdate()->first();
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private static function moved(ResolvedMap $to, array $before, array $values): array
    {
        $after = array_replace($before, $values);
        $moved = $values;

        foreach ($to->attributes as $name => $attribute) {
            if ($to->owners[$name] === $to->target?->successor && ! array_key_exists($name, $values) && $attribute->isWritable()) {
                $moved[$name] = $after[$name] ?? null;
            }
        }

        return $moved;
    }
}
