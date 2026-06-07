<?php

namespace MpcTests\Unit\Cli;

use MpcServices\Cli\ManifestLoader;
use PHPUnit\Framework\TestCase;

/**
 * Резолвинг пути манифеста (PURE-часть, без modX): дефолты имён по группе,
 * достройка .php, профили-окружения, passthrough явных путей.
 */
class ManifestLoaderTest extends TestCase
{
    public function testDefaultNameByGroup(): void
    {
        // нет аргумента → {base}/{группа}.php
        $this->assertSame('/base/settings.php', ManifestLoader::resolvePath('/base/', 'settings', ''));
        $this->assertSame('/base/resources.php', ManifestLoader::resolvePath('/base/', 'resources', ''));
    }

    public function testProfileNameGetsPhpSuffix(): void
    {
        // короткое имя → профиль/окружение в базе, .php достраивается
        $this->assertSame('/base/prod.php', ManifestLoader::resolvePath('/base/', 'settings', 'prod'));
    }

    public function testExplicitPhpExtensionNotDuplicated(): void
    {
        $this->assertSame('/base/prod.php', ManifestLoader::resolvePath('/base/', 'settings', 'prod.php'));
    }

    public function testBaseTrailingSlashNormalized(): void
    {
        // база без завершающего слэша — всё равно один разделитель
        $this->assertSame('/base/settings.php', ManifestLoader::resolvePath('/base', 'settings', ''));
    }

    public function testAbsoluteNonexistentPathPassthrough(): void
    {
        // абсолютный путь не префиксуется базой, даже если файла пока нет
        $this->assertSame('/abs/x.php', ManifestLoader::resolvePath('/base/', 'settings', '/abs/x.php'));
    }

    public function testExistingFilePassthrough(): void
    {
        // существующий файл по явному пути берётся как есть (совместимость)
        $tmp = tempnam(sys_get_temp_dir(), 'mpc') . '.php';
        file_put_contents($tmp, "<?php return [];\n");
        try {
            $this->assertSame($tmp, ManifestLoader::resolvePath('/base/', 'settings', $tmp));
        } finally {
            @unlink($tmp);
        }
    }
}
