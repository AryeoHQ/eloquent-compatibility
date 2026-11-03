<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Write;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use Support\Database\Eloquent\Compatibility\Definition\ResolvedMap;
use Support\Database\Eloquent\Compatibility\Exceptions\CompatibilityWriteRefused;
use Support\Database\Eloquent\Compatibility\Schema\SqlFragments;

final readonly class MorphMove
{
    public function __construct(private ResolvedMap $map, private Connection $connection) {}

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $values
     */
    public function run(Model $legacy, Model $primary, null|ResolvedMap $from, ResolvedMap $to, array $before, array $values, bool $deleting = false, bool $restoring = false): int
    {
        $old = $from === null || $from->target === null ? null : $this->locked($from, $primary);
        $updated = (new RecordWriter($to, $this->connection, $this->map->externals(), moving: true))->update($legacy, self::moved($to, $before, $values), $deleting, $restoring);

        if ($old === null || $from === null || $this->named($from, $old)) {
            return $updated;
        }

        $pointer = $this->pointer($primary);

        if (Applying::run($legacy, $old, fn () => $old->forceDelete()) === false) {
            throw CompatibilityWriteRefused::bySuccessorListener($legacy::class, $old);
        }

        $deleted = $this->pointer($primary);

        if ($deleted !== $pointer) {
            $morph = $this->morph();
            $record = $legacy->getKey();

            throw CompatibilityWriteRefused::unsupported($legacy::class, 'a successor listener that repoints record '.(is_int($record) || is_string($record) ? $record : ''), class_basename($this->map->primary->model).' stored '.$morph[0].' '.self::quoted($deleted[$morph[0]] ?? null).' and '.$morph[1].' '.self::quoted($deleted[$morph[1]] ?? null).' after the delete of the old '.class_basename($old).', where the move gives '.self::quoted($pointer[$morph[0]] ?? null).' and '.self::quoted($pointer[$morph[1]] ?? null).', so the record would point at a target the move did not store.');
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

    private function named(ResolvedMap $from, Model $old): bool
    {
        [$type, $id] = $this->morph();

        return $this->connection->table($this->map->primary->table)->where($type, $from->target?->morphAlias)->where($id, $old->getKey())->exists();
    }

    /**
     * @return array<string, mixed>
     */
    private function pointer(Model $primary): array
    {
        $table = $this->map->primary->table;

        return (array) $this->connection->table($table)->where($table.'.'.$this->map->primary->key, $primary->getKey())->first($this->morph());
    }

    /**
     * @return array{string, string}
     */
    private function morph(): array
    {
        $morph = $this->map->morph ?? throw new LogicException("Compatibility map '{$this->map->table}' moves a record between targets of no relatedMorph().");

        return [$morph->typeColumn, $morph->idColumn];
    }

    private static function quoted(mixed $value): string
    {
        return is_scalar($value) ? SqlFragments::literal((string) $value) : 'null';
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
