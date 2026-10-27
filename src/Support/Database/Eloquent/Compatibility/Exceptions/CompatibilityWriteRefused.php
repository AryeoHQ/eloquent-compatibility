<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Exceptions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use PDOException;
use Support\Database\Eloquent\Compatibility\Mapping\ModelEvent;
use Support\Database\Eloquent\Compatibility\Schema\SqlState;
use Throwable;

final class CompatibilityWriteRefused extends CompatibilityException
{
    public function __construct(
        public readonly string $refused,
        public readonly string $because,
        public readonly string $instead,
        null|Throwable $previous = null,
    ) {
        parent::__construct("{$refused}. Why: {$because} Instead: {$instead}", 0, $previous);
    }

    public static function isRefusal(Throwable $e): bool
    {
        return $e instanceof self
            || ($e instanceof QueryException && (string) $e->getCode() === SqlState::Refused->value);
    }

    public static function fromQueryException(QueryException $e): null|self
    {
        if ((string) $e->getCode() !== SqlState::Refused->value) {
            return null;
        }

        $previous = $e->getPrevious();
        $text = $previous instanceof PDOException && is_array($previous->errorInfo)
            ? (string) ($previous->errorInfo[2] ?? $e->getMessage())
            : $e->getMessage();

        return new self(
            self::line($text, 'ERROR'),
            self::line($text, 'DETAIL'),
            self::line($text, 'HINT'),
            $e,
        );
    }

    public static function byMirroredListener(string $map, Model $destination, ModelEvent $event): self
    {
        $record = class_basename($destination).' '.self::key($destination);

        return new self(
            "Compatibility map '{$map}' refused this write, because a '{$event->value}' listener of {$record} returned false",
            "The write changed {$record} through the other side, and every listener of that side runs for it, so that side's rules refused the change.",
            'Make the write satisfy the rules of '.class_basename($destination).", or exclude the listener in the map's event mirror if it must not run for writes through the other side.",
        );
    }

    /**
     * @param  list<string>  $columns
     */
    public static function byMirroredChange(string $map, Model $destination, ModelEvent $event, array $columns): self
    {
        $record = class_basename($destination).' '.self::key($destination);

        return new self(
            "Compatibility map '{$map}' refused this write, because a '{$event->value}' listener of {$record} changed ".implode(', ', $columns),
            'Mirrored events fire after the write through the other side has reached the database, so a change a listener makes to the mirrored record cannot be stored.',
            'Make the change through '.class_basename($destination)." itself, or exclude the listener in the map's event mirror.",
        );
    }

    public static function byTransition(string $map, Model $destination, string $state, Throwable $previous): self
    {
        $record = class_basename($destination).' '.self::key($destination);

        return new self(
            "Compatibility map '{$map}' refused this write, because it changes {$state} of {$record} in a way its state machine cannot replay",
            'A state changes only through the trigger its #[Transition] names, so the event bridge replays that trigger for a write through the other side, and the replay failed: '.rtrim($previous->getMessage(), '.').'.',
            "Change {$state} to a state the stored state has a #[Transition] to, whose trigger allows the change and needs no constructor argument the container cannot resolve, or run the trigger on ".class_basename($destination).' itself.',
            $previous,
        );
    }

    private static function key(Model $model): string
    {
        $key = $model->getKey();

        return is_scalar($key) ? '#'.$key : '(new)';
    }

    private static function line(string $text, string $label): string
    {
        return preg_match('/'.$label.':\s+(.*?)\s*(?:\n|$)/', $text, $matches) === 1 ? $matches[1] : '';
    }
}
