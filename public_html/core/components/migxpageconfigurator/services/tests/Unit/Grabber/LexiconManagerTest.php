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

    public function testSetLexiconsReturnsLexiconTag(): void
    {
        $m = $this->makeManager();
        $m->setContext('hero', false);

        $result = $m->setLexicons('Hello World', ['fieldName' => 'title']);

        $this->assertEquals("{'hero_title' | lexicon}", $result);
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

        $this->assertEquals("{'team_members_name_1' | lexicon}", $result);
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
