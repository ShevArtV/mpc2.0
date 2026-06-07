<?php

namespace MpcTests\Unit;

use MpcServices\Handlers\RecordUtil;
use PHPUnit\Framework\TestCase;

/** Юнит чистых record-утилит (вынос из FieldWriter). */
class RecordUtilTest extends TestCase
{
    public function testIsRecordValue(): void
    {
        $this->assertTrue(RecordUtil::isRecordValue('[{"src":"a"}]'));
        $this->assertFalse(RecordUtil::isRecordValue('hero_title'));
        $this->assertFalse(RecordUtil::isRecordValue('[1,2,3]'));  // первый элемент не массив
        $this->assertFalse(RecordUtil::isRecordValue('{"a":1}'));  // объект, reset не массив
        $this->assertFalse(RecordUtil::isRecordValue(''));
    }

    public function testDecodeRecord(): void
    {
        $this->assertSame([['src' => 'a']], RecordUtil::decodeRecord('[{"src":"a"}]'));
        $this->assertSame([], RecordUtil::decodeRecord('не json'));
        $this->assertSame([], RecordUtil::decodeRecord(''));
        $this->assertSame(['x' => 1], RecordUtil::decodeRecord(['x' => 1])); // массив отдаём как есть
    }
}
