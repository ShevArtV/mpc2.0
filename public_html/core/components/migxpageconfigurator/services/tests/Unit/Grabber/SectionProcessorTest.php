<?php

namespace MpcTests\Unit\Grabber;

use MpcServices\Handlers\Grabber\SectionProcessor;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Тест pure-ядра разрешения полей секции (makeFieldDef): clone-by-type из
 * прототипов mpc_base + синтез скаляров + ftype/fcap/fdesc + дефолт по элементу.
 */
class SectionProcessorTest extends TestCase
{
    private function processor(): SectionProcessor
    {
        return (new ReflectionClass(SectionProcessor::class))->newInstanceWithoutConstructor();
    }

    /** Прототипы mpc_base (упрощённо): text-аналог, img (migx), list_simple (migx). */
    private function byName(): array
    {
        return [
            'title'       => ['field' => 'title', 'caption' => 'Заголовок', 'inputTVtype' => 'textarea', 'description' => '', 'configs' => ''],
            'img'         => ['field' => 'img', 'caption' => 'Изображение', 'inputTVtype' => 'migx', 'configs' => 'mpc_img', 'description' => ''],
            'picture'     => ['field' => 'picture', 'caption' => 'Картинка', 'inputTVtype' => 'migx', 'configs' => 'mpc_picture', 'description' => ''],
            'bg_img'      => ['field' => 'bg_img', 'caption' => 'Фон', 'inputTVtype' => 'image', 'configs' => '', 'description' => ''],
            'list_simple' => ['field' => 'list_simple', 'caption' => 'Список', 'inputTVtype' => 'migx', 'configs' => 'mpc_list_simple', 'description' => ''],
        ];
    }

    private function def(string $name, array $attrs, string $kind): array
    {
        return $this->processor()->makeFieldDef($name, $attrs, $this->byName(), $kind, 1);
    }

