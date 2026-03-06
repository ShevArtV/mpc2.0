<?php
/**
 * Returns list of all resource lexicon files with resource titles.
 */
class MigxpageconfiguratorLexiconsGetlistProcessor extends modProcessor
{
    public function process()
    {
        $lexiconBase   = $this->modx->getOption('core_path')
            . $this->modx->getOption('mpc_lexicon_path', null, 'components/migxpageconfigurator/lexicon/');
        $defaultLang   = $this->modx->getOption('mpc_default_language', null, 'ru');
        $filenameField = $this->modx->getOption('mpc_lexicon_filename_field', null, 'alias');
        $labelField    = $this->modx->getOption('mpc_cmp_resource_label_field', null, 'pagetitle');

        $langDir = $lexiconBase . $defaultLang . '/';
        $files   = glob($langDir . '*.inc.php') ?: [];

        $rows = [];
        foreach ($files as $file) {
            $name = basename($file, '.inc.php');
            if (in_array($name, ['default', 'properties', 'setting'])) {
                continue;
            }
            $title    = $name;
            $resource = $this->modx->getObject('modResource', [$filenameField => $name]);
            if ($resource) {
                $title = $resource->get($labelField) ?: $name;
            }
            $rows[] = ['filename' => $name, 'title' => $title];
        }

        return $this->outputArray($rows, count($rows));
    }
}
return 'MigxpageconfiguratorLexiconsGetlistProcessor';
