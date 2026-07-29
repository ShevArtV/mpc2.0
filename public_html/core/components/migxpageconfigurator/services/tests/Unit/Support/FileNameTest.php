<?php

namespace MpcTests\Unit\Support;

use PHPUnit\Framework\TestCase;
use MpcServices\Handlers\Support\FileName;

/**
 * Единая точка нормализации имён: политика пакета, точка расширения проекта
 * (mpcOnSanitizeFileName) и ОБЯЗАТЕЛЬНЫЙ security-постфильтр после неё.
 *
 * Последнее — главное, что проверяется здесь: событие даёт проекту власть над
 * стилем имени, но не над безопасностью, иначе точка расширения превращается в
 * дыру, через которую один плагин обходит весь блок-лист.
 */
class FileNameTest extends TestCase
{
    /** modX-стаб, возвращающий заданное имя из плагина на mpcOnSanitizeFileName. */
    private function modx(?string $pluginReturns, array &$seen = []): \modX
    {
        return new class($pluginReturns, $seen) extends \modX {
            private ?string $ret;
            /** @var array ссылка на массив, куда пишем параметры события */
            private array $seenRef;
            public array $lastParams = [];

            public function __construct(?string $ret, array &$seen)
            {
                parent::__construct();
                $this->ret     = $ret;
                $this->seenRef = &$seen;
            }

            public function invokeEvent(string $name, array $params = []): void
            {
                $this->seenRef[] = ['event' => $name, 'params' => $params];
                if ($this->ret !== null) {
                    $this->event->returnedValues = ['name' => $this->ret];
                }
            }
        };
    }

    // --- Политика пакета (без modX: событие не участвует) -------------------

    /** @dataProvider fileCases */
    public function testDefaultPolicyForFile(string $in, string $out): void
    {
        $this->assertSame($out, FileName::forFile(null, $in), "in=$in");
    }

    public function fileCases(): array
    {
        return [
            'cyrillic'        => ['Фото', 'foto'],
            'spaces'          => ['My Photo', 'my-photo'],
            'uppercase'       => ['IMG', 'img'],
            'underscore'      => ['my_photo_2', 'my-photo-2'],
            'special chars'   => ['a!!!b', 'a-b'],
            'dots collapse'   => ['shell.php', 'shell-php'],
            'leading dots'    => ['...hidden', 'hidden'],
            'traversal'       => ['../../etc/passwd', 'passwd'],
            'win traversal'   => ['..\\..\\shell', 'shell'],
            'empty result'    => ['---', FileName::FALLBACK_FILE],
            'empty input'     => ['', FileName::FALLBACK_FILE],
        ];
    }

    public function testDefaultPolicyForFolderUsesSnakeCase(): void
    {
        $this->assertSame('nashi_uslugi', FileName::forFolder(null, 'Наши услуги'));
        $this->assertSame('a_b', FileName::forFolder(null, 'a-b'));
        $this->assertSame(FileName::FALLBACK_DIR, FileName::forFolder(null, '///'));
    }

    public function testLongNameIsTruncatedToLimit(): void
    {
        $out = FileName::forFile(null, str_repeat('a', 400));
        $this->assertSame(FileName::MAX_BASE_LENGTH, strlen($out));
    }

    public function testForFileNameKeepsExtensionLowercased(): void
    {
        $this->assertSame('foto-2024.jpg', FileName::forFileName(null, 'Фото 2024.JPG'));
        // Двойное расширение схлопывается ещё политикой: '.php' в имени не остаётся.
        $this->assertSame('shell-php.jpg', FileName::forFileName(null, 'shell.php.jpg'));
        // Имя целиком из расширения ('.htaccess') базы не имеет — расширением его
        // считать нельзя, иначе на диск ушёл бы файл ровно с этим именем.
        $this->assertSame(FileName::FALLBACK_FILE, FileName::forFileName(null, '.htaccess'));
        // Исполняемое расширение не доезжает до итогового имени даже тогда, когда
        // база безобидна: оно сливается в базу, файл остаётся неисполняемым.
        $this->assertSame('shell-php', FileName::forFileName(null, 'shell.php'));
        $this->assertSame('arch-zip-php', FileName::forFileName(null, 'arch.zip.php'));
    }

    // --- Точка расширения проекта -------------------------------------------

    public function testPluginCanReplaceName(): void
    {
        $seen = [];
        $modx = $this->modx('proekt-svoi-pravila', $seen);

        $this->assertSame('proekt-svoi-pravila', FileName::forFile($modx, 'Фото', [
            'extension' => 'jpg',
            'directory' => 'images/',
            'context'   => FileName::CTX_EDITOR_UPLOAD,
        ]));

        $this->assertCount(1, $seen);
        $this->assertSame(FileName::EVENT, $seen[0]['event']);
        $this->assertSame('Фото', $seen[0]['params']['name']);
        $this->assertSame('foto', $seen[0]['params']['sanitized']);   // дефолт виден плагину
        $this->assertSame(FileName::KIND_FILE, $seen[0]['params']['kind']);
        $this->assertSame('jpg', $seen[0]['params']['extension']);
        $this->assertSame('images/', $seen[0]['params']['directory']);
        $this->assertSame(FileName::CTX_EDITOR_UPLOAD, $seen[0]['params']['context']);
    }