    /** Вызов приватного метода через рефлексию. */
    private function priv(string $method, array $args)
    {
        $ref = new \ReflectionMethod(SectionProcessor::class, $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs($this->processor(), $args);
    }

    /** Имя совпало с прототипом, ftype нет → берём прототип как есть. */
    public function testDirectMatchKeepsPrototype(): void
    {
        $d = $this->def('img', [], 'img');
        $this->assertSame('img', $d['field']);
        $this->assertSame('mpc_img', $d['configs']);
        $this->assertSame('Изображение', $d['caption']); // без суффикса
    }

    /** Произвольное имя на не-медиа, без ftype → text, caption «тип (имя)». */
    public function testArbitraryNameDefaultsToText(): void
    {
        $d = $this->def('subtitle', [], 'other');
        $this->assertSame('subtitle', $d['field']);
        $this->assertSame('text', $d['inputTVtype']);
        $this->assertSame('Текстовое поле (subtitle)', $d['caption']);
    }

    /** ftype ссылается на существующий прототип-список → клон с переименованием. */
    public function testFtypeClonesListPrototype(): void
    {
        $d = $this->def('reviews', ['ftype' => 'list_simple'], 'other');
        $this->assertSame('reviews', $d['field']);
        $this->assertSame('migx', $d['inputTVtype']);
        $this->assertSame('mpc_list_simple', $d['configs']); // sub-config сохранён
        $this->assertSame('Список (reviews)', $d['caption']);
    }

    /** ftype скалярного типа, которого нет в mpc_base → синтез (richtext). */
    public function testFtypeSynthesizesRichtext(): void
    {
        $d = $this->def('body', ['ftype' => 'richtext'], 'other');
        $this->assertSame('richtext', $d['inputTVtype']);
        $this->assertSame('Форматированный текст (body)', $d['caption']);
    }

    /** checkbox синтезируется со значением Да==1. */
    public function testCheckboxSynthesized(): void
    {
        $d = $this->def('is_hit', ['ftype' => 'checkbox'], 'other');
        $this->assertSame('checkbox', $d['inputTVtype']);
        $this->assertSame('Да==1', $d['inputOptionValues']);
    }

    /** Медиа-тег без ftype → дефолт по элементу (img-прототип). */
    public function testImgTagDefaultsToImgPrototype(): void
    {
        $d = $this->def('photo', [], 'img');
        $this->assertSame('photo', $d['field']);
        $this->assertSame('mpc_img', $d['configs']);
        $this->assertSame('Изображение (photo)', $d['caption']);
    }

    /** Фон (style background) без ftype → bg_img-прототип. */
    public function testBackgroundDefaultsToBgImg(): void
    {
        $d = $this->def('hero_bg', [], 'bg');
        $this->assertSame('image', $d['inputTVtype']);
        $this->assertSame('Фон (hero_bg)', $d['caption']);
    }

    /** fcap/fdesc переопределяют caption/description. */
    public function testFcapFdescOverride(): void
    {
        $d = $this->def('title', ['ftype' => 'textarea', 'fcap' => 'Мой заголовок', 'fdesc' => 'Подсказка'], 'other');
        $this->assertSame('Мой заголовок', $d['caption']);
        $this->assertSame('Подсказка', $d['description']);
        $this->assertSame('textarea', $d['inputTVtype']);
    }

    /** fcap при прямом совпадении имени тоже применяется. */
    public function testFcapOnDirectMatch(): void
    {
        $d = $this->def('img', ['fcap' => 'Главное фото'], 'img');
        $this->assertSame('Главное фото', $d['caption']);
        $this->assertSame('mpc_img', $d['configs']);
    }

    /** Неизвестный ftype на картинке → фолбэк image. */
    public function testUnknownFtypeOnImageFallsBack(): void
    {
        $d = $this->def('pic', ['ftype' => 'whatever'], 'img');
        $this->assertSame('image', $d['inputTVtype']);
    }

    /** Имя ЕСТЬ в mpc_base + задан ДРУГОЙ ftype → mpc_base выигрывает (без дубликата). */
    public function testNameInBaseBeatsFtype(): void
    {
        $d = $this->def('img', ['ftype' => 'text'], 'other');
        $this->assertSame('img', $d['field']);
        $this->assertSame('migx', $d['inputTVtype']);    // прототип img из mpc_base, не text
        $this->assertSame('mpc_img', $d['configs']);     // sub-config сохранён
        $this->assertSame('Изображение', $d['caption']); // как в mpc_base, без суффикса «(img)»
    }

    /** Зарезервированные имена = поля настроек+стилей mpc_base, контент-таб исключён. */
    public function testCollectReservedFieldNames(): void
    {
        $tabs = [
            0 => ['fields' => [['field' => 'section_name'], ['field' => 'position']]],
            1 => ['fields' => [['field' => 'title'], ['field' => 'content']]], // контент-таб
            2 => ['fields' => [['field' => 'inline_styles'], ['field' => 'class_names'], ['field' => 'css_file_path']]],
        ];
        $reserved = $this->priv('collectReservedFieldNames', [$tabs]);

        $this->assertArrayNotHasKey('title', $reserved);   // контент-таб не резервируется
        $this->assertArrayNotHasKey('content', $reserved);
        $this->assertArrayHasKey('section_name', $reserved);
        $this->assertArrayHasKey('inline_styles', $reserved);
        $this->assertArrayHasKey('class_names', $reserved);
        $this->assertArrayHasKey('css_file_path', $reserved);
    }

    /** Мерж без overwrite: старое сохранено, новое добавлено, ушедшее удалено, служебное свежее. */
    public function testMergePreservesExistingAddsNewDropsGone(): void
    {
        $reserved = ['inline_styles' => true, 'position' => true];
        $grabbed = [
            'MIGX_id' => 2, 'MIGX_formname' => 'hero', 'section_name' => 'Hero NEW',
            'title' => 'template title',    // есть в existing → сохранить старое
            'subtitle' => 'new field val',  // нет в existing → новое поле + контент
            'inline_styles' => '--x: red',  // reserved+grabbed → сохранить старое
        ];
        $existing = [
            'MIGX_id' => 1, 'MIGX_formname' => 'hero', 'section_name' => 'Hero OLD',
            'title' => 'admin edited',      // сохранить
            'oldfield' => 'gone',           // нет в grabbed, не reserved → удалить
            'inline_styles' => '--x: green',// reserved → сохранить
            'position' => '3',              // reserved вне шаблона → сохранить
        ];

        $m = $this->processor()->mergeSectionFields($grabbed, $existing, $reserved, false);

        $this->assertSame('Hero NEW', $m['section_name']);    // служебное → свежее
        $this->assertSame(2, $m['MIGX_id']);
        $this->assertSame('admin edited', $m['title']);       // старое сохранено
        $this->assertSame('new field val', $m['subtitle']);   // новое поле
        $this->assertArrayNotHasKey('oldfield', $m);          // ушедшее удалено
        $this->assertSame('--x: green', $m['inline_styles']); // reserved сохранён
        $this->assertSame('3', $m['position']);               // reserved вне шаблона сохранён
    }

    /** Мерж с overwrite: шаблон побеждает, admin-настройка вне шаблона сохраняется. */
    public function testMergeOverwriteTemplateWins(): void
    {
        $reserved = ['position' => true];
        $grabbed = ['MIGX_formname' => 'hero', 'title' => 'template'];
        $existing = ['MIGX_formname' => 'hero', 'title' => 'admin', 'position' => '5'];

        $m = $this->processor()->mergeSectionFields($grabbed, $existing, $reserved, true);

        $this->assertSame('template', $m['title']); // overwrite → шаблон
        $this->assertSame('5', $m['position']);     // admin-настройка вне шаблона сохранена
    }

    // --- S1.5: динамические списки (чистые хелперы) -----------------------

    /** Имя авто-конфига списка детерминированно слугифицируется. */
    public function testAutoListNameSlug(): void
    {
        $this->assertSame('mpc_auto_features_advantages', $this->priv('autoListName', ['features_advantages']));
        $this->assertSame('mpc_auto_a_b_c', $this->priv('autoListName', ['A B/c']));
    }

    /** listFieldDef → migx-поле со ссылкой на под-конфиг. */
    public function testListFieldDef(): void
    {
        $d = $this->priv('listFieldDef', ['reviews', 'mpc_auto_sec_reviews', ['fcap' => '', 'fdesc' => ''], 3]);
        $this->assertSame('reviews', $d['field']);
        $this->assertSame('migx', $d['inputTVtype']);
        $this->assertSame('mpc_auto_sec_reviews', $d['configs']);
        $this->assertSame('Список (reviews)', $d['caption']);
        $this->assertSame(3, $d['pos']);
    }

    /** listFieldDef: fcap/fdesc применяются. */
    public function testListFieldDefWithCaption(): void
    {
        $d = $this->priv('listFieldDef', ['reviews', 'cfg', ['fcap' => 'Отзывы', 'fdesc' => 'Опц'], 1]);
        $this->assertSame('Отзывы', $d['caption']);
        $this->assertSame('Опц', $d['description']);
    }

    /** Колонки грида: картинка → renderImage, вложенный список → скрыт, текст → показан. */
    public function testBuildColumns(): void
    {
        $fields = [
            ['field' => 'title', 'caption' => 'Заголовок', 'inputTVtype' => 'text'],
            ['field' => 'photo', 'caption' => 'Фото', 'inputTVtype' => 'image'],
            ['field' => 'items', 'caption' => 'Список', 'inputTVtype' => 'migx'],
        ];
        $cols = $this->priv('buildColumns', [$fields]);
        $this->assertSame('title', $cols[0]['dataIndex']);
        $this->assertSame(1, $cols[0]['show_in_grid']);
        $this->assertSame('', $cols[0]['renderer']);
        $this->assertSame('this.renderImage', $cols[1]['renderer']);
        $this->assertSame(0, $cols[2]['show_in_grid']); // вложенный список не в грид
    }
}
