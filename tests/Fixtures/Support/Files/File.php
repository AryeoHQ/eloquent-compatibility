<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Files;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property null|string $legacy_type
 * @property int $legacy_id
 * @property string $legacy_uuid
 * @property string $filename
 * @property string $mime_type
 * @property null|int $size_bytes
 * @property null|Carbon $created_at
 * @property null|Carbon $updated_at
 */
final class File extends Model
{
    use HasUuids;

    protected $table = 'files';

    protected $guarded = [];
}
