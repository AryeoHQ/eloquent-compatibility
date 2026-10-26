<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

$root = dirname(__DIR__);
$package = 'Support\\Database\\Eloquent\\Compatibility\\';
$external = [
    'Connection' => Illuminate\Database\Connection::class,
    'Event' => Illuminate\Support\Facades\Event::class,
    'EventFake' => Illuminate\Support\Testing\Fakes\EventFake::class,
    'Looping' => Illuminate\Queue\Events\Looping::class,
    'Model' => Illuminate\Database\Eloquent\Model::class,
    'QueryException' => Illuminate\Database\QueryException::class,
    'QueryExecuted' => Illuminate\Database\Events\QueryExecuted::class,
];

$exists = fn (string $class): bool => class_exists($class) || interface_exists($class) || trait_exists($class);

$files = function (string $directory, string $pattern): array {
    if (! is_dir($directory)) {
        return [];
    }

    $paths = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file instanceof SplFileInfo && fnmatch($pattern, $file->getFilename())) {
            $paths[] = $file->getPathname();
        }
    }

    sort($paths);

    return $paths;
};

$classes = [];

foreach ([...$files($root.'/src', '*.php'), ...$files($root.'/tests', '*.php')] as $path) {
    $source = (string) file_get_contents($path);
    $namespace = preg_match('/^namespace\s+([^;]+);/m', $source, $match) === 1 ? $match[1].'\\' : '';
    preg_match_all('/^(?:(?:final|abstract|readonly)\s+)*(?:class|interface|trait|enum)\s+([A-Za-z_][A-Za-z0-9_]*)/m', $source, $declarations);

    foreach ($declarations[1] as $declared) {
        $classes[$declared][] = $namespace.$declared;
    }
}

$resolve = function (string $name) use ($classes, $external, $package, $exists): string {
    if (str_starts_with($name, '\\')) {
        return $exists(substr($name, 1)) ? substr($name, 1) : throw new UnexpectedValueException('names no class that exists');
    }

    if (str_contains($name, '\\')) {
        foreach ([$package.$name, $name] as $candidate) {
            if ($exists($candidate)) {
                return $candidate;
            }
        }

        throw new UnexpectedValueException("names no class: neither {$package}{$name} nor {$name} exists");
    }

    $declared = $classes[$name] ?? [];

    if (count($declared) > 1) {
        throw new UnexpectedValueException('matches more than one package class; name it relative to '.$package.' instead, as one of '.implode(', ', $declared));
    }

    if ($declared !== []) {
        return $exists($declared[0]) ? $declared[0] : throw new UnexpectedValueException("is declared as {$declared[0]}, which the autoloader cannot load");
    }

    return $external[$name] ?? throw new UnexpectedValueException('names no package class, and the external allowlist in bin/check-doc-names.php does not list it');
};

$checkMember = function (string $class, string $member, bool $called): void {
    $reflection = new ReflectionClass($class);
    $found = match (true) {
        $called => $reflection->hasMethod($member),
        str_starts_with($member, '$') => $reflection->hasProperty(substr($member, 1)),
        $member === 'class' => true,
        default => $reflection->hasConstant($member),
    };

    if (! $found) {
        throw new UnexpectedValueException("resolves to {$class}, which has no ".match (true) {
            $called => "method {$member}()",
            str_starts_with($member, '$') => "property {$member}",
            default => "case or constant {$member}",
        });
    }
};

$problems = [];
$names = 0;
$links = 0;

foreach ($external as $short => $class) {
    if (! $exists($class)) {
        $problems[] = "bin/check-doc-names.php: the external allowlist maps {$short} to {$class}, which does not exist";
    }
}

$documents = [$root.'/README.md', ...$files($root.'/docs', '*.md'), ...$files($root.'/src', 'README.md')];

foreach ($documents as $document) {
    $relative = substr($document, strlen($root) + 1);
    $fenced = false;

    foreach (explode("\n", (string) file_get_contents($document)) as $index => $line) {
        $at = $relative.':'.($index + 1).': ';

        if (preg_match('/[A-Za-z0-9_\/.-]*[A-Za-z0-9_]\.php:\d+/', $line, $citation) === 1) {
            $problems[] = $at."cites a line number ({$citation[0]}); name the class and method instead";
        }

        if (preg_match('/^\s*(?:```|~~~)/', $line) === 1) {
            $fenced = ! $fenced;

            continue;
        }

        if ($fenced) {
            continue;
        }

        preg_match_all('/(`+)(.+?)\1/', $line, $spans);

        foreach ($spans[2] as $code) {
            $code = trim($code);
            $references = [];

            if (preg_match('/^\\\\?(?:[A-Z][A-Za-z0-9_]*\\\\)*[A-Z][A-Za-z0-9_]*[a-z][A-Za-z0-9_]*$/', $code) === 1) {
                $references[] = [$code, null, false];
            }

            preg_match_all('/(?<![A-Za-z0-9_\\\\$>:])(\\\\?(?:[A-Z][A-Za-z0-9_]*\\\\)*[A-Z][A-Za-z0-9_]*)::(\$?[A-Za-z_][A-Za-z0-9_]*)(\()?/', $code, $members, PREG_SET_ORDER);

            foreach ($members as $member) {
                $references[] = [$member[1], $member[2], ($member[3] ?? '') === '('];
            }

            foreach ($references as [$name, $member, $called]) {
                $names++;
                $label = $member === null ? $name : $name.'::'.$member.($called ? '()' : '');

                try {
                    $class = $resolve($name);

                    if ($member !== null) {
                        $checkMember($class, $member, $called);
                    }
                } catch (UnexpectedValueException $e) {
                    $problems[] = $at."`{$label}` ".$e->getMessage();
                }
            }
        }

        $prose = (string) preg_replace('/(`+).+?\1/', '', $line);
        preg_match_all('/!?\[[^\]]*\]\(\s*<?([^)\s>]+)>?(?:\s+"[^"]*")?\s*\)/', $prose, $inline);
        preg_match_all('/^\s{0,3}\[[^\]]+\]:\s*<?([^\s>]+)>?/', $prose, $definitions);

        foreach ([...$inline[1], ...$definitions[1]] as $target) {
            if (preg_match('/^(?:[a-z][a-z0-9+.-]*:|#)/i', $target) === 1) {
                continue;
            }

            $links++;
            $path = rawurldecode((string) preg_replace('/[#?].*$/', '', $target));
            $resolved = str_starts_with($path, '/') ? $root.$path : dirname($document).'/'.$path;

            if (! file_exists($resolved)) {
                $problems[] = $at."links to {$target}, which does not exist";
            }
        }
    }
}

foreach ($problems as $problem) {
    fwrite(STDERR, $problem.PHP_EOL);
}

fwrite($problems === [] ? STDOUT : STDERR, sprintf(
    '%d %s in %d documents: %d names and %d relative links checked.'.PHP_EOL,
    count($problems),
    count($problems) === 1 ? 'problem' : 'problems',
    count($documents),
    $names,
    $links,
));

exit($problems === [] ? 0 : 1);
