<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Files;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Support\Database\Eloquent\Compatibility\Compatibility;
use Support\Database\Eloquent\Compatibility\Contracts\Compatible;
use Support\Database\Eloquent\Compatibility\Definition\Attribute;
use Support\Database\Eloquent\Compatibility\Definition\Successor;
use Support\Database\Eloquent\Compatibility\Provides\ServesCompatibility;

/**
 * @property int $id
 * @property string $uuid
 * @property string $url
 * @property null|Carbon $created_at
 * @property null|Carbon $updated_at
 */
final class InteractiveContent extends Model implements Compatible
{
    use ServesCompatibility;

    protected $table = 'interactive_content';

    protected $guarded = [];

    protected static function booted(): void
    {
        Relation::morphMap(['interactive_content' => self::class]);
    }

    public static function compatibility(Compatibility $interactiveContent): Compatibility
    {
        return $interactiveContent
            ->retainedIn('interactive_content_retained')
            ->servedFrom(root: Link::class, key: 'link_id', successor: fn (Successor $link) => $link
                ->attribute(
                    name: 'created_at',
                    definition: fn (Attribute $attribute) => $attribute->from('created_at'),
                )
                ->attribute(
                    name: 'updated_at',
                    definition: fn (Attribute $attribute) => $attribute->from('updated_at'),
                )
                ->attribute(
                    name: 'url',
                    definition: fn (Attribute $attribute) => $attribute->from('url'),
                ));
    }
}
