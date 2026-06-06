<?php

namespace MpcServices\Handlers;

/**
 * Резолвер «контекста» ключа лексикона: человекочитаемая подпись вида
 * «Секция: Поле» для справочной колонки экспорта (на импорт не влияет).
 *
 * Источники (схему БД НЕ трогаем — каскад global→type→resource на .inc.php):
 *  - per-resource `mpc_config` TV (секции страниц): lexicon_prefix →
 *    section_name (человекочитаемое data-mpc-name); основной источник имени
 *    секции — отдельных migxConfig по имени секции на стенде может не быть;
 *  - манифест {@see TrackedFields}: запасной мост lexicon_prefix → section
 *    (кодовое имя), если секции нет в mpc_config TV;
 *  - migx-конфиги (mpc_base, list_* и пр.): подписи полей
 *    (formtabs[*].fields[*].caption) → caption по имени поля.
 *
 * Спец-ключи: `mpc_resource_tv_<f>` → «TV: <f>», `mpc_resource_<f>` →
 * «Поля ресурса: <f>». Секционные `{prefix}_{...}` → подбор по самому
 * длинному совпавшему префиксу + подпись поля с фолбэками (idx/опции).
 *
 * Best-effort: для глубоко вложенных/опционных ключей подпись приблизительная
 * (это справочная колонка, не данные).
 */
class LexiconContext
{
    private \modX $modx;
    private string $resourceLabel;
    private string $tvLabel;

    /** Разделитель сегментов пути в колонке «Контекст». */
    private const SEP = ' › ';

    /** lexicon_prefix => отображаемое имя секции (data-mpc-name; фолбэк — кодовое). */
    private ?array $prefixDisplay = null;
    /** имя поля => подпись (по всем migx-конфигам). */
    private array $fieldCaptions = [];
    /** имя поля-списка (kind=list из манифеста) => true. */
    private array $listFields = [];

    public function __construct(\modX $modx, string $resourceLabel = 'Поля ресурса', string $tvLabel = 'TV')
    {
        $this->modx          = $modx;
        $this->resourceLabel = $resourceLabel;
        $this->tvLabel       = $tvLabel;
    }

    /** Подпись «Секция: Поле» для ключа (пусто, если контекст не распознан). */
    public function contextFor(string $key): string
    {
        if ($key === '') {
            return '';
        }

        if (strpos($key, 'mpc_resource_tv_') === 0) {
            return $this->tvLabel . ': ' . substr($key, strlen('mpc_resource_tv_'));
        }
        if (strpos($key, 'mpc_resource_') === 0) {
            return $this->resourceLabel . ': ' . substr($key, strlen('mpc_resource_'));
        }

        $this->ensureMaps();

        $split = self::splitSectionKey($key, array_keys($this->prefixDisplay));
        if ($split === null) {
            return '';
        }
        [$prefix, $remainder] = $split;

        $display    = self::stripStaticMarker($this->prefixDisplay[$prefix] ?? $prefix);
        $breadcrumb = self::buildBreadcrumb($remainder, $this->fieldCaptions, $this->listFields);

        $parts = [$display];
        if ($breadcrumb !== '') {
            $parts = array_merge($parts, explode(self::SEP, $breadcrumb));
        }
        return implode(self::SEP, self::dedupeConsecutive($parts));
    }

    /** Схлопывает подряд идущие одинаковые сегменты пути (напр. имя списка == имя секции). */
    public static function dedupeConsecutive(array $parts): array
    {
        $out = [];
        foreach ($parts as $p) {
            if (empty($out) || end($out) !== $p) {
                $out[] = $p;
            }
        }
        return $out;
    }

