<?php

namespace MpcTests\Unit;

use PHPUnit\Framework\TestCase;
use MpcServices\Handlers\AdminAudit;

/**
 * Юнит чистого определения уровня записи аудита (resolveLevel) — без БД.
 * Ошибка здесь молча проставляет неверный level (resource/type/global) всем
 * записям журнала изменений.
 */
class AdminAuditTest extends TestCase
{
    /** Сам staticBlocksPage → global. */
    public function testStaticBlocksPageIsGlobal(): void
    {
        $this->assertSame('global', AdminAudit::resolveLevel(3, 0, 3));
        // даже если у sbp есть parent — он сам определяет global по id
        $this->assertSame('global', AdminAudit::resolveLevel(3, 1, 3));
    }

    /** Прямой ребёнок sbp (ресурс-тип страницы) → type. */
    public function testChildOfStaticBlocksPageIsType(): void
    {
        $this->assertSame('type', AdminAudit::resolveLevel(43, 3, 3));
    }

    /** Обычный ресурс (parent не sbp) → resource. */
    public function testOrdinaryResource(): void
    {
        $this->assertSame('resource', AdminAudit::resolveLevel(10, 5, 3));
        $this->assertSame('resource', AdminAudit::resolveLevel(10, 0, 3));
    }

    /** global имеет приоритет над type: rid==sbp при parent==sbp всё равно global. */
    public function testGlobalTakesPrecedenceOverType(): void
    {
        $this->assertSame('global', AdminAudit::resolveLevel(3, 3, 3));
    }
}
