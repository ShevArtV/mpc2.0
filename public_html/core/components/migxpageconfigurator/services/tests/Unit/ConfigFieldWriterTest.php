<?php

namespace MpcTests\Unit;

use MpcServices\Handlers\ConfigFieldWriter;
use PHPUnit\Framework\TestCase;

/**
 * Тесты чистой мутации mpc_config (ConfigFieldWriter).
 */
class ConfigFieldWriterTest extends TestCase
{
    private function sampleConfig(): string
    {
        return json_encode([
            '1' => [
                'section_name'  => 'hero',
                'MIGX_formname' => 'mpc_hero',
                'position'      => 1,
                'title'         => 'Старый заголовок',
                'cards'         => json_encode([
                    ['MIGX_id' => 1, 'title' => 'Карточка 1'],
                    ['MIGX_id' => 2, 'title' => 'Карточка 2'],
                ], JSON_UNESCAPED_UNICODE),
            ],
            '2' => [
                'section_name'  => 'about',
                'MIGX_formname' => 'mpc_about',
                'position'      => 2,
                'text'          => 'О нас',
            ],
        ], JSON_UNESCAPED_UNICODE);
    }

    public function testSetsTopLevelField(): void
    {
        $w = new ConfigFieldWriter();
        $res = $w->setValue($this->sampleConfig(), ['section' => 'hero', 'fieldName' => 'title'], 'Новый');
        $this->assertTrue($res['success'], $res['message']);

        $config = json_decode($res['data']['json'], true);
        $this->assertSame('Новый', $config['1']['title']);
        $this->assertSame('О нас', $config['2']['text'], 'другие секции не тронуты');
    }

    public function testSetsMigxRowField(): void
    {
        $w = new ConfigFieldWriter();
        $res = $w->setValue(
            $this->sampleConfig(),
            ['section' => 'hero', 'parentField' => 'cards', 'idx' => 1, 'fieldName' => 'title'],
            'Карточка ДВА'
        );
        $this->assertTrue($res['success'], $res['message']);

        $config = json_decode($res['data']['json'], true);
        $rows = json_decode($config['1']['cards'], true);
        $this->assertSame('Карточка ДВА', $rows[1]['title']);
        $this->assertSame('Карточка 1', $rows[0]['title'], 'соседняя строка не тронута');
    }

    public function testFindsSectionByMigxFormname(): void
    {
        $w = new ConfigFieldWriter();
        $res = $w->setValue($this->sampleConfig(), ['section' => 'mpc_about', 'fieldName' => 'text'], 'Изменено');
        $this->assertTrue($res['success'], $res['message']);
        $config = json_decode($res['data']['json'], true);
        $this->assertSame('Изменено', $config['2']['text']);
    }

    public function testRejectsUnknownSection(): void
    {
        $w = new ConfigFieldWriter();
        $res = $w->setValue($this->sampleConfig(), ['section' => 'nope', 'fieldName' => 'title'], 'x');
        $this->assertFalse($res['success']);
        $this->assertStringContainsString('section not found', $res['message']);
    }

    public function testRejectsUnknownRow(): void
    {
        $w = new ConfigFieldWriter();
        $res = $w->setValue(
            $this->sampleConfig(),
            ['section' => 'hero', 'parentField' => 'cards', 'idx' => 9, 'fieldName' => 'title'],
            'x'
        );
        $this->assertFalse($res['success']);
        $this->assertStringContainsString('row not found', $res['message']);
    }

    public function testRejectsInvalidJson(): void
    {
        $w = new ConfigFieldWriter();
        $res = $w->setValue('not-json', ['section' => 'hero', 'fieldName' => 'title'], 'x');
        $this->assertFalse($res['success']);
    }

    public function testGetValueTopLevelAndRow(): void
    {
        $w = new ConfigFieldWriter();
        $this->assertSame(
            'Старый заголовок',
            $w->getValue($this->sampleConfig(), ['section' => 'hero', 'fieldName' => 'title'])['data']['value']
        );
        $this->assertSame(
            'Карточка 2',
            $w->getValue($this->sampleConfig(), ['section' => 'hero', 'parentField' => 'cards', 'idx' => 1, 'fieldName' => 'title'])['data']['value']
        );
    }

