<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\PlaceAssets;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection as Values;
use Tests\Fixtures\Support\Files\FakeMediaLibrary;
use Tests\Fixtures\Support\Files\FileMetadata;

/**
 * @property string $id
 * @property string $filename
 * @property null|string $mime_type
 * @property null|int $size_bytes
 * @property null|string $zml_asset_id
 * @property null|Carbon $created_at
 * @property null|Carbon $updated_at
 */
final class AssetFile extends Model
{
    use HasUuids;

    protected $table = 'asset_files';

    protected $guarded = [];

    private null|FileMetadata $metadata = null;

    /**
     * @param  Collection<array-key, AssetFile>  $files
     * @return Values<array-key, FileMetadata>
     */
    public static function metadataFor(Collection $files): Values
    {
        $found = FakeMediaLibrary::bound()->fetch(array_values(array_filter($files->map(fn (AssetFile $file) => $file->zml_asset_id)->all(), is_string(...))));

        return $files->toBase()->mapWithKeys(fn (AssetFile $file) => [$file->getKey() => $found[(string) $file->zml_asset_id] ?? new FileMetadata]);
    }

    public function metadata(): FileMetadata
    {
        return $this->metadata ??= $this->zml_asset_id === null ? new FileMetadata : FakeMediaLibrary::bound()->fetch([$this->zml_asset_id])[$this->zml_asset_id] ?? new FileMetadata;
    }

    protected static function booted(): void
    {
        Relation::morphMap(['place_asset_file' => self::class]);

        self::saving(function (AssetFile $file): void {
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
