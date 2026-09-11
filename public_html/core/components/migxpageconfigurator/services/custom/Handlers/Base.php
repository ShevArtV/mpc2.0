<?php

/**
 * Сервис с общими методами для обработчиков.
 */

namespace MpcServices\Handlers;

use DiDom\Exceptions\InvalidSelectorException;
use MpcServices\Helpers\Logging;
use MpcServices\Helpers\Response;

/**
 * @author Arthur Shevchenko (https://t.me/ShevArtV)
 */
class Base
{
    /**
     * Служебное поле записи слитого конфига: `type` — секция пришла из конфига
     * типа страницы, `resource` — ресурс перекрыл её своим `mpc_config`.
     * Живёт только в памяти: в TV конфиг из `mergeSectionConfigs` не пишется.
     */
    public const SECTION_OWNER_FIELD = 'mpc_config_owner';

    /**
     * @var \modX
     */
    public \modX $modx;
    /**
     * @var array
     */
    public array $properties = [];
    /**
     * @var Logging
     */
    protected Logging $logging;
    /**
     * @var Response
     */
    protected Response $response;
    /** Инжектированные сверху (Mpc) Logging/Response; null → создаём свои. */
    private ?Logging $injectedLogging = null;
    private ?Response $injectedResponse = null;
    /**
     * @var Parser
     */
    protected Parser $parser;
    /**
     * @var bool
     */
    public bool $debug = false;
    /**
     * @var array
     */
    public array $staticSectionNames = [];

    /**
     * Разбирает настройку mpc_contact_lexicon_fields (или per-маркер
     * data-mpc-translate) в карту тип→поля. Записи через запятую: `поле` →
     * правило для всех типов (ключ '*'), `тип:поле` → правило для контакта
     * этого типа. Пример: "caption, address:value" → ['*'=>['caption'],
     * 'address'=>['value']].
     *
     * @param string $raw
     * @return array<string,string[]>
     */
    public static function parseContactLexiconFields(string $raw): array
    {
        $map = [];
        foreach (array_filter(array_map('trim', explode(',', $raw))) as $entry) {
            if (strpos($entry, ':') !== false) {
                [$type, $field] = array_map('trim', explode(':', $entry, 2));
            } else {
                $type  = '*';
                $field = $entry;
            }
            if ($type === '' || $field === '') {
                continue;
            }
            $map[$type][] = $field;
        }
        return $map;
    }

    /**
     * Переводимо ли под-поле контакта данного типа: поле есть в правилах для
     * всех типов ('*') ИЛИ в правилах конкретного типа. Единая точка решения
     * для грабера (ContactUpdater) и каттера (Cutter::contactFieldExpr).
     *
     * @param array<string,string[]> $map
     * @param string $type
     * @param string $field
     * @return bool
     */
    public static function isContactFieldTranslatable(array $map, string $type, string $field): bool
    {
        if (in_array($field, $map['*'] ?? [], true)) {
            return true;
        }
        return in_array($field, $map[$type] ?? [], true);
    }

    /**
     * @param \modX $modx
     * @param array $properties
     */
    public function __construct(\modX $modx, array $properties = [], ?Logging $logging = null, ?Response $response = null)
    {
        $this->modx = $modx;
        $this->properties = $properties;
        $this->injectedLogging = $logging;
        $this->injectedResponse = $response;
        $this->initialize();
    }

