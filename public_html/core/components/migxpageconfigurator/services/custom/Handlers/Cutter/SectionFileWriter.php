<?php

namespace MpcServices\Handlers\Cutter;

use DiDom\Element;
use MpcServices\Handlers\Parser;

/**
 * Создаёт .tpl файлы для секций и чанков на диске.
 *
 * Ответственность:
 *  - обход вложенных data-mpc-chunk и создание файлов чанков
 *  - создание файлов секций
 *  - запись HTML в файл через putToFile()
 */
class SectionFileWriter
{
    /** @var string[] Имена уже обработанных чанков (дедупликация) */
    private array $chunkNames = [];

    private array $properties;
    private Parser $parser;
    private PlaceholderProcessor $placeholderProcessor;
    private SpecialTagProcessor $specialTagProcessor;

    /** Полный HTML шаблона — нужен для wrapper-секций (вставка в <body>). */
    private string $fullHtml = '';

    public function __construct(
        array $properties,
        Parser $parser,
        PlaceholderProcessor $placeholderProcessor,
        SpecialTagProcessor $specialTagProcessor
    ) {
        $this->properties = $properties;
        $this->parser = $parser;
        $this->placeholderProcessor = $placeholderProcessor;
        $this->specialTagProcessor = $specialTagProcessor;
    }

    /**
     * Рекурсивно обрабатывает вложенные data-mpc-chunk и создаёт файлы.
     */
    public function parseInnerChunks(array $innerChunks): void
    {
        foreach ($innerChunks as $innerChunk) {
            $chunkName = $innerChunk->getAttribute('data-mpc-chunk');

            if ($chunkName === '' || strpos($chunkName, '..') !== false) {
                continue; // пустое имя или path traversal — пропускаем
            }
            if (in_array($chunkName, $this->chunkNames)) {
                continue;
            }
            $this->chunkNames[] = $chunkName;

            // Рекурсия для вложенных чанков
            $subInnerChunks = $this->parser->findByAttribute($this->parser->getHTMLString($innerChunk), '[data-mpc-chunk]');
            if ($subInnerChunks) {
                $this->parseInnerChunks($subInnerChunks);
            }

            // Создаём директорию если нужно
            $dirName = explode('/', $chunkName);
            if (count($dirName) > 1) {
                unset($dirName[count($dirName) - 1]);
                $dirName = implode('/', $dirName);
            } else {
                $dirName = '';
            }

            $baseDir = $this->properties['pdotoolsElementsPath'] . $this->properties['pathToChunks'];
            if ($dirName && !is_dir($baseDir . $dirName)) {
                mkdir($baseDir . $dirName, 0777, true);
            }

            $this->putToFile($innerChunk, $baseDir . $chunkName, false);
        }
    }

    /**
     * Создаёт .tpl файл для секции.
     */
    public function createSectionFiles(Element $section): void
    {
        $sectionName = trim((string)$section->getAttribute('data-mpc-section'));
        if ($sectionName === '' || strpos($sectionName, '..') !== false) {
            return; // пустое имя или path traversal — не пишем
        }
        $isStatic = $section->hasAttribute('data-mpc-static');
        $fileName = $sectionName . $this->properties['extension'];
        $sectionsDir = $this->properties['pdotoolsElementsPath'] . $this->properties['pathToSections'];

        if (!is_dir($sectionsDir)) {
            mkdir($sectionsDir, 0777, true);
        }

        $this->putToFile($section, $sectionsDir . $fileName, $isStatic);

        $unstaticFilePath = $sectionsDir . $sectionName . '_unstatic' . $this->properties['extension'];
        if ($isStatic) {
            // Создаём _unstatic-вариант: ## → { (standalone) или $var (внутри Fenom-блока)
            $content = file_get_contents($sectionsDir . $fileName);
            if (file_put_contents($unstaticFilePath, $this->convertStaticToUnstatic($content)) === false) {
                throw new \RuntimeException('Не удалось записать _unstatic-файл: ' . $unstaticFilePath);
            }
        } elseif (file_exists($unstaticFilePath)) {
            // Секция больше не статичная — _unstatic не нужен
            unlink($unstaticFilePath);
        }
    }

