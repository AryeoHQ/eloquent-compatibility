<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Workbench;

use Illuminate\Database\Eloquent\Model;
use Support\Database\Eloquent\Compatibility\Contracts\Compatible;
use Support\Database\Eloquent\Compatibility\Provides\HasCompatibility;

/**
 * @property int $id
 * @property null|string $publication_id
 * @property string $status
 * @property null|string $published_at
 * @property null|string $featured_at
 */
final class Article extends Model implements Compatible
{
    use HasCompatibility;

    protected $table = 'articles';

    protected $guarded = [];
}
