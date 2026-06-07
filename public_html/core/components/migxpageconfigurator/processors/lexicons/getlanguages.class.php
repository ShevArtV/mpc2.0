<?php
/**
 * Returns available lexicon languages, default language first.
 */
class MigxpageconfiguratorLexiconsGetlanguagesProcessor extends modProcessor
{
    /** Требуется право редактирования (коннектор проверяет лишь сессию). */
    public function checkPermissions()
    {
        return $this->modx->hasPermission('save_document') || $this->modx->hasPermission('mpc_edit');
    }

    public function process()
    {
        $lexiconBase = $this->modx->getOption('core_path')
            . $this->modx->getOption('mpc_lexicon_path', null, 'components/migxpageconfigurator/lexicon/');
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
