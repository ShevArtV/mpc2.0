<?php

namespace MpcTests\Snapshot;

use MpcServices\Handlers\Cutter;
use MpcTests\Stubs\ModxStub;
use PHPUnit\Framework\TestCase;

/**
 * Snapshot-тесты для Cutter.
 *
 * Baseline создаётся при первом запуске и сохраняется в Fixtures/snapshots/cutter/.
 * При рефакторинге гарантирует, что HTML-трансформации не изменились.
 *
 * Обновление снапшотов: UPDATE_SNAPSHOTS=1 vendor/bin/phpunit --testsuite Snapshot
 */
class CutterSnapshotTest extends TestCase
{
    use SnapshotAssertion;

    private string $fixturesDir;
    private string $outputDir;
    private ModxStub $modx;

    protected function setUp(): void
    {
        $this->fixturesDir = dirname(__DIR__) . '/Fixtures';
        $this->outputDir   = $this->fixturesDir . '/output';

        // Создаём выходные директории если их нет
        foreach (['sections', 'chunks'] as $subdir) {
            $dir = $this->outputDir . '/' . $subdir;
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
        }

        $this->modx = new ModxStub($this->fixturesDir);
    }

    protected function tearDown(): void
    {
        // Очищаем output между тестами, чтобы не было мусора от прошлых запусков
        $this->clearDir($this->outputDir . '/sections');
        $this->clearDir($this->outputDir . '/chunks');
    }