    // --- row-операции: add / delete / move -------------------------------

    /** Декодирует строки списка cards из результата операции. */
    private function cardsOf(array $res): array
    {
        $config = json_decode($res['data']['json'], true);
        return json_decode($config['1']['cards'], true);
    }

    public function testAddRowAppendsEmptyByTemplate(): void
    {
        $w = new ConfigFieldWriter();
        $res = $w->addRow($this->sampleConfig(), ['section' => 'hero', 'parentField' => 'cards']);
        $this->assertTrue($res['success'], $res['message']);

        $rows = $this->cardsOf($res);
        $this->assertCount(3, $rows);
        $this->assertSame(3, $rows[2]['MIGX_id']);   // max+1
        $this->assertSame('', $rows[2]['title']);    // структура из первой строки, значение пустое
    }

    public function testDeleteRow(): void
    {
        $w = new ConfigFieldWriter();
        $res = $w->deleteRow($this->sampleConfig(), ['section' => 'hero', 'parentField' => 'cards', 'idx' => 0]);
        $this->assertTrue($res['success'], $res['message']);

        $rows = $this->cardsOf($res);
        $this->assertCount(1, $rows);
        $this->assertSame('Карточка 2', $rows[0]['title']); // осталась вторая, переиндексирована
    }

    public function testDeleteRowRejectsBadIdx(): void
    {
        $w = new ConfigFieldWriter();
        $res = $w->deleteRow($this->sampleConfig(), ['section' => 'hero', 'parentField' => 'cards', 'idx' => 9]);
        $this->assertFalse($res['success']);
    }

    public function testMoveRowReordersAndValuesTravel(): void
    {
        $w = new ConfigFieldWriter();
        $res = $w->moveRow($this->sampleConfig(), ['section' => 'hero', 'parentField' => 'cards', 'fromIdx' => 0, 'toIdx' => 1]);
        $this->assertTrue($res['success'], $res['message']);

        $rows = $this->cardsOf($res);
        $this->assertSame('Карточка 2', $rows[0]['title']); // порядок переставлен
        $this->assertSame('Карточка 1', $rows[1]['title']); // значение уехало вместе со строкой
    }

    public function testMoveRowRejectsOutOfBounds(): void
    {
        $w = new ConfigFieldWriter();
        // toIdx за пределами диапазона существующих строк → отказ (не молча клампит).
        $bad = $w->moveRow($this->sampleConfig(), ['section' => 'hero', 'parentField' => 'cards', 'fromIdx' => 0, 'toIdx' => 9]);
        $this->assertFalse($bad['success']);
        // fromIdx вне диапазона — тоже отказ.
        $bad2 = $w->moveRow($this->sampleConfig(), ['section' => 'hero', 'parentField' => 'cards', 'fromIdx' => 9, 'toIdx' => 0]);
        $this->assertFalse($bad2['success']);
    }

    // --- path: вложенные списки (произвольная глубина) -------------------

    private function nestedConfig(): string
    {
        return json_encode([
            '1' => [
                'section_name'  => 'cat',
                'MIGX_formname' => 'mpc_cat',
                'title'         => 'Топ-заголовок',
                'catalog'       => json_encode([
                    ['MIGX_id' => 1, 'title' => 'Группа', 'items' => json_encode([
                        ['MIGX_id' => 1, 'title' => 'Старый товар'],
                    ], JSON_UNESCAPED_UNICODE)],
                ], JSON_UNESCAPED_UNICODE),
            ],
        ], JSON_UNESCAPED_UNICODE);
    }

    public function testSetValueByNestedPath(): void
    {
        $w = new ConfigFieldWriter();
        $address = [
            'section'   => 'cat',
            'fieldName' => 'title',
            'path'      => [['field' => 'catalog', 'idx' => 0], ['field' => 'items', 'idx' => 0]],
        ];
        $res = $w->setValue($this->nestedConfig(), $address, 'Новый товар');
        $this->assertTrue($res['success'], $res['message']);

        $decoded = json_decode($res['data']['json'], true);
        $catalog = json_decode($decoded['1']['catalog'], true);
        $items   = json_decode($catalog[0]['items'], true);
        $this->assertSame('Новый товар', $items[0]['title']);   // вложенное поле записано
        $this->assertSame('Группа', $catalog[0]['title']);      // родительская строка цела
        $this->assertSame('Топ-заголовок', $decoded['1']['title']); // top-level title НЕ затронут
    }

