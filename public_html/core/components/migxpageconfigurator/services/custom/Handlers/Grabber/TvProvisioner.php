<?php

namespace MpcServices\Handlers\Grabber;

use DiDom\Element;
use MpcServices\Handlers\Parser;

/**
 * Автосоздание/синхронизация TV из data-mpc-tv маркеров шаблона. Дух mpc — «не
 * лазить в админку»: шаблон источник правды и для TV (как для секционных полей).
 *
 * ВЛАДЕНИЕ через категорию (`mpc_tv_category`, дефолт «mpc»): mpc создаёт TV в этой
 * категории и управляет (type/elements) ТОЛЬКО своими. Чужую одноимённую TV (в
 * другой категории) НЕ трогаем — это глобальный объект MODX, его может использовать
 * ручной шаблон/сниппет/другой пакет (десинхрон B). Для чужой только привязываем к
 * шаблону + пишем значение (ResourceFieldGrabber), структуру не правим.
 *
 * Десинхрон A (правка СВОЕЙ TV в админке) НЕ предотвращаем — это та же модель, что у
 * секционных полей: шаблон — истина, нарезка регенерит.
 *
 * Тип TV: из data-mpc-ftype (нативные типы TV: listbox/number/date/checkbox/option/…);
 * нет ftype → image для img/picture, иначе text. Опции listbox/checkbox/option — из
 * data-mpc-values, нормализованные (Caption==norm(value) / norm(value)).
 */
class TvProvisioner
{
    private \modX $modx;
    private Parser $parser;
    private array $properties;
    private ?int $categoryId = null;

    public function __construct(\modX $modx, Parser $parser, array $properties)
    {
        $this->modx = $modx;
        $this->parser = $parser;
        $this->properties = $properties;
    }

    public function provision(string $html): void
    {
        $templateId = $this->resolveTemplateId($html);
        $markers = $this->parser->findByAttribute($html, '[data-mpc-tv]');
        if (!$markers) {
            return;
        }
        $seen = [];
        foreach ($markers as $el) {
            $name = trim((string)$el->getAttribute('data-mpc-tv'));
            // Кросс-ресурсные маркеры (внутри data-mpc-res) — чужие данные, пропускаем.
            if ($name === '' || isset($seen[$name]) || $el->closest('[data-mpc-res]') !== null) {
                continue;
            }
            $seen[$name] = true;
            $this->provisionOne($name, $el, $templateId);
        }
    }

    private function provisionOne(string $name, Element $el, int $templateId): void
    {
        $type     = $this->resolveType(trim((string)$el->getAttribute('data-mpc-ftype')), strtolower($el->tagName()));
        $values   = (string)$el->getAttribute('data-mpc-values');
        // keyed-форма (Caption==norm(value)) — сохраняет оригинальный капшен в TV
        // (источник лексикона), value нормализован (совпадает с ключом/рендером).
        $elements = $values !== '' ? LexiconManager::normalizeInputOptionValues($values) : '';

        /** @var \modTemplateVar|null $tv */
        $tv = $this->modx->getObject('modTemplateVar', ['name' => $name]);

        if ($tv) {
            // Чужая TV (вне категории mpc) — структуру НЕ трогаем (десинхрон B),
            // только обеспечиваем привязку к шаблону.
            if ((int)$tv->get('category') !== $this->mpcCategoryId()) {
                $this->modx->log(\modX::LOG_LEVEL_INFO, "[mpc] TV «{$name}» вне категории mpc — структуру не трогаю (только значение/привязка).");
                $this->bindToTemplate((int)$tv->get('id'), $templateId);
                return;
            }
            // Своя TV — синкаем тип/опции из шаблона (шаблон — истина).
            $tv->set('type', $type);
            $tv->set('elements', $elements);
            $tv->save();
        } else {
            /** @var \modTemplateVar $tv */
            $tv = $this->modx->newObject('modTemplateVar');
            $tv->fromArray([
                'name'     => $name,
                'caption'  => $name,
                'type'     => $type,
                'elements' => $elements,
                'category' => $this->mpcCategoryId(),
                'rank'     => 0,
                'display'  => 'default',
            ]);
            if (!$tv->save()) {
                $this->modx->log(\modX::LOG_LEVEL_ERROR, "[mpc] Не удалось создать TV «{$name}».");
                return;
            }
        }

        $this->bindToTemplate((int)$tv->get('id'), $templateId);
    }

    /** id шаблона из заголовка <!--##{"templatename":…}##--> (авторитетный источник). */
    private function resolveTemplateId(string $html): int
    {
        if (!preg_match('/<!--##(.*?)##-->/s', $html, $m)) {
            return 0;
        }
        $data = json_decode($m[1], true);
        $name = is_array($data) ? trim((string)($data['templatename'] ?? '')) : '';
        if ($name === '') {
            return 0;
        }
        $tpl = $this->modx->getObject('modTemplate', ['templatename' => $name]);
        return $tpl ? (int)$tpl->get('id') : 0;
    }

    /** ftype → тип TV; пусто → image (img/picture) или text; медиа-прототипы → image. */
    private function resolveType(string $ftype, string $tag): string
    {
        if ($ftype === '') {
            return in_array($tag, ['img', 'picture'], true) ? 'image' : 'text';
        }
        if (in_array($ftype, ['img', 'bg_img', 'picture'], true)) {
            return 'image';
        }
        return $ftype; // listbox/listbox-multiple/option/checkbox/number/date/email/url/file/tag/…
    }

    private function bindToTemplate(int $tvId, int $templateId): void
    {
        if ($tvId <= 0 || $templateId <= 0) {
            return;
        }
        $link = $this->modx->getObject('modTemplateVarTemplate', ['tmplvarid' => $tvId, 'templateid' => $templateId]);
        if (!$link) {
            // set() поимённо: fromArray по умолчанию НЕ ставит поля составного PK
            // (tmplvarid+templateid) → save падал бы.
            /** @var \modTemplateVarTemplate $link */
            $link = $this->modx->newObject('modTemplateVarTemplate');
            $link->set('tmplvarid', $tvId);
            $link->set('templateid', $templateId);
            $link->save();
        }
    }

    /**
     * id категории-владельца mpc. Кэш на инстанс. Имя категории НЕ хардкодим:
     *   1) настройка mpc_tv_category (admin может переопределить);
     *   2) иначе — категория самого пакета mpc, резолвится ДИНАМИЧЕСКИ через его
     *      плагин (имя плагина = идентичность пакета, не косметика).
     * Свою категорию не создаём (у установленного пакета она уже есть).
     */
    private function mpcCategoryId(): int
    {
        if ($this->categoryId !== null) {
            return $this->categoryId;
        }
        // 1) Явная настройка (admin может переопределить).
        $name = trim((string)$this->modx->getOption('mpc_tv_category'));
        if ($name !== '' && ($cat = $this->modx->getObject('modCategory', ['category' => $name]))) {
            return $this->categoryId = (int)$cat->get('id');
        }
        // 2) Иначе — категория самого пакета: берём её у плагина пакета (имя плагина
        //    совпадает с неймспейсом без учёта регистра). Неймспейс — из свойств,
        //    имя категории нигде не хардкодим.
        $ns = (string)($this->properties['lexiconsNamespace'] ?? 'migxpageconfigurator');
        $plugin = $this->modx->getObject('modPlugin', ['name:LIKE' => $ns]);
        return $this->categoryId = $plugin ? (int)$plugin->get('category') : 0;
    }
}
