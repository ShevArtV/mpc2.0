<?php
/**
 * Сервис экспорта файлов лексиконов для перевода.
 */

namespace MpcServices\Widgets;

use MpcServices\Helpers\ExcelFileHandler;

/**
 * @author Arthur Shevchenko (https://t.me/ShevArtV)
 */
class LexiconExport
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
     * @var string
     */
    protected string $defaultLanguageKey;
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
        $this->defaultLanguageKey = $this->modx->getOption('mpc_default_language', '', 'ru');
        $this->paths = [
            'base' => $this->modx->getOption('base_path', null, $_SERVER['DOCUMENT_ROOT'] . '/'),
            'core' => $this->modx->getOption('core_path', null, MODX_CORE_PATH),
            'lexicons' => $this->modx->getOption('mpc_lexicon_path', '', 'components/migxpageconfigurator/lexicon/'),
        ];
        $this->ExcelFileHandler = new ExcelFileHandler($this->modx);
    }

    /**
     * @return array
     */
    public function run(): array
    {
        $data = $this->getData();
        $filename = str_replace('.inc.php', '.xlsx', $_POST['filename']);
        $exportFilePath = $this->ExcelFileHandler->generateFile($data, $filename, 'lexicons-export/');
        return [
            'success' => true,
            'message' => $this->scriptProperties['successMessage'],
            'data' => [
                'fileName' => $filename,
                'filePath' => $exportFilePath
            ]
        ];
    }

    public function loadSections(){
        $pathToLexiconFile = $this->paths['core'] . $this->paths['lexicons'] . $this->defaultLanguageKey . '/' . $_POST['filename'];
        $optionLexicon = $this->modx->lexicon('mpc_widget_all_sections');
        $sections['all'] = '<option value="">' . $optionLexicon . '</option>' . PHP_EOL;
        if (file_exists($pathToLexiconFile)) {
            include $pathToLexiconFile;
            foreach ($_lang as $k => $v) {
                $parts = explode('_', $k);
                if (!isset($sections[$parts[0]])) {
                    $sections[$parts[0]] = '<option value="' . $parts[0] . '">' . $parts[0] . '</option>' . PHP_EOL;
                }
            }
        }

        return [
            'success' => true,
            'message' => $this->scriptProperties['successMessage'],
            'data' => [
                'html' => implode('', $sections)
            ]
        ];
    }

    /**
     * @return array
     */
    private function getData(): array
    {
        $languages = scandir($this->paths['core'] . $this->paths['lexicons']);
        $data = [];
        unset($languages[0], $languages[1]);
        foreach ($languages as $language) {
            $_lang = [];
            $pathToLexiconFile = $this->paths['core'] . $this->paths['lexicons'] . $language . '/' . $_POST['filename'];
            if (file_exists($pathToLexiconFile)) {
                include $pathToLexiconFile;
            }
            $this->modx->log(1, print_r($_POST['section'], 1));
            foreach ($_lang as $k => $v) {
                if($_POST['section'] && strpos($k, $_POST['section']) !== 0){
                    continue;
                }
                if (!isset($data[$k]['lexicon_key'])) {
                    $data[$k]['lexicon_key'] = $k;
                }
                $data[$k][$language] = $v;
            }
        }

        return array_values($data);
    }
}