    /**
     * Конвертирует статичный шаблон (с ##...}) в нестатичный для Fenom.
     *
     * Для каждого ##...} ищет соответствующий закрывающий } с учётом вложенности.
     * Внутри блока убирает {$var} → $var (вложенные переменные не нужно оборачивать в {}).
     * Standalone ##...} → {...}, внутри Fenom-блока → без внешних фигурных скобок.
     */
    private function convertStaticToUnstatic(string $content): string
    {
        $result = '';
        $len = strlen($content);
        $i = 0;
        $fenomDepth = 0;

        while ($i < $len) {
            if ($content[$i] === '#' && $i + 1 < $len && $content[$i + 1] === '#') {
                // Ищем соответствующий закрывающий } с учётом вложенных { }
                $block = $this->extractFenomBlock($content, $i + 2, $len);
                if ($block === null) {
                    $result .= $content[$i++];
                    continue;
                }
                // Внутри блока {$var} → $var, т.к. они будут внутри Fenom-выражения
                $inner = $this->stripNestedFenomVars($block['content']);
                $result .= $fenomDepth > 0 ? $inner : '{' . $inner . '}';
                $i = $block['end'] + 1;
            } elseif ($content[$i] === '{') {
                $fenomDepth++;
                $result .= $content[$i++];
            } elseif ($content[$i] === '}' && $fenomDepth > 0) {
                $fenomDepth--;
                $result .= $content[$i++];
            } else {
                $result .= $content[$i++];
            }
        }

        return $result;
    }

    /**
     * Находит содержимое блока от $start до соответствующего закрывающего },
     * учитывая вложенные { }. Возвращает ['content' => '...', 'end' => pos] или null.
     */
    private function extractFenomBlock(string $content, int $start, int $len): ?array
    {
        $depth = 0;
        for ($i = $start; $i < $len; $i++) {
            if ($content[$i] === '{') {
                $depth++;
            } elseif ($content[$i] === '}') {
                if ($depth === 0) {
                    return ['content' => substr($content, $start, $i - $start), 'end' => $i];
                }
                $depth--;
            }
        }
        return null;
    }

    /**
     * Готовит содержимое статик-блока к не-статик (одиночному Fenom-пассу):
     *  1. `'{$expr}'` → `$expr` — снимаем кавычки вокруг интерполяции лексикона.
     *     В статике `##'{$expr}' | lexicon}` опирается на eager-интерполяцию
     *     `{$expr}` в значение-ключ ПЕРЕД `| lexicon`. В не-статике пасс один:
     *     переменную надо ВЫЧИСЛИТЬ, а в одинарных кавычках Fenom её не
     *     интерполирует (литерал `'$expr'`) → `| lexicon` получил бы строку
     *     `"$expr"` вместо значения ключа → пусто/неверно. Поэтому `'{$expr}'`
     *     превращаем в голое `$expr` (как в defer-var форме вложенных полей).
     *  2. Прочие `{$var}` → `$var` (вложенные теги внутри Fenom-выражения не
     *     оборачиваются в `{}`).
     */
    private function stripNestedFenomVars(string $content): string
    {
        $content = preg_replace('/\'\{(\$[^}]+)\}\'/', '$1', $content);
        return preg_replace('/\{(\$[^}]+)\}/', '$1', $content);
    }