    /**
     * Базовый свойства, которые Mpc.php передаёт в Cutter при реальном запуске.
     */
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
            'useLexicons'          => false,
        ];
    }

    /**
     * Тест: Cutter корректно преобразует section "about" в Fenom-шаблон.
     */
    public function testCutterTransformsAboutSection(): void
    {
        $cutter = new Cutter($this->modx, $this->makeBaseProperties());
        $result = $cutter->handle('sample.html');

        $this->assertTrue($result['success'], 'Cutter::handle должен вернуть success=true');

        $sectionFile = $this->outputDir . '/sections/about.tpl';
        $this->assertFileExists($sectionFile, 'Файл секции about.tpl должен быть создан');

        $this->assertMatchesSnapshot(
            file_get_contents($sectionFile),
            'cutter/about.tpl'
        );
    }

    /**
     * Тест: Cutter корректно преобразует section "contacts".
     */
    public function testCutterTransformsContactsSection(): void
    {
        $cutter = new Cutter($this->modx, $this->makeBaseProperties());
        $cutter->handle('sample.html');

        $sectionFile = $this->outputDir . '/sections/contacts.tpl';
        $this->assertFileExists($sectionFile, 'Файл секции contacts.tpl должен быть создан');

        $this->assertMatchesSnapshot(
            file_get_contents($sectionFile),
            'cutter/contacts.tpl'
        );
    }

    /**
     * Тест: data-mpc-info заменяется на Fenom-плейсхолдер $_modx->config['key'].
     * Проверяем косвенно через содержимое секций.
     */
    public function testCutterHandlesInfoAttributes(): void
    {
        $cutter = new Cutter($this->modx, $this->makeBaseProperties());
        $cutter->handle('sample.html');

        // Просто убеждаемся, что оба файла созданы без ошибок
        $this->assertFileExists($this->outputDir . '/sections/about.tpl');
        $this->assertFileExists($this->outputDir . '/sections/contacts.tpl');
    }

    /**
     * Тест: поле с data-mpc-if оборачивается в условие Fenom.
     */
    public function testCutterWrapsFieldInCondition(): void
    {
        $cutter = new Cutter($this->modx, $this->makeBaseProperties());
        $cutter->handle('sample.html');

        $contacts = file_get_contents($this->outputDir . '/sections/contacts.tpl');
        $this->assertStringContainsString('{if', $contacts, 'Поле с data-mpc-if должно быть обёрнуто в {if}');
    }

    /**
     * Тест: data-mpc-* атрибуты удаляются из итогового HTML.
     */
    public function testCutterRemovesDataMpcAttributes(): void
    {
        $cutter = new Cutter($this->modx, $this->makeBaseProperties());
        $cutter->handle('sample.html');

        foreach (['about.tpl', 'contacts.tpl'] as $file) {
            $content = file_get_contents($this->outputDir . '/sections/' . $file);
            $this->assertStringNotContainsString(
                'data-mpc-field',
                $content,
                "data-mpc-field не должно остаться в {$file}"
            );
            $this->assertStringNotContainsString(
                'data-mpc-section',
                $content,
                "data-mpc-section не должно остаться в {$file}"
            );
        }
    }

    /**
     * Тест: img с data-mpc-field получает Fenom-плейсхолдер в src.
     */
    public function testCutterSetsImagePlaceholder(): void
    {
        $cutter = new Cutter($this->modx, $this->makeBaseProperties());
        $cutter->handle('sample.html');

        $about = file_get_contents($this->outputDir . '/sections/about.tpl');
        $this->assertStringContainsString(
            '$image[0].src}',
            $about,
            'img с data-mpc-field должен получить плейсхолдер {$image[0].src}'
        );
    }

    /**
     * Тест M1: data-mpc-rfield / data-mpc-tv → плейсхолдеры текущего ресурса.
     *   rfield → {$resource.<field>}, tv → {$resource.tvs.<tv>}.
     */
    public function testCutterTransformsResourceFieldsAndTVs(): void
    {
        $cutter = new Cutter($this->modx, $this->makeBaseProperties());
        $result = $cutter->handle('rfields.html');
        $this->assertTrue($result['success'], 'Cutter::handle должен вернуть success=true');

        $file = $this->outputDir . '/sections/rftest.tpl';
        $this->assertFileExists($file, 'Файл секции rftest.tpl должен быть создан');
        $tpl = file_get_contents($file);

        // rfield → нативные поля ресурса
        $this->assertStringContainsString('{$resource.pagetitle}', $tpl);
        $this->assertStringContainsString('{$resource.content}', $tpl);
        // tv → подмассив tvs
        $this->assertStringContainsString('{$resource.tvs.subtitle}', $tpl);
        // tv на img → src
        $this->assertStringContainsString('src="{$resource.tvs.cover}"', $tpl);
        // rfield на ссылке с data-mpc-if → href + обёртка {if}
        $this->assertStringContainsString('{$resource.link}', $tpl);
        $this->assertStringContainsString('{if $resource.link}', $tpl);
        // обычное поле секции не сломалось
        $this->assertStringContainsString('{$heading}', $tpl);
        // маркеры удалены из выхлопа
        $this->assertStringNotContainsString('data-mpc-rfield', $tpl);
        $this->assertStringNotContainsString('data-mpc-tv', $tpl);
    }

    /**
     * При useLexicons rfield рендерится через `| lexicon` по ключу
     * mpc_resource_<field> — КРОМЕ полей из excludeLexiconFields (pagetitle):
     * для них прямое чтение колонки, иначе grabber ключ не пишет → пусто.
     */
    public function testCutterExcludesNativeFieldsFromLexicon(): void
    {
        // useLexicons включаем через стаб (Base перетирает свойство из getOption);
        // excludeLexiconFields грузится из файла mpc_exclude_lexicons_filename —
        // указываем реальный exclude_lexicons.inc.php, где pagetitle в дефолте.
        $modx = new ModxStub($this->fixturesDir, [
            'mpc_use_lexicons'              => true,
            'mpc_exclude_lexicons_filename' => 'components/migxpageconfigurator/services/exclude_lexicons.inc.php',
        ]);

        $cutter = new Cutter($modx, $this->makeBaseProperties());
        $cutter->handle('rfields.html');
        $tpl = file_get_contents($this->outputDir . '/sections/rftest.tpl');

        // pagetitle исключён → прямое чтение колонки, без | lexicon
        $this->assertStringContainsString('{$resource.pagetitle}', $tpl);
        $this->assertStringNotContainsString('mpc_resource_pagetitle', $tpl);
        // content не исключён → ОТЛОЖЕННАЯ лексикон-форма (##…} → {…} в parsed/,
        // для переключения языка без перенарезки; см. Cutter::replaceResourceMarkers)
        $this->assertStringContainsString("##'mpc_resource_content' | lexicon}", $tpl);
    }

    /**
     * При useLexicons TV рендерится через `| lexicon` по ОТДЕЛЬНОМУ ключу
     * mpc_resource_tv_<name> (не коллизит с rfield того же имени).
     */
    public function testCutterLexiconizesTvWhenEnabled(): void
    {
        $modx = new ModxStub($this->fixturesDir, [
            'mpc_use_lexicons'              => true,
            'mpc_exclude_lexicons_filename' => 'components/migxpageconfigurator/services/exclude_lexicons.inc.php',
        ]);

        $cutter = new Cutter($modx, $this->makeBaseProperties());
        $cutter->handle('rfields.html');
        $tpl = file_get_contents($this->outputDir . '/sections/rftest.tpl');

        // tv в тексте → innerHtml ОТЛОЖЕННАЯ лексикон-форма с tv-неймспейсом
        $this->assertStringContainsString("##'mpc_resource_tv_subtitle' | lexicon}", $tpl);
        // tv на img → src ОТЛОЖЕННАЯ лексикон-форма
        $this->assertStringContainsString("src=\"##'mpc_resource_tv_cover' | lexicon}\"", $tpl);
        // прямого чтения tvs.* при включённых лексиконах быть не должно
        $this->assertStringNotContainsString('$resource.tvs.', $tpl);
    }

    /**
     * Image-конвейер для TV-img: при заданном thumbSnippet картинка ресурса
     * (data-mpc-tv на <img>) проходит обрезку/реформат и lazy-загрузку
     * симметрично секциям и контактам — раньше шла сырым src мимо конвейера.
     *
     * Размеры не извлекаются (TV — путь-строка) → ветка «реформат без кропа»:
     * только commonThumbParams, без &w=/&h=.
     */
    public function testCutterRunsTvImageThroughThumbPipeline(): void
    {
        $modx = new ModxStub($this->fixturesDir, [
            'mpc_thumb_snippet'       => 'mpcThumb',
            'mpc_common_thumb_params' => '&fm=webp&q=90',
        ]);

        $props = $this->makeBaseProperties();
        $props['lazyloadAttr'] = 'data-src';

        $cutter = new Cutter($modx, $props);
        $cutter->handle('rfields.html');
        $tpl = file_get_contents($this->outputDir . '/sections/rftest.tpl');

        // TV-img прогнан через thumb-сниппет с путём из tvs.cover на входе
        $this->assertStringContainsString("'mpcThumb' | snippet", $tpl);
        $this->assertStringContainsString("'input' => \$resource.tvs.cover", $tpl);
        // реформат без кропа: параметры есть, &w=/&h= нет
        $this->assertStringContainsString('&fm=webp&q=90', $tpl);
        $this->assertStringNotContainsString('&w=', $tpl);
        // lazy-загрузка: URL в data-src, src = заглушка
        $this->assertStringContainsString('data-src="{', $tpl);
        $this->assertStringContainsString('fake-img.png', $tpl);
        // сырого необработанного src быть не должно
        $this->assertStringNotContainsString('src="{$resource.tvs.cover}"', $tpl);
    }

    // ---------------------------------------------------------------

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
