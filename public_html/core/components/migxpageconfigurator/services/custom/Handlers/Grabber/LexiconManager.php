<?php

namespace MpcServices\Handlers\Grabber;

/**
 * Управление лексиконами: запись, чтение, санитизация значений.
 */
class LexiconManager
{
    public array $lexicons = [];

    private string $sectionLexiconPrefix = '';
    private bool   $sectionIsStatic      = false;
    private \modX  $modx;
    private array  $properties;

    /**
     * Кэш rid → идентификатор лексикона за запрос. Метод зовётся per-resource
     * (каталог), а при lexiconFilenameField=alias делал бы запрос к modResource +
     * invokeEvent на КАЖДЫЙ вызов. Эффективен благодаря request-scoped Mpc
     * (один LexiconManager на запрос — см. Mpc::instance()).
     */
    private array  $identifierCache = [];

    /**
     * Реестр известных префиксов секций: `prefix => true`. `null` — реестр не
     * построен (или построить не удалось), тогда чистка по префиксу не идёт
     * вовсе: без полного реестра нельзя отличить свой устаревший ключ от
     * чужого. Заполняется лениво в `ensurePrefixRegistry()` или снаружи через
     * `setKnownPrefixes()`.
     */
    private ?array $knownPrefixes = null;

    /** Попытка построить реестр уже была (второй раз не читаем диск и БД). */
    private bool $prefixRegistryAttempted = false;

    /** Префиксы секций, обработанные текущим проходом нарезки: prefix => true. */
    private array $processedPrefixes = [];

    public function __construct(\modX $modx, array $properties)
    {
        $this->modx       = $modx;
        $this->properties = $properties;
    }

    /**
     * Обновляет свойства после отложенной инициализации (например, после загрузки лексиконов).
     */
    public function updateProperties(array $properties): void
    {
        $this->properties = array_merge($this->properties, $properties);
    }

    /**
     * Устанавливает контекст текущей секции перед её обработкой.
     *
     * Для статик-секций дополнительно вычищает прежние ключи ЭТОЙ секции из
     * предзагруженного массива лексиконов (wipe-then-refill). Зачем: статик-файл
     * (`page-types.inc.php`) предзагружается целиком в `lexicons[$staticId]`
     * (см. Grabber), а `createLexicons` переписывает файл этим массивом. Exclude
     * гейтит только запись НОВЫХ ключей и ничего не удаляет → excluded/orphan
     * ключ, записанный до попадания в exclude, переживал бы реграб. У нестатики
     * файл не предзагружается и пересобирается с нуля, поэтому чистка не нужна —
     * этим wipe приводим статику к тому же поведению.
     *
     * Копии (`data-mpc-copy`) wipe пропускают (`$isCopy`): это часто пустой
     * плейсхолдер-ссылка на оригинал без полей — wipe удалил бы ключи секции, а
     * refill не наполнил (полей нет) → ключи пропали бы безвозвратно. Лексиконами
     * секции владеет грабинг non-copy оригинала.
     *
     * No-op для cutter-флоу: там `lexicons[$rid]` не предзагружается, guard
     * `empty(...)` коротко замыкает. Глобалки (`mpc_resource_*` и пр.) целы —
     * они не начинаются с префикса секции.
     *
     * Чистка идёт только при полном реестре известных префиксов
     * (`ensurePrefixRegistry`) и сносит лишь ключи, принадлежащие ИМЕННО этой
     * секции (`ownsLexiconKey`, правило самого длинного префикса). Реестр
     * строится ДО первой очистки — иначе результат зависел бы от того, какой
     * шаблон нарезан раньше.
     */
    public function setContext(string $prefix, bool $isStatic, bool $isCopy = false): void
    {
        $this->sectionLexiconPrefix = $prefix;
        $this->sectionIsStatic      = $isStatic;
        if ($prefix !== '') {
            // Префиксы, которые этот проход реально нарезал. Только их ключи
            // createLexicons вправе считать «пропавшими из вёрстки»; чужие
            // секции и ручные ключи проход не видел и судить о них не может.
            $this->processedPrefixes[$prefix] = true;
        }

        if (!$isStatic || $isCopy || $prefix === '') {
            return;
        }
        $rid = $this->properties['staticBlocksPageLexiconFilename'] ?? '';
        if ($rid === '' || empty($this->lexicons[$rid])) {
            return;
        }
        if (!$this->ensurePrefixRegistry()) {
            return;
        }
        foreach (array_keys($this->lexicons[$rid]) as $key) {
            if ($this->ownsLexiconKey((string)$key, $prefix)) {
                unset($this->lexicons[$rid][$key]);
            }
        }
    }

