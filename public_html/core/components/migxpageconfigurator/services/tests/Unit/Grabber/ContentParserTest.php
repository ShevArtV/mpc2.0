<?php

namespace MpcTests\Unit\Grabber;

use DiDom\Document;
use MpcServices\Handlers\Grabber\ContentParser;
use MpcServices\Handlers\Grabber\FieldValueExtractor;
use MpcServices\Handlers\Grabber\LexiconManager;
use MpcServices\Handlers\Grabber\MediaDownloader;
use MpcServices\Handlers\Parser;
use MpcTests\Snapshot\SnapshotAssertion;
use MpcTests\Stubs\ModxStub;
use PHPUnit\Framework\TestCase;

/**
 * Тесты ContentParser.getFieldsValues() — нет зависимости от modX.
 */
class ContentParserTest extends TestCase
{
    use SnapshotAssertion;

    private function makeParser(array $extraProperties = []): ContentParser
    {
        $fixturesDir = dirname(__DIR__, 2) . '/Fixtures';
        $modx        = new ModxStub($fixturesDir);
        $parser      = new Parser();
        $properties  = array_merge([
            'translatableContentTypes' => [],
            'useLexicons'              => false,
            'excludeLexiconFields'     => [],
            'allowModxTags'            => false,
            'allowedTags'              => [],
        ], $extraProperties);

        $mediaDownloader    = new MediaDownloader($modx, $properties);
        $lexiconManager     = new LexiconManager($modx, $properties);
        $fieldValueExtractor = new FieldValueExtractor($properties, $mediaDownloader, $lexiconManager, $parser);

        return new ContentParser($parser, $fieldValueExtractor);
    }

    // ---------------------------------------------------------------
    // Unit-тесты pure поведения
    // ---------------------------------------------------------------

    public function testReturnsEmptyArrayForHtmlWithoutFields(): void
    {
        $parser = $this->makeParser();
        $result = $parser->getFieldsValues('<div><p>Hello</p></div>');
        $this->assertSame([], $result);
    }

    public function testExtractsTextFieldValue(): void
    {
        $parser = $this->makeParser();
        $result = $parser->getFieldsValues('<div data-mpc-field="title">Hello World</div>');
        $this->assertEquals('Hello World', $result['title']);
    }

    public function testExtractsImageFieldValue(): void
    {
        $parser = $this->makeParser();
        $result = $parser->getFieldsValues(
            '<img data-mpc-field="image" src="images/test.jpg" width="800" height="600" alt="Test">'
        );
        $this->assertArrayHasKey('image', $result);
        $imageData = json_decode($result['image'], true);
        $this->assertEquals('images/test.jpg', $imageData[0]['src']);
        $this->assertEquals('Test', $imageData[0]['alt']);
        $this->assertEquals('800', $imageData[0]['width']);
    }

    public function testExtractsImageTitleAttribute(): void
    {
        $parser = $this->makeParser();
        $result = $parser->getFieldsValues(
            '<img data-mpc-field="image" src="images/test.jpg" alt="A" title="Tooltip">'
        );
        $imageData = json_decode($result['image'], true);
        $this->assertEquals('Tooltip', $imageData[0]['title']);
    }

    public function testExtractsVideoTitleAttribute(): void
    {
        $parser = $this->makeParser();
        $result = $parser->getFieldsValues(
            '<video data-mpc-field="clip" src="m.mp4" title="Clip tooltip" controls></video>'
        );
        $mediaData = json_decode($result['clip'], true);
        $this->assertEquals('Clip tooltip', $mediaData[0]['title']);
    }

    public function testExtractsBackgroundImageValue(): void
    {
        $parser = $this->makeParser();
        $result = $parser->getFieldsValues(
            '<div data-mpc-field="bg_img" style="background-image: url(\'images/bg.jpg\');"></div>'
        );
        $this->assertEquals('images/bg.jpg', $result['bg_img']);
    }

