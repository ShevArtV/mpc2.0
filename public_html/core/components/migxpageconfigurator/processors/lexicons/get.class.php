<?php
/**
 * Returns all lexicon key-value pairs for a file across all languages.
 */
class MigxpageconfiguratorLexiconsGetProcessor extends modProcessor
{
    public function process()
    {
        $this->modx->lexicon->load('migxpageconfigurator:default');

        $filename = basename($this->getProperty('filename', ''));
        if (!$filename) {
            return $this->failure($this->modx->lexicon('mpc_err_no_filename'));
        }

        $lexiconBase = $this->modx->getOption('core_path')
            . $this->modx->getOption('mpc_lexicon_path', null, 'components/migxpageconfigurator/lexicon/');
        $defaultLang = $this->modx->getOption('mpc_default_language', null, 'ru');
        $incFile     = $filename . '.inc.php';

        // Collect language directories
        $langDirs  = glob($lexiconBase . '*', GLOB_ONLYDIR) ?: [];
        $languages = array_map('basename', $langDirs);
        usort($languages, function ($a, $b) use ($defaultLang) {
            if ($a === $defaultLang) return -1;
            if ($b === $defaultLang) return 1;
            return strcmp($a, $b);
        });

        // Load each language once
        $langData = [];
        foreach ($languages as $lang) {
            $_lang   = [];
            $langFile = $lexiconBase . $lang . '/' . $incFile;
            if (file_exists($langFile)) {
                include $langFile;
            }
            $langData[$lang] = $_lang;
        }

        // All keys come from the default language file
        $allKeys = array_keys($langData[$defaultLang] ?? []);

        $rows = [];
        foreach ($allKeys as $key) {
            $row = ['key' => $key];
            foreach ($languages as $lang) {
                $row[$lang] = $langData[$lang][$key] ?? '';
            }
            $rows[] = $row;
        }

        return $this->success('', [
            'rows'      => $rows,
            'languages' => $languages,
            'total'     => count($rows),
        ]);
    }
}
return 'MigxpageconfiguratorLexiconsGetProcessor';