    public function testGetValueByNestedPath(): void
    {
        $w = new ConfigFieldWriter();
        $address = [
            'section'   => 'cat',
            'fieldName' => 'title',
            'path'      => [['field' => 'catalog', 'idx' => 0], ['field' => 'items', 'idx' => 0]],
        ];
        $this->assertSame('Старый товар', $w->getValue($this->nestedConfig(), $address)['data']['value']);
    }

    public function testSetValueByNestedPathBadIdxFails(): void
    {
        $w = new ConfigFieldWriter();
        $address = [
            'section'   => 'cat',
            'fieldName' => 'title',
            'path'      => [['field' => 'catalog', 'idx' => 0], ['field' => 'items', 'idx' => 9]],
        ];
        $this->assertFalse($w->setValue($this->nestedConfig(), $address, 'x')['success']);
    }

    // --- row-операции по path: вложенные списки + deep-clear --------------

    public function testAddRowDeepClearsNestedStructure(): void
    {
        $w = new ConfigFieldWriter();
        // добавляем строку в top-level catalog: новая строка сохраняет СТРУКТУРУ
        // вложенного items (1 строка), но значения пустые → дочерний список заполняем.
        $res = $w->addRow($this->nestedConfig(), ['section' => 'cat', 'parentField' => 'catalog']);
        $this->assertTrue($res['success'], $res['message']);

        $catalog = json_decode(json_decode($res['data']['json'], true)['1']['catalog'], true);
        $this->assertCount(2, $catalog);
        $newRow = $catalog[1];
        $this->assertSame(2, $newRow['MIGX_id']);
        $this->assertSame('', $newRow['title']);

        $items = json_decode($newRow['items'], true);
        $this->assertCount(1, $items, 'структура вложенного списка сохранена');
        $this->assertSame('', $items[0]['title'], 'значения вложенной строки очищены');
        $this->assertSame(1, $items[0]['MIGX_id']);
    }

    public function testAddRowToNestedListByPath(): void
    {
        $w = new ConfigFieldWriter();
        $address = [
            'section'     => 'cat',
            'parentField' => 'items',
            'path'        => [['field' => 'catalog', 'idx' => 0]],
        ];
        $res = $w->addRow($this->nestedConfig(), $address);
        $this->assertTrue($res['success'], $res['message']);

        $catalog = json_decode(json_decode($res['data']['json'], true)['1']['catalog'], true);
        $items = json_decode($catalog[0]['items'], true);
        $this->assertCount(2, $items, 'во вложенный список добавлена строка');
        $this->assertSame('Старый товар', $items[0]['title'], 'существующая строка цела');
        $this->assertSame(2, $items[1]['MIGX_id']);
        $this->assertSame('', $items[1]['title']);
    }

    public function testDeleteAndMoveRowInNestedListByPath(): void
    {
        $w = new ConfigFieldWriter();
        $base = [
            'section'     => 'cat',
            'parentField' => 'items',
            'path'        => [['field' => 'catalog', 'idx' => 0]],
        ];
        // добавляем 2-ю вложенную строку и пишем в неё значение
        $cfg = $w->addRow($this->nestedConfig(), $base)['data']['json'];
        $cfg = $w->setValue($cfg, [
            'section'   => 'cat', 'fieldName' => 'title',
            'path'      => [['field' => 'catalog', 'idx' => 0], ['field' => 'items', 'idx' => 1]],
        ], 'Второй')['data']['json'];

        // move 0 -> 1 (значение едет со строкой)
        $moved = $w->moveRow($cfg, array_merge($base, ['fromIdx' => 0, 'toIdx' => 1]));
        $this->assertTrue($moved['success'], $moved['message']);
        $catalog = json_decode(json_decode($moved['data']['json'], true)['1']['catalog'], true);
        $items = json_decode($catalog[0]['items'], true);
        $this->assertSame('Второй', $items[0]['title']);
        $this->assertSame('Старый товар', $items[1]['title']);

        // delete idx 0 из вложенного списка
        $del = $w->deleteRow($cfg, array_merge($base, ['idx' => 0]));
        $this->assertTrue($del['success'], $del['message']);
        $catalog2 = json_decode(json_decode($del['data']['json'], true)['1']['catalog'], true);
        $items2 = json_decode($catalog2[0]['items'], true);
        $this->assertCount(1, $items2);
        $this->assertSame('Второй', $items2[0]['title']);
    }