    /**
     * @return void
     */
    protected function initialize(): void
    {
        // Регистрируем xPDO-модели пакетов (migxpageconfigurator: mpcType/…; migx:
        // migxConfig) — единым bootstrap'ом, чтобы getObject/newObject видели
        // кастомные классы в грабере, каттере и рендере (раньше addPackage был
        // рассредоточен по Base/Grabber/Render).
        PackageBootstrap::ensure(
            $this->modx,
            (string)($this->properties['corePath'] ?? $this->modx->getOption('core_path'))
        );

        $translatableContentTypes = $this->modx->getOption('mpc_translated_content', '', 'text,image,poster,video,audio');

        // excludeLexiconFields подгружаем здесь (Base), а не только в Grabber,
        // чтобы Cutter тоже видел список и не ставил `| lexicon` на excluded
        // поля (иначе грабер не пишет ключ → переопределённый модификатор
        // отдаёт пусто → пустота на сайте). Поскольку `corePath` устанавливается
        // ребёнком ДО parent::initialize() (см. Cutter/Grabber), здесь он уже
        // доступен в $this->properties.
        $excludeLexiconFields = [];
        $excludeLexiconFilename = $this->modx->getOption(
            'mpc_exclude_lexicons_filename',
            '',
            'components/migxpageconfigurator/services/exclude_lexicons.inc.php'
        );
        if ($excludeLexiconFilename) {
            $corePath = $this->properties['corePath'] ?? $this->modx->getOption('core_path');
            // realpath-граница: настройка mpc_exclude_lexicons_filename с '../'
            // или абсолютным путём иначе дала бы include произвольного файла (LFI).
            $real     = realpath($corePath . $excludeLexiconFilename);
            $baseReal = realpath($corePath);
            if ($real !== false && $baseReal !== false
                && strpos($real, rtrim($baseReal, '/') . '/') === 0 && is_file($real)) {
                include $real;
            }
        }

        $properties = [
            'commonConfigTvName' => $this->modx->getOption('mpc_common_config_name', null, 'mpc_config'),
            'baseSectionName' => $this->modx->getOption('mpc_base_section_name', null, 'mpc_base'),
            'staticBlocksPageId' => (int)$this->modx->getOption('mpc_static_block_page_id', null, 1),
            'pathToSections' => $this->modx->getOption('mpc_path_to_sections', null, 'sections/'),
            'contactsPageId' => (int)$this->modx->getOption('mpc_contacts_page_id', null, 1),
            'contactsTvName' => $this->modx->getOption('mpc_contacts_tv_name', null, 'contacts'),
            'contactsTvId' => $this->modx->getOption('mpc_contacts_tv_id', null, 0),
            // Какие под-поля контакта переводимы (лексиконятся), картой тип→поля.
            // Синтаксис настройки: записи через запятую, каждая либо `поле`
            // (для всех типов), либо `тип:поле` (только для контакта этого типа).
            // Пример: "caption, address:value, address:fvalue" → caption у всех +
            // value/fvalue только у адреса. По умолчанию только caption у всех.
            'contactLexiconFields' => self::parseContactLexiconFields(
                (string)$this->modx->getOption('mpc_contact_lexicon_fields', null, 'caption')
            ),
            // Нативные поля ресурса, которые можно наследовать от «типа страницы»
            // (каскад тип→ресурс) и писать через rfield. Структурные поля сюда
            // не входят и каскадом не затрагиваются.
            'editableResourceFields' => array_values(array_filter(array_map(
                'trim',
                explode(',', (string)$this->modx->getOption('mpc_editable_resource_fields', null, 'longtitle,description,introtext,content,menutitle'))
            ))),
            'assetsPath' => $this->modx->getOption('assets_path', null, ''),
            'useLexicons' => $this->modx->getOption('mpc_use_lexicons', '', false),
            'defaultLanguageKey' => $this->modx->getOption('mpc_default_language', '', 'ru'),
            // Базовая культура сайта — системное значение `mpc_default_language`
            // БЕЗ контекстного переопределения. `defaultLanguageKey` строкой выше
            // читается с переопределением (2.5.62-rc: язык записи словаря — по
            // контексту), поэтому в не-web контексте значения расходятся, и это
            // расхождение — единственный надёжный признак «нарезка идёт в
            // культуре перевода, а значения в вёрстке — на базовом языке».
            'baseLanguageKey' => $this->getSystemDefaultLanguage(),
            'translatableContentTypes' => explode(',', $translatableContentTypes),
            'excludeLexiconFields' => $excludeLexiconFields,
            // Топики (имена файлов лексикона без .inc.php), куда каттер вырезает
            // произвольные ключи data-mpc-lexicon и где редактор ищет ключ без
            // явного топика. Первый в списке — топик по умолчанию для маркеров
            // вида data-mpc-lexicon="key" (без префикса topic:).
            'arbitraryLexiconTopics' => array_values(array_filter(array_map(
                'trim',
                explode(',', (string)$this->modx->getOption('mpc_arbitrary_lexicon_topics', null, ''))
            ))),
        ];
        $this->properties = array_merge($this->properties, $properties);

        // DI: используем инжектированные сверху (Mpc) Logging/Response — один набор
        // на запрос. Без инъекции (прямое создание хендлера) — свои, с пер-классным
        // лог-файлом (backward-compat).
        if ($this->injectedLogging !== null) {
            $this->logging = $this->injectedLogging;
        } else {
            $this->logging = new Logging($this->modx);
        }
        $this->response = $this->injectedResponse ?? new Response($this->logging);
        $this->parser = new Parser();
    }

