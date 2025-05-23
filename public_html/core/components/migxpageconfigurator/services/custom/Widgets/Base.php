<?php

namespace MpcServices\Widgets;

use MpcServices\Helpers\ExcelFileHandler;

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
    public array $scriptProperties;
    /**
     * @var array
     */
    protected array $paths;

    /**
     * @var ExcelFileHandler
     */
    protected ExcelFileHandler $ExcelFileHandler;

    /**
     * @param \modX $modx
     * @param array $scriptProperties
     */
    public function __construct(\modX $modx, array $scriptProperties)
    {
        $this->modx = $modx;
        $this->modx->switchContext('mgr');
        $this->scriptProperties = $scriptProperties;
        $this->initialize();
    }

    /**
     * @return void
     */
    protected function initialize()
    {
        $this->paths = [
            'base' => $this->modx->getOption('base_path', null, $_SERVER['DOCUMENT_ROOT'] . '/'),
            'core' => $this->modx->getOption('core_path', null, MODX_CORE_PATH),
            'lexicons' => $this->modx->getOption('mpc_lexicon_path', '', 'components/migxpageconfigurator/lexicon/'),
        ];
        $this->modx->addPackage('migx', $this->paths['core'] . 'components/migx/model/');
        $this->ExcelFileHandler = new ExcelFileHandler($this->modx);
    }

    /**
     * @return array
     */
    protected function getAllConfigs(): array
    {
        $q = $this->modx->newQuery('migxConfig');
        $q->select('name');
        $q->prepare();
        if (!$q->stmt->execute()) {
            return [];
        }
        return $q->stmt->fetchAll(\PDO::FETCH_COLUMN) ?? [];
    }
}