    public function testRowOpBadNestedPathFails(): void
    {
        $w = new ConfigFieldWriter();
        $res = $w->addRow($this->nestedConfig(), [
            'section'     => 'cat',
            'parentField' => 'items',
            'path'        => [['field' => 'catalog', 'idx' => 9]],
        ]);
        $this->assertFalse($res['success']);
    }

    // --- реальный формат грабера: вложенный список = НАТИВНЫЙ массив ------
    // ContentParser json-кодирует только ВЕРХНИЙ уровень поля, поэтому вложенный
    // список внутри строки лежит нативным массивом (не JSON-строкой). Регрессия:
    // «row not found in path for field img» при сохранении img нового элемента.

    private function nativeNestedConfig(): string
    {
        return json_encode([
            '1' => [
                'section_name'  => 'cat',
                'MIGX_formname' => 'mpc_cat',
                'catalog'       => json_encode([
                    ['MIGX_id' => 1, 'title' => 'Группа', 'items' => [
                        ['MIGX_id' => 1, 'title' => 'Товар', 'img' => [['MIGX_id' => 1, 'src' => 'a.jpg', 'alt' => 'A']]],
                    ]],
                ], JSON_UNESCAPED_UNICODE),
            ],
        ], JSON_UNESCAPED_UNICODE);
    }

    public function testAddRowDeepClearsNativeNestedStructure(): void
    {
        $w = new ConfigFieldWriter();
        $res = $w->addRow($this->nativeNestedConfig(), ['section' => 'cat', 'parentField' => 'catalog']);
        $this->assertTrue($res['success'], $res['message']);

        $catalog = json_decode(json_decode($res['data']['json'], true)['1']['catalog'], true);
        $this->assertCount(2, $catalog);
        $new = $catalog[1];
        $items = $new['items'];
        $this->assertIsArray($items, 'вложенный нативный список сохранён как массив');
        $this->assertCount(1, $items, 'структура вложенного списка не затёрта');
        $this->assertSame('', $items[0]['title'], 'значения вложенной строки очищены');
        $this->assertSame('', $items[0]['img'][0]['src'], 'img внутри вложенной строки очищен, структура цела');
    }

    public function testSetNestedImgInNewTopRowResolves(): void
    {
        $w = new ConfigFieldWriter();
        // добавляем новую верхнюю строку (с вложенной структурой), затем пишем img
        // в её вложенный элемент по path — раньше падало «row not found».
        $cfg = $w->addRow($this->nativeNestedConfig(), ['section' => 'cat', 'parentField' => 'catalog'])['data']['json'];
        $res = $w->setValue($cfg, [
            'section'   => 'cat',
            'fieldName' => 'img',
            'path'      => [['field' => 'catalog', 'idx' => 1], ['field' => 'items', 'idx' => 0]],
        ], json_encode([['MIGX_id' => 1, 'src' => 'new.jpg']]));
        $this->assertTrue($res['success'], $res['message']);

        $catalog = json_decode(json_decode($res['data']['json'], true)['1']['catalog'], true);
        // items после правки через mutateAtPath перекодирован в JSON-строку.
        $items = is_string($catalog[1]['items']) ? json_decode($catalog[1]['items'], true) : $catalog[1]['items'];
        $img = is_string($items[0]['img']) ? json_decode($items[0]['img'], true) : $items[0]['img'];
        $this->assertSame('new.jpg', $img[0]['src']);
    }
}
