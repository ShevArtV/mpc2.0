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

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*') as $f) {
            unlink($f);
        }
        rmdir($this->tmpDir);
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

    public function testCreateLexiconsDeletesFileForEmptyLexicons(): void
    {
        $filePath = $this->tmpDir . '/page99.inc.php';
        file_put_contents($filePath, '<?php $_lang["key"] = "val";');

        $m = $this->makeManager();
        $m->createLexicons(['page99' => []]);

        $this->assertFileDoesNotExist($filePath);
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
    // getResourceIdentifierById() — uri mode
    // ---------------------------------------------------------------

    /**
     * Создаёт LexiconManager с modX-заглушкой, чья newQuery() вернёт заданный uri.
     */
    private function makeManagerWithUri(string $uri): LexiconManager
    {
        $modx = new class($uri) extends \MpcTests\Stubs\ModxStub {
            private string $fakeUri;
            public function __construct(string $uri) {
                parent::__construct();
                $this->fakeUri = $uri;
            }
            public function newQuery(string $class): object {
                $uri = $this->fakeUri;
                return new class($uri) {
                    private string $uri;
                    public object $stmt;
                    public function __construct(string $uri) {
                        $this->uri = $uri;
                        $u = $uri;
                        $this->stmt = new class($u) {
                            private string $v;
                            public function __construct(string $v) { $this->v = $v; }
                            public function execute(): bool { return true; }
                            public function fetchColumn(): mixed { return $this->v; }
                        };
                    }
                    public function select(string $f): void {}
                    public function where(array $c): void {}
                    public function prepare(): void {}
                };
            }
        };

        $props = [
            'useLexicons'                    => true,
            'excludeLexiconFields'           => [],
            'allowModxTags'                  => false,
            'allowedTags'                    => '',
            'lexiconFilenameField'           => 'uri',
            'staticBlocksPageLexiconFilename' => 'static',
            'contactsPageLexiconFilename'    => 'contacts',
            'basePathToLexiconFile'          => $this->tmpDir . '/',
            'corePath'                       => '',
            'resourceLexiconKeysPath'        => 'nonexistent_rlang.php',
            'resource'                       => new \stdClass(),
        ];

        return new LexiconManager($modx, $props);
    }

    public function testGetResourceIdentifierByIdUriSimple(): void
    {
        $m = $this->makeManagerWithUri('about/');
        $this->assertEquals('about', $m->getResourceIdentifierById(5));
    }

    public function testGetResourceIdentifierByIdUriNested(): void
    {
        $m = $this->makeManagerWithUri('services/team/');
        $this->assertEquals('services_team', $m->getResourceIdentifierById(5));
    }

    public function testGetResourceIdentifierByIdUriRoot(): void
    {
        $m = $this->makeManagerWithUri('');
        $this->assertEquals('root', $m->getResourceIdentifierById(1));
    }

    public function testGetResourceIdentifierByIdUriRootSlash(): void
    {
        $m = $this->makeManagerWithUri('/');
        $this->assertEquals('root', $m->getResourceIdentifierById(1));
    }

    public function testGetResourceIdentifierByIdUriLeadingSlash(): void
    {
        $m = $this->makeManagerWithUri('/about/');
        $this->assertEquals('about', $m->getResourceIdentifierById(5));
    }

    public function testGetResourceIdentifierByIdUriDeepNested(): void
    {
        $m = $this->makeManagerWithUri('services/consulting/team/');
        $this->assertEquals('services_consulting_team', $m->getResourceIdentifierById(5));
    }

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
     * шаблоном); новый ключ берёт значение из шаблона; ключ удалённого поля
     * (нет в текущей нарезке) ВЫПАДАЕТ — иначе orphan маскировал бы новое значение.
     */
    public function testCreateLexiconsPreservesLiveDropsOrphanWithoutOverwrite(): void
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
        $this->assertArrayNotHasKey('k_old', $_lang);              // удалённое поле → orphan выпал
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
}
