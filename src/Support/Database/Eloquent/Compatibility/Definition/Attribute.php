<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Definition;

use BackedEnum;
use Closure;
use DateTimeZone;
use LogicException;
use Support\Database\Eloquent\Compatibility\Definition\Conversions\AsType;
use Support\Database\Eloquent\Compatibility\Definition\Conversions\Conversion;
use Support\Database\Eloquent\Compatibility\Definition\Conversions\DividedBy;
use Support\Database\Eloquent\Compatibility\Definition\Conversions\Fixed;
use Support\Database\Eloquent\Compatibility\Definition\Conversions\InTimeZone;
use Support\Database\Eloquent\Compatibility\Definition\Conversions\IsNotNull;
use Support\Database\Eloquent\Compatibility\Definition\Conversions\Mapping;
use Support\Database\Eloquent\Compatibility\Definition\Conversions\MultipliedBy;
use Support\Database\Eloquent\Compatibility\Definition\Conversions\OrDefault;
use Support\Database\Eloquent\Compatibility\Definition\Conversions\ReadAs;
use Support\Database\Eloquent\Compatibility\Exceptions\InvalidCompatibilityMap;
use Support\Database\Eloquent\Compatibility\Schema\SqlFragments;

final class Attribute
{
    private null|string $column = null;

    private null|string $readOnly = null;

    /** @var list<Conversion> */
    private array $conversions = [];

    private null|Fixed $fixed = null;

    private null|string $readAs = null;

    /** @var null|Closure(mixed): mixed */
    private null|Closure $writeTo = null;

    private bool $transitions = false;

    /** @var null|array{Closure, Closure} */
    private null|array $external = null;

    public function __construct(private readonly string $map, public readonly string $name) {}

    public function from(string $column): self
    {
        if ($this->column !== null) {
            throw $this->invalid("reads from both '{$this->column}' and '{$column}'");
        }

        if ($column === '' || str_contains($column, '.')) {
            throw $this->invalid("reads from '{$column}', which names no column of its successor's own table; reach another table through related()");
        }

        $this->column = $column;

        return $this;
    }

    public function readOnly(string $because): self
    {
        if (trim($because) === '') {
            throw $this->invalid('is read-only without a reason; readOnly() takes the reason a write is refused');
        }

        $this->readOnly = $because;

        return $this;
    }

    public function dividedBy(int|float $divisor): self
    {
        if ($divisor === 0 || $divisor === 0.0) {
            throw $this->invalid('is divided by 0');
        }

        return $this->convert(new DividedBy($divisor));
    }

    public function multipliedBy(int|float $factor): self
    {
        if ($factor === 0 || $factor === 0.0) {
            throw $this->invalid('is multiplied by 0, so no write can be inverted');
        }

        return $this->convert(new MultipliedBy($factor));
    }

    /**
     * @param  array<int|string, int|string|BackedEnum>  $values
     */
    public function mapping(array $values): self
    {
        if ($values === []) {
            throw $this->invalid('maps no values');
        }

        $mapping = new Mapping($values);
        $repeated = array_diff_key($mapping->values, array_unique($mapping->values));

        if ($repeated !== []) {
            throw $this->invalid("maps more than one stored value to '".reset($repeated)."', so a write of it has no one stored value");
        }

        return $this->convert($mapping);
    }

    /**
     * @param  Closure(): mixed  $setTo
     */
    public function isNotNull(Closure $setTo): self
    {
        ResolvedMap::refuseCaptures($this->map, "the isNotNull() closure of attribute '{$this->name}'", $setTo);

        return $this->convert(new IsNotNull($setTo));
    }

    public function orDefault(bool|int|float|string|BackedEnum $default): self
    {
        return $this->convert(new OrDefault($default));
    }

    public function asType(string $type): self
    {
        if (preg_match('/^[a-z_][a-z0-9_.]*(\(\d+(, ?\d+)?\))?( [a-z_][a-z0-9_]*(\(\d+(, ?\d+)?\))?)*(\[\])?$/i', $type) !== 1) {
            throw $this->invalid("reads as type '{$type}', which is not a type name");
        }

        return $this->convert(new AsType($type));
    }