    /**
     * Чистый разбор секционного ключа: ищет самый ДЛИННЫЙ префикс, на который
     * ключ совпадает целиком или начинается с `{prefix}_`. Возвращает
     * [prefix, remainder] либо null. Длинный-первый важен, чтобы `features_demo`
     * не перехватывался более коротким `features`.
     *
     * @param string[] $prefixes набор lexicon_prefix
     * @return array{0:string,1:string}|null
     */
    public static function splitSectionKey(string $key, array $prefixes): ?array
    {
        usort($prefixes, static fn($a, $b) => strlen((string)$b) <=> strlen((string)$a));
        foreach ($prefixes as $prefix) {
            $prefix = (string)$prefix;
            if ($prefix === '') {
                continue;
            }
            if ($key === $prefix) {
                return [$prefix, ''];
            }
            if (strpos($key, $prefix . '_') === 0) {
                return [$prefix, substr($key, strlen($prefix) + 1)];
            }
        }
        return null;
    }

    /**
     * Имя секции по lexicon_prefix из секций mpc_config TV (PURE). Для каждой
     * секции prefix = lexicon_prefix ?: MIGX_formname; display = section_name
     * (data-mpc-name) ?: prefix. Первое непустое имя на префикс — побеждает.
     *
     * @param array $sections массив секций (как в значении mpc_config TV)
     * @param array $into     карта prefix=>display для накопления (по ссылке)
     */
    public static function collectPrefixDisplay(array $sections, array &$into): void
    {
        foreach ($sections as $sec) {
            if (!is_array($sec)) {
                continue;
            }
            $prefix = (string)($sec['lexicon_prefix'] ?? ($sec['MIGX_formname'] ?? ''));
            if ($prefix === '') {
                continue;
            }
            $display = (string)($sec['section_name'] ?? '');
            if ($display === '') {
                $display = $prefix;
            }
            // Не перетираем уже найденное человекочитаемое имя пустым/кодовым.
            if (!isset($into[$prefix]) || $into[$prefix] === $prefix) {
                $into[$prefix] = $display;
            }
        }
    }

    /**
     * Чистый разбор «остатка» ключа (после префикса секции) в ПОЛНЫЙ путь
     * «Поле › Элемент N › Подполе …». Ключ кодирует структуру:
     * `{список}` либо `{список}_{idx}` на каждый уровень + лист `{поле}` с
     * зеркальным хвостовым `_{idx}` (его игнорируем — дублирует idx строки).
     *
     * Уровень считаем СТРОКОЙ СПИСКА («Элемент N», N = idx+1), если поле — список
     * (kind=list из манифеста) ИЛИ за ним явный числовой idx. Прочие промежуточные
     * поля (медиа-база img/picture и т.п.) идут как сегмент пути без «Элемента».
     * Имена полей матчим жадно-длинным совпадением (учитываем `cta_text`,
     * `list_triple_picture` и т.п.).
     *
     * @param array $fieldCaptions имя поля => подпись
     * @param array $listFields    имя поля => true (kind=list)
     */
    public static function buildBreadcrumb(string $remainder, array $fieldCaptions, array $listFields): string
    {
        if ($remainder === '') {
            return '';
        }
        $known = $fieldCaptions + $listFields; // объединённый словарь имён для матчинга
        $parts = explode('_', $remainder);
        $n     = count($parts);

        $crumbs = [];
        $i      = 0;
        while ($i < $n) {
            [$field, $flen] = self::longestFieldMatch($parts, $i, $known);
            $i += $flen;

            $idx = null;
            if ($i < $n && ctype_digit($parts[$i])) {
                $idx = (int)$parts[$i];
                $i++;
            }
            $isLast  = $i >= $n;
            $caption = $fieldCaptions[$field] ?? $field;

            if (!$isLast && (isset($listFields[$field]) || $idx !== null)) {
                // строка списка
                $crumbs[] = $caption;
                $crumbs[] = 'Элемент ' . (($idx ?? 0) + 1);
            } else {
                // промежуточный сегмент пути ИЛИ лист (хвостовой idx-зеркало опущен)
                $crumbs[] = $caption;
            }
        }
        return implode(self::SEP, $crumbs);
    }

    /**
     * Жадно-длинное совпадение имени поля от позиции $i: пробуем самую длинную
     * цепочку сегментов, что есть в словаре $known. Нет совпадения — один сегмент.
     * @return array{0:string,1:int} [имя поля, число съеденных сегментов]
     */
    private static function longestFieldMatch(array $parts, int $i, array $known): array
    {
        $n = count($parts);
        for ($len = $n - $i; $len >= 1; $len--) {
            $candidate = implode('_', array_slice($parts, $i, $len));
            if (isset($known[$candidate])) {
                return [$candidate, $len];
            }
        }
        return [$parts[$i], 1];
    }