    /**
     * Принадлежит ли ключ секции с префиксом `$prefix` — правило «побеждает самый
     * длинный известный префикс».
     *
     * Сравниваем ПОЛНЫЙ ключ с `$prefix . '_'`, а затем проверяем, нет ли в
     * реестре более длинного известного префикса, которому ключ подходит лучше.
     * Без второй проверки секция с коротким префиксом (`difference`) сносила
     * ключи соседей с длинными (`difference_weighted_title`), а вернуть их мог
     * только грабинг самих соседей — то есть выживание ключа зависело от порядка
     * обхода каталога шаблонов (`Mpc::getFilesList()` не сортирует). Разбор —
     * knowledge-base `tasks/2026-09-09-sleepandglow-empty-section-titles.md`.
     *
     * Границы соблюдаются за счёт подчёркивания: для префикса `x_y` ключ
     * `x_yz_title` чужой (не начинается с `x_y_`), а `x_y_title` — свой.
     * Устаревшее СОБСТВЕННОЕ поле (`cta_old_orphan` при отсутствии секции
     * `cta_old`) чистится по-прежнему: более длинного известного префикса нет.
     */
    private function ownsLexiconKey(string $key, string $prefix): bool
    {
        $needle = $prefix . '_';
        if (strpos($key, $needle) !== 0) {
            return false;
        }
        foreach ($this->knownPrefixes as $known => $_) {
            if (strlen($known) <= strlen($prefix)) {
                continue;
            }
            if (strpos($known, $needle) !== 0) {
                continue; // не вложен в наш префикс — к этому ключу отношения не имеет
            }
            if (strpos($key, $known . '_') === 0) {
                return false; // ключ принадлежит более длинному префиксу
            }
        }
        return true;
    }

    /**
     * Задать реестр известных префиксов секций снаружи (нарезка/тесты).
     * Вызов означает: реестр полный, чистке можно доверять.
     */
    public function setKnownPrefixes(array $prefixes): void
    {
        $clean = [];
        foreach ($prefixes as $prefix) {
            $prefix = trim((string)$prefix);
            if ($prefix !== '') {
                $clean[$prefix] = true;
            }
        }
        $this->knownPrefixes = $clean;
        $this->prefixRegistryAttempted = true;
    }

    /** Реестр известных префиксов (диагностика и тесты). */
    public function getKnownPrefixes(): array
    {
        return $this->knownPrefixes === null ? [] : array_keys($this->knownPrefixes);
    }

    /**
     * Построить реестр, если он ещё не задан. Источники — ОБА, и оба доступны ДО
     * первой очистки, чтобы результат не зависел от порядка обхода файлов:
     *  1) вёрстка целиком (`pathToSrc`) — все секции текущего прогона, включая
     *     ещё не обработанные;
     *  2) сохранённые префиксы манифеста `mpc_tracked_fields` — секции, которых
     *     в текущем дереве шаблонов может уже не быть.
     *
     * Реестр считается полным, только если ОБА источника отработали без ошибок.
     * Ошибка любого из них (каталога вёрстки нет, отдельный шаблон не читается,
     * запрос к манифесту упал) и пустой скан вёрстки дают false: чистка не идёт,
     * устаревшие ключи переживут прогон. Это осознанный размен — лишний ключ
     * безвреден, потерянный перевод не восстановить.
     *
     * ⚠️ Отличать «источник вернул пусто» от «источник не смог ответить»
     * обязательно: пока `prefixes()` глушила ошибку пустым массивом, сбой чтения
     * манифеста выглядел как «сохранённых префиксов нет», реестр объявлялся
     * полным по одной вёрстке и чужой ключ `x_y_title` при известном только `x`
     * уходил под нож. Поэтому оба сборщика отдают `null` на ошибке.
     */
    private function ensurePrefixRegistry(): bool
    {
        if ($this->prefixRegistryAttempted) {
            return $this->knownPrefixes !== null;
        }
        $this->prefixRegistryAttempted = true;

        $fromTemplates = $this->collectTemplatePrefixes();
        if ($fromTemplates === null) {
            $this->modx->log(
                \modX::LOG_LEVEL_WARN,
                '[mpc lexicon] вёрстку прочитать не удалось — реестр префиксов неполон,'
                . ' чистка статик-ключей пропущена'
            );
            return false;
        }
        if ($fromTemplates === []) {
            $this->modx->log(
                \modX::LOG_LEVEL_WARN,
                '[mpc lexicon] реестр префиксов секций пуст — чистка статик-ключей пропущена'
            );
            return false;
        }

        $fromTracked = $this->collectTrackedPrefixes();
        if ($fromTracked === null) {
            $this->modx->log(
                \modX::LOG_LEVEL_WARN,
                '[mpc lexicon] манифест трекаемых полей недоступен — реестр префиксов неполон,'
                . ' чистка статик-ключей пропущена'
            );
            return false;
        }

        $registry = $fromTemplates;
        foreach ($fromTracked as $prefix) {
            $prefix = trim((string)$prefix);
            if ($prefix !== '') {
                $registry[$prefix] = true;
            }
        }
        $this->knownPrefixes = $registry;
        return true;
    }

