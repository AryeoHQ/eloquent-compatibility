<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Discovery;

use Composer\Autoload\ClassLoader;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Filesystem\Filesystem;
use ReflectionClass;
use Support\Database\Eloquent\Compatibility\Contracts\Compatible;
use Support\Database\Eloquent\Compatibility\Exceptions\UndiscoveredServedModel;
use Support\Database\Eloquent\Compatibility\Provides\ServesCompatibility;

final class ServedModels
{
    private const string PACKAGE = 'aryeo/eloquent-compatibility';

    /** @var null|list<class-string<Model&Compatible>> */
    private null|array $models = null;

    public function __construct(
        private readonly string $cache,
        private readonly bool $scans,
        private readonly bool $withoutCache,
        private readonly Filesystem $files,
    ) {}

    /**
     * @return list<class-string<Model&Compatible>>
     */
    public function all(): array
    {
        return $this->models ??= $this->scans ? $this->scan() : $this->cached();
    }

    public function contains(string $model): bool
    {
        return in_array($model, $this->all(), true);
    }

    public function admits(string $model): bool
    {
        return $this->withoutCache || $this->contains($model);
    }

    public function cache(): void
    {
        $this->files->ensureDirectoryExists(dirname($this->cache));
        $this->files->replace($this->cache, '<?php return '.var_export($this->scan(), true).';'.PHP_EOL);
    }

    public function clear(): void
    {
        $this->files->delete($this->cache);
    }

    /**
     * @return list<class-string<Model&Compatible>>
     */
    private function cached(): array
    {
        if (! $this->files->isFile($this->cache)) {
            throw UndiscoveredServedModel::withoutCache($this->cache);
        }

        /** @var list<class-string<Model&Compatible>> $models */
        $models = $this->files->getRequire($this->cache);

        return array_values(array_filter($models, fn (string $model) => class_exists($model)));
    }

    /**
     * @return list<class-string<Model&Compatible>>
     */
    private function scan(): array
    {
        $files = $this->classmap();
        $models = [];
        $checked = [];
        $mentions = '/ServesCompatibility/';

        while ($mentions !== null) {
            $inherited = [];

            foreach ($files as $class => [$file, $listed]) {
                if (array_key_exists($class, $checked) || preg_match($mentions, (string) file_get_contents($file)) !== 1) {
                    continue;
                }

                $checked[$class] = true;

                if (! (class_exists($class) || trait_exists($class)) || ! in_array(ServesCompatibility::class, class_uses_recursive($class), true)) {
                    continue;
                }

                $reflection = new ReflectionClass($class);

                if ($reflection->isTrait()) {
                    $inherited[] = '\buse\s+[^;]*\b'.preg_quote($reflection->getShortName(), '/').'\b';

                    continue;
                }

                if (! $reflection->isFinal()) {
                    $inherited[] = '\bextends\s+\\\\?(?:\w+\\\\)*'.preg_quote($reflection->getShortName(), '/').'\b|\b'.preg_quote($reflection->getShortName(), '/').'\s+as\s+\w';
                }

                if ($listed && ! $reflection->isAbstract() && is_subclass_of($class, Model::class) && is_subclass_of($class, Compatible::class)) {
                    $models[] = $class;
                }
            }

            $mentions = $inherited === [] ? null : '/'.implode('|', $inherited).'/';
        }

        sort($models);

        return $models;
    }

    /**
     * @return array<string, array{string, bool}>
     */
    private function classmap(): array
    {
        $files = [];

        foreach (ClassLoader::getRegisteredLoaders() as $vendor => $loader) {
            $real = realpath($vendor);
            $vendor = ($real === false ? $vendor : $real).DIRECTORY_SEPARATOR;
            [$paths, $dependents] = $this->packages($vendor);

            foreach ($loader->getClassMap() as $class => $file) {
                $real = realpath($file);

                if ($real === false) {
                    continue;
                }

                $vendored = str_starts_with($file, $vendor) || str_starts_with($real, $vendor);
                $within = fn (array $packages): bool => array_filter($packages, fn (string $package) => str_starts_with($real, $package)) !== [];

                if (! $vendored || $within($paths)) {
                    $files[$class] ??= [$real, true];
                } elseif ($within($dependents)) {
                    $files[$class] ??= [$real, false];
                }
            }
        }

        return $files;
    }

    /**
     * @return array{list<string>, list<string>}
     */
    private function packages(string $vendor): array
    {
        $installed = "{$vendor}composer/installed.json";

        if (! $this->files->isFile($installed)) {
            return [[], []];
        }

        $decoded = json_decode($this->files->get($installed), true);
        $packages = array_filter(is_array($decoded) ? $decoded['packages'] ?? $decoded : [], fn (mixed $package) => is_array($package) && is_string($package['name'] ?? null));
        $depends = [self::PACKAGE => true];

        do {
            $grown = false;

            foreach ($packages as $package) {
                if (! array_key_exists($package['name'], $depends) && array_intersect_key(is_array($package['require'] ?? null) ? $package['require'] : [], $depends) !== []) {
                    $depends[$package['name']] = $grown = true;
                }
            }
        } while ($grown);

        $paths = [];
        $dependents = [];

        foreach ($packages as $package) {
            $path = is_string($package['install-path'] ?? null) ? realpath("{$vendor}composer/{$package['install-path']}") : false;

            if ($path === false) {
                continue;
            }

            if (($package['dist']['type'] ?? null) === 'path') {
                $paths[] = $path.DIRECTORY_SEPARATOR;
            } elseif ($package['name'] !== self::PACKAGE && array_key_exists($package['name'], $depends)) {
                $dependents[] = $path.DIRECTORY_SEPARATOR;
            }
        }

        return [$paths, $dependents];
    }
}
