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

        $lexiconBase = $this->modx->getOption('core_path')
            . $this->modx->getOption('mpc_lexicon_path', null, 'components/migxpageconfigurator/lexicon/');
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

        // Ввод перевода для неосновного языка снимает ключ с реестра
        // непереведённых (подход «явный pending-list» для экспорта). Пустое
        // значение = ещё не переведено → оставляем в pending.
        $defaultLang = $this->modx->getOption('mpc_default_language', null, 'ru');
        if ($lang !== $defaultLang && $value !== '') {
            $corePath = $this->modx->getOption('migxpageconfigurator_core_path', null,
                $this->modx->getOption('core_path') . 'components/migxpageconfigurator/');
            require_once $corePath . 'services/vendor/autoload.php';
            (new \MpcServices\Handlers\PendingTranslations($lexiconBase))->remove($lang, $filename, $key);
        }

        $this->modx->cacheManager->refresh(['lexicon_topics' => []]);

        return $this->success('');
    }
}
return 'MigxpageconfiguratorLexiconsUpdatekeyProcessor';
