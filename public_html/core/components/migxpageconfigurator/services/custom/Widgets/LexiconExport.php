<?php
/**
 * Сервис экспорта файлов лексиконов для перевода.
 */

namespace MpcServices\Widgets;

use MpcServices\Helpers\ExcelFileHandler;

/**
 * @author Arthur Shevchenko (https://t.me/ShevArtV)
 */
class LexiconExport extends Base
{
    /**
     * @var string
     */
    protected string $defaultLanguageKey;

    /**
     * @return void
     */
    protected function initialize()
    {
        parent::initialize();
        $this->defaultLanguageKey = $this->modx->getOption('mpc_default_language', '', 'ru');
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

    /**
     * @return array
     */
    public function loadSections()
    {
        $configNames = $this->getAllConfigs();
        $pathToLexiconFile = $this->paths['core'] . $this->paths['lexicons'] . $this->defaultLanguageKey . '/' . $_POST['filename'];
        $optionLexicon = $this->modx->lexicon('mpc_widget_all_sections');
        $sections['all'] = '<option value="">' . $optionLexicon . '</option>' . PHP_EOL;
        $usedConfigNames = [];
        if (file_exists($pathToLexiconFile)) {
            include $pathToLexiconFile;
            $lexiconKeys = array_keys($_lang);
            $result = array_filter($configNames, function ($config) use ($lexiconKeys) {
                foreach ($lexiconKeys as $lexiconKey) {
                    if (strpos($lexiconKey, $config) === 0) {
                        return true;
                    }
                }
                return false;
            });
            $usedConfigNames = array_merge($usedConfigNames, $result);
            foreach ($usedConfigNames as $usedConfigName) {
                $sections[$usedConfigName] = '<option value="' . $usedConfigName . '">' . $usedConfigName . '</option>' . PHP_EOL;
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
        unset($languages[0], $languages[1]);
        $data = [];
        $selectedLanguages = $_POST['languages'] ? json_decode($_POST['languages']) : [];
        $defaultLanguage = $selectedLanguages[] = $this->modx->getOption('mpc_default_language', '', 'ru');
        $pathToLexiconFile = $this->paths['core'] . $this->paths['lexicons'] . $defaultLanguage . '/' . $_POST['filename'];
        include $pathToLexiconFile;
        $lexiconKeys = array_keys($_lang);
        $priority = [$defaultLanguage => 1];
        usort($languages, function ($a, $b) use ($priority) {
            $aPriority = $priority[$a] ?? PHP_INT_MAX;
            $bPriority = $priority[$b] ?? PHP_INT_MAX;

            if ($aPriority !== $bPriority) {
                return $aPriority <=> $bPriority;
            }
            return strcmp($a, $b);
        });
        $languages = array_intersect($languages, $selectedLanguages);
        foreach ($languages as $language) {
            $_lang = [];
            $pathToLexiconFile = $this->paths['core'] . $this->paths['lexicons'] . $language . '/' . $_POST['filename'];
            if (file_exists($pathToLexiconFile)) {
                include $pathToLexiconFile;
            }

            foreach ($lexiconKeys as $k) {
                if ($_POST['section'] && strpos($k, $_POST['section']) !== 0) {
                    continue;
                }
                if (!isset($data[$k]['lexicon_key'])) {
                    $data[$k]['lexicon_key'] = $k;
                }
                $data[$k][$language] = $_lang[$k];
            }
        }

        return array_values($data);
    }
}
