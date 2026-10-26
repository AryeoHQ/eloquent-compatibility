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
        public string $readFrom,
        public string $lockedTable,
        public string $keyColumn,
        public int|string $keyValue,
        public RecordRole $role,
    ) {}

    public function id(): string
    {
        return $this->readFrom.'#'.$this->keyValue;
    }
}
