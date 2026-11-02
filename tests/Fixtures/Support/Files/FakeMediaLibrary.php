<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Files;

use Illuminate\Container\Container;
use Illuminate\Support\Str;
use LogicException;

final class FakeMediaLibrary
{
    /** @var list<array{string, list<string>}> */
    public array $calls = [];

    /** @var array<string, FileMetadata> */
    private array $assets = [];

    public static function bound(): self
    {
        $container = Container::getInstance();

        if (! $container->bound(self::class)) {
            $container->instance(self::class, new self);
        }

        $library = $container->make(self::class);

        return $library instanceof self ? $library : throw new LogicException(self::class.' is bound to '.get_debug_type($library).'.');
    }

    /**
     * @param  list<string>  $assets
     * @return array<string, FileMetadata>
     */
    public function fetch(array $assets): array
    {
        $this->calls[] = ['fetch', $assets];
        $found = [];

        foreach ($assets as $asset) {
            if (array_key_exists($asset, $this->assets)) {
                $found[$asset] = clone $this->assets[$asset];
            }
        }

        return $found;
    }

    public function store(FileMetadata $metadata): string
    {
        $asset = 'zml-'.Str::lower((string) Str::ulid());
        $this->calls[] = ['store', [$asset]];
        $this->assets[$asset] = clone $metadata;

        return $asset;
    }

    public function put(string $asset, FileMetadata $metadata): void
    {
        $this->calls[] = ['put', [$asset]];
        $this->assets[$asset] = clone $metadata;
    }

    public function stored(string $asset): null|FileMetadata
    {
        return array_key_exists($asset, $this->assets) ? clone $this->assets[$asset] : null;
    }
}
