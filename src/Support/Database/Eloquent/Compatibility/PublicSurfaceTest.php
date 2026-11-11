<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;
use SplFileInfo;
use Tests\TestCase;

#[CoversNothing]
final class PublicSurfaceTest extends TestCase
{
    private const string NAMESPACE = 'Support\\Database\\Eloquent\\Compatibility\\';

    private const array DEFERRED = ['Definition\\Attribute' => ['position']];

    #[Test]
    public function it_leaves_exactly_the_classes_the_surface_lists_without_internal(): void
    {
        $unmarked = [];

        foreach (self::shipped() as $class) {
            $reflection = new ReflectionClass($class);

            if (! self::internal($reflection->getDocComment())) {
                $unmarked[] = str_starts_with(
                    haystack: $class,
                    needle: self::NAMESPACE,
                ) ? substr($class, strlen(self::NAMESPACE)) : $class;
            }
        }

        $listed = self::listedClasses();
        sort($unmarked);
        sort($listed);

        $this->assertSame($listed, $unmarked);
    }

    #[Test]
    public function it_marks_every_unlisted_public_method_of_a_published_map_class_internal_and_no_listed_one(): void
    {
        $misplaced = [];

        foreach (self::listedMethods() as $class => $listed) {
            foreach ((new ReflectionClass(self::NAMESPACE.$class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getDeclaringClass()->getName() !== self::NAMESPACE.$class) {
                    continue;
                }

                $internal = self::internal($method->getDocComment());

                if (in_array(
                    needle: $method->getName(),
                    haystack: $listed,
                    strict: true,
                )) {
                    if ($internal) {
                        $misplaced[] = "{$class}::{$method->getName()}() is listed and carries @internal";
                    }
                } elseif (! $internal) {
                    $misplaced[] = "{$class}::{$method->getName()}() is unlisted and carries no @internal";
                }
            }
        }

        $this->assertSame([], $misplaced);
    }

    #[Test]
    public function it_declares_every_method_the_surface_lists_or_names_internal(): void
    {
        $named = self::listedMethods();
        $named['Compatibility'] = [...$named['Compatibility'], ...self::namedInternal()];
        $missing = [];

        foreach ($named as $class => $methods) {
            foreach ($methods as $method) {
                if (! method_exists(self::NAMESPACE.$class, $method) && ! in_array(
                    needle: $method,
                    haystack: self::DEFERRED[$class] ?? [],
                    strict: true,
                )) {
                    $missing[] = "{$class}::{$method}()";
                }
            }
        }

        $this->assertSame([], $missing);
    }

    /**
     * @return list<string>
     */
    private static function shipped(): array
    {
        $source = dirname(__DIR__, 4);
        $classes = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS)) as $file) {
            if (! $file instanceof SplFileInfo || ! fnmatch('*.php', $file->getFilename()) || fnmatch('*Test.php', $file->getFilename()) || fnmatch('*TestCases.php', $file->getFilename())) {
                continue;
            }

            $classes[] = str_replace(
                search: ['/', '.php'],
                replace: ['\\', ''],
                subject: substr($file->getPathname(), strlen($source) + 1),
            );
        }

        return $classes;
    }

    /**
     * @return list<string>
     */
    private static function listedClasses(): array
    {
        preg_match_all('/^\| `([A-Za-z\\\\]+)` \|/m', self::section('## Classes'), $matches);

        return $matches[1];
    }

    /**
     * @return array<string, list<string>>
     */
    private static function listedMethods(): array
    {
        preg_match_all('/^\| `Compatibility::(\w+)\(/m', self::section('## Operations'), $operations);

        return [
            'Compatibility' => [...self::tabled('Compatibility'), ...$operations[1]],
            'Definition\\Successor' => self::tabled('Definition\\Successor'),
            'Definition\\Attribute' => self::tabled('Definition\\Attribute'),
            'Definition\\Targets' => self::tabled('Definition\\Targets'),
        ];
    }

    /**
     * @return list<string>
     */
    private static function tabled(string $class): array
    {
        $table = explode("\n#", explode("### `{$class}`", self::specification(), 2)[1], 2)[0];
        preg_match_all('/^\| (.+?) \| /m', $table, $rows);
        $names = [];

        foreach ($rows[1] as $row) {
            preg_match_all('/`(\w+)\(/', $row, $methods);
            $names = [...$names, ...$methods[1]];
        }

        return $names;
    }

    /**
     * @return list<string>
     */
    private static function namedInternal(): array
    {
        preg_match_all('/`(\w+)\(\)`/', explode('Every public method', self::section('## Internal'), 2)[0], $matches);

        return $matches[1];
    }

    private static function section(string $heading): string
    {
        return explode("\n## ", explode($heading, self::specification(), 2)[1], 2)[0];
    }

    private static function specification(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 5).'/docs/specification/public-surface.md');
    }

    private static function internal(false|string $docblock): bool
    {
        return $docblock !== false && preg_match('/^\s*(?:\/\*\*|\*)\s*@internal\b/m', $docblock) === 1;
    }
}
