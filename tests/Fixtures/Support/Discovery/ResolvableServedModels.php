<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Discovery;

use Illuminate\Filesystem\Filesystem;
use Support\Database\Eloquent\Compatibility\Discovery\ServedModels;

final class ResolvableServedModels
{
    private const array UNRESOLVABLE = [InheritedServed::class, TraitServed::class];

    public static function of(ServedModels $served): ServedModels
    {
        $files = new Filesystem;
        $cache = (string) tempnam(
            directory: sys_get_temp_dir(),
            prefix: 'compatibility-served-',
        );
        $files->put(
            path: $cache,
            contents: '<?php return '.var_export(
                value: array_values(array_filter(
                    array: $served->all(),
                    callback: fn (string $model) => ! in_array(
                        needle: $model,
                        haystack: self::UNRESOLVABLE,
                        strict: true,
                    ),
                )),
                return: true,
            ).';',
        );
        $resolvable = new ServedModels(
            cache: $cache,
            scans: false,
            withoutCache: false,
            files: $files,
        );
        $resolvable->all();
        $files->delete($cache);

        return $resolvable;
    }
}