    public function inTimeZone(string $zone): self
    {
        if (! in_array($zone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
            throw $this->invalid("reads in the time zone '{$zone}', which PHP does not know");
        }

        return $this->convert(new InTimeZone($zone));
    }

    public function fixed(null|bool|int|float|string|BackedEnum $value): self
    {
        if ($this->fixed !== null) {
            throw $this->invalid('is fixed twice');
        }

        $this->fixed = new Fixed($value);

        return $this;
    }

    public function readAs(string $sql): self
    {
        if (trim($sql) === '') {
            throw $this->invalid('reads as no SQL');
        }

        if ($this->readAs !== null) {
            throw $this->invalid('reads as raw SQL twice');
        }

        $this->readAs = $sql;

        return $this;
    }

    /**
     * @param  Closure(mixed): mixed  $inverse
     */
    public function writeTo(Closure $inverse): self
    {
        ResolvedMap::refuseCaptures($this->map, "the writeTo() closure of attribute '{$this->name}'", $inverse);

        $this->writeTo = $inverse;

        return $this;
    }

    public function transitions(): self
    {
        $this->transitions = true;

        return $this;
    }

    public function external(Closure $read, Closure $write): self
    {
        ResolvedMap::refuseCaptures($this->map, "the external() read closure of attribute '{$this->name}'", $read);
        ResolvedMap::refuseCaptures($this->map, "the external() write closure of attribute '{$this->name}'", $write);

        $this->external = [$read, $write];

        return $this;
    }

    public function column(): null|string
    {
        return $this->column;
    }

    public function readOnlyBecause(): null|string
    {
        return $this->readOnly;
    }

    /**
     * @return list<Conversion>
     */
    public function conversions(): array
    {
        return match (true) {
            $this->fixed !== null => [$this->fixed],
            $this->readAs !== null => [new ReadAs($this->readAs, $this->writeTo)],
            default => $this->conversions,
        };
    }

    public function runsTransitions(): bool
    {
        return $this->transitions;
    }

    public function isExternal(): bool
    {
        return $this->external !== null;
    }

    public function isWritable(): bool
    {
        return $this->readOnly === null && $this->column !== null && $this->fixed === null && $this->external === null;
    }

    public function resolve(): self
    {
        $problem = match (true) {
            $this->fixed !== null && ($this->column !== null || $this->conversions !== [] || $this->readAs !== null || $this->external !== null) => 'is fixed(), so it reads no column and takes no other conversion',
            $this->readAs !== null && $this->conversions !== [] => 'reads as raw SQL with readAs(), so it takes no other conversion',
            $this->readAs !== null && $this->writeTo === null && $this->readOnly === null => 'reads as raw SQL with readAs(), and says neither writeTo() nor readOnly()',
            $this->writeTo !== null && $this->readAs === null => 'says writeTo(), which inverts readAs(), without readAs()',
            $this->writeTo !== null && $this->column === null => 'says writeTo() without from(), so its inverse has no column to store',
            $this->column === null && $this->fixed === null && $this->readAs === null && $this->external === null => 'names no column; give it from(), fixed(), readAs() or external()',
            default => null,
        };

        if ($problem !== null) {
            throw $this->invalid($problem);
        }

        return $this;
    }

    /**
     * @param  null|(Closure(null|string): (null|string))  $baseType
     */
    public function read(string $table, null|string $stored = null, null|Closure $baseType = null): string
    {
        if ($this->external !== null) {
            throw new LogicException("Attribute '{$this->name}' is external, so no SQL reads it.");
        }

        $sql = $this->column === null ? 'NULL' : SqlFragments::identifier($table).'.'.SqlFragments::identifier($this->column);
        $types = $this->types($stored);
        $baseType ??= fn (null|string $type) => $type;

        foreach ($this->conversions() as $at => $conversion) {
            if ($conversion instanceof AsType && SqlFragments::isZoned($baseType($types[$at])) && SqlFragments::isNaive($baseType($conversion->type))) {
                $sql = SqlFragments::inApplicationZone($sql);
            }

            $sql = $conversion->read($sql);
        }

        return $sql;
    }

    public function write(mixed $value): mixed
    {
        foreach (array_reverse($this->conversions()) as $conversion) {
            $value = $conversion->write($value);
        }

        return $value;
    }

    /**
     * @return null|array{string, mixed}
     */
    public function filter(string $operator, mixed $value, null|string $stored = null): null|array
    {
        if ($this->external !== null) {
            return null;
        }

        $types = $this->types($stored);
        $filter = [$operator, $value];

        foreach (array_reverse($this->conversions(), true) as $at => $conversion) {
            $filter = match ($filter[0]) {
                'none', 'all' => $filter,
                'in' => self::each($conversion, is_array($filter[1]) ? $filter[1] : [$filter[1]], $types[$at]),
                default => $conversion->filter($filter[0], $filter[1], $types[$at]),
            };

            if ($filter === null) {
                return null;
            }
        }

        return $filter;
    }

    public function type(null|string $stored): null|string
    {
        $types = $this->types($stored);

        return $types[count($types) - 1];
    }

    public function exact(null|string $stored, string $served): bool
    {
        $conversions = $this->conversions();
        $types = $this->types($stored);

        foreach ($conversions as $at => $conversion) {
            $output = $at === array_key_last($conversions) ? $served : $types[$at + 1];

            if ($output === null || ! $conversion->exact($types[$at], $output)) {
                return false;
            }
        }

        return true;
    }

    public function order(string $direction): null|string
    {
        if ($this->external !== null) {
            return null;
        }

        $direction = strtolower($direction);

        foreach (array_reverse($this->conversions()) as $conversion) {
            $direction = $conversion->order($direction);

            if ($direction === null) {
                return null;
            }
        }

        return $direction;
    }

    /**
     * @return non-empty-list<null|string>
     */
    private function types(null|string $stored): array
    {
        $types = [$this->column === null ? null : $stored];

        foreach ($this->conversions() as $at => $conversion) {
            $types[] = $conversion->type($types[$at]);
        }

        return $types;
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return null|array{string, list<mixed>}
     */
    private static function each(Conversion $conversion, array $values, null|string $type): null|array
    {
        $stored = [];

        foreach ($values as $value) {
            $filter = $conversion->filter('=', $value, $type);

            if ($filter === null || ! in_array($filter[0], ['=', 'none'], true)) {
                return null;
            }

            if ($filter[0] === '=') {
                $stored[] = $filter[1];
            }
        }

        return ['in', $stored];
    }

    private function convert(Conversion $conversion): self
    {
        $this->conversions[] = $conversion;

        return $this;
    }

    private function invalid(string $problem): InvalidCompatibilityMap
    {
        return InvalidCompatibilityMap::because($this->map, "attribute '{$this->name}' {$problem}");
    }
}
