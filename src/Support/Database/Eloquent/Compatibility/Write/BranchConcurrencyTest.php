<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Write;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Support\Database\Eloquent\Compatibility\Compatibility;
use Support\Database\Eloquent\Compatibility\Contracts\Compatible;
use Support\Database\Eloquent\Compatibility\Definition\Attribute;
use Support\Database\Eloquent\Compatibility\Definition\Identifier;
use Support\Database\Eloquent\Compatibility\Definition\Successor;
use Support\Database\Eloquent\Compatibility\Exceptions\CompatibilityWriteRefused;
use Support\Database\Eloquent\Compatibility\Provides\ServesCompatibility;
use Tests\Fixtures\Support\Files\File;
use Tests\Fixtures\Support\Files\FileTables;
use Tests\Fixtures\Support\Files\Link;
use Tests\Fixtures\Support\Files\Video;
use Tests\TestCase;

#[CoversClass(RecordWriter::class)]
#[CoversClass(MassWriter::class)]
#[CoversClass(BranchMove::class)]
final class BranchConcurrencyTest extends TestCase
{
    /** @var list<string> */
    protected $connectionsToTransact = [];

    /** @var null|array{resource, array<int, resource>} */
    private null|array $concurrent = null;

    protected function setUp(): void
    {
        parent::setUp();

        FileTables::create();
        FileTables::seed();
        FileTables::seedMedia();
        FileTables::install();
        FileTables::installMedia();
    }

    protected function tearDown(): void
    {
        if ($this->concurrent !== null) {
            proc_terminate($this->concurrent[0]);
            proc_close($this->concurrent[0]);
            $this->concurrent = null;
        }

        FileTables::drop();

        parent::tearDown();
    }

    /**
     * @return iterable<string, array{Closure(Video): mixed}>
     */
    public static function writesDuringAMove(): iterable
    {
        yield 'a record update' => [fn (Video $video) => $video->update(['original_file_name' => 'oak-v2.mp4', 'title' => 'Oak upload'])];
        yield 'a mass update' => [fn (Video $video) => Video::query()->whereKey($video->id)->update(['original_file_name' => 'oak-v2.mp4', 'title' => 'Oak upload'])];
    }

