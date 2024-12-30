<?php

/**
 * Сервис с общими методами для обработчиков.
 */

namespace MpcServices\Handlers;

use MpcServices\Helpers\Logging;
use MpcServices\Helpers\Response;

/**
 * @author Arthur Shevchenko (https://t.me/ShevArtV)
 */
class Base{

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
    public array $staticSectionNames = [];

    public function __construct(\modX $modx, array $properties = [])
    {
        $this->modx = $modx;
        $this->properties = $properties;
        $this->initialize();
    }

    protected function initialize()
    {
        $properties = [
            'commonConfigTvName' => $this->modx->getOption('mpc_common_config_name', null, 'mpc_config'),
            'baseSectionName' => $this->modx->getOption('mpc_base_section_name', null, 'mpc_base'),
            'staticBlocksPageId' => (int)$this->modx->getOption('mpc_static_block_page_id', null, 1),
            'pathToSections' => $this->modx->getOption('mpc_path_to_sections', null, 'sections/'),
            'contactsPageId' => (int)$this->modx->getOption('mpc_contacts_page_id', null, 1),
            'contactsTvName' => $this->modx->getOption('mpc_contacts_tv_name', null, 'contacts'),
            'contactsTvId' => $this->modx->getOption('mpc_contacts_tv_id', null, 0),
            'assetsPath' => $this->modx->getOption('assets_path', null, ''),
        ];
        $this->properties = array_merge($this->properties, $properties);

        $this->logging = new Logging();
        $logFileName = str_replace('\\', '-', self::class) . '.txt';
        $this->logging->setPath($logFileName);
        $this->response = new Response($this->logging);
        $this->parser = new Parser();
    }

    public function getFileContent($fileName): string
    {
        $filePath = $this->properties['pdotoolsElementsPath'] . $this->properties['pathToSrc'] . $fileName;
        if ($this->debug) {
            $this->logging->write(__METHOD__, "Path to file is $filePath");
        }
        if (!file_exists($filePath)) {
            $this->response->error(__METHOD__, "File not found $filePath");
            return '';
        }
        return file_get_contents($filePath);
    }

    /**
     * @param string $html
     * @param string $selector
     * @return \DOMNodeList|null
     */
    public function getItems(string $html, string $selector): ?\DOMNodeList
    {
        $items = $this->parser->findByAttribute($html, $selector);
        if (!$items->count()) {
            return null;
        }
        return $items;
    }

    public function getContactKey(string $value): string
    {
        return md5($value);
    }

    public function getStaticSectionNames(\modResource $resource): array
    {
        if(!$config = $resource->getTVValue($this->properties['commonConfigTvName'])){
            return [];
        }
        $config = json_decode($config, true);
        $output = [];
        foreach ($config as $item){
            if(!$item['is_static']){
                continue;
            }
            $output[] = $item['section_name'];
        }
        return $output;
    }

}
