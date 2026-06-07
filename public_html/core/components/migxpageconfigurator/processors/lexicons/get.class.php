<?php
/**
 * Returns all lexicon key-value pairs for a file across all languages.
 */
class MigxpageconfiguratorLexiconsGetProcessor extends modProcessor
{
    /** Требуется право mpc_view (как CMP лексиконов); коннектор проверяет лишь сессию. */
    public function checkPermissions()
    {
        return $this->modx->hasPermission('mpc_view');
    }

    public function process()
    {
        $this->modx->lexicon->load('migxpageconfigurator:default');

        $corePath = $this->modx->getOption('migxpageconfigurator_core_path', null,
            $this->modx->getOption('core_path') . 'components/migxpageconfigurator/');
        require_once $corePath . 'services/vendor/autoload.php';

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

        $context = new \MpcServices\Handlers\LexiconContext($this->modx);

        $rows = [];
        foreach ($allKeys as $key) {
            $row = ['key' => $key, 'context' => $context->contextFor((string)$key)];
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