    /**
     * @param  Closure(Video): mixed  $write
     */
    #[DataProvider('writesDuringAMove')]
    #[Test]
    public function it_writes_a_record_another_legacy_write_moves_between_successors_while_the_write_waits(Closure $write): void
    {
        $video = Video::query()->findOrFail(2);
        $moved = $this->writeConcurrently(Video::class.'::query()->findOrFail(2)->update(["youtube_embed_url" => null, "original_file_name" => "oak.mp4", "mime_type" => "video/mp4"]);');

        $write($video);

        $this->assertSame(["written\n", ''], [$moved, $this->concurrentFailure()]);
        $this->assertSame(['oak-v2.mp4', 'Oak upload', null], [Video::query()->findOrFail(2)->original_file_name, Video::query()->findOrFail(2)->title, Video::query()->findOrFail(2)->youtube_embed_url]);
        $this->assertSame([1, 0], [DB::table('files')->where('legacy_type', 'video')->where('legacy_id', 2)->count(), DB::table('links')->where('legacy_type', 'video')->where('legacy_id', 2)->count()]);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function guardedWrites(): iterable
    {
        yield 'an update its successor keeps' => [['title' => 'Tour v2']];
        yield 'an update that moves it' => [['youtube_embed_url' => 'https://youtu.be/tour']];
    }

    /**
     * @param  array<string, mixed>  $values
     */
    #[DataProvider('guardedWrites')]
    #[Test]
    public function it_runs_a_guard_against_the_retained_values_a_concurrent_write_committed_for_the_legacy_key(array $values): void
    {
        $video = new class extends Model implements Compatible
        {
            use ServesCompatibility;

            protected $table = 'videos';

            protected $guarded = [];

            public function getMorphClass()
            {
                return 'video';
            }

            public static function compatibility(Compatibility $video): Compatibility
            {
                return $video
                    ->discriminatedBy('legacy_type')
                    ->identifiedBy('id', fn (Identifier $identifier) => $identifier->integer()->from('legacy_id'))
                    ->identifiedBy('uuid', fn (Identifier $identifier) => $identifier->uuid()->from('legacy_uuid'))
                    ->retainedIn('videos_retained')
                    ->timestamps()
                    ->guard('held video', fn (Model $after, Model $before) => $before->getAttribute('views') === 99, because: 'A held video keeps its title and source.', instead: 'Release the hold first.')
                    ->servedFrom(fn (Model $video): string => $video->getAttribute('youtube_embed_url') !== null ? Link::class : File::class)
                    ->as(File::class, fn (Successor $file) => $file
                        ->attribute('original_file_name', fn (Attribute $attribute) => $attribute->from('filename'))
                        ->attribute('mime_type', fn (Attribute $attribute) => $attribute->from('mime_type'))
                        ->attribute('size_kb', fn (Attribute $attribute) => $attribute->from('size_bytes')->dividedBy(1000))
                        ->attribute('youtube_embed_url', fn (Attribute $attribute) => $attribute->fixed(null)))
                    ->as(Link::class, fn (Successor $link) => $link
                        ->attribute('original_file_name', fn (Attribute $attribute) => $attribute->fixed(null))
                        ->attribute('mime_type', fn (Attribute $attribute) => $attribute->fixed(null))
                        ->attribute('size_kb', fn (Attribute $attribute) => $attribute->fixed(null))
                        ->attribute('youtube_embed_url', fn (Attribute $attribute) => $attribute->from('url')));
            }
        };
        $record = $video->newQuery()->findOrFail(1);
        $locked = $this->lockConcurrently('update videos_retained set views = 99 where id = 1');

        try {
            $record->update($values);
            $this->fail('The write was not refused.');
        } catch (CompatibilityWriteRefused $refusal) {
            $this->assertStringEndsWith(" record 1 cannot take this write, because guard 'held video' refuses it. Why: A held video keeps its title and source. Instead: Release the hold first.", $refusal->getMessage());
        }

        $this->assertSame(["locked\n", ''], [$locked, $this->concurrentFailure()]);
        $this->assertSame(['Tour', 99, null], [Video::query()->findOrFail(1)->title, Video::query()->findOrFail(1)->views, Video::query()->findOrFail(1)->youtube_embed_url]);
    }

    #[Test]
    public function it_leaves_a_record_a_concurrent_retained_write_takes_out_of_a_mass_update_filter_before_the_update_commits(): void
    {
        $locked = $this->lockConcurrently("update videos_retained set title = 'Retitled' where id = 1");

        $updated = Video::query()->where('title', 'Tour')->update(['mime_type' => 'video/webm']);

        $this->assertSame(["locked\n", ''], [$locked, $this->concurrentFailure()]);
        $this->assertSame([0, 'Retitled', 'video/mp4'], [$updated, Video::query()->findOrFail(1)->title, Video::query()->findOrFail(1)->mime_type]);
    }

    /**
     * @return iterable<string, array{Closure(Video): mixed}>
     */
    public static function retainedExpressionWrites(): iterable
    {
        yield 'a record update' => [fn (Video $video) => $video->update(['title' => DB::raw("title || '!'")])];
        yield 'a record increment' => [fn (Video $video) => $video->increment('views', 1, ['title' => DB::raw("title || '!'")])];
    }

    /**
     * @param  Closure(Video): mixed  $write
     */
    #[DataProvider('retainedExpressionWrites')]
    #[Test]
    public function it_evaluates_a_retained_expression_of_a_record_write_against_the_value_a_concurrent_retained_write_commits(Closure $write): void
    {
        $video = Video::query()->findOrFail(1);
        $locked = $this->lockConcurrently("update videos_retained set title = 'Retitled' where id = 1");

        $write($video);

        $this->assertSame(["locked\n", ''], [$locked, $this->concurrentFailure()]);
        $this->assertSame(['Retitled!', 'Retitled!'], [$video->title, Video::query()->findOrFail(1)->title]);
    }

    private function writeConcurrently(string $write): string
    {
        $concurrent = 'require '.var_export(dirname(__DIR__, 6).'/vendor/autoload.php', true).';'
            .' Orchestra\Testbench\Foundation\Application::create();'
            .' Illuminate\Support\Facades\DB::transaction(function (): void { '.$write.' echo "written\n"; fflush(STDOUT); sleep(2); });'
            .' Illuminate\Support\Facades\DB::disconnect();';

        return $this->start($concurrent);
    }

    private function lockConcurrently(string $sql): string
    {
        $config = DB::connection()->getConfig();
        $dsn = 'pgsql:host='.$config['host'].';port='.$config['port'].';dbname='.DB::connection()->getDatabaseName();

        return $this->start('$pdo = new PDO('.var_export($dsn, true).', '.var_export($config['username'], true).', '.var_export($config['password'], true).');'
            .' $pdo->beginTransaction(); $pdo->exec('.var_export($sql, true).'); echo "locked\n"; fflush(STDOUT); sleep(2); $pdo->commit();');
    }

    private function start(string $concurrent): string
    {
        $process = proc_open([PHP_BINARY, '-r', $concurrent], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['DB_DATABASE' => DB::connection()->getDatabaseName()] + getenv());

        if ($process === false) {
            $this->fail('The concurrent writer did not start.');
        }

        $this->concurrent = [$process, $pipes];

        return (string) fgets($pipes[1]);
    }

    private function concurrentFailure(): string
    {
        if ($this->concurrent === null) {
            $this->fail('No concurrent writer is running.');
        }

        [$process, $pipes] = $this->concurrent;
        $failure = (string) stream_get_contents($pipes[2]).(string) stream_get_contents($pipes[1]);
        proc_close($process);
        $this->concurrent = null;

        return $failure;
    }
}
