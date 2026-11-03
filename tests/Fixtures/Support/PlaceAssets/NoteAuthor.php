<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\PlaceAssets;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $asset_note_id
 * @property null|string $name
 */
final class NoteAuthor extends Model
{
    use HasUuids;

    protected $table = 'note_authors';

    protected $guarded = [];
}
