<?php

namespace MpcTests\Snapshot;

use MpcServices\Handlers\Cutter;
use MpcTests\Stubs\ModxStub;
use PHPUnit\Framework\TestCase;

/**
 * Snapshot-тесты Cutter С ВКЛЮЧЁННЫМИ лексиконами.
 *
 * Зачем отдельно от CutterSnapshotTest: тот гоняет `mpc_use_lexicons=false`,
 * поэтому вся ветка расстановки `| lexicon` (и exclude-логика) была вне эталона
 * — там и прошёл баг 2.4.6-rc (асимметрия Cutter↔Grabber по префиксному
 * exclude). Здесь вход — реальный HTML (`lexicon.html`), прогон через
 * `Cutter::handle`, сверка `sections/promo.tpl` с эталоном + явные ассерты на
 * ключевой инвариант.
 *
 * Обновление снапшотов: UPDATE_SNAPSHOTS=1 vendor/bin/phpunit --testsuite Snapshot
 */
class CutterLexiconSnapshotTest extends TestCase
{
    use SnapshotAssertion;

    private string $fixturesDir;
    private string $outputDir;
    private ModxStub $modx;

    protected function setUp(): void
    {
        $this->fixturesDir = dirname(__DIR__) . '/Fixtures';
        $this->outputDir   = $this->fixturesDir . '/output';

        foreach (['sections', 'chunks'] as $subdir) {
            $dir = $this->outputDir . '/' . $subdir;
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
        }

        // Лексиконы ВКЛ + exclude-файл с записью в префиксной форме (promo_content).
        $this->modx = new ModxStub($this->fixturesDir, [
            'mpc_use_lexicons'              => true,
            'mpc_exclude_lexicons_filename' => 'components/migxpageconfigurator/services/tests/Fixtures/exclude_lexicons_test.inc.php',
        ]);
    }

    protected function tearDown(): void
    {
        $this->clearDir($this->outputDir . '/sections');
        $this->clearDir($this->outputDir . '/chunks');
    }

    private function makeBaseProperties(): array
    {
        $corePath = dirname($this->fixturesDir, 5) . '/'; // .../public_html/core/

        return [
            'corePath'             => $corePath,
            'pdotoolsElementsPath' => $this->fixturesDir . '/',
            'pathToDist'           => 'output/parsed/',
            'extension'            => '.tpl',
            'pathToSrc'            => 'html/',
            'lazyloadAttr'         => '',
            'expandAttr'           => '',
            'pathToCreate'         => 'output/create/',
            'devMode'              => false,
            'lazyloadEnabled'      => false,
            'expandEnabled'        => false,
            'lexiconsNamespace'    => 'migxpageconfigurator',
        ];
    }

    private function cutPromo(): string
    {
        $cutter = new Cutter($this->modx, $this->makeBaseProperties());
        $result = $cutter->handle('lexicon.html');
        $this->assertTrue($result['success'], 'Cutter::handle должен вернуть success=true');

        $file = $this->outputDir . '/sections/promo.tpl';
        $this->assertFileExists($file, 'Файл секции promo.tpl должен быть создан');
        return file_get_contents($file);
    }

    /**
     * Golden-эталон секции с лексиконами целиком.
     */
    public function testPromoSectionMatchesSnapshot(): void
    {
        $this->assertMatchesSnapshot($this->cutPromo(), 'cutter/promo.tpl');
    }

    /**
     * Инвариант (страж бага 2.4.6-rc): поле, исключённое по ПРЕФИКСНОМУ ключу
     * (`promo_content`), НЕ получает `| lexicon`, а обычные текстовые поля
     * секции — получают. Без фикса асимметрии Cutter↔Grabber `content` тоже
     * лексиконился бы → пусто на рендере.
     */
    public function testPrefixedExcludeDropsLexiconButKeepsOthers(): void
    {
        $tpl = $this->cutPromo();

        // content исключён по префиксному ключу promo_content → без lexicon
        $this->assertStringContainsString('{$content}', $tpl);
        $this->assertStringNotContainsString("'{\$content}' | lexicon}", $tpl);

        // title и disclaimer — лексиконятся
        $this->assertStringContainsString("##'{\$title}' | lexicon}", $tpl);
        $this->assertStringContainsString("##'{\$disclaimer}' | lexicon}", $tpl);
    }

    private function clearDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (glob($dir . '/*') as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }
}