    /**
     * Убирает из отображаемого имени секции маркер статичности (по требованию:
     * «не писать статична или нет»): скобочный «(…статичн…)» и отдельное слово
     * «статичн…». Чистит сдвоенные пробелы.
     */
    public static function stripStaticMarker(string $name): string
    {
        $name = preg_replace('/\s*\([^)]*статичн[^)]*\)/ui', '', $name);
        $name = preg_replace('/\s*статичн[а-яё]*/ui', '', (string)$name);
        return trim(preg_replace('/\s{2,}/u', ' ', (string)$name));
    }

    /** Лениво строит карты prefix→display и field→caption. */
    private function ensureMaps(): void
    {
        if ($this->prefixDisplay !== null) {
            return;
        }
        $this->prefixDisplay = [];

        // migxConfig / mpc_config TV — кастомные xPDO-классы пакета migx; без
        // addPackage getCollection/getObject вернут пусто (контекст не резолвится).
        $corePath = $this->modx->getOption('migxpageconfigurator_core_path', null,
            $this->modx->getOption('core_path') . 'components/migxpageconfigurator/');
        $this->modx->addPackage('migx', rtrim($corePath, '/') . '/../migx/model/');

        // Запасной мост из манифеста: prefix → кодовое имя секции (если секции
        // нет в mpc_config TV — напр. ещё не сохранялась). Плюс набор полей-списков
        // (kind=list) — для нумерации «Элемент N» в пути.
        foreach ((new TrackedFields($this->modx))->all() as $row) {
            $prefix = (string)($row['lexicon_prefix'] ?? '');
            if ($prefix !== '' && !isset($this->prefixDisplay[$prefix])) {
                $this->prefixDisplay[$prefix] = (string)($row['section'] ?? $prefix) ?: $prefix;
            }
            if (($row['kind'] ?? '') === 'list' && ($row['field'] ?? '') !== '') {
                $this->listFields[(string)$row['field']] = true;
            }
        }

        // Основной источник имени секции — секции из всех значений mpc_config TV.
        $this->collectSectionDisplayFromTv();

        // Подписи полей — из formtabs всех migx-конфигов (mpc_base, list_* и пр.).
        foreach ($this->modx->getCollection('migxConfig') as $cfg) {
            $tabs = json_decode((string)$cfg->get('formtabs'), true);
            if (!is_array($tabs)) {
                continue;
            }
            foreach ($tabs as $tab) {
                foreach ($tab['fields'] ?? [] as $f) {
                    $field   = (string)($f['field'] ?? '');
                    $caption = (string)($f['caption'] ?? '');
                    if ($field !== '' && $caption !== '' && !isset($this->fieldCaptions[$field])) {
                        $this->fieldCaptions[$field] = $caption;
                    }
                }
            }
        }
    }

    /** Имена секций (data-mpc-name) из ВСЕХ значений mpc_config TV → prefixDisplay. */
    private function collectSectionDisplayFromTv(): void
    {
        $tvName = (string)$this->modx->getOption('mpc_common_config_name', null, 'mpc_config');
        $tv = $this->modx->getObject('modTemplateVar', ['name' => $tvName]);
        if (!$tv) {
            return;
        }
        $tvId = (int)$tv->get('id');

        // Per-resource значения (секции страниц/типов/статик-блоков).
        foreach ($this->modx->getCollection('modTemplateVarResource', ['tmplvarid' => $tvId]) as $tvr) {
            $sections = json_decode((string)$tvr->get('value'), true);
            if (is_array($sections)) {
                self::collectPrefixDisplay($sections, $this->prefixDisplay);
            }
        }
        // Дефолтное значение TV (если секции заданы там).
        $sections = json_decode((string)$tv->get('default_text'), true);
        if (is_array($sections)) {
            self::collectPrefixDisplay($sections, $this->prefixDisplay);
        }
    }
}
