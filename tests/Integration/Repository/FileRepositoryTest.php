<?php

declare(strict_types=1);

namespace Contenir\Maintenance\Tests\Integration\Repository;

use Contenir\Config\Exception\WriteException;
use Contenir\Maintenance\MaintenanceState;
use Contenir\Maintenance\Repository\FileRepository;
use Contenir\Maintenance\Tests\Trait\TemporaryDirectoryTrait;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function file_put_contents;
use function glob;
use function mkdir;
use function sprintf;
use function var_export;

#[Group('integration')]
#[Group('repository')]
final class FileRepositoryTest extends TestCase
{
    use TemporaryDirectoryTrait;

    /**
     * @return array<string, array{array<array-key, mixed>}>
     */
    public static function inactiveConfigProvider(): array
    {
        return [
            'namespace missing'        => [['somethingelse' => ['stuff' => 'here']]],
            'state missing'            => [['maintenance' => ['file' => '/tmp/x']]],
            'state not an array'       => [['maintenance' => ['state' => true]]],
            'active flag missing'      => [['maintenance' => ['state' => ['message' => 'm']]]],
            'active flag false'        => [['maintenance' => ['state' => ['active' => false, 'message' => 'stale']]]],
            'active flag falsy string' => [['maintenance' => ['state' => ['active' => '0', 'message' => 'stale']]]],
            'active flag zero'         => [['maintenance' => ['state' => ['active' => 0]]]],
        ];
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function messageProvider(): array
    {
        return [
            'string'  => ['Down for upgrade', 'Down for upgrade'],
            'missing' => [null, ''],
            'integer' => [503, '503'],
            'array'   => [['nested'], ''],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unusableFileProvider(): array
    {
        return [
            'not an array'       => ["<?php\n\nreturn 'not an array';\n"],
            'parse error'        => ["<?php\n\nreturn [\n"],
            'empty array'        => ["<?php\n\nreturn [];\n"],
            'namespace a string' => ["<?php\n\nreturn ['maintenance' => 'on'];\n"],
        ];
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function unusableSinceProvider(): array
    {
        return [
            'missing'      => [null],
            'empty string' => [''],
            'not a date'   => ['not-a-date'],
            'not a string' => [1_700_000_000],
        ];
    }

    #[Test]
    public function createsMissingParentDirectories(): void
    {
        $nested = $this->path('nested/inner/maintenance.local.php');

        (new FileRepository($nested))->save(MaintenanceState::active('hi'));

        static::assertTrue((new FileRepository($nested))->get()->active);
    }

    #[Test]
    public function leavesNoTemporaryFilesBehind(): void
    {
        (new FileRepository($this->path()))->save(MaintenanceState::active('first'));

        static::assertSame([$this->path()], glob("{$this->tmpDir}/*"));
    }

    #[Test]
    public function preservesSiblingKeysWithinTheMaintenanceNamespace(): void
    {
        $this->writeConfig(['maintenance' => ['file' => '/some/path.php', 'retry_after' => 600]]);

        (new FileRepository($this->path()))->save(MaintenanceState::inactive());

        static::assertSame(
            [
                'maintenance' => [
                    'file'        => '/some/path.php',
                    'retry_after' => 600,
                    'state'       => ['active' => false, 'message' => '', 'since' => null],
                ],
            ],
            include $this->path(),
        );
    }

    #[Test]
    public function preservesUnmanagedTopLevelKeys(): void
    {
        $this->writeConfig([
            'pagecache' => ['options' => ['cache' => false]],
            'errors'    => ['pages' => [404 => ['title' => 'Lost']]],
        ]);

        (new FileRepository($this->path()))->save(MaintenanceState::active('Down', new DateTimeImmutable('@0')));

        static::assertSame(
            [
                'pagecache'   => ['options' => ['cache' => false]],
                'errors'      => ['pages' => [404 => ['title' => 'Lost']]],
                'maintenance' => ['state' => [
                    'active'  => true,
                    'message' => 'Down',
                    'since'   => '1970-01-01T00:00:00+00:00',
                ]],
            ],
            include $this->path(),
        );
    }

    #[Test]
    public function readsAnActiveStateWithItsMessageAndSince(): void
    {
        $this->writeConfig([
            'maintenance' => [
                'state' => [
                    'active'  => true,
                    'message' => 'Down for upgrade',
                    'since'   => '2026-01-02T03:04:05+00:00',
                ],
            ],
        ]);

        $state = (new FileRepository($this->path()))->get();

        static::assertEquals(
            new MaintenanceState(
                active: true,
                message: 'Down for upgrade',
                since: new DateTimeImmutable('2026-01-02T03:04:05+00:00'),
            ),
            $state,
        );
    }

    #[Test]
    #[DataProvider('messageProvider')]
    public function readsTheMessageAsAString(mixed $stored, string $expected): void
    {
        $this->writeConfig(['maintenance' => ['state' => ['active' => true, 'message' => $stored]]]);

        $state = (new FileRepository($this->path()))->get();

        static::assertSame($expected, $state->message);
    }

    #[Test]
    public function replacesAMaintenanceNamespaceThatIsNotAnArray(): void
    {
        $this->writeConfig(['maintenance' => 'on']);

        (new FileRepository($this->path()))->save(MaintenanceState::inactive());

        static::assertSame(
            ['maintenance' => ['state' => ['active' => false, 'message' => '', 'since' => null]]],
            include $this->path(),
        );
    }

    #[Test]
    public function returnsInactiveWhenTheFileIsMissing(): void
    {
        $state = (new FileRepository($this->path()))->get();

        static::assertEquals(MaintenanceState::inactive(), $state);
    }

    #[Test]
    #[DataProvider('unusableFileProvider')]
    public function returnsInactiveWhenTheFileIsUnusable(string $contents): void
    {
        file_put_contents($this->path(), $contents);

        $state = (new FileRepository($this->path()))->get();

        static::assertEquals(MaintenanceState::inactive(), $state);
    }

    /**
     * @param array<array-key, mixed> $config
     */
    #[Test]
    #[DataProvider('inactiveConfigProvider')]
    public function returnsInactiveWithoutAStaleMessageWhenTheStateIsNotActive(array $config): void
    {
        $this->writeConfig($config);

        $state = (new FileRepository($this->path()))->get();

        static::assertEquals(MaintenanceState::inactive(), $state);
    }

    #[Test]
    public function roundTripsAnActiveState(): void
    {
        $saved = MaintenanceState::active('Back at 5pm', new DateTimeImmutable('2026-04-01T12:00:00+00:00'));

        (new FileRepository($this->path()))->save($saved);

        static::assertEquals($saved, (new FileRepository($this->path()))->get());
    }

    #[Test]
    public function roundTripsAnInactiveStateOverAnActiveOne(): void
    {
        $repository = new FileRepository($this->path());
        $repository->save(MaintenanceState::active('temp'));

        $repository->save(MaintenanceState::inactive());

        static::assertEquals(MaintenanceState::inactive(), (new FileRepository($this->path()))->get());
    }

    #[Test]
    public function throwsALabelledWriteExceptionWhenTheDirectoryIsNotWritable(): void
    {
        $this->skipWhenRunningAsRoot();
        mkdir($this->path('locked'), permissions: 0o555);
        $file = $this->path('locked/maintenance.local.php');

        $this->expectException(WriteException::class);
        $this->expectExceptionMessage(sprintf('Cannot write maintenance state to "%s".', $file));

        (new FileRepository($file))->save(MaintenanceState::active('x'));
    }

    #[Test]
    #[DataProvider('unusableSinceProvider')]
    public function treatsAnUnusableSinceAsUnknown(mixed $since): void
    {
        $this->writeConfig(['maintenance' => ['state' => ['active' => true, 'message' => 'down', 'since' => $since]]]);

        $state = (new FileRepository($this->path()))->get();

        static::assertNull($state->since);
    }

    #[Test]
    public function writesTheNamespacedFileFormat(): void
    {
        $since = new DateTimeImmutable('2026-04-01T12:00:00+00:00');

        (new FileRepository($this->path()))->save(MaintenanceState::active('Hi', $since));

        static::assertSame(
            [
                'maintenance' => [
                    'state' => [
                        'active'  => true,
                        'message' => 'Hi',
                        'since'   => '2026-04-01T12:00:00+00:00',
                    ],
                ],
            ],
            include $this->path(),
        );
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTemporaryDirectory();
    }

    protected function tearDown(): void
    {
        $this->tearDownTemporaryDirectory();
        parent::tearDown();
    }

    /**
     * @param array<array-key, mixed> $config
     */
    private function writeConfig(array $config): void
    {
        file_put_contents($this->path(), "<?php\n\nreturn " . var_export($config, return: true) . ";\n");
    }
}
