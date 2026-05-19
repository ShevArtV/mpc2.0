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
     */
    public function setContext(string $prefix, bool $isStatic): void
    {
        $this->sectionLexiconPrefix = $prefix;
        $this->sectionIsStatic      = $isStatic;
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
     * Комбинирует `isLexiconField` (content-type translatable) + проверку поля
     * и накопленного родителя против `excludeLexiconFields`.
     *
     * Возвращает true только если ВСЕ условия выполнены:
     *  - лексиконы включены и content-type входит в translatableContentTypes;
     *  - fieldName не попадает под exclude-паттерн;
     *  - parentFieldName (если задан) не попадает под exclude-паттерн.
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
        if ($parentFieldName !== '' && $this->isFieldExcluded($parentFieldName)) {
            return false;
        }
        return true;
    }

    public function getResourceIdentifierById(int $rid): string
    {
        if ($this->properties['lexiconFilenameField'] !== 'id') {
            $q = $this->modx->newQuery('modResource');
            $q->select($this->properties['lexiconFilenameField']);
            $q->where(['id' => $rid]);
            $q->prepare();
            if ($q->stmt->execute()) {
                $rid = $q->stmt->fetchColumn();
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

        if ($parentFieldName && $this->isFieldExcluded($parentFieldName)) {
            return $value ?? '';
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

    public function createLexicons(array $allLexicons): void
    {
        $basePathToLexiconFile   = $this->properties['basePathToLexiconFile'];
        $resourceLexiconKeysPath = $this->properties['corePath'] . $this->properties['resourceLexiconKeysPath'];

        $_rlang = $_lang = [];
        if (file_exists($resourceLexiconKeysPath)) {
            include $resourceLexiconKeysPath;
        }

        foreach ($allLexicons as $rid => $lexicons) {
            $pathToLexiconFile = $basePathToLexiconFile . $rid . '.inc.php';
            if (file_exists($pathToLexiconFile) && !empty($_rlang)) {
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
     *  - точное имя (`picture`), если не содержит `*` или `?`;
     *  - glob-паттерн (`img*`, `*_picture`, `hero_*_img`), иначе.
     */
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

            $isPattern = strpbrk($pattern, '*?') !== false;
            $matches   = $isPattern ? fnmatch($pattern, $name) : $pattern === $name;

            if ($matches) {
                return true;
            }
        }

        return false;
    }
}
