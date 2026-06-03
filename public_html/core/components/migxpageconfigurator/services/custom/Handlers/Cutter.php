<?php

/**
 * Сервис для нарезки шаблона на чанки и секции и расстановки плейсхолдеров.
 *
 * Тонкий фасад: оркестрирует четыре специализированных подкласса из Cutter/.
 */

namespace MpcServices\Handlers;

use MpcServices\Handlers\Cutter\PlaceholderProcessor;
use MpcServices\Handlers\Cutter\SnippetCallBuilder;
use MpcServices\Handlers\Cutter\SpecialTagProcessor;
use MpcServices\Handlers\Cutter\SectionFileWriter;
use MpcServices\Handlers\Grabber\LexiconManager;

/**
 * @author Arthur Shevchenko (https://t.me/ShevArtV)
 */
class Cutter extends Base
{
    /**
     * Полный HTML загруженного файла-шаблона.
     *
     * @var string
     */
    private string $html = '';

    /**
     * @var PlaceholderProcessor
     */
    private PlaceholderProcessor $placeholderProcessor;

    /**
     * @var SnippetCallBuilder
     */
    private SnippetCallBuilder $snippetCallBuilder;

    /**
     * @var SpecialTagProcessor
     */
    private SpecialTagProcessor $specialTagProcessor;

    /**
     * @var SectionFileWriter
     */
    private SectionFileWriter $sectionFileWriter;

    /**
     * @var LexiconManager
     */
    private LexiconManager $lexiconManager;

    // ---------------------------------------------------------------
    // Инициализация
    // ---------------------------------------------------------------

    /**
     * @return void
     */
    protected function initialize(): void
    {
        parent::initialize();

        $properties = [
            'pathToChunks'      => $this->modx->getOption('mpc_path_to_chunks', null, 'chunks/'),
            'chunkNames'        => [],
            'pattern'           => '/(\s)*?data-mpc-(nolazy|copy|symbol|if|static|name|item|unwrap|section|snippet|chunk|include|parse|remove|attr|field|ftype|fcap|fdesc|cfield|rfield|res|tv|contact|ctx|info|lim|off|nothumb|thumb|lexicon|key)(-){0,1}([0-9]*)(=".*?"){0,1}(\s)*?/',
            'wrapperName'       => $this->modx->getOption('mpc_wrapper_name', null, 'wrapper'),
            'thumbFormat'       => $this->modx->getOption('mpc_thumb_format', null, 'png'),
            'fakeImgPath'       => $this->modx->getOption('mpc_fake_img_path', null, 'assets/components/migxpageconfigurator/images/fake-img.png'),
            'pathToPresets'     => $this->modx->getOption('mpc_path_to_presets', null, 'components/migxpageconfigurator/elements/presets/'),
            'pathToSamples'     => $this->modx->getOption('mpc_path_to_samples', null, 'components/migxpageconfigurator/elements/samples/'),
            'presets'           => [],
            'commonThumbParams' => $this->modx->getOption('mpc_common_thumb_params', null, ''),
            'thumbSnippet'      => $this->modx->getOption('mpc_thumb_snippet', null, ''),
            'editMode'          => (bool)$this->modx->getOption('mpc_edit_mode', null, false),
        ];
        $this->properties = array_merge($this->properties, $properties);

        $this->getPresets();
        $this->getSamples();

        // LexiconManager нужен PlaceholderProcessor'у для решения, добавлять
        // ли `| lexicon` к плейсхолдеру: учитывает translatableContentTypes
        // и excludeLexiconFields (последнее — критично, иначе cutter ставит
        // `| lexicon` для полей, которые grabber пропускает → пусто на сайте).
        $lexiconManager = new LexiconManager($this->modx, $this->properties);
        $this->lexiconManager = $lexiconManager;

        $this->placeholderProcessor = new PlaceholderProcessor(
            $this->modx,
            $this->properties,
            $this->parser,
            $lexiconManager
        );

        $this->snippetCallBuilder = new SnippetCallBuilder($this->properties);

        $this->specialTagProcessor = new SpecialTagProcessor(
            $this->properties,
            $this->parser,
            $this->snippetCallBuilder,
            $this->placeholderProcessor
        );

        $this->sectionFileWriter = new SectionFileWriter(
            $this->properties,
            $this->parser,
            $this->placeholderProcessor,
            $this->specialTagProcessor
        );
    }

