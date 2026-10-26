<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Bridge;

use Illuminate\Database\Eloquent\Model;

final readonly class AffectedRecord
{
    /**
     * @param  class-string<Model>  $model
     */
    public function __construct(
        public string $map,
        public string $model,
        public string $relation,
        public string $lockTable,
        public string $keyName,
        public int|string $key,
        public RecordRole $role,
    ) {}

    public function id(): string
    {
        return $this->relation.'#'.$this->key;
    }
}
