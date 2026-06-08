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
     */
    public function setContext(string $prefix, bool $isStatic, bool $isCopy = false): void
    {
        $this->sectionLexiconPrefix = $prefix;
        $this->sectionIsStatic      = $isStatic;

        if (!$isStatic || $isCopy || $prefix === '') {
            return;
        }
        $rid = $this->properties['staticBlocksPageLexiconFilename'] ?? '';
        if ($rid === '' || empty($this->lexicons[$rid])) {
            return;
        }
        $needle = $prefix . '_';
        foreach (array_keys($this->lexicons[$rid]) as $key) {
            if (strpos((string)$key, $needle) === 0) {
                unset($this->lexicons[$rid][$key]);
            }
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
        return preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$identifier);
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

        foreach ($allLexicons as $rid => $lexicons) {
            $pathToLexiconFile = $basePathToLexiconFile . $rid . '.inc.php';
            if (!$overwrite && file_exists($pathToLexiconFile)) {
                // Без updContent: сохраняем ЗНАЧЕНИЯ существующих переводов, но
                // ТОЛЬКО для ключей, которые ещё есть в текущей нарезке (поле
                // живо). Ключи удалённых полей НЕ переносим — иначе orphan-перевод
                // оставался бы в файле и маскировал новое значение при повторном
                // добавлении поля. Новые поля берут значение из шаблона.
                $_lang = [];
                include $pathToLexiconFile;
                if (is_array($_lang)) {
                    foreach ($lexicons as $k => $v) {
                        if (array_key_exists($k, $_lang)) {
                            $lexicons[$k] = $_lang[$k];
                        }
                    }
                }
            }

            if (!empty($lexicons)) {
                $content = '<?php' . PHP_EOL;
                foreach ($lexicons as $k => $v) {
                    // var_export ключа И значения — безопасный PHP-литерал (см.
                    // LexiconWriter::set): защита от инъекции через ключ и поломки
                    // файла бэкслешем в значении.
                    $content .= '$_lang[' . var_export((string)$k, true) . '] = '
                        . var_export($this->sanitizeValue($v), true) . ';' . PHP_EOL;
                }
                file_put_contents($pathToLexiconFile, $content, LOCK_EX);
            } else {
                if (file_exists($pathToLexiconFile)) {
                    unlink($pathToLexiconFile);
                }
            }
        }

        // Синк остальных языков (mpc_available_languages): набор ключей приводится
        // к дефолтному, существующие переводы сохраняются, НОВЫЕ ключи получают
        // значение дефолтного языка как плейсхолдер (страница не ломается до
        // перевода), orphan-ключи (удалённые поля) выкидываются.
        $this->syncOtherLanguages($allLexicons);

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
        $langs   = array_diff($langs, [$default, '']);
        if (empty($langs) || empty($allLexicons)) {
            return;
        }
        $baseLexiconPath = ($this->properties['corePath'] ?? '') . ($this->properties['lexiconPath'] ?? '');
        if ($baseLexiconPath === '') {
            return;
        }
        $defaultBase     = $baseLexiconPath . $default . '/';
        $pending         = new \MpcServices\Handlers\PendingTranslations($baseLexiconPath);
        foreach ($langs as $lang) {
            $langBase = $baseLexiconPath . $lang . '/';
            if (!is_dir($langBase)) {
                mkdir($langBase, 0755, true);
            }
            foreach (array_keys($allLexicons) as $rid) {
                // Источник истины — ТОЛЬКО ЧТО записанный файл дефолтного языка
                // (в нём полный набор ключей, включая смерженные $_rlang — pagetitle/
                // longtitle/description, которых нет в $allLexicons из-за пустых значений).
                $_lang = [];
                $defaultFile = $defaultBase . $rid . '.inc.php';
                if (is_file($defaultFile)) {
                    include $defaultFile;
                }
                $defaultLex = is_array($_lang) ? $_lang : [];
                if (empty($defaultLex)) {
                    continue;
                }

                $_lang = [];
                $file = $langBase . $rid . '.inc.php';
                if (is_file($file)) {
                    include $file;
                }
                $existing = is_array($_lang) ? $_lang : [];

                $out = [];
                foreach ($defaultLex as $k => $defVal) {
                    $out[$k] = array_key_exists($k, $existing) ? $existing[$k] : $defVal;
                }
                $this->writeLexiconFile($file, $out);

                // Реестр непереведённых: новые ключи (не было в файле языка) →
                // pending; переведённые ранее остаются вне; orphan выкидывается.
                // (string)$k — ключи лексикона числовыми не бывают, но array_keys
                // мог бы вернуть int для «12»-подобных — приводим к строке.
                $pending->sync(
                    $lang,
                    (string)$rid,
                    array_map('strval', array_keys($defaultLex)),
                    array_map('strval', array_keys($existing))
                );
            }
        }
    }

    /** Записать лексиконы в файл (или удалить файл, если массив пуст). */
    private function writeLexiconFile(string $path, array $lexicons): void
    {
        if (empty($lexicons)) {
            if (is_file($path)) {
                unlink($path);
            }
            return;
        }
        $content = '<?php' . PHP_EOL;
        foreach ($lexicons as $k => $v) {
            // var_export ключа И значения — безопасный PHP-литерал (см. выше).
            $content .= '$_lang[' . var_export((string)$k, true) . '] = '
                . var_export($this->sanitizeValue($v), true) . ';' . PHP_EOL;
        }
        file_put_contents($path, $content, LOCK_EX);
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
