<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Definition\Conversions;

use Closure;
use InvalidArgumentException;

final readonly class IsNotNull implements Conversion
{
    /**
     * @param  Closure(): mixed  $setTo
     */
    public function __construct(public Closure $setTo) {}

    public function read(string $stored): string
    {
        return '('.$stored.' IS NOT NULL)';
    }

    public function write(mixed $value): mixed
    {
        $set = self::boolean($value) ?? throw new InvalidArgumentException(var_export($value, true).' is not a boolean, so isNotNull() cannot store it.');

        return $set ? ($this->setTo)() : null;
    }

    public function filter(string $operator, mixed $value, null|string $type = null): null|array
    {
        if (! in_array($operator, ['=', '!=', '<>'], true)) {
            return null;
        }

        $equal = $operator === '=';

        if ($value === null) {
            return [$equal ? 'none' : 'all', null];
        }

        $set = self::boolean($value);

        return $set === null ? null : [$set === $equal ? '!=' : '=', null];
    }

    public function order(string $direction): null|string
    {
        return null;
    }

    public function type(null|string $type): string
    {
        return 'boolean';
    }

    public function exact(null|string $type, string $served): bool
    {
        return in_array($served, ['boolean', 'bool'], true);
    }

    public static function boolean(mixed $value): null|bool
    {
        if ($value === null || is_bool($value)) {
            return $value ?? false;
        }

        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return null;
        }

        return match (strtolower(trim((string) $value))) {
            '1', 'true', 'on', 'yes' => true,
            '0', 'false', 'off', 'no', '' => false,
            default => null,
        };
    }
}
