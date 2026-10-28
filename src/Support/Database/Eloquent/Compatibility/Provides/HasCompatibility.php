<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Provides;

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Support\Database\Eloquent\Compatibility\Bridge\EventBridge;
use Support\Database\Eloquent\Compatibility\Contracts\Compatible;

/**
 * @phpstan-require-extends Model
 *
 * @phpstan-require-implements Compatible
 */
trait HasCompatibility
{
    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    protected function setKeysForSaveQuery($query)
    {
        parent::setKeysForSaveQuery($query);
        $this->compatibilityEventBridge()?->markOwnStatement($this, $query->getQuery());

        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getAttributesForInsert()
    {
        $attributes = parent::getAttributesForInsert();
        $this->compatibilityEventBridge()?->markOwnStatement($this);

        return $attributes;
    }

    /**
     * @param  string  $event
     * @param  bool  $halt
     * @return mixed
     */
    protected function fireModelEvent($event, $halt = true)
    {
        $bridge = $this->compatibilityEventBridge();

        if ($bridge === null) {
            return parent::fireModelEvent($event, $halt);
        }

        return $bridge->fireModelEvent($this, $event, fn () => parent::fireModelEvent($event, $halt));
    }

    private function compatibilityEventBridge(): null|EventBridge
    {
        $container = Container::getInstance();

        return $container->resolved(EventBridge::class) ? $container->make(EventBridge::class) : null;
    }
}
