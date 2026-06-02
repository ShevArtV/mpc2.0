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
}
