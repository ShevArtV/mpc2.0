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
class LexiconImport extends Base
{
    /**
     * @return void
     */
    protected function initialize()
    {
        parent::initialize();
        $this->grabber = new Grabber($this->modx);
        $this->paths['assets'] = $this->modx->getOption('assets_path', null, MODX_ASSETS_PATH);
        $this->paths['upload'] = $this->modx->getOption('si_uploaddir', '', '[[+asseetsUrl]]components/sendit/uploaded_files/');
        $this->paths['upload'] = str_replace('[[+asseetsUrl]]', $this->paths['assets'], $this->paths['upload']) . session_id() . '/';
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
        $configNames = $this->getAllConfigs();
        $this->grabber->properties['resource'] = $this->modx->newObject('modResource');
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
                    if (strpos($v, 'http') !== false) {
                        $sectionName = '';
                        foreach ($configNames as $configName) {
                            if (strpos($k, $configName) === 0) {
                                $sectionName = $configName;
                                break;
                            }
                        }

                        $this->grabber->imagesPath = $this->grabber->properties['imagesPath'] . $sectionName . '/';
                        $v = $this->grabber->downloadImage($language . '-' . $v);
                    }
                    $v = $this->grabber->sanitizeValue($v);
                    $content .= '$_lang[\'' . $k . '\'] = \'' . $v . '\';' . PHP_EOL;
                }
                file_put_contents($pathToLexiconFile, $content);
            }
        }
        $this->modx->cacheManager->refresh(['lexicon_topics' => []]);
    }
}
