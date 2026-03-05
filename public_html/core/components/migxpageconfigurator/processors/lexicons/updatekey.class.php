<?php
/**
 * Updates a single lexicon key in one language file.
 */
class MigxpageconfiguratorLexiconsUpdatekeyProcessor extends modProcessor
{
    public function process()
    {
        $filename = basename($this->getProperty('filename', ''));
        $lang     = basename($this->getProperty('lang', ''));
        $key      = $this->getProperty('key', '');
        $value    = $this->getProperty('value', '');

        $this->modx->lexicon->load('migxpageconfigurator:default');

        if (!$filename || !$lang || $key === '') {
            return $this->failure($this->modx->lexicon('mpc_err_missing_params'));
        }

        // Validate lang looks like a language code
        if (!preg_match('/^[a-z]{2}/', $lang)) {
            return $this->failure($this->modx->lexicon('mpc_err_invalid_lang'));
        }

        $corePath    = $this->modx->getOption('migxpageconfigurator_core_path', null,
            $this->modx->getOption('core_path') . 'components/migxpageconfigurator/');
        $lexiconBase = $corePath . 'lexicon/';
        $langDir     = $lexiconBase . $lang . '/';
        $filePath    = $langDir . $filename . '.inc.php';

        if (!is_dir($langDir)) {
            mkdir($langDir, 0777, true);
        }

        $_lang = [];
        if (file_exists($filePath)) {
            include $filePath;
        }

        $_lang[$key] = $value;
        ksort($_lang);

        $content = '<?php' . PHP_EOL;
        foreach ($_lang as $k => $v) {
            $v = str_replace("'", '&apos;', $v);
            $content .= '$_lang[\'' . $k . '\'] = \'' . $v . '\';' . PHP_EOL;
        }
        file_put_contents($filePath, $content);

        $this->modx->cacheManager->refresh(['lexicon_topics' => []]);

        return $this->success('');
    }
}
return 'MigxpageconfiguratorLexiconsUpdatekeyProcessor';