    public function testEmptyPluginAnswerKeepsDefault(): void
    {
        $seen = [];
        $this->assertSame('foto', FileName::forFile($this->modx('', $seen), 'Фото'));
        $this->assertSame('foto', FileName::forFile($this->modx(null, $seen), 'Фото'));
    }

    public function testStaleReturnedValueDoesNotLeakToNextFile(): void
    {
        // Грабер зовёт нормализацию в цикле по всем медиа страницы. Плагин,
        // сработавший на одном файле и промолчавший на следующем, не должен
        // «переименовать» и его — returnedValues чистятся перед каждым вызовом.
        $seen = [];
        $modx = $this->modx(null, $seen);
        $modx->event->returnedValues = ['name' => 'ostatok-ot-proshlogo-fayla'];

        $this->assertSame('vtoroy', FileName::forFile($modx, 'второй'));
    }

    public function testNestedCallFromPluginDoesNotRecurse(): void
    {
        // Плагин, который внутри обработчика снова зовёт нормализацию (напрямую
        // или через MediaDownloader::sanitizeFileName), не должен уводить процесс
        // в бесконечную рекурсию — вложенный вызов получает дефолтную политику.
        $modx = new class extends \modX {
            public int $calls = 0;
            public function invokeEvent(string $name, array $params = []): void
            {
                $this->calls++;
                $nested = \MpcServices\Handlers\Support\FileName::forFile($this, 'Вложенный');
                $this->event->returnedValues = ['name' => $nested . '-ok'];
            }
        };

        $this->assertSame('vlozhennyy-ok', \MpcServices\Handlers\Support\FileName::forFile($modx, 'Внешний'));
        $this->assertSame(1, $modx->calls);
    }

    // --- Security-постфильтр поверх ответа плагина --------------------------

    /** @dataProvider hostileCases */
    public function testHardenSanitizesPluginAnswer(string $pluginReturns, string $expected): void
    {
        $seen = [];
        $out  = FileName::forFile($this->modx($pluginReturns, $seen), 'photo', ['extension' => 'jpg']);
        $this->assertSame($expected, $out, "plugin returned: $pluginReturns");
    }

    public function hostileCases(): array
    {
        return [
            'traversal'       => ['../shell', 'shell'],
            'win traversal'   => ['..\\..\\shell', 'shell'],
            'абс. путь'       => ['/etc/passwd', 'passwd'],
            'double ext'      => ['shell.php', 'shell-php'],
            'null byte'       => ["shell\0.php", 'shell-php'],
            'control chars'   => ["ph\x07oto", 'photo'],
            'только точки'    => ['...', FileName::FALLBACK_FILE],
            // Пустой ответ плагина = «дефолт устраивает», а не «сотри имя».
            'пусто'           => ['   ', 'photo'],
        ];
    }

    public function testHardenRejectsBaseCollapsingToExecutable(): void
    {
        // Файл без расширения: база станет хвостом имени, поэтому 'htaccess'
        // здесь так же опасен, как расширение.
        $seen = [];
        $this->assertSame(
            FileName::FALLBACK_FILE,
            FileName::forFile($this->modx('htaccess', $seen), 'photo')
        );
    }

    public function testHardenCapsLengthOfPluginAnswer(): void
    {
        $seen = [];
        $out  = FileName::forFile($this->modx(str_repeat('b', 500), $seen), 'photo');
        $this->assertSame(FileName::MAX_BASE_LENGTH, strlen($out));
    }

    public function testPluginKeepsItsOwnStyle(): void
    {
        // harden режет только опасное. Регистр и пробелы — вопрос стиля: если
        // проект вернул их осознанно, пакет не вправе их переписывать.
        $seen = [];
        $this->assertSame('My Photo', FileName::forFile($this->modx('My Photo', $seen), 'photo'));
    }

    // --- Блок-лист ----------------------------------------------------------

    public function testBlockedNames(): void
    {
        $this->assertTrue(FileName::isBlockedName('shell.php'));
        $this->assertTrue(FileName::isBlockedName('shell.php.jpg'));   // pathinfo видит только jpg
        $this->assertTrue(FileName::isBlockedName('SHELL.PHP.JPG'));
        $this->assertTrue(FileName::isBlockedName('arch.zip.php'));
        $this->assertTrue(FileName::isBlockedName('.htaccess'));
        $this->assertFalse(FileName::isBlockedName('photo.jpg'));
        $this->assertFalse(FileName::isBlockedName('картинка.png'));
        $this->assertFalse(FileName::isBlockedName('my.photo.2024.jpeg'));
    }
}
