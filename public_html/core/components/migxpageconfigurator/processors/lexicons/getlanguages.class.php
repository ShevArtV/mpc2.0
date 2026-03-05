<?php
/**
 * Returns available lexicon languages, default language first.
 */
class MigxpageconfiguratorLexiconsGetlanguagesProcessor extends modProcessor
{
    public function process()
    {
        $corePath    = $this->modx->getOption('migxpageconfigurator_core_path', null,
            $this->modx->getOption('core_path') . 'components/migxpageconfigurator/');
        $lexiconBase = $corePath . 'lexicon/';
        $defaultLang = $this->modx->getOption('mpc_default_language', null, 'ru');

        $langDirs  = glob($lexiconBase . '*', GLOB_ONLYDIR) ?: [];
        $languages = array_map('basename', $langDirs);

        usort($languages, function ($a, $b) use ($defaultLang) {
            if ($a === $defaultLang) return -1;
            if ($b === $defaultLang) return 1;
            return strcmp($a, $b);
        });

        return $this->success('', [
            'languages'   => $languages,
            'defaultLang' => $defaultLang,
        ]);
    }
}
return 'MigxpageconfiguratorLexiconsGetlanguagesProcessor';