    public function testExtractsNestedItemsAsArray(): void
    {
        $parser = $this->makeParser();
        // Nested item fields use data-mpc-field-1 (level suffix) for Grabber to extract them into the item array.
        // With plain data-mpc-field, inner fields are extracted as top-level keys in addition to the items array.
        $html = '
            <div data-mpc-field="items">
                <article data-mpc-item="items">
                    <h3 data-mpc-field-1="item_title">Title 1</h3>
                    <p data-mpc-field-1="item_text">Text 1</p>
                </article>
            </div>
        ';
        $result = $parser->getFieldsValues($html);

        $this->assertArrayHasKey('items', $result);
        $items = json_decode($result['items'], true);
        $this->assertEquals(1, $items[0]['MIGX_id']);
        $this->assertEquals('Title 1', $items[0]['item_title']);
        $this->assertEquals('Text 1', $items[0]['item_text']);
    }

    public function testExtractsHrefAsValue(): void
    {
        $parser = $this->makeParser();
        $result = $parser->getFieldsValues(
            '<a data-mpc-field="link" href="https://example.com">Click</a>'
        );
        $this->assertEquals('https://example.com', $result['link']);
    }

    // ---------------------------------------------------------------
    // Snapshot-тест: about-секция из sample.html
    // ---------------------------------------------------------------

    public function testSnapshotAboutSectionFields(): void
    {
        $parser      = $this->makeParser();
        $sampleHtml  = file_get_contents(dirname(__DIR__, 2) . '/Fixtures/html/sample.html');
        $doc         = new Document($sampleHtml);
        $aboutSection = $doc->find('[data-mpc-section="about"]')[0];

        $result = $parser->getFieldsValues((new Parser())->getHTMLString($aboutSection));
        $actual = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $this->assertMatchesSnapshot($actual, 'grabber/about_fields.json');
    }

    // ---------------------------------------------------------------
    // listbox: статический список нормализуется, @SELECT — нет
    // ---------------------------------------------------------------

    public function testNormalizesStaticListboxValue(): void
    {
        $parser = $this->makeParser();
        // Статический список опций: выбранное значение приводится к ключ-форме
        // (транслит + lowercase), чтобы совпасть с ключами опций.
        $result = $parser->getFieldsValues(
            '<div data-mpc-field="color" data-mpc-values="Красный||Синий">Синий</div>'
        );
        $this->assertSame('sinij', $result['color']);
    }

    public function testDoesNotNormalizeDynamicListboxValue(): void
    {
        $parser = $this->makeParser();
        // @SELECT-опции: реальное значение — алиас ресурса из БД. Нормализация
        // порезала бы дефисы/регистр/не-латиницу, поэтому значение должно
        // сохраниться как есть.
        $result = $parser->getFieldsValues(
            '<div data-mpc-field="page" data-mpc-values="@SELECT pagetitle, id FROM modx_site_content">My-Page-Alias</div>'
        );
        $this->assertSame('My-Page-Alias', $result['page']);
    }

    public function testListboxDecisionTakenFromFirstListItem(): void
    {
        $parser = $this->makeParser();
        // Поле списка определяется ОДИН раз: тип/множественность listbox берутся
        // по ПЕРВОМУ item. Тут item[0] — одиночный listbox, item[1] объявлен как
        // listbox-multiple с другими опциями — но переопределять не должен.
        $html = '
            <div data-mpc-field="cards">
                <div data-mpc-item="cards">
                    <span data-mpc-field-1="opt" data-mpc-values="A||B" data-mpc-ftype="listbox">A</span>
                </div>
                <div data-mpc-item="cards">
                    <span data-mpc-field-1="opt" data-mpc-values="X||Y" data-mpc-ftype="listbox-multiple">X,Y</span>
                </div>
            </div>
        ';
        $result = $parser->getFieldsValues($html);
        $cards  = json_decode($result['cards'], true);
        // Первый item — одиночный listbox: значение нормализуется как одно.
        $this->assertSame('a', $cards[0]['opt']);
        // Второй item: решение «одиночный» взято от первого, поэтому "X,Y" НЕ
        // разбивается в набор ключей через "||" (что дал бы listbox-multiple),
        // а нормализуется как одно значение → "xy" (не "x||y").
        $this->assertSame('xy', $cards[1]['opt']);
    }
}
