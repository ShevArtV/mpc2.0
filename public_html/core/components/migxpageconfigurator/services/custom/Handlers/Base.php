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
     * @param \modX $modx
     * @param array $properties
     */
    public function __construct(\modX $modx, array $properties = [])
    {
        $this->modx = $modx;
        $this->properties = $properties;
        $this->initialize();
    }

    /**
     * @return void
     */
    protected function initialize(): void
    {
        // Регистрируем xPDO-модель пакета (mpcType / mpcTypeCollection / mpcTypeData),
        // чтобы getObject/newObject видели кастомные resource-классы в грабере,
        // каттере и рендере.
        $this->modx->addPackage(
            'migxpageconfigurator',
            ($this->properties['corePath'] ?? $this->modx->getOption('core_path')) . 'components/migxpageconfigurator/model/'
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
            $excludeLexiconFieldsPath = $corePath . $excludeLexiconFilename;
            if (is_file($excludeLexiconFieldsPath)) {
                include $excludeLexiconFieldsPath;
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
            // Какие под-поля контакта переводимы (лексиконятся). По умолчанию только
            // caption; трансграничный сайт ставит "caption,value,fvalue,attributes".
            'contactLexiconFields' => array_values(array_filter(array_map(
                'trim',
                explode(',', (string)$this->modx->getOption('mpc_contact_lexicon_fields', null, 'caption'))
            ))),
            'assetsPath' => $this->modx->getOption('assets_path', null, ''),
            'useLexicons' => $this->modx->getOption('mpc_use_lexicons', '', false),
            'defaultLanguageKey' => $this->modx->getOption('mpc_default_language', '', 'ru'),
            'translatableContentTypes' => explode(',', $translatableContentTypes),
            'excludeLexiconFields' => $excludeLexiconFields,
        ];
        $this->properties = array_merge($this->properties, $properties);

        $this->logging = new Logging();
        $logFileName = str_replace('\\', '-', self::class) . '.txt';
        $this->logging->setPath($logFileName);
        $this->response = new Response($this->logging);
        $this->parser = new Parser();
    }

    /**
     * @param string $fileName
     * @return string
     */
    public function getFileContent(string $fileName): string
    {
        $filePath = $this->properties['pdotoolsElementsPath'] . $this->properties['pathToSrc'] . $fileName;
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
        $config = '';
        $q = $this->modx->newQuery('modTemplateVarResource');
        $q->leftJoin('modTemplateVar', 'TV', 'modTemplateVarResource.tmplvarid=TV.id');
        $q->where(['TV.name' => $this->properties['commonConfigTvName'], 'modTemplateVarResource.contentid' => $rid]);
        $q->select('modTemplateVarResource.value as value');
        $q->prepare();
        if($q->stmt->execute()){
            $config = $q->stmt->fetchColumn();
        }
        /*if (!$config = $resource->getTVValue($this->properties['commonConfigTvName'])) {
            return [];
        }*/

        if (!$config) {
            return [];
        }

        $config = json_decode($config, true);
        $output = [];
        foreach ($config as $item) {
            if (!$item['is_static'] && !$all) {
                continue;
            }
            $output[] = $item['section_name'];
        }
        return $output;
    }

    public function getLexiconKey(array $options): string
    {
        $fieldName = $options['fieldName'] ?? '';
        $idx = $options['idx'] ?? '';
        $parentFieldName = $options['parentFieldName'] ?? '';
        $prefix = $options['prefix'] ?? '';

        $lexiconKey = $parentFieldName ? "{$prefix}_{$parentFieldName}_$fieldName" : "{$prefix}_$fieldName";
        return $idx ? "{$lexiconKey}_$idx" : $lexiconKey;
    }

}
