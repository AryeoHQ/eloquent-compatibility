<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Definition\Conversions;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use Support\Database\Eloquent\Compatibility\Schema\SqlFragments;

final readonly class InTimeZone implements Conversion
{
    public function __construct(public string $zone) {}

    public function read(string $stored): string
    {
        return '('.$stored.' AT TIME ZONE '.SqlFragments::literal($this->zone).')';
    }

    public function write(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        $wallClock = match (true) {
            $value instanceof DateTimeInterface => $value->format('Y-m-d H:i:s.u'),
            is_string($value) => $value,
            default => throw new InvalidArgumentException(var_export($value, true).' is not a date and time in '.$this->zone.'.'),
        };

        return (new DateTimeImmutable($wallClock, new DateTimeZone($this->zone)))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s.uP');
    }

    public function filter(string $operator, mixed $value, null|string $type = null): null|array
    {
        return in_array($operator, ['=', '!=', '<>', '<', '>', '<=', '>='], true) ? [$operator, $this->write($value)] : null;
    }

    public function order(string $direction): string
    {
        return strtolower($direction);
    }

    public function type(null|string $type): string
    {
        return 'timestamp without time zone';
    }

    public function exact(null|string $type, string $served): bool
    {
        return SqlFragments::isZoned($type) && SqlFragments::isWallClock($served) && SqlFragments::precision($served) >= SqlFragments::precision((string) $type);
    }
}
