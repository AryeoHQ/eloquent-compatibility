<?php

declare(strict_types=1);

namespace Tooling\Database\Eloquent\Compatibility;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversNothing]
final class CheckDocNamesTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/check-doc-names-'.bin2hex(random_bytes(8));
        mkdir(
            directory: $this->root.'/bin',
            recursive: true,
        );
    }

    protected function tearDown(): void
    {
        foreach (['/README.md', '/bin/doc-names-unresolved.php', '/tests/Listing.php'] as $file) {
            if (is_file($this->root.$file)) {
                unlink($this->root.$file);
            }
        }

        foreach (['/bin', '/tests', ''] as $directory) {
            if (is_dir($this->root.$directory)) {
                rmdir($this->root.$directory);
            }
        }

        parent::tearDown();
    }

    #[Test]
    public function it_passes_while_each_allowlisted_name_stays_unresolved_and_a_document_names_it(): void
    {
        $this->assertSame([0, "0 problems in 1 documents: 1 names, 1 allowlist entries, 0 relative links and 0 invariant references checked. 0 proposed documents skipped.\n", ''], $this->check(
            readme: 'The map replaces `Definition\Successors`.',
            unresolved: ['Definition\Successors' => 'a removed name'],
        ));
    }

    #[Test]
    public function it_resolves_no_name_against_a_class_its_tests_declare(): void
    {
        mkdir($this->root.'/tests');
        file_put_contents(
            filename: $this->root.'/tests/Listing.php',
            data: "<?php\n\nnamespace Tests\\Fixtures\\Support\\Listings;\n\nfinal class Listing {}\n",
        );

        $this->assertSame([0, "0 problems in 1 documents: 1 names, 1 allowlist entries, 0 relative links and 0 invariant references checked. 0 proposed documents skipped.\n", ''], $this->check(
            readme: 'Aryeo serves `Listing` from its map.',
            unresolved: ['Listing' => 'an illustrative Aryeo name'],
        ));
    }

    #[Test]
    public function it_fails_once_an_allowlisted_name_resolves(): void
    {
        $this->assertSame([1, '', "README.md:1: `Definition\\Targets` now resolves, so the unresolved allowlist in bin/doc-names-unresolved.php must drop it\n1 problem in 1 documents: 1 names, 1 allowlist entries, 0 relative links and 0 invariant references checked. 0 proposed documents skipped.\n"], $this->check(
            readme: 'The map declares its targets through `Definition\Targets`.',
            unresolved: ['Definition\Targets' => 'a removed name'],
        ));
    }

    #[Test]
    public function it_fails_while_no_checked_document_names_an_allowlisted_name(): void
    {
        $this->assertSame([1, '', "bin/doc-names-unresolved.php: the unresolved allowlist lists `Definition\\Successors` (a removed name), which no checked document names\n1 problem in 1 documents: 0 names, 0 allowlist entries, 0 relative links and 0 invariant references checked. 0 proposed documents skipped.\n"], $this->check(
            readme: 'The map declares its targets.',
            unresolved: ['Definition\Successors' => 'a removed name'],
        ));
    }

    /**
     * @param  array<string, string>  $unresolved
     * @return array{int, string, string}
     */
    private function check(string $readme, array $unresolved): array
    {
        file_put_contents(
            filename: $this->root.'/README.md',
            data: $readme."\n",
        );
        file_put_contents(
            filename: $this->root.'/bin/doc-names-unresolved.php',
            data: "<?php\n\nreturn ".var_export(
                value: $unresolved,
                return: true,
            ).";\n",
        );
        $process = proc_open(
            command: [PHP_BINARY, dirname(__DIR__, 5).'/bin/check-doc-names.php', $this->root],
            descriptor_spec: [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            pipes: $pipes,
        );

        if ($process === false) {
            $this->fail('bin/check-doc-names.php did not start.');
        }

        $output = (string) stream_get_contents($pipes[1]);
        $errors = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $output, $errors];
    }
}
