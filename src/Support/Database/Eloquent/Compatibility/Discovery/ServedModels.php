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
    /** @var null|list<class-string<Model&Compatible>> */
    private null|array $models = null;

    public function __construct(
        private readonly string $cache,
        private readonly bool $scans,
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

        /** @var list<class-string<Model&Compatible>> */
        return $this->files->getRequire($this->cache);
    }

    /**
     * @return list<class-string<Model&Compatible>>
     */
    private function scan(): array
    {
        $models = [];

        foreach (ClassLoader::getRegisteredLoaders() as $vendor => $loader) {
            $vendor = realpath($vendor).DIRECTORY_SEPARATOR;

            foreach ($loader->getClassMap() as $class => $file) {
                $file = realpath($file);

                if ($file !== false && ! str_starts_with($file, $vendor) && $this->serves($class, $file)) {
                    $models[] = $class;
                }
            }
        }

        $models = array_values(array_unique($models));
        sort($models);

        return $models;
    }

    /**
     * @phpstan-assert-if-true class-string<Model&Compatible> $class
     */
    private function serves(string $class, string $file): bool
    {
        return str_contains((string) file_get_contents($file), 'ServesCompatibility')
            && class_exists($class)
            && ! (new ReflectionClass($class))->isAbstract()
            && in_array(ServesCompatibility::class, class_uses_recursive($class), true);
    }
}
