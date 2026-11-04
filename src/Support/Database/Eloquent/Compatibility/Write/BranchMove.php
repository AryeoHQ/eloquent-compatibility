<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Write;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Support\Database\Eloquent\Compatibility\Definition\Attribute;
use Support\Database\Eloquent\Compatibility\Definition\Conversions\ReadAs;
use Support\Database\Eloquent\Compatibility\Definition\ResolvedMap;
use Support\Database\Eloquent\Compatibility\Exceptions\CompatibilityWriteRefused;

final readonly class BranchMove
{
    public function __construct(private ResolvedMap $map, private Connection $connection) {}

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $values
     */
    public function run(Model $legacy, Model $old, ResolvedMap $to, array $before, array $values): int|string
    {
        if (Applying::run($legacy, $old, fn () => $old->forceDelete()) === false) {
            throw CompatibilityWriteRefused::bySuccessorListener($legacy::class, $old);
        }

        $recreated = self::recreated($to, $before, $values);
        (new MassWriter($this->map, $this->connection))->claim($recreated, $to);

        return (new RecordWriter($to, $this->connection))->insert($legacy, $recreated);
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private static function recreated(ResolvedMap $to, array $before, array $values): array
    {
        $after = array_replace($before, $values);
        $recreated = [];

        foreach (array_keys($to->identifiers) as $name) {
            $recreated[$name] = $after[$name] ?? null;
        }

        foreach ($to->attributes as $name => $attribute) {
            if (! $attribute->isExternal() && (array_key_exists($name, $values) || ($attribute->column() !== null && ! self::readsOnly($attribute)))) {
                $recreated[$name] = $after[$name] ?? null;
            }
        }

        return $recreated + array_intersect_key($values, array_flip($to->legacyOnly(array_keys($values))));
    }

    private static function readsOnly(Attribute $attribute): bool
    {
        $conversion = $attribute->conversions()[0] ?? null;

        return $conversion instanceof ReadAs && $conversion->inverse === null;
    }
}
