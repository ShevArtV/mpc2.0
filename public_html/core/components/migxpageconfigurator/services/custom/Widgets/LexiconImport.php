<?php
/**
 * Сервис экспорта файлов лексиконов для перевода.
 */

namespace MpcServices\Widgets;

use MpcServices\Helpers\ExcelFileHandler;
use MpcServices\Handlers\Grabber;

/**
 * @author Arthur Shevchenko (https://t.me/ShevArtV)
 */
class LexiconImport
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


    /**
     * @param \modX $modx
     * @param array $scriptProperties
     */
    public function __construct(\modX $modx, array $scriptProperties)
    {
        $this->modx = $modx;
        $this->scriptProperties = $scriptProperties;
        $this->initialize();
    }

    /**
     * @return void
     */
    protected function initialize()
    {
        $this->grabber = new Grabber($this->modx);
        $this->paths = [
            'base' => $this->modx->getOption('base_path', null, $_SERVER['DOCUMENT_ROOT'] . '/'),
            'core' => $this->modx->getOption('core_path', null, MODX_CORE_PATH),
            'assets' => $this->modx->getOption('assets_path', null, MODX_ASSETS_PATH),
            'lexicons' => $this->modx->getOption('mpc_lexicon_path', '', 'components/migxpageconfigurator/lexicon/'),
            'upload' => $this->modx->getOption('si_uploaddir', '', '[[+asseetsUrl]]components/sendit/uploaded_files/'),
        ];
        $this->paths['upload'] = str_replace('[[+asseetsUrl]]', $this->paths['assets'], $this->paths['upload']) . session_id() . '/';
        $this->ExcelFileHandler = new ExcelFileHandler($this->modx);
    }

    /**
     * @return array
     */
    public function run(): array
    {
        $filelist = explode(',', $_POST['filelist']);

        $data = $this->getFileData($filelist);
        if (empty($data)) {
            return [
                'success' => false,
                'message' => $this->modx->lexicon('mpc_widget_err_empty_data_import'),
                'data' => []
            ];
        }

        $this->import($data);

        return [
            'success' => true,
            'message' => $this->scriptProperties['successMessage'],
            'data' => []
        ];
    }

    /**
     * @param array $filelist
     * @return array
     */
    private function getFileData(array $filelist): array
    {
        $data = [];
        foreach ($filelist as $filename) {
            $filePath = $this->paths['upload'] . $filename;
            $filename = str_replace('.xlsx', '.inc.php', $filename);
            $data[$filename] = $this->ExcelFileHandler->getDataFromFile($filePath);
        }
        return $data;
    }

    /**
     * @param array $data
     * @return void
     */
    private function import(array $data)
    {
        foreach ($data as $filename => $content) {
            $lexicons = [];
            foreach ($content as $values) {
                $lexiconKey = $values['lexicon_key'];
                unset($values['lexicon_key']);
                foreach ($values as $k => $v) {
                    $lexicons[$k][$lexiconKey] = $v;
                }
            }

            foreach ($lexicons as $language => $values) {
                $languageDir = $this->paths['core'] . $this->paths['lexicons'] . $language . '/';
                $pathToLexiconFile = $languageDir . $filename;
                if (!file_exists($pathToLexiconFile)) {
                    $_lang = [];
                } else {
                    include $this->paths['core'] . $this->paths['lexicons'] . $language . '/' . $filename;
                }
                if (!file_exists($languageDir)) {
                    mkdir($languageDir);
                }

                $_lang = array_merge($_lang, $values);
                $content = '<?php' . PHP_EOL;
                foreach ($_lang as $k => $v) {
                    $v = $this->grabber->sanitizeValue($v);
                    $content .= '$_lang[\'' . $k . '\'] = \'' . $v . '\';' . PHP_EOL;
                }
                file_put_contents($pathToLexiconFile, $content);
            }
        }
        $this->modx->cacheManager->refresh(['lexicon_topics' => []]);
    }
}