    // ---------------------------------------------------------------
    // Загрузка пресетов и сэмплов
    // ---------------------------------------------------------------

    /**
     * Загружает файлы пресетов в $this->properties['presets'].
     *
     * @return void
     */
    private function getPresets(): void
    {
        $pathToPresets = $this->properties['pdotoolsElementsPath'] . $this->properties['pathToPresets'];
        if (!file_exists($pathToPresets)) {
            return;
        }
        $files = scandir($pathToPresets);
        unset($files[0], $files[1]);
        if (count($files)) {
            foreach ($files as $file) {
                $presetsFilePath = $pathToPresets . $file;
                $this->properties['presets'][str_replace('.inc.php', '', $file)] = include($presetsFilePath);
            }
        }
    }

    /**
     * Загружает файлы шаблонов-сэмплов (Fenom-фрагменты) в $this->properties['samples'].
     *
     * @return void
     */
    private function getSamples(): void
    {
        $pathToSamples = $this->properties['corePath'] . $this->properties['pathToSamples'];
        if (!file_exists($pathToSamples)) {
            return;
        }
        $files = scandir($pathToSamples);
        unset($files[0], $files[1]);
        if (count($files)) {
            foreach ($files as $file) {
                $presetsFilePath = $pathToSamples . $file;
                $this->properties['samples'][str_replace('.tpl', '', $file)] = file_get_contents($presetsFilePath);
            }
        }
    }

    // ---------------------------------------------------------------
    // Публичный API
    // ---------------------------------------------------------------

    /**
     * Точка входа: загружает файл и запускает все этапы обработки.
     *
     * @param string $fileName
     * @return array
     */
    public function handle(string $fileName): array
    {
        if ($this->debug) {
            $this->logging->write(__METHOD__, "Handle file $fileName");
        }

        if (!$this->html = $this->getFileContent($fileName)) {
            return $this->response->error(__METHOD__, "File $fileName is empty");
        }

        $this->sectionFileWriter->setFullHtml($this->html);

        $this->handleInformation();
        $this->handleContacts();
        $this->handleResourceFields();
        $this->handleSections();

        return $this->response->success(__METHOD__, "Processing of file $fileName is completed");
    }

    // ---------------------------------------------------------------
    // Внутренние этапы обработки
    // ---------------------------------------------------------------

    /**
     * Заменяет [data-mpc-info] элементы на плейсхолдеры из $_modx->config.
     *
     * @return void
     */
    private function handleInformation(): void
    {
        if (!$items = $this->getItems($this->html, '[data-mpc-info]')) {
            return;
        }
        foreach ($items as $item) {
            $infoKey     = $item->getAttribute('data-mpc-info');
            $itemHtml    = $this->parser->getHTMLString($item);
            $itemHtmlNew = '';
            $pls         = "{\$_modx->config['$infoKey']}";

            switch ($item->tagName()) {
                case 'link':
                    $item->setAttribute('href', $pls);
                    break;
                case 'img':
                    $item->setAttribute('src', $pls);
                    break;
                default:
                    if ($item->hasAttribute('data-mpc-unwrap')) {
                        $itemHtmlNew = $pls;
                    } else {
                        $item->setInnerHtml($pls);
                    }
                    break;
            }

            $itemHtmlNew = $itemHtmlNew ?: $this->parser->getHTMLString($item);

            if ($item->hasAttribute('data-mpc-if')) {
                $condition   = $item->getAttribute('data-mpc-if') ?: "\$_modx->config['$infoKey']";
                $itemHtmlNew = $this->placeholderProcessor->wrapInCondition($condition, $itemHtmlNew);
            }

            $this->html = str_replace($itemHtml, $itemHtmlNew, $this->html);
        }
    }