    /**
     * Применяет все трансформации к HTML элемента и записывает в файл.
     */
    public function putToFile(Element $element, string $pathToFile, bool $isStatic): void
    {
        $html = $this->parser->getHTMLString($element);
        $sectionLexiconPrefix = trim((string)$element->getAttribute('data-mpc-lexicon'));

        $properties = [
            'html' => $html,
            'element' => $element,
            'fieldAttrName' => 'data-mpc-field',
            'itemAttrName' => 'data-mpc-item',
            'level' => 0,
            'sectionLexiconPrefix' => $sectionLexiconPrefix,
            'isStatic' => $isStatic,
        ];

        $properties = $this->placeholderProcessor->setPlaceholders($properties);
        $properties = $this->specialTagProcessor->setSnippetTags($properties);

        if (!$properties['element']->hasAttribute('data-mpc-parse')) {
            $properties = $this->specialTagProcessor->setParseChunks($properties);
        }
        if (!$properties['element']->hasAttribute('data-mpc-include')) {
            $properties = $this->specialTagProcessor->setIncludeChunks($properties);
        }

        $properties = $this->specialTagProcessor->removeHiddenPlaceholders($properties);

        // data-mpc-attr: заменить "data-mpc-attr="attr=val"" на сам атрибут
        if ($attrs = $this->parser->findByAttribute($properties['html'], '[data-mpc-attr]')) {
            foreach ($attrs as $attr) {
                $attrValue = $attr->getAttribute('data-mpc-attr');
                $search = 'data-mpc-attr="' . $attrValue . '"';
                $properties['html'] = str_replace($search, $attrValue, $properties['html']);
            }
        }

        $properties['html'] = $this->placeholderProcessor->unwrapBlock($properties['html']);

        // Если это wrapper-секция — вставляем в <body>
        if (strpos((string)$element->getAttribute('data-mpc-section'), $this->properties['wrapperName']) !== false) {
            $fullHtml = $this->getFullHtml();
            if ($fullHtml) {
                // callback, а не строка-замена: иначе `$1`/`\1` внутри HTML-контента
                // секции интерпретировались бы preg_replace как backreference и
                // искажали вывод. $m[1] сохраняет атрибуты <body…>.
                $sectionHtml = $properties['html'];
                $properties['html'] = preg_replace_callback(
                    '/<body(.*?)>(.*?)<\/body>/s',
                    static function (array $m) use ($sectionHtml): string {
                        return '<body' . $m[1] . '>' . $sectionHtml . '</body>';
                    },
                    $fullHtml
                );
            }
        }

        // Финальная очистка
        $properties['html'] = str_replace('`', '"', $properties['html']);

        // Версия с сохранёнными data-mpc-* маркерами (до strip) — для edit-mode
        // рендера mpcVE: фронт-редактору нужны маркеры, чтобы найти поля и адреса.
        $htmlMarked = $properties['html'];
        $properties['html'] = preg_replace($this->properties['pattern'], '', $properties['html']);

        // Обёртка в условие если есть data-mpc-if на самой секции
        if ($element->hasAttribute('data-mpc-if')) {
            $condition = $element->getAttribute('data-mpc-if');
            $firstSymbol = (string)$element->getAttribute('data-mpc-symbol') ?: '{';
            $properties['html'] = $this->placeholderProcessor->wrapInCondition($condition, $properties['html'], $firstSymbol);
            $htmlMarked = $this->placeholderProcessor->wrapInCondition($condition, $htmlMarked, $firstSymbol);
        }

        // Основной чанк: при mpc_edit_mode (editMode) НЕ режем data-mpc-* маркеры —
        // edit-mode рендерится штатным путём (как прод) + маркеры, без отдельных
        // _edit/_unstatic_edit вариантов (реальному пользователю атрибуты не мешают).
        // На прод-деплое mpc_edit_mode=0 → перенарезка даёт чистые файлы.
        // _unstatic генерится из ЭТОГО же файла (createSectionFiles) → тоже с маркерами.
        if (file_put_contents($pathToFile, !empty($this->properties['editMode']) ? $htmlMarked : $properties['html']) === false) {
            throw new \RuntimeException('Не удалось записать файл секции/чанка: ' . $pathToFile);
        }
    }

    /**
     * Устанавливает полный HTML шаблона (нужен для wrapper-секций).
     */
    public function setFullHtml(string $html): void
    {
        $this->fullHtml = $html;
    }

    private function getFullHtml(): string
    {
        return $this->fullHtml;
    }
}
