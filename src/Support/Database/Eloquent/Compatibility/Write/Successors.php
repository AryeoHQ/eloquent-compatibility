<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Write;

use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * @template TRoot of Model
 */
final readonly class Successors
{
    /**
     * @param  Staging<TRoot>  $staging
     */
    public function __construct(private Staging $staging) {}

    /**
     * @return TRoot
     */
    public function root(): Model
    {
        return $this->staging->root();
    }

    /**
     * @template TRelated of Model
     *
     * @param  class-string<TRelated>  $class
     * @return null|TRelated
     */
    public function related(string $class): null|Model
    {
        return $this->staging->related($class);
    }

    /**
     * @template TRelated of Model
     *
     * @param  class-string<TRelated>  $class
     * @return TRelated
     */
    public function relatedOrNew(string $class): Model
    {
        return $this->staging->relatedOrNew($class);
    }

    /**
     * @param  class-string<Model>  $class
     */
    public function deleteRelated(string $class): void
    {
        $this->staging->deleteRelated($class);
    }

    /**
     * @param  Closure(): mixed  $action
     */
    public function afterSave(Closure $action): void
    {
        $this->staging->afterSave($action);
    }
}
