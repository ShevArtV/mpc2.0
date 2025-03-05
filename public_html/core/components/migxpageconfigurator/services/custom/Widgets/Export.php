<?php
/**
 * Сервис экспорта файлов лексиконов для перевода.
 */

namespace MpcServices\Widgets;

use MpcServices\Helpers\ExcelFileHandler;

/**
 * @author Arthur Shevchenko (https://t.me/ShevArtV)
 */
class Export
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
    private ExcelFileHandler $ExcelFileHandler;

    public function __construct(\modX $modx, array $scriptProperties)
    {
        $this->modx = $modx;
        $this->scriptProperties = $scriptProperties;
        $this->initialize();
    }

    protected function initialize()
    {
        $this->paths = [
            'base' => $this->modx->getOption('base_path', null, $_SERVER['DOCUMENT_ROOT'] . '/'),
            'core' => $this->modx->getOption('core_path', null, MODX_CORE_PATH),
            'lexicons' => $this->modx->getOption('mpc_lexicon_path', '', 'components/migxpageconfigurator/lexicon/'),
        ];
        $this->ExcelFileHandler = new ExcelFileHandler($this->modx);
    }

    public function run()
    {
        $data = $this->getData();
        $filename = str_replace('.inc.php', '.xlsx', $_POST['filename']);
        $exportFilePath = $this->ExcelFileHandler->generateFile($data, $filename, 'lexicons-export/');
        return [
            'success' => true,
            'message' => $this->scriptProperties['successMessage'],
            'data' => ['fileName' => $filename, 'filePath' => $exportFilePath]
        ];
    }

    private function getData(): array
    {
        $languages = scandir($this->paths['core'] . $this->paths['lexicons']);
        $data = [];
        unset($languages[0], $languages[1]);
        foreach ($languages as $language) {
            include $this->paths['core'] . $this->paths['lexicons'] . $language . '/' . $_POST['filename'];
            foreach ($_lang as $k => $v) {
                if (!isset($data[$k]['lexicon_key'])) {
                    $data[$k]['lexicon_key'] = $k;
                }
                $data[$k][$language] = $v;
            }
        }

        return array_values($data);
    }
}
