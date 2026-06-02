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

    public function getResourceIdentifierById(int $rid): string
    {
        if ($this->properties['lexiconFilenameField'] !== 'id') {
            $q = $this->modx->newQuery('modResource');
            $q->select($this->properties['lexiconFilenameField']);
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

                if ($this->properties['lexiconFilenameField'] === 'uri') {
                    $rid = trim($rid, '/');
                    $rid = str_replace('/', '_', $rid);
                    if ($rid === '') {
                        $rid = 'root';
                    }
                }
            } else {
                $this->modx->log(\modX::LOG_LEVEL_ERROR, sprintf(
                    '[mpc] LexiconManager: не удалось подготовить запрос идентификатора лексикона по полю "%s" (проверьте mpc_lexicon_filename_field — должна быть колонка modResource). Фолбэк на числовой id %d.',
                    $this->properties['lexiconFilenameField'],
                    $rid
                ));
            }
        }

        $this->modx->invokeEvent('mpcOnGetResourceIdentifier', [
            'rid'     => $rid,
            'Grabber' => $this,
        ]);

        return isset($this->modx->event->returnedValues) && !empty($this->modx->event->returnedValues['rid'])
            ? $this->modx->event->returnedValues['rid'] : $rid;
    }

    public function getLexicons(string $rid, string $basePath): array
    {
        $pathToLexiconFile = $basePath . $rid . '.inc.php';
        if (file_exists($pathToLexiconFile)) {
            include $pathToLexiconFile;
            return $_lang ?? [];
        }
        return [];
    }

    public function sanitizeValue(?string $value = ''): string
    {
        if (!$value) {
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
        $lexiconKey        = $this->getLexiconKey($options);

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

    public function createLexicons(array $allLexicons, bool $overwrite = true): void
    {
        $basePathToLexiconFile   = $this->properties['basePathToLexiconFile'];
        $resourceLexiconKeysPath = $this->properties['corePath'] . $this->properties['resourceLexiconKeysPath'];

        $_rlang = $_lang = [];
        if (file_exists($resourceLexiconKeysPath)) {
            include $resourceLexiconKeysPath;
        }

        foreach ($allLexicons as $rid => $lexicons) {
            $pathToLexiconFile = $basePathToLexiconFile . $rid . '.inc.php';
            if (!$overwrite && file_exists($pathToLexiconFile)) {
                // Без updContent: существующие переводы в файле НЕ трогаем —
                // дописываем только новые ключи (для новых полей). Значения
                // переводов админа сохраняются (existing побеждает в мерже).
                $_lang = [];
                include $pathToLexiconFile;
                if (is_array($_lang)) {
                    $lexicons = array_merge($lexicons, $_lang);
                }
            } elseif (file_exists($pathToLexiconFile) && !empty($_rlang)) {
                include $pathToLexiconFile;
                $tmp = array_intersect_key($_lang, $_rlang);
                if (empty($tmp)) {
                    $tmp = $_rlang;
                }
                $lexicons = array_merge($tmp, $lexicons);
            }

            if (!empty($lexicons)) {
                $content = '<?php' . PHP_EOL;
                foreach ($lexicons as $k => $v) {
                    $content .= '$_lang[\'' . $k . '\'] = \'' . $this->sanitizeValue($v) . '\';' . PHP_EOL;
                }
                file_put_contents($pathToLexiconFile, $content);
            } else {
                if (file_exists($pathToLexiconFile)) {
                    unlink($pathToLexiconFile);
                }
            }
        }

        $this->modx->cacheManager->refresh(['lexicon_topics' => []]);
    }

    private function getLexiconKey(array $options): string
    {
        $fieldName       = $options['fieldName'] ?? '';
        $idx             = $options['idx'] ?? '';
        $parentFieldName = $options['parentFieldName'] ?? '';
        $prefix          = $options['prefix'] ?? '';

        $lexiconKey = $parentFieldName
            ? "{$prefix}_{$parentFieldName}_$fieldName"
            : "{$prefix}_$fieldName";

        return $idx ? "{$lexiconKey}_$idx" : $lexiconKey;
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