    /**
     * Заменяет [data-mpc-contact] блоки на Fenom-плейсхолдеры контактов.
     *
     * @return void
     */
    private function handleContacts(): void
    {
        if (!$items = $this->getItems($this->html, '[data-mpc-contact]')) {
            return;
        }

        $search      = [];
        $replacement = [];

        foreach ($items as $item) {
            if (!$fields = $this->getItems($this->parser->getHTMLString($item), '[data-mpc-cfield]')) {
                continue;
            }
            $contactAttrValue = explode('|', trim($item->getAttribute('data-mpc-contact')));
            if (!$valueField = $this->getItems($this->parser->getHTMLString($item), '[data-mpc-cfield="value"]')[0]) {
                continue;
            }

            $href  = $valueField->getAttribute('href');
            $value = $href ?: trim($valueField->textContent);

            $key       = $item->getAttribute('data-mpc-key') ?: $this->getContactKey($value);
            $type      = $contactAttrValue[0];
            $placement = $contactAttrValue[1] ?? 'default';

            foreach ($fields as $field) {
                $fieldName   = $field->getAttribute('data-mpc-cfield');
                $complexName = "{\$contacts['$placement']['$key']['$fieldName']}";
                $search[]    = $this->parser->getHTMLString($field);

                if ($fieldName === 'value') {
                    if ($field->hasAttribute('href')) {
                        if ($type === 'phone') {
                            $complexName = 'tel:' . $complexName;
                        }
                        if ($type === 'email') {
                            $complexName = 'mailto:' . $complexName;
                        }
                        $field->setAttribute('href', $complexName);
                    }
                    $field->setInnerHtml("{\$contacts['$placement']['$key']['fvalue']}");
                    $replacement[] = $this->parser->getHTMLString($field);
                } else {
                    if ($field->hasAttribute('src')) {
                        if ($field->tagName() === 'img') {
                            if (!$field->hasAttribute('data-mpc-nothumb') && !empty($this->properties['thumbSnippet'])) {
                                $complexName = $this->placeholderProcessor->getThumb([
                                    'width'       => $field->getAttribute('width'),
                                    'height'      => $field->getAttribute('height'),
                                    'thumbParams' => $field->getAttribute('data-mpc-thumb'),
                                    'firstSymbol' => '{',
                                    'complexName' => "\$contacts['$placement']['$key']['$fieldName']",
                                    'srcAttr'     => '',
                                ]);
                            }

                            if ($this->properties['lazyloadAttr'] && !$field->hasAttribute('data-mpc-nolazy')) {
                                $field->setAttribute($this->properties['lazyloadAttr'], $complexName);
                                $field->setAttribute('src', $this->properties['fakeImgPath']);
                            } else {
                                $field->setAttribute('src', $complexName);
                            }
                        } else {
                            $field->setAttribute('src', $complexName);
                        }

                        $replacement[] = $this->parser->getHTMLString($field);
                    } else {
                        $replacement[] = $complexName;
                    }
                }
            }
        }

        if (!empty($replacement)) {
            $this->html = str_replace($search, $replacement, $this->html);
        }
    }

    /**
     * Заменяет [data-mpc-rfield] и [data-mpc-tv] на Fenom-плейсхолдеры,
     * читающие поля/TV ТЕКУЩЕГО ресурса.
     *
     * Render.php прокидывает в скоуп секции `$resource` = полный массив полей
     * ресурса, включая подмассив `tvs` (см. Render::parseConfig — `$section['resource']`).
     * Поэтому:
     *   - data-mpc-rfield="pagetitle" → {$resource.pagetitle} | {'mpc_resource_pagetitle' | lexicon}
     *   - data-mpc-tv="myTV"          → {$resource.tvs.myTV}   | {'mpc_resource_tv_myTV' | lexicon}
     *
     * При useLexicons оба переводятся per-resource (rfield → mpc_resource_<field>,
     * tv → mpc_resource_tv_<name>); без лексиконов или для excludeLexiconFields —
     * прямое чтение колонки/TV. Эти поля динамические, в статичные секции
     * (data-mpc-static) не предназначены. Адрес для редактирования (mpcVE)
     * собирается отдельно — фасадом writeField.
     *
     * @return void
     */
    private function handleResourceFields(): void
    {
        // rfield — нативная колонка ресурса; tv — значение TV (через подмассив tvs).
        // Оба лексиконятся per-resource, но разными ключами, чтобы поля с одним
        // именем не коллизили: rfield → mpc_resource_<field>, tv → mpc_resource_tv_<name>.
        $this->replaceResourceMarkers('[data-mpc-rfield]', 'data-mpc-rfield', '$resource.', true, 'mpc_resource_');
        $this->replaceResourceMarkers('[data-mpc-tv]', 'data-mpc-tv', '$resource.tvs.', true, 'mpc_resource_tv_');
    }

