<?php

declare(strict_types=1);

namespace Tests\Fixtures\Tooling\Compatibility;

use Illuminate\Database\Eloquent\Model;
use Support\Database\Eloquent\Compatibility\Compatibility;
use Support\Database\Eloquent\Compatibility\Contracts\Compatible;
use Support\Database\Eloquent\Compatibility\Provides\ServesCompatibility;

final class AnonymousClasses
{
    /**
     * @return list<object>
     */
    public static function all(): array
    {
        return [
            new class extends Model implements Compatible
            {
                public static function compatibility(Compatibility $compatibility): Compatibility
                {
                    return $compatibility;
                }
            },
            new class extends Model
            {
                use ServesCompatibility;
            },
            new class implements Compatible
            {
                public static function compatibility(Compatibility $compatibility): Compatibility
                {
                    return $compatibility;
                }
            },
            new class extends ValidModel
            {
                /**
                 * @return array<string, mixed>
                 */
                protected function getAttributesForInsert()
                {
                    return [];
                }
            },
        ];
    }
}
