<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\PlaceAssets;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Support\Database\Eloquent\Compatibility\Compatibility;
use Support\Database\Eloquent\Compatibility\Contracts\Compatible;
use Support\Database\Eloquent\Compatibility\Definition\Attribute;
use Support\Database\Eloquent\Compatibility\Definition\Successor;
use Support\Database\Eloquent\Compatibility\Definition\Targets;
use Support\Database\Eloquent\Compatibility\Provides\ServesCompatibility;

/**
 * @property int $id
 * @property string $uuid
 * @property null|string $title
 * @property string $url
 * @property null|Carbon $created_at
 * @property null|Carbon $updated_at
 */
final class InteractiveContent extends Model implements Compatible
{
    use ServesCompatibility;

    protected $table = 'place_interactive_content';

    protected $guarded = [];

    protected static function booted(): void
    {
        Relation::morphMap(['place_interactive_content' => self::class]);
    }

    public static function compatibility(Compatibility $interactiveContent): Compatibility
    {
        return $interactiveContent
            ->retainedIn('place_interactive_content_retained')
            ->successorsInitializedBy(ClassifyPlaceAsset::class)
            ->servedFrom(root: PlaceAsset::class, key: 'place_asset_id', successor: fn (Successor $asset) => $asset
                ->when(fn (Builder $assets) => $assets->where(column: 'legacy_type', operator: '=', value: 'place_interactive_content'))
                ->attribute(
                    name: 'created_at',
                    definition: fn (Attribute $attribute) => $attribute->from('created_at'),
                )
                ->attribute(
                    name: 'updated_at',
                    definition: fn (Attribute $attribute) => $attribute->from('updated_at'),
                )
                ->attribute(
                    name: 'title',
                    definition: fn (Attribute $attribute) => $attribute->from('title'),
                )
                ->relatedMorph(relation: fn (PlaceAsset $asset) => $asset->asset(),
                    choose: fn (InteractiveContent $content): string => AssetLink::class,
                    targets: fn (Targets $targets) => $targets
                        ->as(target: AssetLink::class, successor: fn (Successor $link) => $link
                            ->attribute(
                                name: 'url',
                                definition: fn (Attribute $attribute) => $attribute->from('url'),
                            ))));
    }
}