    /**
     * Префиксы всех секций вёрстки. Читаем текстом, а не парсером DOM: нужен
     * только набор значений маркеров, и обход сотен шаблонов DiDom'ом ради этого
     * неоправдан. Префикс секции = `data-mpc-lexicon`, при его отсутствии —
     * `data-mpc-section` (тот же фолбэк, что в `SectionProcessor::grabSection`),
     * поэтому собираем оба маркера.
     *
     * `null` — обход НЕ УДАЛСЯ целиком или частично: каталога нет, итератор
     * упал на нечитаемой подпапке, отдельный шаблон не прочитался. Частично
     * прочитанный каталог — тот же неполный реестр, что и полностью нечитаемый:
     * секции из пропущенного файла в нём не окажется, и её ключи снесёт сосед с
     * более коротким префиксом. Пустой файл ошибкой не считается — там просто
     * нет маркеров.
     */
    private function collectTemplatePrefixes(): ?array
    {
        $dir = (string)($this->properties['pdotoolsElementsPath'] ?? '')
            . (string)($this->properties['pathToSrc'] ?? '');
        if ($dir === '' || !is_dir($dir)) {
            return null;
        }
        $prefixes = [];
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(rtrim($dir, '/\\'), \RecursiveDirectoryIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }
                $path = $file->getPathname();
                $html = @file_get_contents($path);
                if ($html === false) {
                    $this->modx->log(
                        \modX::LOG_LEVEL_WARN,
                        '[mpc lexicon] шаблон не прочитан: ' . $path
                    );
                    return null;
                }
                if ($html === '') {
                    continue;
                }
                if (!preg_match_all(
                    '/data-mpc-(?:lexicon|section)\s*=\s*([\'"])(.*?)\1/i',
                    $html,
                    $matches
                )) {
                    continue;
                }
                foreach ($matches[2] as $value) {
                    $value = trim($value);
                    if ($value !== '') {
                        $prefixes[$value] = true;
                    }
                }
            }
        } catch (\Throwable $ex) {
            $this->modx->log(\modX::LOG_LEVEL_WARN, '[mpc lexicon] обход вёрстки: ' . $ex->getMessage());
            return null;
        }
        return $prefixes;
    }

    /**
     * Сохранённые префиксы манифеста трекаемых полей. `null` — манифест
     * недоступен (в отличие от пустого массива «манифест пуст»); по `null`
     * вызывающий обязан признать реестр неполным.
     */
    private function collectTrackedPrefixes(): ?array
    {
        try {
            return (new \MpcServices\Handlers\TrackedFields($this->modx))->prefixes();
        } catch (\Throwable $ex) {
            $this->modx->log(\modX::LOG_LEVEL_WARN, '[mpc lexicon] манифест трекаемых полей: ' . $ex->getMessage());
            return null;
        }
    }

    /**
     * Включён ли лексикон для указанного content-type.
     * Используется и грабером (нужно ли заводить ключ), и каттером (нужно ли
     * добавлять `| lexicon` к плейсхолдеру) — единый источник решения.
     */
    public function isLexiconField(string $contentType): bool
    {
        if (empty($this->properties['useLexicons'])) {
            return false;
        }
        $types = $this->properties['translatableContentTypes'] ?? [];
        return in_array($contentType, $types, true);
    }

    /**
     * Должно ли поле быть лексиконизировано с учётом exclusion-паттернов.
     * Комбинирует `isLexiconField` (content-type translatable) + проверку
     * против `excludeLexiconFields` по двум именам:
     *  - `fieldName` — само имя поля (для exclude-паттернов вроде `picture`,
     *    `MIGX_id`, `img*`).
     *  - **полный путь** `parentFieldName_fieldName` — для exclude-паттернов
     *    типа `*_picture`, `hero_*`, конкретных накопленных путей.
     *
     * Раньше проверялся `parentFieldName` отдельно — это давало ложные
     * срабатывания: pattern `*_picture` (для image-полей) матчился с
     * контейнерным именем `list_triple_picture` и исключал любые text-поля
     * (subtitle/title) внутри такого контейнера. Проверка по полному пути
     * лечит это: `list_triple_picture_subtitle` не оканчивается на
     * `_picture`, а `list_triple_picture_picture` — оканчивается.
     *
     * Лимитация: cutter работает на уровне схемы (без idx), поэтому
     * exclude-паттерны с конкретными row-индексами (`cards_1_subtitle_2`)
     * не сматчатся на cutter-стороне. Для row-агностических исключений
     * используйте glob (`cards_*_subtitle_*`).
     */
    public function shouldLexiconize(string $contentType, string $fieldName, string $parentFieldName = ''): bool
    {
        if (!$this->isLexiconField($contentType)) {
            return false;
        }
        if ($fieldName !== '' && $this->isFieldExcluded($fieldName)) {
            return false;
        }
        if ($parentFieldName !== '') {
            $fullPath = "{$parentFieldName}_{$fieldName}";
            if ($fullPath !== $fieldName && $this->isFieldExcluded($fullPath)) {
                return false;
            }
        }
        // Третья проверка — полный lex-ключ с префиксом секции
        // (`{prefix}_{parent}_{field}`), зеркало грабера: `setLexicons` исключает
        // по `isFieldExcluded($lexiconKey)` (строка ~227), где ключ строится с
        // префиксом через `getLexiconKey`. Без неё каттер (единственный, кто
        // вызывает shouldLexiconize) не видел exclude-записи в префиксной форме
        // (`image_banner_content`, `*_content`) → ставил `| lexicon` там, где
        // грабер ключ не заводил → на рендере `| lexicon` отдавал пусто.
        // Префикс заполняется через setContext (грабер — SectionProcessor,
        // каттер — PlaceholderProcessor::setSectionContext). Idx опускаем:
        // каттер работает на уровне схемы (без row-индексов) — для row-агностики
        // используйте glob.
        if ($this->sectionLexiconPrefix !== '' && $fieldName !== '') {
            $lexKey = $parentFieldName !== ''
                ? "{$this->sectionLexiconPrefix}_{$parentFieldName}_{$fieldName}"
                : "{$this->sectionLexiconPrefix}_{$fieldName}";
            if ($this->isFieldExcluded($lexKey)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Допустимые поля-источники имени лексикон-файла: ТОЛЬКО alias (структура
     * одинакова в разных контекстах) и id (структура разная). Любое иное
     * значение → 'id'. Сужает «полную свободу» (любая колонка), чтобы опечатка/
     * произвольная колонка не ломала именование. Применяется ВЕЗДЕ, где читается
     * mpc_lexicon_filename_field.
     */
    public static function normalizeFilenameField(?string $field): string
    {
        $field = trim((string)$field);
        return in_array($field, ['id', 'alias'], true) ? $field : 'id';
    }

    public function getResourceIdentifierById(int $rid): string
    {
        if (isset($this->identifierCache[$rid])) {
            return $this->identifierCache[$rid];
        }
        $cacheKey = $rid; // ниже $rid переписывается значением поля (alias) — фиксируем ключ
        $field = self::normalizeFilenameField($this->properties['lexiconFilenameField'] ?? 'id');
        if ($field !== 'id') {
            $q = $this->modx->newQuery('modResource');
            $q->select($field);
            $q->where(['id' => $rid]);
            // prepare() возвращает false, если SQL не подготовился (напр.
            // mpc_lexicon_filename_field указывает на несуществующую колонку
            // modResource / TV). Тогда $q->stmt === false и execute() фаталит —
            // поэтому проверяем подготовку и деградируем на числовой id.
            $q->prepare();
            if (is_object($q->stmt) && $q->stmt->execute()) {
                $rid = (string)$q->stmt->fetchColumn();
                $rid = trim($rid);
                $rid = strtolower($rid);
                $rid = str_replace([' ', "\n", "\r"], '-', $rid);
            } else {
                $this->modx->log(\modX::LOG_LEVEL_ERROR, sprintf(
                    '[mpc] LexiconManager: не удалось подготовить запрос идентификатора лексикона по полю "%s" (допустимы только id/alias). Фолбэк на числовой id %d.',
                    $field,
                    $rid
                ));
            }
        }

        $this->modx->invokeEvent('mpcOnGetResourceIdentifier', [
            'rid'     => $rid,
            'Grabber' => $this,
        ]);

        $identifier = isset($this->modx->event->returnedValues) && !empty($this->modx->event->returnedValues['rid'])
            ? $this->modx->event->returnedValues['rid'] : $rid;
        // идентификатор становится именем файла лексикона — запрещаем traversal
        // (mpcOnGetResourceIdentifier может вернуть '../../evil').
        return $this->identifierCache[$cacheKey] = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$identifier);
    }

    public function getLexicons(string $rid, string $basePath): array
    {
        $rid = basename($rid); // защита от traversal в имени файла лексикона
        $pathToLexiconFile = $basePath . $rid . '.inc.php';
        if (file_exists($pathToLexiconFile)) {
            include $pathToLexiconFile;
            return $_lang ?? [];
        }
        return [];
    }

    public function sanitizeValue(?string $value = ''): string
    {
        // null/'' — пусто; но НЕ "0" (оно falsy в PHP) — иначе валидное
        // лексикон-значение "0" терялось бы.
        if ($value === null || $value === '') {
            return '';
        }

        // Инлайн-style, обработчики событий (on*) и js/vbscript-URL в значении не
        // нужны и небезопасны: strip_tags оставляет ВСЕ атрибуты на разрешённых
        // тегах, поэтому чистим их явно (иначе вставка из IDE/браузера тащит style
        // в лексикон, хотя он не разрешён). Разметка в лексиконе — только теги
        // форматирования из mpc_allowed_tags, без инлайн-оформления.
        $value = preg_replace('/\s+(style|on\w+)\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', (string)$value);
        $value = preg_replace('/\s+(href|src|xlink:href)\s*=\s*("\s*(?:javascript|vbscript):[^"]*"|\'\s*(?:javascript|vbscript):[^\']*\')/i', '', $value);

        $value = str_replace("'", '&apos;', $value);
        $value = strip_tags($value, $this->properties['allowedTags']);
        $value = trim($value);

        if (!$this->properties['allowModxTags']) {
            $value = preg_replace('/\{.*?\}/', '', $value);
            $value = preg_replace('/\[\[\+.*?\]\]/', '', $value);
            $value = str_replace('{', '{ ', $value);
        }

        $this->modx->invokeEvent('mpcOnImportLexiconValue', [
            '$value' => $value,
        ]);

        return isset($this->modx->event->returnedValues) && !empty($this->modx->event->returnedValues['value'])
            ? $this->modx->event->returnedValues['value'] : $value;
    }

    public function setLexicons(?string $value = '', ?array $options = []): string
    {
        if (!$this->properties['useLexicons'] || !$value) {
            return $value ?? '';
        }

        $fieldName       = $options['fieldName'] ?? '';
        $parentFieldName = $options['parentFieldName'] ?? '';

        if ($this->isFieldExcluded($fieldName)) {
            return $value ?? '';
        }

        // Проверка по ПОЛНОМУ пути (parent_field), не parentFieldName
        // в одиночку — иначе pattern `*_picture` исключал бы все text-поля
        // (subtitle/title) внутри контейнера с именем `list_triple_picture`.
        // Подробнее — в shouldLexiconize() docstring.
        if ($parentFieldName && $fieldName) {
            $fullPath = "{$parentFieldName}_{$fieldName}";
            if ($this->isFieldExcluded($fullPath)) {
                return $value ?? '';
            }
        }

        $options['prefix'] = $options['prefix'] ?? $this->sectionLexiconPrefix;
        $lexiconKey        = self::getLexiconKey($options);

        $this->modx->invokeEvent('mpcOnGetLexiconKey', [
            'sectionLexiconPrefix' => $this->sectionLexiconPrefix,
            'lexiconKey'           => $lexiconKey,
            'fieldName'            => $fieldName,
            'Grabber'              => $this,
        ]);

        $lexiconKey = isset($this->modx->event->returnedValues) && !empty($this->modx->event->returnedValues['lexiconKey'])
            ? $this->modx->event->returnedValues['lexiconKey'] : $lexiconKey;

        if (!$lexiconKey || $this->isFieldExcluded($lexiconKey)) {
            return $value;
        }

        if ($this->sectionIsStatic) {
            $rid = $this->properties['staticBlocksPageLexiconFilename'];
        } elseif ($options['prefix'] === 'contact') {
            $rid = $this->properties['contactsPageLexiconFilename'];
        } else {
            $rid = $this->getResourceIdentifierById($this->properties['resource']->get('id'));
        }

        $this->lexicons[$rid][$lexiconKey] = $this->sanitizeValue($value);

        // Возвращаем сам ключ. Cutter на своей стороне добавит `| lexicon` к плейсхолдеру,
        // если поле лексиконное. Так значение в БД остаётся «чистыми данными»,
        // Fenom-синтаксис строит только PlaceholderProcessor — единый источник правды
        // для шаблона.
        return $lexiconKey;
    }

    /** Префикс лексиконов текущей секции (каттеру для listbox-ключа {prefix}_{field}_{value}). */
    public function getSectionPrefix(): string
    {
        return $this->sectionLexiconPrefix;
    }

    /**
     * Капшены опций TV → лексикон ресурса под ключами mpc_resource_tv_<tv>_<value>
     * (как секции, но per-resource неймспейс). Источник — elements TV из БД (keyed,
     * капшен сохранён normalizeInputOptionValues). value уже нормализован.
     */
    public function writeTvOptionCaptions(int $resourceId, string $tvName, string $elements): void
    {
        $parsed = OptionFieldHelper::classifyListboxOptions($elements);
        if ($parsed['mode'] === 'dynamic' || empty($parsed['options'])) {
            return;
        }
        $rid = $this->getResourceIdentifierById($resourceId);
        if ($rid === '') {
            return;
        }
        $base = 'mpc_resource_tv_' . $tvName . '_';
        foreach ($parsed['options'] as $opt) {
            if ($opt['lexValue'] === '') {
                continue;
            }
            $this->lexicons[$rid][$base . $opt['value']] = $this->sanitizeValue($opt['lexValue']);
        }
    }

    /**
     * Лексиконизация ОПЦИЙ listbox: капшены уезжают в лексикон под ключами
     * {prefix}_{field}_{optionKey} (пустой key → {prefix}_{field}_). Значение поля
     * остаётся СЫРЫМ ключом опции — резолв на рендере делает плейсхолдер
     * ##'{prefix}_{field}_{$value}' | lexicon} (его ставит PlaceholderProcessor).
     * Пишем только если shouldLexiconize('text',…), список keyed и текущее значение
     * входит в список ключей (иначе лексикон не применяется — каттер ставит {$value}).
     */
    public function writeListboxOptions(string $fieldName, string $parentFieldName, string $rawValues, string $currentValue, bool $multiple = false): void
    {
        if (!$this->shouldLexiconize('text', $fieldName, $parentFieldName)) {
            return;
        }
        $parsed = OptionFieldHelper::classifyListboxOptions($rawValues);
        // dynamic (@SELECT) — лексикон не пишем (резолвит migx/SQL).
        if ($parsed['mode'] === 'dynamic' || empty($parsed['options'])) {
            return;
        }

        if ($this->sectionIsStatic) {
            $rid = $this->properties['staticBlocksPageLexiconFilename'];
        } elseif ($this->sectionLexiconPrefix === 'contact') {
            $rid = $this->properties['contactsPageLexiconFilename'];
        } else {
            $rid = $this->getResourceIdentifierById($this->properties['resource']->get('id'));
        }

        // Ключ {prefix}_{field}_{norm(value)} → lexValue (caption для keyed, оригинал
        // для list). Пишем ВСЕ опции (рендер резолвит выбранные).
        $base = $this->sectionLexiconPrefix . '_' . $fieldName . '_';
        foreach ($parsed['options'] as $opt) {
            if ($opt['lexValue'] === '') {
                continue;
            }
            $this->lexicons[$rid][$base . $opt['value']] = $this->sanitizeValue($opt['lexValue']);
        }
    }

    public function createLexicons(array $allLexicons, bool $overwrite = true): void
    {
        $basePathToLexiconFile = $this->properties['basePathToLexiconFile'];
        // basePathToLexiconFile — всегда каталог ОДНОГО языка, поэтому язык и
        // корень словаря берутся из самого пути.
        $lang  = basename(rtrim($basePathToLexiconFile, '/'));
        $store = new \MpcServices\Handlers\Lexicon\LexiconStore(dirname(rtrim($basePathToLexiconFile, '/')));

        // Нарезка — такой же писатель словаря, как импорт XLSX и правка ключа,
        // поэтому идёт под ОБЩЕЙ блокировкой и пишет атомарно (temp + rename).
        // Иначе параллельный импорт читал бы файл в момент перезаписи.
        $store->withLock(function () use ($allLexicons, $overwrite, $basePathToLexiconFile, $lang, $store): void {
            foreach ($allLexicons as $rid => $lexicons) {
                $pathToLexiconFile = $basePathToLexiconFile . $rid . '.inc.php';
                // Санитизируем ТОЛЬКО свежую нарезку: значения, уже лежащие в
                // файле, прошли санитайз при своей записи, а повторный проход
                // по ним менял бы чужие тексты без причины.
                $lexicons = array_map(function ($v): string {
                    return $this->sanitizeValue((string)$v);
                }, $lexicons);
                // Ключи, которых нарезка не встретила, БОЛЬШЕ НЕ УДАЛЯЮТСЯ: файл
                // пересобирался целиком, и переводы принятых, но не затронутых этим
                // проходом ключей исчезали вместе с ним (инцидент 01.09.2026 —
                // потерянный набор ключей на живом сервере). Такие ключи остаются в
                // файле, а те из них, что принадлежат нарезанным секциям,
                // записываются кандидатами на удаление; чистит их отдельное явное
                // действие с бэкапом.
                $lexicons = $this->keepUntouchedKeys($pathToLexiconFile, $lexicons, (string)$rid);
                if (!$overwrite && file_exists($pathToLexiconFile)) {
                    // Без updContent: сохраняем ЗНАЧЕНИЯ существующих переводов, но
                    // ТОЛЬКО для ключей, которые ещё есть в текущей нарезке (поле
                    // живо). Ключи полей, ушедших из вёрстки, сюда уже добавил
                    // keepUntouchedKeys со своими прежними значениями — они
                    // сохраняются и помечены кандидатами на удаление.
                    // Новые поля берут значение из шаблона.
                    $existing = $store->read($lang, (string)$rid);
                    foreach ($lexicons as $k => $v) {
                        if (array_key_exists($k, $existing)) {
                            $lexicons[$k] = $existing[$k];
                        }
                    }
                }

                if (!empty($lexicons)) {
                    // var_export ключа И значения делает LexiconStore.
                    $store->write($lang, (string)$rid, $lexicons);
                }
                // Пустой набор больше НЕ удаляет файл: раньше сбой разбора вёрстки
                // или страница без переводимых полей сносили словарь ресурса
                // целиком. Нечего писать — файл остаётся как есть.
            }
        });

        // Синк остальных языков (mpc_available_languages): набор ключей приводится
        // к дефолтному, существующие переводы сохраняются, НОВЫЕ ключи получают
        // значение дефолтного языка как плейсхолдер (страница не ломается до
        // перевода), orphan-ключи (удалённые поля) выкидываются. Пропускается при
        // пер-полевой правке в НЕ дефолтный язык (skipLexiconSync): тогда basePath
        // указывает на файл текущего языка, и распространять правку по другим
        // языкам нельзя (перевод утёк бы в дефолт/прочие). См. Grabber::handleContactsHtml.
        if (empty($this->properties['skipLexiconSync'])) {
            $this->syncOtherLanguages($allLexicons);
        }

        // getCacheManager() гарантирует инстанс (cacheManager мог быть не загружен).
        $this->modx->getCacheManager()->refresh(['lexicon_topics' => []]);
    }

    /**
     * Синхронизация непереведённых языков с дефолтным набором ключей. Для каждого
     * языка из mpc_available_languages (кроме дефолтного) и каждого файла лексикона:
     * существующий перевод сохраняется по ключу, новый ключ берёт значение
     * дефолтного языка (плейсхолдер), удалённые из шаблона ключи выкидываются.
     * Источник набора ключей и плейсхолдеров — текущая нарезка ($allLexicons).
     */
    private function syncOtherLanguages(array $allLexicons): void
    {
        $default = (string)($this->properties['defaultLanguageKey'] ?? '');
        $langs   = array_filter(array_map('trim', explode(',', (string)$this->modx->getOption('mpc_available_languages'))));
        if (empty(array_diff($langs, [$default, ''])) || empty($allLexicons)) {
            return;
        }
        $baseLexiconPath = ($this->properties['corePath'] ?? '') . ($this->properties['lexiconPath'] ?? '');
        if ($baseLexiconPath === '') {
            return;
        }
        // Синхронизация языков + pending — через общий сервis (тот же, что зовёт
        // редактор), чтобы логика была единой.
        $sync        = new \MpcServices\Handlers\LexiconSync($baseLexiconPath, $default, $langs);
        $defaultBase = rtrim($baseLexiconPath, '/') . '/' . $default . '/';
        foreach (array_keys($allLexicons) as $rid) {
            // Источник истины — ТОЛЬКО ЧТО записанный файл дефолтного языка
            // (полный набор ключей, включая смерженные $_rlang — pagetitle/longtitle/
            // description, которых нет в $allLexicons из-за пустых значений).
            $_lang = [];
            $defaultFile = $defaultBase . $rid . '.inc.php';
            if (is_file($defaultFile)) {
                include $defaultFile;
            }
            $defaultLex = is_array($_lang) ? $_lang : [];
            $sync->syncResource((string)$rid, $defaultLex);
        }
    }

    /**
     * Переносит в новый набор ключи, которые уже лежат в файле, но в этом
     * проходе нарезки не встретились.
     *
     * Ключ, принадлежащий нарезанной секции (правило «самого длинного известного
     * префикса», то же, что у wipe статики), отмечается кандидатом на удаление:
     * поле, похоже, ушло из вёрстки. Ключ БЕЗ такого префикса — ручной или
     * чужой, и кандидатом не становится вовсе. Удаление в обоих случаях —
     * отдельное явное действие, здесь только пометка.
     *
     * @return array новый набор ключей файла (нарезанные + сохранённые)
     */
    private function keepUntouchedKeys(string $path, array $lexicons, string $rid): array
    {
        if (!is_file($path)) {
            return $lexicons;
        }
        $_lang = [];
        include $path;
        if (!is_array($_lang) || empty($_lang)) {
            return $lexicons;
        }

        $candidates = [];
        $revived    = [];
        foreach ($_lang as $key => $value) {
            if (array_key_exists($key, $lexicons)) {
                $revived[] = (string)$key; // поле снова в вёрстке
                continue;
            }
            $lexicons[$key] = $value;
            $prefix = $this->owningProcessedPrefix((string)$key);
            if ($prefix !== null) {
                $candidates[(string)$key] = (string)$value;
            }
        }

        $this->updateOrphanRegistry($path, $rid, $candidates, $revived);
        return $lexicons;
    }

    /**
     * Префикс нарезанной секции, которому принадлежит ключ, или null. Реестр
     * префиксов обязателен: без него «принадлежность» вырождается в сравнение
     * начала строки и ключ соседней секции с более длинным префиксом попал бы
     * в кандидаты на удаление.
     */
    private function owningProcessedPrefix(string $key): ?string
    {
        if (empty($this->processedPrefixes) || !$this->ensurePrefixRegistry()) {
            return null;
        }
        foreach (array_keys($this->processedPrefixes) as $prefix) {
            if ($this->ownsLexiconKey($key, (string)$prefix)) {
                return (string)$prefix;
            }
        }
        return null;
    }

    /** Отметить кандидатов и снять с учёта вернувшиеся в вёрстку ключи. */
    private function updateOrphanRegistry(string $path, string $rid, array $candidates, array $revived): void
    {
        if (empty($candidates) && empty($revived)) {
            return;
        }
        // basePathToLexiconFile всегда указывает на каталог одного языка,
        // поэтому язык и корень словаря достаём из пути файла.
        $lang = basename(dirname($path));
        $base = dirname(dirname($path));
        if ($lang === '' || $base === '') {
            return;
        }

        $registry = new \MpcServices\Handlers\Lexicon\OrphanRegistry($base);
        if (!empty($candidates)) {
            $registry->record($lang, $rid, $candidates);
        }
        if (!empty($revived)) {
            $registry->forget($lang, $rid, $revived);
        }
    }

    /**
     * ЕДИНЫЙ источник формата лексикон-ключа. Логика вынесена в {@see LexiconKeyHelper};
     * метод сохранён делегатом для обратной совместимости вызовов LexiconManager::getLexiconKey.
     */
    public static function getLexiconKey(array $options): string
    {
        return LexiconKeyHelper::getLexiconKey($options);
    }

    /** Делегат → {@see LexiconKeyHelper::appendLexiconParent} (сохранён для совместимости). */
    public static function appendLexiconParent(string $parent, string $field, int $idx): string
    {
        return LexiconKeyHelper::appendLexiconParent($parent, $field, $idx);
    }

    /** Делегат → {@see LexiconKeyHelper::getLexiconKeyForPath} (сохранён для совместимости). */
    public static function getLexiconKeyForPath(string $prefix, array $path, string $fieldName): string
    {
        return LexiconKeyHelper::getLexiconKeyForPath($prefix, $path, $fieldName);
    }

    /**
     * Проверяет, попадает ли имя поля под список исключений.
     * Каждая запись в excludeLexiconFields трактуется как:
     *  - regex-литерал, если обёрнут в разделители (`/^cards_\d+$/`, `~...~i`,
     *    см. {@see looksLikeRegex}) — гоним `preg_match` напрямую; невалидный
     *    паттерн = не матчит (тихо, чтобы не ронять сборку);
     *  - точное имя (`picture`), если не содержит `*`, `?` или `[`;
     *  - glob-паттерн (`img*`, `*_picture`, `hero_*_img`), если есть `*`/`?`
     *    и нет `[`;
     *  - числовой паттерн с `[...]`-токенами, если есть `[` (см.
     *    {@see matchNumericPattern}). Каждый `[...]` матчит ОДНО целое число
     *    в этой позиции имени:
     *      - список:   `[6,8,10]`        — число из перечисления;
     *      - диапазон: `[6-10]`          — включительно;
     *      - nth:      `[2n]`, `[2n+1]`, `[3n-1]`, `[n]` — `a*k+b`, k≥0, a≥1.
     *    Литералы и `*`/`?` вокруг токенов работают как glob. Пример:
     *    `table_list_triple_[2n+1]_subtitle_1` исключит row с нечётным idx.
     *    Числа в ключе появляются на grabber-стороне (полный lex-ключ с `_idx`),
     *    поэтому row-специфичные паттерны действуют там; на cutter-стороне
     *    (без idx) такой паттерн просто не сматчится.
     */
    /**
     * Публичный фасад над {@see isFieldExcluded} — единая точка для внешних
     * лексикон-путей (rfield-грабер, каттер resource-маркеров), чтобы exclude
     * действовал на ВСЕ поля, а не только на config-секции внутри менеджера.
     */
    public function isExcluded(string $name): bool
    {
        return $this->isFieldExcluded($name);
    }

    private function isFieldExcluded(string $name): bool
    {
        if ($name === '') {
            return false;
        }

        $patterns = $this->properties['excludeLexiconFields'] ?? [];
        if (!is_array($patterns)) {
            return false;
        }

        foreach ($patterns as $pattern) {
            if (!is_string($pattern) || $pattern === '') {
                continue;
            }

            if ($this->looksLikeRegex($pattern)) {
                // невалидный regex → preg_match вернёт false + warning; @ глушит,
                // трактуем как «не совпало» (запись просто не исключает).
                $matches = @preg_match($pattern, $name) === 1;
            } elseif (strpos($pattern, '[') !== false) {
                $matches = $this->matchNumericPattern($pattern, $name);
            } else {
                $isGlob  = strpbrk($pattern, '*?') !== false;
                $matches = $isGlob ? fnmatch($pattern, $name) : $pattern === $name;
            }

            if ($matches) {
                return true;
            }
        }

        return false;
    }

    /**
     * Похожа ли запись на regex-литерал: первый символ — небуквенно-цифровой
     * разделитель (кроме `[`, `\`, пробела и glob-символов `*`/`?`) и тот же
     * разделитель встречается дальше как закрывающий. Имена/glob/числовые
     * паттерны (`MIGX_id`, `*_picture`, `cards_[2n]`) этому НЕ удовлетворяют
     * (буква/цифра/`*`/`[` в начале), поэтому остаются на своих ветках.
     */
    private function looksLikeRegex(string $pattern): bool
    {
        if (strlen($pattern) < 2) {
            return false;
        }
        $delim = $pattern[0];
        if (ctype_alnum($delim) || strpbrk($delim, "*?[\\ ") !== false) {
            return false;
        }
        return strrpos($pattern, $delim) > 0;
    }

    /**
     * Матчит имя против паттерна с `[...]`-токенами (числовые списки/диапазоны/
     * nth) и опциональными glob-символами в литеральных частях.
     *
     * Подход: компилируем паттерн в regex (литералы экранируем, `*`→`.*`,
     * `?`→`.`, каждый распознанный `[...]`→`(\d+)`) и параллельно копим
     * предикаты на числа. Затем `preg_match` + проверка каждого захваченного
     * числа своим предикатом. Нераспознанный `[...]` трактуется буквально.
     */
    private function matchNumericPattern(string $pattern, string $name): bool
    {
        $regex      = '';
        $predicates = [];
        $offset     = 0;
        $len        = strlen($pattern);

        while ($offset < $len) {
            $open = strpos($pattern, '[', $offset);
            if ($open === false) {
                $regex .= $this->globLiteralToRegex(substr($pattern, $offset));
                break;
            }
            $close = strpos($pattern, ']', $open);
            if ($close === false) {
                $regex .= $this->globLiteralToRegex(substr($pattern, $offset));
                break;
            }

            $regex .= $this->globLiteralToRegex(substr($pattern, $offset, $open - $offset));

            $token     = substr($pattern, $open + 1, $close - $open - 1);
            $predicate = $this->compileNumericToken($token);
            if ($predicate === null) {
                // не распознали — кладём [..] как литерал/glob
                $regex .= $this->globLiteralToRegex(substr($pattern, $open, $close - $open + 1));
            } else {
                $regex       .= '(\d+)';
                $predicates[] = $predicate;
            }

            $offset = $close + 1;
        }

        if (!preg_match('/^' . $regex . '$/', $name, $m)) {
            return false;
        }

        foreach ($predicates as $i => $predicate) {
            if (!$predicate((int) $m[$i + 1])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Экранирует литерал под regex, сохраняя glob-семантику `*`/`?`.
     */
    private function globLiteralToRegex(string $literal): string
    {
        $out = '';
        $len = strlen($literal);
        for ($i = 0; $i < $len; $i++) {
            $ch = $literal[$i];
            if ($ch === '*') {
                $out .= '.*';
            } elseif ($ch === '?') {
                $out .= '.';
            } else {
                $out .= preg_quote($ch, '/');
            }
        }
        return $out;
    }

    /**
     * Парсит содержимое `[...]`-токена в предикат на целое число.
     * Возвращает `callable(int): bool` или `null`, если синтаксис не распознан.
     */
    private function compileNumericToken(string $token): ?callable
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }

        // Список: 6,8,10
        if (strpos($token, ',') !== false && preg_match('/^\d+(\s*,\s*\d+)*$/', $token)) {
            $set = array_flip(array_map('intval', array_map('trim', explode(',', $token))));
            return static fn(int $x): bool => isset($set[$x]);
        }

        // Диапазон: 6-10 (включительно)
        if (preg_match('/^(\d+)\s*-\s*(\d+)$/', $token, $mm)) {
            $from = (int) $mm[1];
            $to   = (int) $mm[2];
            if ($from > $to) {
                [$from, $to] = [$to, $from];
            }
            return static fn(int $x): bool => $x >= $from && $x <= $to;
        }

        // nth: an+b / an-b / an / n+b / n  (a≥1, b — целое). a*k+b при k≥0.
        if (preg_match('/^(\d*)n\s*([+-]\s*\d+)?$/', $token, $mm)) {
            $a = ($mm[1] === '') ? 1 : (int) $mm[1];
            $b = (isset($mm[2]) && $mm[2] !== '') ? (int) str_replace(' ', '', $mm[2]) : 0;
            if ($a === 0) {
                return static fn(int $x): bool => $x === $b;
            }
            return static function (int $x) use ($a, $b): bool {
                $k = $x - $b;
                return $k >= 0 && $k % $a === 0;
            };
        }

        // Одиночное число: [6]
        if (preg_match('/^\d+$/', $token)) {
            $val = (int) $token;
            return static fn(int $x): bool => $x === $val;
        }

        return null;
    }
}
