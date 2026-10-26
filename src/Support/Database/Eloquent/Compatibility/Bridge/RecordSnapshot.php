<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Bridge;

final readonly class RecordSnapshot
{
    /**
     * @param  null|array<string, mixed>  $row
     */
    public function __construct(
        public AffectedRecord $record,
        public null|array $row,
    ) {}

    /**
     * @param  null|array<string, mixed>  $row
     */
    public function withRow(null|array $row): self
    {
        return new self($this->record, $row);
    }
}