    /**
     * @param string $fileName
     * @return string
     */
    public function getFileContent(string $fileName): string
    {
        $filePath = $this->properties['pdotoolsElementsPath'] . $this->properties['pathToSrc'] . $fileName;
        // Автодобавление расширения: `cut wrapper` (без .tpl) не находил файл и
        // молча давал пустоту (а CLI рапортовал успех). Если как есть не найден —
        // пробуем с расширением шаблонов. Берём из properties (грабер), иначе из
        // системной настройки mpc_tpl_file_extension напрямую (каттер не кладёт
        // 'extension' в свои properties) — НЕ хардкод '.tpl'.
        $ext = (string)($this->properties['extension']
            ?? $this->modx->getOption('mpc_tpl_file_extension', null, '.tpl'));
        if (!file_exists($filePath) && $ext !== '' && substr($fileName, -strlen($ext)) !== $ext
            && file_exists($filePath . $ext)) {
            $filePath .= $ext;
        }
        if ($this->debug) {
            $this->logging->write(__METHOD__, "Path to file is $filePath");
        }
        if (!file_exists($filePath)) {
            $this->response->error(__METHOD__, "File not found $filePath");
            return '';
        }
        return str_replace("\r", '', file_get_contents($filePath));
    }

    /**
     * @param string $html
     * @param string $selector
     * @return array|null
     * @throws InvalidSelectorException
     */
    public function getItems(string $html, string $selector): ?array
    {
        if(empty($html)){
            return null;
        }
        $items = $this->parser->findByAttribute($html, $selector);
        if (!count($items)) {
            return null;
        }
        return $items;
    }

    /**
     * Заменяет фрагмент элемента в исходном HTML, перебирая формы сериализации.
     *
     * Прямой `str_replace($this->parser->getHTMLString($item), ...)` промахивается,
     * если в исходнике есть HTML-сущности (`TERMS &amp; CONDITIONS`): getHTMLString
     * их раскрывает, такой строки в файле нет — замена молча не происходит
     * (баг #2609-70). Идём по формам из Parser::getHTMLVariants: `decoded` (старое
     * поведение, покрывает url-энкод атрибутов), затем `entities`, затем `raw`.
     * Подставляем ту же форму замены, что и найденная, — иначе неизменная часть
     * фрагмента потеряла бы сущности. Ни одна форма не нашлась — пишем в лог, а не
     * молчим.
     *
     * @param string $html исходный HTML
     * @param array $search формы искомого фрагмента (Parser::getHTMLVariants)
     * @param array|string $replacement формы замены или готовая строка на все формы
     * @param string $context для лога (__METHOD__ вызывающего)
     * @return string
     */
    protected function replaceElementHtml(string $html, array $search, $replacement, string $context): string
    {
        $result = $this->parser->replaceFragment($html, $search, $replacement);
        if ($result !== null) {
            return $result;
        }

        $this->logging->write(
            $context,
            'Фрагмент не найден в исходном HTML, замена пропущена: '
            . mb_substr(trim((string)($search['decoded'] ?? '')), 0, 200),
            [],
            false,
            Logging::WARN
        );

        return $html;
    }

    /**
     * @param string $value
     * @return string
     */
    public function getContactKey(string $value): string
    {
        // strip_tags+trim — единая нормализация значения для ckey: грабер берёт
        // значение через getValue (может прийти с дочерними тегами, напр.
        // "<span>тел</span>"), каттер — textContent (без тегов). Без нормализации
        // md5 расходился → контакты без data-mpc-key не резолвились на рендере.
        return md5(trim(strip_tags($value)));
    }

    /**
     * @param int $rid
     * @param bool $all
     * @return array
     */
    public function getStaticSectionNames(int $rid, bool $all = false): array
    {
        return $this->getStaticSectionNamesFromConfig($this->getSectionConfig($rid), $all);
    }

