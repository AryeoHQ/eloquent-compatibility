<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Write;

use Illuminate\Database\Eloquent\Model;

/**
 * One record of a previous model, read through its compatibility view.
 */
final readonly class PreviousRecord
{
    /**
     * @param  class-string<Model>  $model
     */
    public function __construct(
        public string $map,
        public string $model,
        public string $view,
        public string $keyColumn,
        public int|string $key,
    ) {}

    public function id(): string
    {
        return $this->view.'#'.$this->key;
    }
}
