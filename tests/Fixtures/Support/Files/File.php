<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Files;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection as Values;

/**
 * @property string $id
 * @property null|string $legacy_type
 * @property int $legacy_id
 * @property string $legacy_uuid
 * @property string $filename
 * @property string $mime_type
 * @property null|int $size_bytes
 * @property null|string $zml_asset_id
 * @property null|Carbon $created_at
 * @property null|Carbon $updated_at
 */
final class File extends Model
{
    use HasUuids;

    protected $table = 'files';

    protected $guarded = [];

    private null|FileMetadata $metadata = null;

    /**
     * @param  Collection<array-key, File>  $files
     * @return Values<array-key, FileMetadata>
     */
    public static function metadataFor(Collection $files): Values
    {
        $found = FakeMediaLibrary::bound()->fetch(array_values(array_filter($files->map(fn (File $file) => $file->zml_asset_id)->all(), is_string(...))));

        return $files->toBase()->mapWithKeys(fn (File $file) => [$file->getKey() => $found[(string) $file->zml_asset_id] ?? new FileMetadata]);
    }

    public function metadata(): FileMetadata
    {
        return $this->metadata ??= $this->zml_asset_id === null ? new FileMetadata : FakeMediaLibrary::bound()->fetch([$this->zml_asset_id])[$this->zml_asset_id] ?? new FileMetadata;
    }

    protected static function booted(): void
    {
        self::saving(function (File $file): void {
            if ($file->metadata === null) {
                return;
            }

            $library = FakeMediaLibrary::bound();

            if ($file->zml_asset_id === null) {
                $file->zml_asset_id = $library->store($file->metadata);
            } else {
                $library->put($file->zml_asset_id, $file->metadata);
            }

            $file->setAttribute('mime_type', $file->metadata->mimeType);
        });
    }
}
