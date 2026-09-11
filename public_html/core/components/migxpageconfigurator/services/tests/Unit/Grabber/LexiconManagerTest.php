<?php

namespace MpcTests\Unit\Grabber;

use MpcServices\Handlers\Grabber\LexiconManager;
use MpcTests\Stubs\ModxStub;
use PHPUnit\Framework\TestCase;

class LexiconManagerTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/mpc_test_lexicons_' . uniqid();
        mkdir($this->tmpDir, 0777, true);
    }

    /** Каталоги вёрстки, созданные тестом реестра префиксов. */
    private array $scratchDirs = [];

    protected function tearDown(): void
    {
        // removeTree, а не glob+unlink: нарезка теперь заводит подкаталог
        // .orphan с реестром кандидатов на удаление.
        $this->removeTree($this->tmpDir);
        foreach ($this->scratchDirs as $dir) {
            $this->removeTree($dir);
        }
        $this->scratchDirs = [];
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        @chmod($dir, 0777);
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $this->removeTree($path);
                continue;
            }
            @chmod($path, 0666);
            @unlink($path);
        }
        @rmdir($dir);
    }

    private function makeManager(array $extraProps = []): LexiconManager
    {
        $modx = new ModxStub();

        $resource = new \stdClass();
        $resource->id = 42;

        $mockResource = new class($resource) {
            private \stdClass $r;
            public function __construct(\stdClass $r) { $this->r = $r; }
            public function get(string $key): mixed { return $this->r->$key ?? null; }
        };

        $props = array_merge([
            'useLexicons'                    => true,
            'excludeLexiconFields'           => [],
            'allowModxTags'                  => false,
            'allowedTags'                    => '',
            'lexiconFilenameField'           => 'id',
            'staticBlocksPageLexiconFilename' => 'static',
            'contactsPageLexiconFilename'    => 'contacts',
            'basePathToLexiconFile'          => $this->tmpDir . '/',
            'corePath'                       => '',
            'resourceLexiconKeysPath'        => 'nonexistent_rlang.php',
            'resource'                       => $mockResource,
        ], $extraProps);

        return new LexiconManager($modx, $props);
    }

    // ---------------------------------------------------------------
    // setLexicons()
    // ---------------------------------------------------------------

    // ---------------------------------------------------------------
    // getTouchedLexicons() — #2609-151
    // ---------------------------------------------------------------

    public function testTouchedLexiconsHoldOnlyKeysWrittenByThisRun(): void
    {
        $m = $this->makeManager();
        // Так граббер предзагружает page-types.inc.php культуры: словарь всех
        // лендингов сразу, ни один ключ этим прогоном не записан.
        $m->lexicons['static'] = ['features_aula_title' => 'Aula', 'difference_title' => 'Difference'];

        $m->setContext('hero', false);
        $m->setLexicons('Hello World', ['fieldName' => 'title']);

        $this->assertSame(['hero_title' => 'Hello World'], $m->getTouchedLexicons());
    }

    public function testTouchedLexiconsSeparateStaticAndResourceFiles(): void
    {
        $m = $this->makeManager();

        $m->setContext('hero', true); // статичная секция пишет в словарь типов
        $m->setLexicons('Static title', ['fieldName' => 'title']);
        $m->setContext('promo', false); // динамическая — в словарь ресурса
        $m->setLexicons('Promo title', ['fieldName' => 'title']);

        $this->assertSame(['hero_title' => 'Static title'], $m->getTouchedLexicons('static'));
        $this->assertSame(['promo_title' => 'Promo title'], $m->getTouchedLexicons('42'));
        $this->assertCount(2, $m->getTouchedLexicons());
    }

    public function testTouchedLexiconsDropKeyRemovedAfterWrite(): void
    {
        $m = $this->makeManager();
        $m->setContext('hero', false);
        $m->setLexicons('Hello World', ['fieldName' => 'title']);

        unset($m->lexicons['42']['hero_title']);

        $this->assertSame([], $m->getTouchedLexicons());
    }

    public function testSetLexiconsReturnsLexiconKey(): void
    {
        $m = $this->makeManager();
        $m->setContext('hero', false);

        $result = $m->setLexicons('Hello World', ['fieldName' => 'title']);

        // setLexicons возвращает голый ключ — Cutter сам добавит `| lexicon`
        // к плейсхолдеру, если поле признано лексиконным.
        $this->assertEquals('hero_title', $result);
    }

    public function testSetLexiconsStoresValueInLexiconsArray(): void
    {
        $m = $this->makeManager();
        $m->setContext('hero', false);
        $m->setLexicons('Hello World', ['fieldName' => 'title']);

        $this->assertEquals('Hello World', $m->lexicons['42']['hero_title']);
    }

    public function testSetLexiconsReturnsOriginalWhenDisabled(): void
    {
        $m = $this->makeManager(['useLexicons' => false]);
        $m->setContext('hero', false);

        $result = $m->setLexicons('Hello World', ['fieldName' => 'title']);

        $this->assertEquals('Hello World', $result);
    }

    public function testSetLexiconsReturnsOriginalForExcludedField(): void
    {
        $m = $this->makeManager(['excludeLexiconFields' => ['link']]);
        $m->setContext('hero', false);

        $result = $m->setLexicons('https://example.com', ['fieldName' => 'link']);

        $this->assertEquals('https://example.com', $result);
    }

    public function testSetLexiconsExcludedByFullPath(): void
    {
        // Pattern `items_*` матчит полный путь `items_text`.
        $m = $this->makeManager(['excludeLexiconFields' => ['items_*']]);
        $m->setContext('hero', false);

        $result = $m->setLexicons('Some text', ['fieldName' => 'text', 'parentFieldName' => 'items']);

        $this->assertEquals('Some text', $result);
    }

    public function testSetLexiconsNotExcludedWhenOnlyParentMatches(): void
    {
        // Pattern `items` точно матчит parentFieldName, НО полный путь
        // `items_text` не матчит (другие правила). Field НЕ должен быть
        // исключён — это критический семантический фикс (раньше «items»
        // как parent исключал любые text-поля под ним; теперь — нет).
        $m = $this->makeManager(['excludeLexiconFields' => ['items']]);
        $m->setContext('hero', false);

        $result = $m->setLexicons('Some text', ['fieldName' => 'text', 'parentFieldName' => 'items']);

        // text НЕ исключён → возвращается ключ.
        $this->assertEquals('hero_items_text', $result);
    }

    public function testSetLexiconsExcludesFieldByPrefixWildcard(): void
    {
        $m = $this->makeManager(['excludeLexiconFields' => ['img*']]);
        $m->setContext('hero', false);

        // под паттерн попадает
        $this->assertEquals('/path/to/a.jpg', $m->setLexicons('/path/to/a.jpg', ['fieldName' => 'img']));
        $this->assertEquals('/b.webp', $m->setLexicons('/b.webp', ['fieldName' => 'img_pc']));
        $this->assertEquals('/c.svg', $m->setLexicons('/c.svg', ['fieldName' => 'img_mobile']));

        // НЕ под паттерн — обычная лексиконизация
        $this->assertEquals('hero_title', $m->setLexicons('Hello', ['fieldName' => 'title']));
    }

    public function testSetLexiconsExcludesFieldBySuffixWildcard(): void
    {
        $m = $this->makeManager(['excludeLexiconFields' => ['*_picture']]);
        $m->setContext('section', false);

        $this->assertEquals('/x.jpg', $m->setLexicons('/x.jpg', ['fieldName' => 'main_picture']));
        $this->assertEquals('/y.jpg', $m->setLexicons('/y.jpg', ['fieldName' => 'thumb_picture']));

        $this->assertEquals('section_title', $m->setLexicons('Hello', ['fieldName' => 'title']));
    }

    public function testSetLexiconsExcludesByFullPathWildcard(): void
    {
        // Pattern `media_*` против полного пути `media_video_src`.
        $m = $this->makeManager(['excludeLexiconFields' => ['media_*']]);
        $m->setContext('hero', false);

        $result = $m->setLexicons('/z.mp4', ['fieldName' => 'src', 'parentFieldName' => 'media_video']);
        $this->assertEquals('/z.mp4', $result);
    }

    public function testSetLexiconsContainerNameMatchDoesNotExcludeChildTextFields(): void
    {
        // Регрессионный кейс: pattern `*_picture` НЕ должен исключать text-поля
        // (subtitle/title) внутри контейнера `list_triple_picture`. Pattern
        // матчит имя контейнера, но не fullPath text-поля.
        $m = $this->makeManager(['excludeLexiconFields' => ['*_picture', 'picture']]);
        $m->setContext('top_slider', false);

        // subtitle/title в picture-контейнере — НЕ исключаются
        $this->assertEquals('top_slider_list_triple_picture_subtitle',
            $m->setLexicons('Subtitle text', ['fieldName' => 'subtitle', 'parentFieldName' => 'list_triple_picture']));
        $this->assertEquals('top_slider_list_triple_picture_title',
            $m->setLexicons('Title text', ['fieldName' => 'title', 'parentFieldName' => 'list_triple_picture']));

        // Само picture-поле — исключается (fullPath = `list_triple_picture_picture`, кончается `_picture`)
        $this->assertEquals('/hero.webp',
            $m->setLexicons('/hero.webp', ['fieldName' => 'picture', 'parentFieldName' => 'list_triple_picture']));
    }

    public function testSetLexiconsExactMatchStillWorksAlongsidePatterns(): void
    {
        $m = $this->makeManager(['excludeLexiconFields' => ['link', 'img*']]);
        $m->setContext('hero', false);

        // точное совпадение — исключено
        $this->assertEquals('https://x', $m->setLexicons('https://x', ['fieldName' => 'link']));
        // wildcard — исключено
        $this->assertEquals('/a.jpg', $m->setLexicons('/a.jpg', ['fieldName' => 'img']));
        // ничего общего — лексиконизируется
        $this->assertEquals('hero_title', $m->setLexicons('Hello', ['fieldName' => 'title']));
    }

    public function testSetLexiconsQuestionMarkWildcardMatchesSingleChar(): void
    {
        $m = $this->makeManager(['excludeLexiconFields' => ['img?']]);
        $m->setContext('hero', false);

        // ровно один символ после img
        $this->assertEquals('/a.jpg', $m->setLexicons('/a.jpg', ['fieldName' => 'img1']));

        // не совпадает — два символа или ноль символов
        $this->assertEquals('hero_img', $m->setLexicons('value', ['fieldName' => 'img']));
        $this->assertEquals('hero_img12', $m->setLexicons('value', ['fieldName' => 'img12']));
    }

    public function testSetLexiconsIgnoresEmptyAndNonStringPatterns(): void
    {
        $m = $this->makeManager(['excludeLexiconFields' => ['', null, 123, 'img*']]);
        $m->setContext('hero', false);

        // мусорные паттерны игнорируются, валидный продолжает работать
        $this->assertEquals('/a.jpg', $m->setLexicons('/a.jpg', ['fieldName' => 'img']));
        $this->assertEquals('hero_title', $m->setLexicons('Hello', ['fieldName' => 'title']));
    }

    public function testSetLexiconsUsesStaticFilenameForStaticSection(): void
    {
        $m = $this->makeManager();
        $m->setContext('cta', true);
        $m->setLexicons('Click me', ['fieldName' => 'btn']);

        $this->assertArrayHasKey('static', $m->lexicons);
        $this->assertEquals('Click me', $m->lexicons['static']['cta_btn']);
    }

    public function testSetLexiconsBuildsKeyWithParentAndIdx(): void
    {
        $m = $this->makeManager();
        $m->setContext('team', false);

        $result = $m->setLexicons('Alice', [
            'fieldName'       => 'name',
            'parentFieldName' => 'members',
            'idx'             => '1',
        ]);

        $this->assertEquals('team_members_name_1', $result);
    }

    // ---------------------------------------------------------------
    // appendLexiconParent / getLexiconKeyForPath — единая конструкция
    // parentFieldName для грабера И редактора. Ключи запиннены по РЕАЛЬНОМУ
    // лексикону секции (prefix 'third'): см. lexicon/ru/index.inc.php.
    // ---------------------------------------------------------------

    public function testAppendLexiconParentBuildsChain(): void
    {
        $this->assertSame('list_of_lists', LexiconManager::appendLexiconParent('', 'list_of_lists', 0));
        $this->assertSame('list_of_lists_1', LexiconManager::appendLexiconParent('', 'list_of_lists', 1));
        $this->assertSame('list_of_lists_list_triple_img', LexiconManager::appendLexiconParent('list_of_lists', 'list_triple_img', 0));
        $this->assertSame('list_of_lists_1_list_triple_img_1', LexiconManager::appendLexiconParent('list_of_lists_1', 'list_triple_img', 1));
    }

    public function testGetLexiconKeyForPathMatchesGrabberKeys(): void
    {
        // top-level поле
        $this->assertSame('third_title', LexiconManager::getLexiconKeyForPath('third', [], 'title'));
        // строка списка (idx0 → без суффиксов; idx>0 → idx в parentFieldName И суффикс листа)
        $this->assertSame('third_list_of_lists_1_title_1', LexiconManager::getLexiconKeyForPath('third', [['field' => 'list_of_lists', 'idx' => 1]], 'title'));
        $this->assertSame('third_list_of_lists_2_title_2', LexiconManager::getLexiconKeyForPath('third', [['field' => 'list_of_lists', 'idx' => 2]], 'title'));
        $this->assertSame('third_list_triple_img_1_title_1', LexiconManager::getLexiconKeyForPath('third', [['field' => 'list_triple_img', 'idx' => 1]], 'title'));
        // ВЛОЖЕННЫЙ список (path длины 2)
        $this->assertSame('third_list_of_lists_list_triple_img_title', LexiconManager::getLexiconKeyForPath('third', [['field' => 'list_of_lists', 'idx' => 0], ['field' => 'list_triple_img', 'idx' => 0]], 'title'));
        $this->assertSame('third_list_of_lists_1_list_triple_img_title', LexiconManager::getLexiconKeyForPath('third', [['field' => 'list_of_lists', 'idx' => 1], ['field' => 'list_triple_img', 'idx' => 0]], 'title'));
        // пустой prefix/field → ''
        $this->assertSame('', LexiconManager::getLexiconKeyForPath('', [], 'title'));
        $this->assertSame('', LexiconManager::getLexiconKeyForPath('third', [], ''));
    }

    // ---------------------------------------------------------------
    // setContext() — wipe прежних статик-ключей секции (orphan/excluded)
    // ---------------------------------------------------------------

    public function testSetContextWipesStaleStaticKeysByPrefix(): void
    {
        $m = $this->makeManager();
        // Предзагрузка статик-файла (как Grabber: lexicons[$staticId]).
        $m->lexicons['static'] = [
            'cta_old_orphan'  => 'stale',   // ключ грабимой секции — должен уйти
            'cta_btn'         => 'old',     // тоже секции cta — уйдёт (наполнится заново)
            'hero_title'      => 'keep',    // другая секция — цел
            'mpc_resource_x'  => 'keep',    // глобалка — цела
        ];

        // Реестр известных префиксов — обязательное условие чистки.
        $m->setKnownPrefixes(['cta', 'hero']);
        $m->setContext('cta', true);

        $this->assertArrayNotHasKey('cta_old_orphan', $m->lexicons['static']);
        $this->assertArrayNotHasKey('cta_btn', $m->lexicons['static']);
        $this->assertArrayHasKey('hero_title', $m->lexicons['static']);
        $this->assertArrayHasKey('mpc_resource_x', $m->lexicons['static']);
    }

    public function testSetContextSkipsWipeForCopySection(): void
    {
        $m = $this->makeManager();
        $m->lexicons['static'] = ['cta_btn' => 'orig'];

        // Копия (data-mpc-copy) не владеет лексиконами оригинала — wipe пропускается.
        $m->setContext('cta', true, true);

        $this->assertArrayHasKey('cta_btn', $m->lexicons['static']);
        $this->assertEquals('orig', $m->lexicons['static']['cta_btn']);
    }

    public function testSetContextNoWipeWithoutPreload(): void
    {
        // Cutter-флоу: lexicons не предзагружены → no-op, без ошибок.
        $m = $this->makeManager();
        $m->setContext('cta', true);
        $this->assertArrayNotHasKey('static', $m->lexicons);
    }

    public function testSetContextDoesNotWipeForNonStaticSection(): void
    {
        $m = $this->makeManager();
        $m->lexicons['static'] = ['cta_btn' => 'orig'];

        $m->setContext('cta', false);

        $this->assertArrayHasKey('cta_btn', $m->lexicons['static']);
    }

    // ---------------------------------------------------------------
    // setContext() — принадлежность ключа префиксу (самый длинный побеждает)
    // ---------------------------------------------------------------

    /** Раскладка-виновник: секция с коротким префиксом и соседи с длинными. */
    private function siblingLexicons(): array
    {
        return [
            'difference_title'            => 'общий заголовок',
            'difference_weighted_title'   => 'DEEP <b>SENSORY PRESSURE</b>',
            'difference_weighted_text'    => 'weighted text',
            'difference_ventilated_title' => 'VENTILATED',
            'mpc_resource_x'              => 'глобалка',
        ];
    }

    private const SIBLING_PREFIXES = ['difference', 'difference_weighted', 'difference_ventilated'];

    public function testShortPrefixKeepsSiblingKeys(): void
    {
        $m = $this->makeManager();
        $m->lexicons['static'] = $this->siblingLexicons();
        $m->setKnownPrefixes(self::SIBLING_PREFIXES);

        $m->setContext('difference', true);

        // Свой ключ ушёл — секция наполнит его заново.
        $this->assertArrayNotHasKey('difference_title', $m->lexicons['static']);
        // Соседи целы: у них есть более длинный известный префикс.
        $this->assertEquals('DEEP <b>SENSORY PRESSURE</b>', $m->lexicons['static']['difference_weighted_title']);
        $this->assertArrayHasKey('difference_weighted_text', $m->lexicons['static']);
        $this->assertEquals('VENTILATED', $m->lexicons['static']['difference_ventilated_title']);
        $this->assertArrayHasKey('mpc_resource_x', $m->lexicons['static']);
    }

    public function testLongPrefixWipesOnlyItsOwnKeys(): void
    {
        $m = $this->makeManager();
        $m->lexicons['static'] = $this->siblingLexicons();
        $m->setKnownPrefixes(self::SIBLING_PREFIXES);

        $m->setContext('difference_weighted', true);

        $this->assertArrayNotHasKey('difference_weighted_title', $m->lexicons['static']);
        $this->assertArrayNotHasKey('difference_weighted_text', $m->lexicons['static']);
        $this->assertEquals('общий заголовок', $m->lexicons['static']['difference_title']);
        $this->assertEquals('VENTILATED', $m->lexicons['static']['difference_ventilated_title']);
    }

    /**
     * Порядок обхода шаблонов на результат не влияет: до фикса выживал тот
     * сосед, чей шаблон нарезан после виновника (`Mpc::getFilesList` не
     * сортирует, порядок зависит от ФС конкретной машины).
     */
    public function testWipeOrderDoesNotChangeResult(): void
    {
        $forward = $this->makeManager();
        $forward->lexicons['static'] = $this->siblingLexicons();
        $forward->setKnownPrefixes(self::SIBLING_PREFIXES);
        $forward->setContext('difference', true);
        $forward->setContext('difference_weighted', true);

        $backward = $this->makeManager();
        $backward->lexicons['static'] = $this->siblingLexicons();
        $backward->setKnownPrefixes(self::SIBLING_PREFIXES);
        $backward->setContext('difference_weighted', true);
        $backward->setContext('difference', true);

        $this->assertEquals($backward->lexicons['static'], $forward->lexicons['static']);
        // В обоих порядках уцелел сосед, которого никто не грабил.
        $this->assertEquals('VENTILATED', $forward->lexicons['static']['difference_ventilated_title']);
    }

    /** Секция новая, записи в mpc_tracked_fields нет — префикс берётся из вёрстки. */
    public function testNewSectionWithoutTrackedRecordIsProtected(): void
    {
        $m = $this->makeManager();
        $m->lexicons['static'] = [
            'difference_title'      => 'общий',
            'difference_new_title'  => 'свежая секция',
        ];
        // Реестр собран из вёрстки: tracked-записи у новой секции ещё нет.
        $m->setKnownPrefixes(['difference', 'difference_new']);

        $m->setContext('difference', true);

        $this->assertEquals('свежая секция', $m->lexicons['static']['difference_new_title']);
    }

    /** Границы: `x_y` не владеет ключами `x_yz` и наоборот. */
    public function testPrefixBoundaryIsUnderscore(): void
    {
        $m = $this->makeManager();
        $m->lexicons['static'] = [
            'x_y_title'  => 'y',
            'x_yz_title' => 'yz',
        ];
        $m->setKnownPrefixes(['x_y', 'x_yz']);

        $m->setContext('x_y', true);

        $this->assertArrayNotHasKey('x_y_title', $m->lexicons['static']);
        $this->assertEquals('yz', $m->lexicons['static']['x_yz_title']);
    }

    /** Три уровня вложенности: каждый владеет только своим. */
    public function testNestedPrefixesEachOwnTheirKeys(): void
    {
        $m = $this->makeManager();
        $base = [
            'a_title'     => 'a',
            'a_b_title'   => 'ab',
            'a_b_c_title' => 'abc',
        ];
        $m->lexicons['static'] = $base;
        $m->setKnownPrefixes(['a', 'a_b', 'a_b_c']);

        $m->setContext('a', true);
        $this->assertEquals(['a_b_title' => 'ab', 'a_b_c_title' => 'abc'], $m->lexicons['static']);

        $m->lexicons['static'] = $base;
        $m->setContext('a_b', true);
        $this->assertEquals(['a_title' => 'a', 'a_b_c_title' => 'abc'], $m->lexicons['static']);
    }

    /** Устаревшее собственное поле чистится по-прежнему: секции `cta_old` нет. */
    public function testStaleOwnFieldStillWiped(): void
    {
        $m = $this->makeManager();
        $m->lexicons['static'] = [
            'cta_old_orphan' => 'stale',
            'cta_btn'        => 'old',
        ];
        $m->setKnownPrefixes(['cta']);

        $m->setContext('cta', true);

        $this->assertSame([], $m->lexicons['static']);
    }

    /**
     * Повторный прогон по тому же набору ничего не меняет: чистка и наполнение
     * приводят массив к одному и тому же состоянию.
     */
    public function testRepeatedRunIsIdempotent(): void
    {
        $run = function (): array {
            $m = $this->makeManager();
            $m->lexicons['static'] = $this->siblingLexicons();
            $m->setKnownPrefixes(self::SIBLING_PREFIXES);
            foreach (self::SIBLING_PREFIXES as $prefix) {
                $m->setContext($prefix, true);
                $m->setLexicons('из вёрстки', ['fieldName' => 'title']);
            }
            return $m->lexicons['static'];
        };

        $first = $run();
        $second = $run();

        $this->assertEquals($first, $second);
        $this->assertArrayHasKey('difference_weighted_title', $first);
        $this->assertArrayHasKey('mpc_resource_x', $first);
    }

    /**
     * Значение, изменённое редактором в админке, чистка соседа не трогает:
     * ключ принадлежит другой секции, а собственную секцию наполняет грабинг.
     */
    public function testEditorValueOfSiblingSurvivesWipe(): void
    {
        $m = $this->makeManager();
        $m->lexicons['static'] = $this->siblingLexicons();
        $m->lexicons['static']['difference_weighted_title'] = 'Правка редактора';
        $m->setKnownPrefixes(self::SIBLING_PREFIXES);

        $m->setContext('difference', true);

        $this->assertEquals('Правка редактора', $m->lexicons['static']['difference_weighted_title']);
    }

    /** Реестр построить не удалось — чистка не идёт вовсе, чужие ключи целы. */
    public function testNoWipeWithoutPrefixRegistry(): void
    {
        // basePath шаблонов в тестовых свойствах не задан → скан пуст → реестр неполон.
        $m = $this->makeManager();
        $m->lexicons['static'] = $this->siblingLexicons();

        $m->setContext('difference', true);

        $this->assertEquals($this->siblingLexicons(), $m->lexicons['static']);
    }

    // ---------------------------------------------------------------
    // Неполный реестр префиксов: ошибка источника ≠ пустой результат
    // ---------------------------------------------------------------

    /** Каталог вёрстки с одним шаблоном на секцию. */
    private function makeTemplateDir(array $prefixesByFile): string
    {
        $dir = sys_get_temp_dir() . '/mpc_test_tpl_' . uniqid();
        mkdir($dir, 0777, true);
        $this->scratchDirs[] = $dir;
        foreach ($prefixesByFile as $file => $prefix) {
            file_put_contents(
                $dir . '/' . $file,
                '<section data-mpc-lexicon="' . $prefix . '" data-mpc-static="1"></section>'
            );
        }

        return $dir;
    }

    /** modX, у которого манифест трекаемых полей читается успешно. */
    private function modxWithTracked(array $prefixes): \modX
    {
        return new class($prefixes) extends ModxStub {
            private array $prefixes;

            public function __construct(array $prefixes)
            {
                parent::__construct();
                $this->prefixes = $prefixes;
            }

            public function exec(string $sql): int
            {
                return 0;
            }

            public function query(string $sql): object
            {
                return new class($this->prefixes) {
                    private array $rows;

                    public function __construct(array $rows)
                    {
                        $this->rows = $rows;
                    }

                    public function fetchAll(int $mode = 0): array
                    {
                        return $this->rows;
                    }
                };
            }
        };
    }

    private function foreignKeyLexicons(): array
    {
        return [
            'x_title'   => 'своё значение',
            'x_y_title' => 'EDITOR VALUE',
        ];
    }

    /**
     * Скан вёрстки непустой, а манифест трекаемых полей прочитать не удалось
     * (`ModxStub` не умеет exec/query). До правки ошибка приходила пустым
     * массивом, реестр объявлялся полным по одной вёрстке — и `x` сносил чужой
     * `x_y_title`. Теперь чистки нет вовсе.
     */
    public function testTrackedManifestFailureBlocksWipe(): void
    {
        $dir = $this->makeTemplateDir(['x.tpl' => 'x']);
        $m = $this->makeManager([
            'pdotoolsElementsPath' => $dir . '/',
            'pathToSrc'            => '',
        ]);
        $m->lexicons['static'] = $this->foreignKeyLexicons();

        $m->setContext('x', true);

        $this->assertEquals($this->foreignKeyLexicons(), $m->lexicons['static']);
        $this->assertSame([], $m->getKnownPrefixes());
    }

    /**
     * Манифест читается, но один шаблон недоступен: реестр всё равно неполон —
     * секции из непрочитанного файла в нём нет, и её ключи снёс бы сосед.
     */
    public function testUnreadableTemplateBlocksWipe(): void
    {
        $dir = $this->makeTemplateDir(['x.tpl' => 'x', 'y.tpl' => 'x_y']);
        chmod($dir . '/y.tpl', 0000);
        if (is_readable($dir . '/y.tpl')) {
            $this->markTestSkipped('права на файл не действуют (запуск под root)');
        }

        $manager = new LexiconManager($this->modxWithTracked(['x']), [
            'useLexicons'                     => true,
            'excludeLexiconFields'            => [],
            'lexiconFilenameField'            => 'id',
            'staticBlocksPageLexiconFilename' => 'static',
            'basePathToLexiconFile'           => $this->tmpDir . '/',
            'pdotoolsElementsPath'            => $dir . '/',
            'pathToSrc'                       => '',
        ]);
        $manager->lexicons['static'] = $this->foreignKeyLexicons();

        $manager->setContext('x', true);

        $this->assertEquals($this->foreignKeyLexicons(), $manager->lexicons['static']);
    }

    /** Нечитаемая подпапка вёрстки роняет обход — тот же неполный реестр. */
    public function testUnreadableTemplateSubdirectoryBlocksWipe(): void
    {
        $dir = $this->makeTemplateDir(['x.tpl' => 'x']);
        $nested = $dir . '/nested';
        mkdir($nested, 0777, true);
        file_put_contents($nested . '/y.tpl', '<section data-mpc-lexicon="x_y"></section>');
        chmod($nested, 0000);
        if (is_readable($nested)) {
            $this->markTestSkipped('права на каталог не действуют (запуск под root)');
        }

        $manager = new LexiconManager($this->modxWithTracked(['x']), [
            'useLexicons'                     => true,
            'excludeLexiconFields'            => [],
            'lexiconFilenameField'            => 'id',
            'staticBlocksPageLexiconFilename' => 'static',
            'basePathToLexiconFile'           => $this->tmpDir . '/',
            'pdotoolsElementsPath'            => $dir . '/',
            'pathToSrc'                       => '',
        ]);
        $manager->lexicons['static'] = $this->foreignKeyLexicons();

        $manager->setContext('x', true);

        $this->assertEquals($this->foreignKeyLexicons(), $manager->lexicons['static']);
    }

    /**
     * Оба источника отработали — реестр полон, чистка идёт: своё поле уходит,
     * чужое остаётся. Контрольный тест к трём предыдущим: они доказывают отказ
     * от чистки, этот — что отказ не превратился в «не чистим никогда».
     */
    public function testCompleteRegistryWipesOwnKeysOnly(): void
    {
        $dir = $this->makeTemplateDir(['x.tpl' => 'x', 'y.tpl' => 'x_y']);
        $manager = new LexiconManager($this->modxWithTracked(['x', 'x_y']), [
            'useLexicons'                     => true,
            'excludeLexiconFields'            => [],
            'lexiconFilenameField'            => 'id',
            'staticBlocksPageLexiconFilename' => 'static',
            'basePathToLexiconFile'           => $this->tmpDir . '/',
            'pdotoolsElementsPath'            => $dir . '/',
            'pathToSrc'                       => '',
        ]);
        $manager->lexicons['static'] = $this->foreignKeyLexicons();

        $manager->setContext('x', true);

        $this->assertArrayNotHasKey('x_title', $manager->lexicons['static']);
        $this->assertEquals('EDITOR VALUE', $manager->lexicons['static']['x_y_title']);
    }

    // ---------------------------------------------------------------
    // isLexiconField()
    // ---------------------------------------------------------------

    public function testIsLexiconFieldFalseWhenLexiconsDisabled(): void
    {
        $m = $this->makeManager([
            'useLexicons'              => false,
            'translatableContentTypes' => ['text', 'image'],
        ]);
        $this->assertFalse($m->isLexiconField('text'));
        $this->assertFalse($m->isLexiconField('image'));
    }

    public function testIsLexiconFieldFalseWhenContentTypeNotTranslatable(): void
    {
        $m = $this->makeManager([
            'useLexicons'              => true,
            'translatableContentTypes' => ['text'],
        ]);
        $this->assertFalse($m->isLexiconField('image'));
        $this->assertFalse($m->isLexiconField('video'));
    }

    public function testIsLexiconFieldTrueWhenEnabledAndTranslatable(): void
    {
        $m = $this->makeManager([
            'useLexicons'              => true,
            'translatableContentTypes' => ['text', 'image', 'poster'],
        ]);
        $this->assertTrue($m->isLexiconField('text'));
        $this->assertTrue($m->isLexiconField('image'));
        $this->assertTrue($m->isLexiconField('poster'));
    }

    // ---------------------------------------------------------------
    // shouldLexiconize() — комбинирует content-type + exclusion
    // ---------------------------------------------------------------

    public function testShouldLexiconizeFalseWhenContentTypeNotTranslatable(): void
    {
        $m = $this->makeManager([
            'useLexicons'              => true,
            'translatableContentTypes' => ['text'],
        ]);
        $this->assertFalse($m->shouldLexiconize('image', 'hero', ''));
    }

    public function testShouldLexiconizeFalseForExcludedFieldName(): void
    {
        $m = $this->makeManager([
            'useLexicons'              => true,
            'translatableContentTypes' => ['text', 'image'],
            'excludeLexiconFields'     => ['MIGX_id', 'inline_styles'],
        ]);
        $this->assertFalse($m->shouldLexiconize('text', 'MIGX_id', ''));
        $this->assertFalse($m->shouldLexiconize('text', 'inline_styles', 'cards'));
        // не excluded — лексиконим
        $this->assertTrue($m->shouldLexiconize('text', 'title', 'cards'));
    }

    public function testShouldLexiconizeFalseWhenFullPathMatchesExcludePattern(): void
    {
        $m = $this->makeManager([
            'useLexicons'              => true,
            'translatableContentTypes' => ['text', 'image'],
            'excludeLexiconFields'     => ['compare_list_compare_product_*'],
        ]);
        // fullPath = `compare_list_compare_product_title` → матчит glob → исключено
        $this->assertFalse($m->shouldLexiconize('text', 'title', 'compare_list_compare_product'));
    }

    public function testShouldLexiconizeTrueWhenOnlyParentNameMatchesNotFullPath(): void
    {
        // Pattern точно матчит parentFieldName, но не fullPath. Не исключаем.
        $m = $this->makeManager([
            'useLexicons'              => true,
            'translatableContentTypes' => ['text'],
            'excludeLexiconFields'     => ['list_triple_picture'],
        ]);
        // fullPath = `list_triple_picture_title` НЕ матчит pattern `list_triple_picture` (exact).
        $this->assertTrue($m->shouldLexiconize('text', 'title', 'list_triple_picture'));
    }

    public function testShouldLexiconizeContainerSuffixDoesNotBleedToChildren(): void
    {
        // Регрессионный кейс: pattern `*_picture` не должен исключать text-поля
        // под контейнером с именем `*_picture`.
        $m = $this->makeManager([
            'useLexicons'              => true,
            'translatableContentTypes' => ['text', 'image'],
            'excludeLexiconFields'     => ['*_picture', 'picture'],
        ]);
        // subtitle/title под контейнером — лексиконим
        $this->assertTrue($m->shouldLexiconize('text', 'subtitle', 'list_triple_picture'));
        $this->assertTrue($m->shouldLexiconize('text', 'title', 'list_triple_picture'));
        // picture-поле под контейнером — fullPath `list_triple_picture_picture` матчит → исключено
        $this->assertFalse($m->shouldLexiconize('image', 'picture', 'list_triple_picture'));
        // picture-поле в любом другом контексте — fieldName=picture exact match → исключено
        $this->assertFalse($m->shouldLexiconize('image', 'picture', 'gallery'));
    }

    public function testShouldLexiconizeRespectsGlobPatternsInExclude(): void
    {
        $m = $this->makeManager([
            'useLexicons'              => true,
            'translatableContentTypes' => ['text', 'image'],
            'excludeLexiconFields'     => ['*_picture', 'img*', 'hero_picture_*'],
        ]);
        // fieldName suffix-glob
        $this->assertFalse($m->shouldLexiconize('image', 'main_picture', ''));
        // fieldName prefix-glob
        $this->assertFalse($m->shouldLexiconize('image', 'img_pc', ''));
        // fullPath-glob — `hero_picture_*` матчит `hero_picture_src`
        $this->assertFalse($m->shouldLexiconize('image', 'src', 'hero_picture'));
        // НЕ под паттерн — лексиконим
        $this->assertTrue($m->shouldLexiconize('image', 'logo_src', 'banner'));
        // Контейнер `hero_picture` без glob-pattern на содержимое — text-поля
        // под ним лексиконятся (вот это и есть фикс).
        $m2 = $this->makeManager([
            'useLexicons'              => true,
            'translatableContentTypes' => ['text'],
            'excludeLexiconFields'     => ['*_picture'],
        ]);
        $this->assertTrue($m2->shouldLexiconize('text', 'subtitle', 'hero_picture'));
    }

    public function testShouldLexiconizeHandlesUndefinedExcludeList(): void
    {
        $m = $this->makeManager([
            'useLexicons'              => true,
            'translatableContentTypes' => ['text'],
            // excludeLexiconFields не задан — не должно ронять
        ]);
        $this->assertTrue($m->shouldLexiconize('text', 'title', 'cards'));
    }

    public function testIsLexiconFieldFalseWhenTranslatableTypesMissing(): void
    {
        $m = $this->makeManager(['useLexicons' => true]);
        // translatableContentTypes не задан в конфиге
        $this->assertFalse($m->isLexiconField('text'));
    }

    public function testSetLexiconsReturnsEmptyForEmptyValue(): void
    {
        $m = $this->makeManager();
        $m->setContext('hero', false);

        $this->assertEquals('', $m->setLexicons('', ['fieldName' => 'title']));
        $this->assertEquals('', $m->setLexicons(null, ['fieldName' => 'title']));
    }

    // ---------------------------------------------------------------
    // sanitizeValue()
    // ---------------------------------------------------------------

    public function testSanitizeValueReturnsEmptyForEmpty(): void
    {
        $m = $this->makeManager();
        $this->assertEquals('', $m->sanitizeValue(''));
        $this->assertEquals('', $m->sanitizeValue(null));
    }

    public function testSanitizeValueTrimmsWhitespace(): void
    {
        $m = $this->makeManager();
        $this->assertEquals('hello', $m->sanitizeValue('  hello  '));
    }

    public function testSanitizeValueReplacesSingleQuotes(): void
    {
        $m = $this->makeManager();
        $this->assertEquals("it&apos;s fine", $m->sanitizeValue("it's fine"));
    }

    public function testSanitizeValueStripsTags(): void
    {
        $m = $this->makeManager();
        $this->assertEquals('Hello', $m->sanitizeValue('<b>Hello</b>'));
    }

    public function testSanitizeValueRemovesModxTagsWhenDisallowed(): void
    {
        $m = $this->makeManager(['allowModxTags' => false]);
        $this->assertEquals('text  more', $m->sanitizeValue('text {[[+var]]} more'));
        $this->assertEquals('text  more', $m->sanitizeValue('text [[+var]] more'));
    }

    public function testSanitizeValueKeepsModxTagsWhenAllowed(): void
    {
        $m = $this->makeManager(['allowModxTags' => true]);
        $result = $m->sanitizeValue('text {[[+var]]} more');
        $this->assertStringContainsString('{', $result);
    }

    // ---------------------------------------------------------------
    // createLexicons()
    // ---------------------------------------------------------------

    public function testCreateLexiconsWritesIncPhpFile(): void
    {
        $m = $this->makeManager();

        $m->createLexicons([
            'page42' => [
                'hero_title' => 'Hello World',
                'hero_text'  => "It's great",
            ],
        ]);

        $filePath = $this->tmpDir . '/page42.inc.php';
        $this->assertFileExists($filePath);

        $content = file_get_contents($filePath);
        $this->assertStringStartsWith('<?php', $content);
        $this->assertStringContainsString('$_lang[\'hero_title\'] = \'Hello World\';', $content);
        $this->assertStringContainsString('$_lang[\'hero_text\'] = \'It&apos;s great\';', $content);
    }

    /**
     * Ключ нарезанной секции, пропавший из вёрстки, остаётся в файле и
     * попадает в реестр кандидатов на удаление; ключ без известного префикса
     * (ручной или чужой) остаётся, но кандидатом не становится.
     */
    public function testCreateLexiconsRecordsOrphanCandidatesInsteadOfDeleting(): void
    {
        $m = $this->makeManager();
        $m->setKnownPrefixes(['hero']);
        $m->setContext('hero', false);

        $file = $this->tmpDir . '/55.inc.php';
        file_put_contents(
            $file,
            "<?php\n\$_lang['hero_title'] = 'Заголовок';\n"
            . "\$_lang['hero_gone'] = 'Ушло из вёрстки';\n"
            . "\$_lang['manual_note'] = 'Ручной ключ';\n"
        );

        $m->createLexicons(['55' => ['hero_title' => 'Заголовок']]);

        $_lang = [];
        include $file;
        $this->assertSame('Ушло из вёрстки', $_lang['hero_gone'], 'ключ не удаляется молча');
        $this->assertSame('Ручной ключ', $_lang['manual_note']);

        $registry = new \MpcServices\Handlers\Lexicon\OrphanRegistry(dirname($this->tmpDir));
        $entries  = $registry->load(basename($this->tmpDir), '55');
        $this->assertSame(['hero_gone'], array_keys($entries));
        $this->assertSame('Ушло из вёрстки', $entries['hero_gone']['value']);
    }

    /** Вернувшийся в вёрстку ключ снимается с учёта кандидатов. */
    public function testCreateLexiconsForgetsCandidateWhenKeyReturns(): void
    {
        $m = $this->makeManager();
        $m->setKnownPrefixes(['hero']);
        $m->setContext('hero', false);

        $file = $this->tmpDir . '/56.inc.php';
        file_put_contents($file, "<?php\n\$_lang['hero_gone'] = 'Ушло';\n");
        $m->createLexicons(['56' => []]);

        $registry = new \MpcServices\Handlers\Lexicon\OrphanRegistry(dirname($this->tmpDir));
        $this->assertNotEmpty($registry->load(basename($this->tmpDir), '56'));

        $m->createLexicons(['56' => ['hero_gone' => 'Вернулось']]);
        $this->assertSame([], $registry->load(basename($this->tmpDir), '56'));
    }

    /**
     * Пустой набор НЕ удаляет словарь ресурса: сбой разбора вёрстки или
     * страница без переводимых полей сносили файл целиком вместе с принятыми
     * переводами (инцидент 01.09.2026).
     */
    public function testCreateLexiconsKeepsFileForEmptyLexicons(): void
    {
        $filePath = $this->tmpDir . '/page99.inc.php';
        file_put_contents($filePath, "<?php\n\$_lang['key'] = 'val';\n");

        $m = $this->makeManager();
        $m->createLexicons(['page99' => []]);

        $this->assertFileExists($filePath);
        $_lang = [];
        include $filePath;
        $this->assertSame('val', $_lang['key']);
    }

    public function testCreateLexiconsIncPhpIsLoadable(): void
    {
        $m = $this->makeManager();
        $m->createLexicons(['mypage' => ['section_title' => 'Заголовок']]);

        $filePath = $this->tmpDir . '/mypage.inc.php';
        $this->assertFileExists($filePath);

        include $filePath;
        $this->assertEquals('Заголовок', $_lang['section_title']);
    }

    // ---------------------------------------------------------------
    // getResourceIdentifierById()
    // ---------------------------------------------------------------


    /**
     * prepare() оставил stmt=false (SQL не подготовился — напр. невалидная
     * mpc_lexicon_filename_field). Метод НЕ фаталит на execute(), деградирует
     * на числовой id. Регресс на «Call to a member function execute() on bool».
     */
    public function testGetResourceIdentifierByIdFallsBackWhenPrepareFails(): void
    {
        $modx = new class extends \MpcTests\Stubs\ModxStub {
            public function newQuery(string $class): object {
                return new class {
                    public $stmt = false; // PDO::prepare вернул false
                    public function select(string $f): void {}
                    public function where(array $c): void {}
                    public function prepare(): void {}
                };
            }
        };
        $m = new LexiconManager($modx, [
            'useLexicons'                     => true,
            'excludeLexiconFields'            => [],
            'allowModxTags'                   => false,
            'allowedTags'                     => '',
            'lexiconFilenameField'            => 'uri',
            'staticBlocksPageLexiconFilename' => 'static',
            'contactsPageLexiconFilename'     => 'contacts',
            'basePathToLexiconFile'           => $this->tmpDir . '/',
            'corePath'                        => '',
            'resourceLexiconKeysPath'         => 'nonexistent_rlang.php',
            'resource'                        => new \stdClass(),
        ]);

        $this->assertSame('7', $m->getResourceIdentifierById(7)); // фолбэк на id, без фатала
    }

    // ---------------------------------------------------------------
    // excludeLexiconFields — числовые [...]-токены (списки/диапазоны/nth)
    // shouldLexiconize('text', <ключ>, '') → false если ключ исключён.
    // ---------------------------------------------------------------

    private function makeNumericManager(array $patterns): LexiconManager
    {
        return $this->makeManager([
            'useLexicons'              => true,
            'translatableContentTypes' => ['text'],
            'excludeLexiconFields'     => $patterns,
        ]);
    }

    public function testNumericListToken(): void
    {
        $m = $this->makeNumericManager(['cards_[6,8,10]_title']);
        $this->assertFalse($m->shouldLexiconize('text', 'cards_8_title', ''));
        $this->assertFalse($m->shouldLexiconize('text', 'cards_6_title', ''));
        $this->assertTrue($m->shouldLexiconize('text', 'cards_7_title', ''));
    }

    public function testNumericRangeToken(): void
    {
        $m = $this->makeNumericManager(['cards_[6-10]_title']);
        $this->assertFalse($m->shouldLexiconize('text', 'cards_6_title', ''));
        $this->assertFalse($m->shouldLexiconize('text', 'cards_10_title', ''));
        $this->assertTrue($m->shouldLexiconize('text', 'cards_5_title', ''));
        $this->assertTrue($m->shouldLexiconize('text', 'cards_11_title', ''));
    }

    public function testNumericNthEven(): void
    {
        $m = $this->makeNumericManager(['cards_[2n]_title']);
        $this->assertFalse($m->shouldLexiconize('text', 'cards_2_title', ''));
        $this->assertFalse($m->shouldLexiconize('text', 'cards_4_title', ''));
        $this->assertTrue($m->shouldLexiconize('text', 'cards_3_title', ''));
    }

    public function testNumericNthOddWithOffset(): void
    {
        $m = $this->makeNumericManager(['cards_[2n+1]_title']);
        $this->assertFalse($m->shouldLexiconize('text', 'cards_1_title', ''));
        $this->assertFalse($m->shouldLexiconize('text', 'cards_3_title', ''));
        $this->assertTrue($m->shouldLexiconize('text', 'cards_2_title', ''));
    }

    public function testNumericTwoTokensBothMustMatch(): void
    {
        $m = $this->makeNumericManager(['table_list_triple_[6,8,10]_subtitle_[6,8,10]']);
        $this->assertFalse($m->shouldLexiconize('text', 'table_list_triple_8_subtitle_10', ''));
        // второй idx вне списка → НЕ исключён
        $this->assertTrue($m->shouldLexiconize('text', 'table_list_triple_8_subtitle_7', ''));
    }

    public function testNumericNthWithLiteralTail(): void
    {
        $m = $this->makeNumericManager(['table_list_triple_[2n+1]_subtitle_1']);
        $this->assertFalse($m->shouldLexiconize('text', 'table_list_triple_3_subtitle_1', ''));
        $this->assertTrue($m->shouldLexiconize('text', 'table_list_triple_4_subtitle_1', ''));
        // литеральный хвост _1 не совпал
        $this->assertTrue($m->shouldLexiconize('text', 'table_list_triple_3_subtitle_2', ''));
    }

    public function testNumericRangeCombinedWithGlob(): void
    {
        $m = $this->makeNumericManager(['cards_[6-10]_*']);
        $this->assertFalse($m->shouldLexiconize('text', 'cards_7_subtitle', ''));
        $this->assertTrue($m->shouldLexiconize('text', 'cards_5_subtitle', ''));
    }

    public function testNumericTokenDoesNotBreakPlainGlob(): void
    {
        // регресс: паттерны без [ работают как раньше
        $m = $this->makeNumericManager(['*_picture', 'inline_styles']);
        $this->assertFalse($m->shouldLexiconize('text', 'inline_styles', ''));
        $this->assertFalse($m->shouldLexiconize('text', 'hero_picture', ''));
        $this->assertTrue($m->shouldLexiconize('text', 'title', ''));
    }

    // ---------------------------------------------------------------
    // excludeLexiconFields — опциональные regex-литералы (гибрид)
    // ---------------------------------------------------------------

    public function testRegexLiteralExcludes(): void
    {
        $m = $this->makeNumericManager(['/^cards_\d+_title$/']);
        $this->assertFalse($m->shouldLexiconize('text', 'cards_5_title', ''));
        $this->assertTrue($m->shouldLexiconize('text', 'cards_5_subtitle', ''));
    }

    public function testRegexLiteralWithFlags(): void
    {
        $m = $this->makeNumericManager(['~^hero~i']);
        $this->assertFalse($m->shouldLexiconize('text', 'HERO_title', ''));
        $this->assertFalse($m->shouldLexiconize('text', 'hero_title', ''));
        $this->assertTrue($m->shouldLexiconize('text', 'about_title', ''));
    }

    public function testRegexLiteralWithCharClassNotRoutedToNumeric(): void
    {
        // regex с `[...]` (char-class) идёт по regex-ветке, не по числовой
        $m = $this->makeNumericManager(['/^cards_[0-9]+$/']);
        $this->assertFalse($m->shouldLexiconize('text', 'cards_3', ''));
        $this->assertTrue($m->shouldLexiconize('text', 'cards_x', ''));
    }

    public function testInvalidRegexDoesNotMatchOrThrow(): void
    {
        // невалидный regex → не исключает и не роняет
        $m = $this->makeNumericManager(['/^cards_(/']);
        $this->assertTrue($m->shouldLexiconize('text', 'cards_5', ''));
    }

    public function testGlobNotMistakenForRegex(): void
    {
        // регресс: glob/числовые/имена НЕ трактуются как regex
        $m = $this->makeNumericManager(['*_picture', 'cards_[2n]', 'MIGX_id']);
        $this->assertFalse($m->shouldLexiconize('text', 'hero_picture', ''));
        $this->assertFalse($m->shouldLexiconize('text', 'cards_4', ''));
        $this->assertFalse($m->shouldLexiconize('text', 'MIGX_id', ''));
        $this->assertTrue($m->shouldLexiconize('text', 'title', ''));
    }

    // ---------------------------------------------------------------
    // createLexicons() — мерж без overwrite (без updContent)
    // ---------------------------------------------------------------

    /**
     * Без overwrite: значение живого поля сохраняется (не перезаписывается
     * шаблоном); новый ключ берёт значение из шаблона; ключ поля, ушедшего из
     * вёрстки, ОСТАЁТСЯ в файле — удаление стало отдельным явным действием,
     * а нарезка только помечает такой ключ кандидатом.
     */
    public function testCreateLexiconsPreservesLiveAndKeepsOrphanWithoutOverwrite(): void
    {
        $lm = $this->makeManager();
        $file = $this->tmpDir . '/7.inc.php';
        file_put_contents($file, "<?php\n\$_lang['k_shared'] = 'admin перевод';\n\$_lang['k_old'] = 'удалённое поле';\n");

        // в нарезке: k_shared (живо, другое значение) + k_new (новое); k_old удалён
        $lm->createLexicons([7 => ['k_shared' => 'из шаблона', 'k_new' => 'новое поле']], false);

        $_lang = [];
        include $file;
        $this->assertSame('admin перевод', $_lang['k_shared']);    // живое → значение сохранено
        $this->assertSame('новое поле', $_lang['k_new']);          // новое → из шаблона
        $this->assertSame('удалённое поле', $_lang['k_old']);      // ушло из вёрстки → значение цело
    }

    /**
     * ЕДИНЫЙ формат ключа (static) — им же пользуется редактор (FieldWriter),
     * чтобы ключи не разъезжались. idx=0 → БЕЗ суффикса (как у грабера на сайте).
     */
    /** Нормализация поля имени файла: ТОЛЬКО id/alias, иначе → id. */
    public function testNormalizeFilenameField(): void
    {
        $this->assertSame('alias', LexiconManager::normalizeFilenameField('alias'));
        $this->assertSame('id', LexiconManager::normalizeFilenameField('id'));
        $this->assertSame('id', LexiconManager::normalizeFilenameField('uri'));       // uri не допускается → id
        $this->assertSame('id', LexiconManager::normalizeFilenameField('pagetitle')); // произвольное → id
        $this->assertSame('id', LexiconManager::normalizeFilenameField(''));
        $this->assertSame('id', LexiconManager::normalizeFilenameField(null));
        $this->assertSame('alias', LexiconManager::normalizeFilenameField('  alias  ')); // trim
    }

    public function testGetLexiconKeyFormat(): void
    {
        $this->assertSame('p_title', LexiconManager::getLexiconKey(['prefix' => 'p', 'fieldName' => 'title']));
        $this->assertSame('p_list_title', LexiconManager::getLexiconKey(['prefix' => 'p', 'parentFieldName' => 'list', 'fieldName' => 'title', 'idx' => 0]));
        $this->assertSame('p_list_title_2', LexiconManager::getLexiconKey(['prefix' => 'p', 'parentFieldName' => 'list', 'fieldName' => 'title', 'idx' => 2]));
    }

    /** С overwrite=true: значение из шаблона перезаписывает существующее. */
    public function testCreateLexiconsOverwritesWithFlag(): void
    {
        $lm = $this->makeManager();
        $file = $this->tmpDir . '/8.inc.php';
        file_put_contents($file, "<?php\n\$_lang['k_shared'] = 'admin перевод';\n");

        $lm->createLexicons([8 => ['k_shared' => 'из шаблона']], true);

        $_lang = [];
        include $file;
        $this->assertSame('из шаблона', $_lang['k_shared']); // overwrite → шаблон
    }

    /**
     * Интеграция: createLexicons → syncOtherLanguages наполняет pending-реестр
     * непереведённых ключей для неосновного языка (НОВЫЕ ключи), а уже
     * переведённый ключ туда не попадает.
     */
    public function testSyncWritesPendingForNewKeys(): void
    {
        $modx = new \MpcTests\Stubs\ModxStub(null, ['mpc_available_languages' => 'en']);

        $base = $this->tmpDir . '/lex/';
        mkdir($base . 'ru', 0777, true);
        mkdir($base . 'en', 0777, true);
        // en уже содержит перевод title → title НЕ должен стать pending
        file_put_contents($base . 'en/7.inc.php', "<?php\n\$_lang['title'] = 'Title EN';\n");

        $resource = new class {
            public function get(string $k): mixed { return $k === 'id' ? 7 : null; }
        };

        $lm = new LexiconManager($modx, [
            'useLexicons'             => true,
            'excludeLexiconFields'    => [],
            'allowModxTags'           => false,
            'allowedTags'             => '',
            'lexiconFilenameField'    => 'id',
            'staticBlocksPageLexiconFilename' => 'static',
            'contactsPageLexiconFilename'     => 'contacts',
            'basePathToLexiconFile'   => $base . 'ru/',
            'corePath'                => $this->tmpDir . '/',
            'lexiconPath'             => 'lex/',
            'defaultLanguageKey'      => 'ru',
            'resourceLexiconKeysPath' => 'nonexistent_rlang.php',
            'resource'                => $resource,
        ]);

        $lm->createLexicons(['7' => ['title' => 'Заголовок', 'lead' => 'Лид', 'cta' => 'Кнопка']], true);

        $pending = new \MpcServices\Handlers\PendingTranslations($base);
        // lead/cta — новые (не было в en) → pending; title уже переведён → нет.
        // Порядок задаёт файл дефолтного языка, а он пишется отсортированным.
        $actual = $pending->load('en', '7');
        sort($actual);
        $this->assertSame(['cta', 'lead'], $actual);
        // en-файл получил все ключи (новые — плейсхолдер дефолта)
        $_lang = [];
        include $base . 'en/7.inc.php';
        $this->assertSame('Title EN', $_lang['title']);   // существующий перевод цел
        $this->assertSame('Лид', $_lang['lead']);          // новый ключ = дефолт-плейсхолдер

        // Плоский tearDown класса не умеет в подпапки — чистим дерево сами.
        $this->rrmdir($base);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            $p = $dir . '/' . $f;
            is_dir($p) ? $this->rrmdir($p) : unlink($p);
        }
        rmdir($dir);
    }
}