    /**
     * Общий обход элементов-маркеров: подставляет `{$exprPrefix.<name>}` в
     * нужное место (href для ссылок, src для img, иначе innerHtml/unwrap) и
     * оборачивает в условие при data-mpc-if. По образцу handleInformation.
     *
     * @return void
     */
    private function replaceResourceMarkers(string $selector, string $attr, string $exprPrefix, bool $lexiconize = false, string $lexiconKeyPrefix = 'mpc_resource_'): void
    {
        if (!$items = $this->getItems($this->html, $selector)) {
            return;
        }

        foreach ($items as $item) {
            $name = trim((string)$item->getAttribute($attr));
            if ($name === '') {
                continue;
            }

            // Кросс-ресурс: data-mpc-res на самом элементе ИЛИ предке (поле
            // выводится сниппетом для ДРУГОГО ресурса). Это разметка ДЛЯ
            // РЕДАКТОРА — render-выражение пишет автор (он знает, как грузить
            // лексиконы чужого ресурса: reslexicons + | lexicon). Каттер контент
            // НЕ трогает, только маркеры остаются. (DiDom closest не включает
            // self → проверяем ещё и сам элемент.)
            if ($item->hasAttribute('data-mpc-res') || $item->closest('[data-mpc-res]') !== null) {
                continue;
            }

            $expr = $exprPrefix . $name;           // $resource.pagetitle / $resource.tvs.myTV
            // Лексикон-форма (rfield/TV, useLexicons): перевод по ключу
            // mpc_resource_<field>; иначе — прямое чтение колонки/TV. Решение —
            // ЕДИНОЕ с грабером и редактором: shouldLexiconize (content-type ∈
            // mpc_translated_content + exclude). content-type — по тегу маркера
            // (img→image и т.д.), prefix пуст (rfield/TV вне секции). Так image-TV
            // не получает `| lexicon`, если image не в mpc_translated_content.
            $this->lexiconManager->setContext('', false);
            $useLexicon = $lexiconize
                && !empty($this->properties['useLexicons'])
                && $this->lexiconManager->shouldLexiconize(
                    LexiconManager::contentTypeForTag($item->tagName()),
                    $name
                );
            $pls = $useLexicon
                ? "{'" . $lexiconKeyPrefix . $name . "' | lexicon}"
                : '{' . $expr . '}';
            $itemHtml    = $this->parser->getHTMLString($item);
            $itemHtmlNew = '';

            switch ($item->tagName()) {
                case 'a':
                case 'link':
                    $item->setAttribute('href', $pls);
                    break;
                case 'img':
                case 'source':
                    $item->setAttribute('src', $pls);
                    break;
                default:
                    if ($item->hasAttribute('data-mpc-unwrap')) {
                        $itemHtmlNew = $pls;
                    } else {
                        $item->setInnerHtml($pls);
                    }
                    break;
            }

            $itemHtmlNew = $itemHtmlNew ?: $this->parser->getHTMLString($item);

            if ($item->hasAttribute('data-mpc-if')) {
                $condition   = $item->getAttribute('data-mpc-if') ?: $expr;
                $itemHtmlNew = $this->placeholderProcessor->wrapInCondition($condition, $itemHtmlNew);
            }

            $this->html = str_replace($itemHtml, $itemHtmlNew, $this->html);
        }
    }

    /**
     * Обходит все [data-mpc-section] и создаёт для каждой секции .tpl файлы.
     *
     * @return void
     */
    private function handleSections(): void
    {
        if (!$sections = $this->getItems($this->html, '[data-mpc-section]')) {
            return;
        }

        foreach ($sections as $section) {
            // Секции-копии не обрабатываем
            if ($section->hasAttribute('data-mpc-copy')) {
                continue;
            }

            // Контекст секции (префикс лексикона) — чтобы exclude-проверка
            // каттера видела префиксный lex-ключ симметрично граберу. Ставим до
            // чанков и секции, чтобы поля чанков наследовали префикс секции.
            $this->placeholderProcessor->setSectionContext($section);

            if ($innerChunks = $this->getItems($this->parser->getHTMLString($section), '[data-mpc-chunk]')) {
                $this->sectionFileWriter->parseInnerChunks($innerChunks);
            }

            $this->sectionFileWriter->createSectionFiles($section);
        }
    }
}