    /**
     * Системное значение `mpc_default_language` — без контекстного
     * переопределения. `getOption()` для этого не годится: он отдаёт значение
     * ТЕКУЩЕГО контекста, а нам нужна культура, на которой написана вёрстка.
     */
    protected function getSystemDefaultLanguage(): string
    {
        $setting = $this->modx->getObject('modSystemSetting', ['key' => 'mpc_default_language']);
        $value   = is_object($setting) && method_exists($setting, 'get')
            ? trim((string)$setting->get('value')) : '';

        return $value !== '' ? $value : trim((string)$this->modx->getOption('mpc_default_language', '', 'ru'));
    }

    /**
     * Конфиг секций ресурса (TV `mpc_config`) как массив записей.
     *
     * @param int $rid id ресурса
     * @return array список записей конфига; пусто, если TV нет или JSON битый
     */
    public function getSectionConfig(int $rid): array
    {
        $config = '';
        $q = $this->modx->newQuery('modTemplateVarResource');
        $q->leftJoin('modTemplateVar', 'TV', 'modTemplateVarResource.tmplvarid=TV.id');
        $q->where(['TV.name' => $this->properties['commonConfigTvName'], 'modTemplateVarResource.contentid' => $rid]);
        $q->select('modTemplateVarResource.value as value');
        $q->prepare();
        if($q->stmt->execute()){
            $config = $q->stmt->fetchColumn();
        }

        if (!$config) {
            return [];
        }

        $config = json_decode($config, true);
        if (!is_array($config)) { // невалидный непустой не-JSON → не падаем на foreach (V8)
            return [];
        }
        return $config;
    }

    /**
     * Конфиг, которым СТРАНИЦА рендерится: тип страницы — база, ресурс
     * перекрывает одноимённые секции. Порядок и правило те же, что у
     * `Render::parseConfig` (`array_merge($typeConfig, $resourceConfig)` по
     * `section_name`) — читатель и писатель обязаны видеть один конфиг.
     *
     * До #2609-151 плагин сохранения решал о статике по конфигу самого ресурса:
     * у контекстной копии с устаревшим снимком секции числились динамическими,
     * и ключи статики уезжали в словарь ресурса.
     *
     * @param int $typeRid     id ресурса-типа страницы (mpcType)
     * @param int $resourceRid id самого ресурса
     * @return array записи конфига в порядке «тип, затем добавленные ресурсом»
     */
    public function getMergedSectionConfig(int $typeRid, int $resourceRid): array
    {
        $typeConfig     = $typeRid ? $this->getSectionConfig($typeRid) : [];
        $resourceConfig = $resourceRid ? $this->getSectionConfig($resourceRid) : [];
        if ($typeRid === $resourceRid) {
            return $resourceConfig;
        }
        return $this->mergeSectionConfigs($typeConfig, $resourceConfig);
    }

    /**
     * Слияние двух конфигов по `section_name`: запись ресурса перекрывает
     * одноимённую запись типа целиком, безымянные записи идут как есть.
     */
    public function mergeSectionConfigs(array $typeConfig, array $resourceConfig): array
    {
        $byName = [];
        $extra  = [];
        foreach (['type' => $typeConfig, 'resource' => $resourceConfig] as $owner => $config) {
            foreach ($config as $item) {
                if (!is_array($item)) {
                    continue;
                }
                // Кто владеет секцией в слитом конфиге. Нужно писателю словарей:
                // у секции, которую ресурс не перекрывает, значения ничем не
                // отличаются от типа, и ресурсный словарь для неё — дубль,
                // который на рендере перебивает словарь типа (#2609-155).
                $item[self::SECTION_OWNER_FIELD] = $owner;
                $name = (string)($item['section_name'] ?? '');
                if ($name === '') {
                    $extra[] = $item;
                    continue;
                }
                $byName[$name] = $item;
            }
        }
        return array_merge(array_values($byName), $extra);
    }

    /**
     * Имена статичных секций (или всех при `$all`) по готовому конфигу.
     */
    public function getStaticSectionNamesFromConfig(array $config, bool $all = false): array
    {
        $output = [];
        foreach ($config as $item) {
            if (!is_array($item)) {
                continue;
            }
            if (empty($item['is_static']) && !$all) {
                continue;
            }
            $output[] = $item['section_name'] ?? '';
        }
        return $output;
    }

}
