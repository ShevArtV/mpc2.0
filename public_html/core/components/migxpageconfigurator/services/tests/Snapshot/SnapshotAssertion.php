<?php

namespace MpcTests\Snapshot;

use PHPUnit\Framework\TestCase;

/**
 * Трейт для snapshot-тестирования.
 *
 * Первый запуск: записывает результат как baseline в Fixtures/snapshots/.
 * Последующие запуски: сравнивает с сохранённым baseline.
 *
 * Для обновления снапшотов: UPDATE_SNAPSHOTS=1 vendor/bin/phpunit
 */
trait SnapshotAssertion
{
    private static string $snapshotsDir = __DIR__ . '/../Fixtures/snapshots';

    /**
     * @param TestCase $test
     */
    public function assertMatchesSnapshot(string $actual, string $snapshotName): void
    {
        $path = self::$snapshotsDir . '/' . $snapshotName;

        if (!file_exists($path) || getenv('UPDATE_SNAPSHOTS')) {
            $dir = dirname($path);
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
            file_put_contents($path, $actual);
            $this->assertTrue(true, "Snapshot created/updated: {$snapshotName}");
            return;
        }

        $this->assertEquals(
            file_get_contents($path),
            $actual,
            "Snapshot mismatch: {$snapshotName}\n" .
            "Run with UPDATE_SNAPSHOTS=1 to update snapshots."
        );
    }
}
