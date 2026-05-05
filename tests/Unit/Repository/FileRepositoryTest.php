<?php

declare(strict_types=1);

namespace Contenir\Maintenance\Tests\Unit\Repository;

use Contenir\Maintenance\MaintenanceState;
use Contenir\Maintenance\Repository\FileRepository;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[Group('unit')]
#[Group('maintenance')]
final class FileRepositoryTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/contenir-maintenance-' . uniqid('', true);
        mkdir($this->tmpDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            $items = glob($this->tmpDir . '/*') ?: [];
            foreach ($items as $item) {
                if (is_file($item)) {
                    unlink($item);
                }
            }
            @rmdir($this->tmpDir);
        }
        parent::tearDown();
    }

    private function path(string $name = 'maintenance.local.php'): string
    {
        return $this->tmpDir . '/' . $name;
    }

    public function testGetReturnsInactiveWhenFileMissing(): void
    {
        $repo  = new FileRepository($this->path());
        $state = $repo->get();

        self::assertFalse($state->active);
        self::assertSame('', $state->message);
        self::assertNull($state->since);
    }

    public function testGetReturnsInactiveWhenFileContentsAreNotAnArray(): void
    {
        file_put_contents($this->path(), "<?php\n\nreturn 'not an array';\n");

        $state = (new FileRepository($this->path()))->get();

        self::assertFalse($state->active);
    }

    public function testGetReturnsInactiveWhenActiveFlagIsFalseEvenIfMessagePresent(): void
    {
        file_put_contents(
            $this->path(),
            "<?php\n\nreturn ['active' => false, 'message' => 'lingering message'];\n",
        );

        $state = (new FileRepository($this->path()))->get();

        self::assertFalse($state->active);
        self::assertSame('', $state->message, 'Inactive states should not surface a stale message.');
    }

    public function testGetReturnsActiveStateWithMessageAndSince(): void
    {
        file_put_contents(
            $this->path(),
            "<?php\n\nreturn [\n"
                . "    'active' => true,\n"
                . "    'message' => 'Down for upgrade',\n"
                . "    'since' => '2026-01-02T03:04:05+00:00',\n"
                . "];\n",
        );

        $state = (new FileRepository($this->path()))->get();

        self::assertTrue($state->active);
        self::assertSame('Down for upgrade', $state->message);
        self::assertEquals(new DateTimeImmutable('2026-01-02T03:04:05+00:00'), $state->since);
    }

    public function testGetTreatsUnparseableSinceAsNull(): void
    {
        file_put_contents(
            $this->path(),
            "<?php\n\nreturn ['active' => true, 'message' => 'down', 'since' => 'not-a-date'];\n",
        );

        $state = (new FileRepository($this->path()))->get();

        self::assertTrue($state->active);
        self::assertNull($state->since);
    }

    public function testSaveWritesActiveStateAndRoundTrips(): void
    {
        $repo  = new FileRepository($this->path());
        $when  = new DateTimeImmutable('2026-04-01T12:00:00+00:00');
        $state = MaintenanceState::active('Back at 5pm', $when);

        $repo->save($state);

        $loaded = (new FileRepository($this->path()))->get();
        self::assertTrue($loaded->active);
        self::assertSame('Back at 5pm', $loaded->message);
        self::assertEquals($when, $loaded->since);
    }

    public function testSaveWritesInactiveState(): void
    {
        $repo = new FileRepository($this->path());
        $repo->save(MaintenanceState::active('temp'));
        $repo->save(MaintenanceState::inactive());

        $loaded = (new FileRepository($this->path()))->get();
        self::assertFalse($loaded->active);
        self::assertSame('', $loaded->message);
        self::assertNull($loaded->since);
    }

    public function testSaveCreatesParentDirectoryIfMissing(): void
    {
        $nested = $this->tmpDir . '/nested/inner/maintenance.local.php';
        $repo   = new FileRepository($nested);

        $repo->save(MaintenanceState::active('hi'));

        self::assertFileExists($nested);
        unlink($nested);
        rmdir(\dirname($nested));
        rmdir(\dirname(\dirname($nested)));
    }

    public function testSaveIsAtomicViaTempFileRename(): void
    {
        $repo = new FileRepository($this->path());
        $repo->save(MaintenanceState::active('first'));

        // After a successful save, no .tmp residue should be left over.
        self::assertFileDoesNotExist($this->path() . '.tmp');
    }

    public function testSaveThrowsWhenDestinationDirectoryUnwritable(): void
    {
        if (\function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('Running as root bypasses filesystem permission checks.');
        }

        $readOnly = $this->tmpDir . '/locked';
        mkdir($readOnly, 0o555, true);

        $repo = new FileRepository($readOnly . '/maintenance.local.php');

        try {
            $this->expectException(RuntimeException::class);
            $repo->save(MaintenanceState::active('x'));
        } finally {
            chmod($readOnly, 0o755);
            rmdir($readOnly);
        }
    }
}
