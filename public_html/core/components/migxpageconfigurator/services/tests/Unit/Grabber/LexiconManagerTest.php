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

    public function testSetLexiconsReturnsOriginalForExcludedParentField(): void
    {
        $m = $this->makeManager(['excludeLexiconFields' => ['items']]);
        $m->setContext('hero', false);

        $result = $m->setLexicons('Some text', ['fieldName' => 'text', 'parentFieldName' => 'items']);

        $this->assertEquals('Some text', $result);
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

    public function testSetLexiconsExcludesParentFieldByWildcard(): void
    {
        $m = $this->makeManager(['excludeLexiconFields' => ['media_*']]);
        $m->setContext('hero', false);

        $result = $m->setLexicons('/z.mp4', ['fieldName' => 'src', 'parentFieldName' => 'media_video']);
        $this->assertEquals('/z.mp4', $result);
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

    public function testShouldLexiconizeFalseForExcludedParentFieldName(): void
    {
        $m = $this->makeManager([
            'useLexicons'              => true,
            'translatableContentTypes' => ['text', 'image'],
            'excludeLexiconFields'     => ['compare_list_compare_product'],
        ]);
        $this->assertFalse($m->shouldLexiconize('text', 'title', 'compare_list_compare_product'));
    }

    public function testShouldLexiconizeRespectsGlobPatternsInExclude(): void
    {
        $m = $this->makeManager([
            'useLexicons'              => true,
            'translatableContentTypes' => ['text', 'image'],
            'excludeLexiconFields'     => ['*_picture', 'img*'],
        ]);
        // fieldName suffix-glob
        $this->assertFalse($m->shouldLexiconize('image', 'main_picture', ''));
        // fieldName prefix-glob
        $this->assertFalse($m->shouldLexiconize('image', 'img_pc', ''));
        // parent suffix-glob
        $this->assertFalse($m->shouldLexiconize('image', 'src', 'hero_picture'));
        // не под паттерн
        $this->assertTrue($m->shouldLexiconize('image', 'logo_src', 'banner'));
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
}
