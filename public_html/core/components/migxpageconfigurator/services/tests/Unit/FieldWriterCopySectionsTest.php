<?php

namespace MpcTests\Unit;

use MpcServices\Handlers\FieldWriter;
use MpcTests\Stubs\ModxObjectStub;
use MpcTests\Stubs\ModxStub;
use PHPUnit\Framework\TestCase;

/**
 * copySectionsFromType: копирование секций тип-страницы в ресурс.
 *  - merge (default): добавляются только отсутствующие (ключ MIGX_formname|lexicon_prefix);
 *  - overwrite: конфиг ресурса заменяется конфигом типа.
 * Лексиконы выключены (useLexicons=false) — проверяем чистую логику конфига.
 */
class FieldWriterCopySectionsTest extends TestCase
{
    private function section(string $form, string $prefix, string $title): array
    {
        return ['MIGX_formname' => $form, 'lexicon_prefix' => $prefix, 'section_name' => $title];
    }

    /** modX-стаб: int id → ресурс; массив-условие [parent,template] → тип-ресурс. */
    private function makeModx(ModxObjectStub $resource, ?ModxObjectStub $typeRes): ModxStub
    {
        return new class($resource, $typeRes) extends ModxStub {
            private ModxObjectStub $res;
            private $type;
            public function __construct(ModxObjectStub $res, $type)
            {
                parent::__construct(null, []);
                $this->res = $res;
                $this->type = $type;
            }
            public function getObject(string $class, $conditions = null): ?object
            {
                if ($class !== 'modResource') {
                    return null;
                }
                return is_array($conditions) ? $this->type : $this->res; // array → запрос типа
            }
        };
    }

    private function resourceWith(array $sections): ModxObjectStub
    {
        return new ModxObjectStub('modResource', [
            'id' => 10, 'template' => 7, 'context_key' => 'web',
            'tv_mpc_config' => json_encode($sections, JSON_UNESCAPED_UNICODE),
        ]);
    }

    private function typeWith(array $sections): ModxObjectStub
    {
        return new ModxObjectStub('modResource', [
            'id' => 43, 'tv_mpc_config' => json_encode($sections, JSON_UNESCAPED_UNICODE),
        ]);
    }

    public function testMergeAppendsOnlyMissingSections(): void
    {
        $resource = $this->resourceWith([$this->section('hero', 'hero', 'Hero')]);
        $type = $this->typeWith([
            $this->section('hero', 'hero', 'Hero'),   // уже есть в ресурсе → пропуск
            $this->section('promo', 'promo', 'Promo'), // новая → добавится
        ]);
        $writer = new FieldWriter($this->makeModx($resource, $type));

        $res = $writer->copySectionsFromType($resource, 'merge');
        $this->assertTrue($res['success'], $res['message']);

        $stored = json_decode($resource->getTVValue('mpc_config'), true);
        $this->assertCount(2, $stored, 'hero не дублируется, promo добавлена');
        $forms = array_column($stored, 'MIGX_formname');
        $this->assertSame(['hero', 'promo'], $forms);
    }

    public function testOverwriteReplacesWithTypeConfig(): void
    {
        $resource = $this->resourceWith([$this->section('old', 'old', 'Old')]);
        $type = $this->typeWith([
            $this->section('hero', 'hero', 'Hero'),
            $this->section('promo', 'promo', 'Promo'),
        ]);
        $writer = new FieldWriter($this->makeModx($resource, $type));

        $res = $writer->copySectionsFromType($resource, 'overwrite');
        $this->assertTrue($res['success'], $res['message']);

        $stored = json_decode($resource->getTVValue('mpc_config'), true);
        $this->assertSame(['hero', 'promo'], array_column($stored, 'MIGX_formname'), 'old заменён конфигом типа');
    }

    public function testFailsWhenNoTypeResource(): void
    {
        $resource = $this->resourceWith([]);
        $writer = new FieldWriter($this->makeModx($resource, null));
        $res = $writer->copySectionsFromType($resource, 'merge');
        $this->assertFalse($res['success']);
    }

    public function testFailsWhenTypeHasNoSections(): void
    {
        $resource = $this->resourceWith([$this->section('hero', 'hero', 'Hero')]);
        $type = $this->typeWith([]); // пустой конфиг типа
        $writer = new FieldWriter($this->makeModx($resource, $type));
        $res = $writer->copySectionsFromType($resource, 'merge');
        $this->assertFalse($res['success']);
    }
}
