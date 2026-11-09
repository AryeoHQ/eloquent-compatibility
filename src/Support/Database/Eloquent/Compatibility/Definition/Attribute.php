<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Definition;

use BackedEnum;
use Closure;
use DateTimeZone;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection as Values;
use LogicException;
use Support\Database\Eloquent\Compatibility\Contracts\WritesAttribute;
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

    private bool $ignoreWrites = false;

    /** @var list<Conversion> */
    private array $conversions = [];

    private null|Fixed $fixed = null;

    private null|string $readAs = null;

    /** @var null|class-string<WritesAttribute> */
    private null|string $operation = null;

    private null|Closure $computed = null;

    /** @var null|array{class-string<Model>, string} */
    private null|array $orFrom = null;

    private null|string $orFromTable = null;

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

    public function readOnly(string $because, bool $ignoreWrites): self
    {
        if (trim($because) === '') {
            throw $this->invalid('is read-only without a reason; readOnly() takes the reason a write is refused');
        }

        if ($this->readOnly !== null) {
            throw $this->invalid('is declared readOnly() twice');
        }

        $this->readOnly = $because;
        $this->ignoreWrites = $ignoreWrites;

        return $this;
    }

    /**
     * @param  class-string<Model>  $successor
     */
    public function orFrom(string $successor, string $column): self
    {
        if ($this->orFrom !== null) {
            throw $this->invalid('reads orFrom() twice');
        }

        if ($column === '' || str_contains(
            haystack: $column,
            needle: '.',
        )) {
            throw $this->invalid("reads orFrom() the column '{$column}', which names no column of ".class_basename($successor).'; name a column of its own table');
        }

        $this->orFrom = [$successor, $column];

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
     * @param  class-string<WritesAttribute>  $operation
     */
    public function writtenBy(string $operation): self
    {
        if ($this->operation !== null) {
            throw $this->invalid('is writtenBy() twice');
        }

        if (! is_subclass_of(
            object_or_class: $operation,
            class: WritesAttribute::class,
        )) {
            throw $this->invalid("is writtenBy() {$operation}, which does not implement ".WritesAttribute::class);
        }

        $this->operation = $operation;

        return $this;
    }

    public function computedAs(Closure $read): self
    {
        ResolvedMap::refuseCaptures($this->map, "the computedAs() read closure of attribute '{$this->name}'", $read);

        if ($this->computed !== null) {
            throw $this->invalid('is computedAs() twice');
        }

        $this->computed = $read;

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

    public function ignoresWrites(): bool
    {
        return $this->readOnly !== null && $this->ignoreWrites;
    }

    /**
     * @return null|class-string<WritesAttribute>
     */
    public function operation(): null|string
    {
        return $this->operation;
    }

    public function invertible(): bool
    {
        return $this->column !== null && $this->fixed === null && $this->readAs === null && $this->computed === null && $this->orFrom === null;
    }

    public function storesColumn(): bool
    {
        return $this->column !== null && $this->computed === null;
    }

    /**
     * @return null|class-string<Model>
     */
    public function orFromSuccessor(): null|string
    {
        return $this->orFrom[0] ?? null;
    }

    /**
     * @return list<Conversion>
     */
    public function conversions(): array
    {
        return match (true) {
            $this->fixed !== null => [$this->fixed],
            $this->readAs !== null => [new ReadAs($this->readAs)],
            default => $this->conversions,
        };
    }

    public function isExternal(): bool
    {
        return $this->computed !== null;
    }

    /**
     * @param  Collection<array-key, covariant Model>  $successors
     * @return array<array-key, mixed>
     */
    public function readExternally(Collection $successors): array
    {
        $read = $this->computed ?? throw new LogicException("Attribute '{$this->name}' is not computedAs(), so no computedAs() read serves it.");
        $values = $read($successors);
        $values = match (true) {
            $values instanceof Values => $values->all(),
            is_array($values) => $values,
            default => throw new LogicException("The computedAs() read closure of attribute '{$this->name}' on {$this->map} returns ".get_debug_type($values).', and it returns the values keyed by successor key.'),
        };
        $received = array_map(fn (Model $successor) => self::keyOf($successor->getKey()), $successors->all());
        $returned = array_map(self::keyOf(...), array_keys($values));
        $missing = array_diff($received, $returned);
        $unexpected = array_diff($returned, $received);

        return match (true) {
            $missing !== [] => throw new LogicException("The computedAs() read closure of attribute '{$this->name}' on {$this->map} returns no value for successor key ".reset($missing).'; it returns one value, or null, for each successor it receives.'),
            $unexpected !== [] => throw new LogicException("The computedAs() read closure of attribute '{$this->name}' on {$this->map} returns a value for successor key ".reset($unexpected).', which it did not receive; it returns one value, or null, for each successor it receives.'),
            default => $values,
        };
    }

    /**
     * @param  array<class-string<Model>, string>  $related
     */
    public function resolve(array $related = []): self
    {
        $declares = $this->operation !== null || $this->readOnly !== null;
        $problem = match (true) {
            $this->fixed !== null && ($this->column !== null || $this->conversions !== [] || $this->readAs !== null || $this->computed !== null || $this->orFrom !== null) => 'is fixed(), so it reads no column and takes no other conversion',
            $this->fixed !== null && $this->operation !== null => 'is fixed(), so it reads one value and no operation writes it',
            $this->computed !== null && ($this->column !== null || $this->conversions !== [] || $this->readAs !== null || $this->orFrom !== null) => 'is computedAs(), so it reads no column and takes no conversion',
            $this->readAs !== null && ($this->conversions !== [] || $this->orFrom !== null) => 'reads as raw SQL with readAs(), so it takes no other conversion',
            $this->orFrom !== null && $this->column === null => 'reads orFrom() without from(), so it has no column to read first',
            $this->operation !== null && $this->readOnly !== null => 'is both writtenBy() and readOnly(), and an attribute has one operation',
            $this->column === null && $this->fixed === null && $this->readAs === null && $this->computed === null => 'names no column; give it from(), fixed(), readAs() or computedAs()',
            ! $declares && $this->fixed === null && ! $this->invertible() => 'has no inverse, so its read says no write; declare writtenBy() or readOnly()',
            default => null,
        };

        if ($problem !== null) {
            throw $this->invalid($problem);
        }

        if ($this->orFrom !== null) {
            $this->orFromTable = $related[$this->orFrom[0]] ?? throw $this->invalid('reads orFrom() '.class_basename($this->orFrom[0]).', which the map does not relate under its root');
        }

        return $this;
    }

    /**
     * @param  null|(Closure(null|string): (null|string))  $baseType
     */
    public function read(string $table, null|string $stored = null, null|Closure $baseType = null): string
    {
        if ($this->computed !== null) {
            throw new LogicException("Attribute '{$this->name}' is computedAs(), so no SQL reads it.");
        }

        $sql = $this->column === null ? 'NULL' : SqlFragments::identifier($table).'.'.SqlFragments::identifier($this->column);

        if ($this->orFrom !== null && $this->orFromTable !== null) {
            $sql = 'COALESCE('.$sql.', '.SqlFragments::identifier($this->orFromTable).'.'.SqlFragments::identifier($this->orFrom[1]).')';
        }

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
        if ($this->computed !== null || $this->orFrom !== null) {
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

    private static function keyOf(mixed $key): string
    {
        return is_int($key) || is_string($key) ? (string) $key : throw new LogicException('A successor handed to a computedAs() read has no key, so the read cannot key its value.');
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
