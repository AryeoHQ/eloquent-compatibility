<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Definition\Conversions;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use Support\Database\Eloquent\Compatibility\Schema\SqlFragments;

/**
 * @internal
 */
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
        if (! in_array($operator, ['=', '!=', '<>', '<', '>', '<=', '>='], true) || ($value !== null && ! $value instanceof DateTimeInterface && ! self::isWallClock($value))) {
            return null;
        }

        return [$operator, $this->write($value)];
    }

    public function type(null|string $type): string
    {
        return 'timestamp without time zone';
    }

    public function exact(null|string $type, string $served): bool
    {
        $transitions = (new DateTimeZone($this->zone))->getTransitions();

        return SqlFragments::isZoned($type) && SqlFragments::isWallClock($served) && SqlFragments::precision($served) >= SqlFragments::precision((string) $type)
            && $transitions !== false && count($transitions) <= 1;
    }

    private static function isWallClock(mixed $value): bool
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}([ T]\d{2}:\d{2}(:\d{2}(\.\d{1,6})?)?)?$/', $value) !== 1) {
            return false;
        }

        $parsed = date_parse($value);

        return $parsed['error_count'] === 0 && $parsed['warning_count'] === 0;
    }
}
