<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Workbench;

use Illuminate\Database\Eloquent\Casts\ArrayObject;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Casts\AsStringable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Stringable;
use Support\Database\Eloquent\Compatibility\Contracts\Compatible;
use Support\Database\Eloquent\Compatibility\Provides\HasCompatibility;

/**
 * @property int $id
 * @property null|string $publication_id
 * @property ArrayObject<array-key, mixed> $title
 * @property null|Stringable $slot
 */
final class CastBulletin extends Model implements Compatible
{
    use HasCompatibility;

    public $timestamps = false;

    protected $table = 'bulletins';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'title' => AsArrayObject::class,
            'slot' => AsStringable::class,
        ];
    }
}
