<?php

namespace MpcTests\Unit\Lexicon;

use MpcServices\Handlers\Lexicon\ReleaseManifestBuilder as B;
use PHPUnit\Framework\TestCase;

/**
 * Сборка манифеста из двух состояний словаря. Проверяется ровно то, ради чего
 * команда появилась: `expected` берётся из базы, а не из текущего значения, и
 * в манифест не попадает ничего, кроме адресов задачи.
 */
class ReleaseManifestBuilderTest extends TestCase
{
    public function testНовыйКлючПолучаетExpectedNull(): void
    {
        $res = B::build(
            ['de' => ['aula' => ['old' => 'Alt']]],
            ['de' => ['aula' => ['old' => 'Alt', 'fresh' => 'Neu']]]
        );

        $this->assertSame(['de' => ['aula' => ['fresh' => null]]], $res['manifest']['expected']);
        $this->assertSame(['de' => ['aula' => ['fresh' => 'Neu']]], $res['manifest']['desired']);
        $this->assertSame(1, $res['summary']['added']);
        $this->assertSame(0, $res['summary']['changed']);
    }

    public function testИзменённыйКлючНесётЗначениеБазыАНеСервера(): void
    {
        $res = B::build(
            ['de' => ['aula' => ['title' => 'Basis']]],
            ['de' => ['aula' => ['title' => 'Meins']]]
        );

        $this->assertSame('Basis', $res['manifest']['expected']['de']['aula']['title']);
        $this->assertSame('Meins', $res['manifest']['desired']['de']['aula']['title']);
        $this->assertSame(1, $res['summary']['changed']);
    }

    public function testСовпадающиеЗначенияВМанифестНеПопадают(): void
    {
        $res = B::build(
            ['de' => ['aula' => ['title' => 'Gleich', 'other' => 'X']]],
            ['de' => ['aula' => ['title' => 'Gleich', 'other' => 'X']]]
        );

        $this->assertSame([], $res['manifest']['expected']);
        $this->assertSame([], $res['manifest']['desired']);
        $this->assertSame(0, $res['summary']['total']);
    }

    public function testПропавшийКлючБезФлагаИгнорируется(): void
    {
        $res = B::build(
            ['de' => ['aula' => ['gone' => 'Weg', 'stay' => 'Da']]],
            ['de' => ['aula' => ['stay' => 'Da']]]
        );

        $this->assertSame(0, $res['summary']['total']);
    }

    public function testПропавшийКлючСФлагомДаётClear(): void
    {
        $res = B::build(
            ['de' => ['aula' => ['gone' => 'Weg']]],
            ['de' => ['aula' => []]],
            ['withClears' => true]
        );

        $this->assertSame('Weg', $res['manifest']['expected']['de']['aula']['gone']);
        $this->assertSame(B::CLEAR, $res['manifest']['desired']['de']['aula']['gone']);
        $this->assertSame(1, $res['summary']['cleared']);
    }

    /**
     * Файла нет в срезе — это не удаление ключей, а сужение области: фильтр по
     * одному языку иначе сгенерировал бы очистку всех остальных.
     */
    public function testОтсутствующийФайлНеСчитаетсяУдалениемДажеСФлагом(): void
    {
        $res = B::build(
            ['de' => ['aula' => ['key' => 'Wert']]],
            ['de' => []],
            ['withClears' => true]
        );

        $this->assertSame(0, $res['summary']['total']);
    }

    public function testФильтрыСужаютМанифестДоАдресовЗадачи(): void
    {
        $base = [
            'de' => ['aula' => ['a' => '1', 'b' => '1']],
            'fi' => ['aula' => ['a' => '1']],
        ];
        $head = [
            'de' => ['aula' => ['a' => '2', 'b' => '2']],
            'fi' => ['aula' => ['a' => '2']],
        ];

        $res = B::build($base, $head, ['langs' => ['de'], 'keys' => ['a']]);

        $this->assertSame(['de' => ['aula' => ['a' => '1']]], $res['manifest']['expected']);
        $this->assertSame(1, $res['summary']['total']);
    }

    public function testФильтрПринимаетСтрокуЧерезЗапятую(): void
    {
        $res = B::build(
            ['de' => ['aula' => ['a' => '1']], 'fi' => ['aula' => ['a' => '1']]],
            ['de' => ['aula' => ['a' => '2']], 'fi' => ['aula' => ['a' => '2']]],
            ['langs' => 'fi']
        );

        $this->assertSame(['fi'], array_keys($res['manifest']['desired']));
    }

    /**
     * Отпечаток релиза считается по содержимому манифеста, поэтому порядок
     * адресов обязан быть одинаковым при любом порядке входных данных.
     */
    public function testПорядокАдресовКанонизирован(): void
    {
        $base = ['fi' => ['b' => ['z' => '1', 'a' => '1']], 'de' => ['a' => ['k' => '1']]];
        $head = ['de' => ['a' => ['k' => '2']], 'fi' => ['b' => ['a' => '2', 'z' => '2']]];

        $first  = B::build($base, $head);
        $second = B::build(
            ['de' => ['a' => ['k' => '1']], 'fi' => ['b' => ['a' => '1', 'z' => '1']]],
            ['fi' => ['b' => ['z' => '2', 'a' => '2']], 'de' => ['a' => ['k' => '2']]]
        );

        $this->assertSame(['de|a|k', 'fi|b|a', 'fi|b|z'], $first['addresses']);
        $this->assertSame(B::encode($first['manifest']), B::encode($second['manifest']));
    }

    /** null в снимке значит «ключа не было», а не пустую строку. */
    public function testNullВБазеСчитаетсяОтсутствиемКлюча(): void
    {
        $res = B::build(
            ['de' => ['aula' => ['key' => null]]],
            ['de' => ['aula' => ['key' => 'Neu']]]
        );

        $this->assertNull($res['manifest']['expected']['de']['aula']['key']);
        $this->assertSame(1, $res['summary']['added']);
    }

    public function testПустоеЗначениеНеПутаетсяСОтсутствием(): void
    {
        $res = B::build(
            ['de' => ['aula' => ['key' => '']]],
            ['de' => ['aula' => ['key' => 'Neu']]]
        );

        $this->assertSame('', $res['manifest']['expected']['de']['aula']['key']);
        $this->assertSame(1, $res['summary']['changed']);
    }
}
