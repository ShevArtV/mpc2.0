<?php

/**
 * Сервис для обработки события OnDocFormSave
 */

namespace MpcServices\Plugins;

use MpcServices\Mpc;

/**
 * @author Arthur Shevchenko (https://t.me/ShevArtV)
 */
class OnDocFormSave extends PluginHandler
{
    /**
     * @return void
     */
    public function run(): void
    {
        $ctx = $this->scriptProperties['resource']->get('context_key');
        if ($this->modx->context->get('key') !== $ctx) {
            $this->modx->switchContext($ctx);
        }
        $Mpc = new Mpc($this->modx);
        $Mpc->render->copyConfig($this->scriptProperties['resource']);
        $Mpc->grabber->fromPlugin = true;
        if ($typeResource = $this->modx->getObject('modResource', [
            'template' => $this->scriptProperties['resource']->get('template'),
            'parent' => $Mpc->cutter->properties['staticBlocksPageId']
        ])) {
            $fileName = $typeResource->get('introtext');
            $Mpc->cutter->staticSectionNames = $Mpc->grabber->staticSectionNames = $Mpc->cutter->getStaticSectionNames($this->scriptProperties['id']);
            $Mpc->handleFile($fileName);

            if($typeResource->get('id') !== $this->scriptProperties['id']){
                $this->manageResourceLexicons($this->scriptProperties['resource'], $Mpc, $typeResource->get('id'));
            }
        }
        if ($this->scriptProperties['id'] === $Mpc->grabber->properties['staticBlocksPageId']) {
            $this->filterStaticSectionsLexicons($Mpc);
        }
        if ($this->scriptProperties['id'] === $Mpc->grabber->properties['contactsPageId']) {
            $this->filterContactsLexicons($Mpc);
        }
    }

    /**
     * @param Mpc $Mpc
     * @return void
     */
    public function filterStaticSectionsLexicons(Mpc $Mpc): void
    {
        $staticBlocksPageId = $Mpc->grabber->properties['staticBlocksPageId'];
        $staticBlocksPageLexiconFilename = $Mpc->grabber->properties['staticBlocksPageLexiconFilename'];
        $lexicons[$staticBlocksPageLexiconFilename] = $Mpc->grabber->getLexicons($staticBlocksPageLexiconFilename, $Mpc->grabber->properties['basePathToLexiconFile']);
        if (empty($lexicons[$staticBlocksPageLexiconFilename])) {
            return;
        }
        $resource = $this->modx->getObject('modResource', $staticBlocksPageId);
        if (!$config = $resource->getTVValue($Mpc->grabber->properties['commonConfigTvName'])) {
            return;
        }
        $lexiconsFiltered[$staticBlocksPageLexiconFilename] = [];
        $config = json_decode($config, true);
        foreach ($config as $item) {
            $result = $this->filterByPrefix($lexicons[$staticBlocksPageLexiconFilename], $item['lexicon_prefix'] ?? $item['MIGX_formname']);
            $lexiconsFiltered[$staticBlocksPageLexiconFilename] = array_merge($result, $lexiconsFiltered[$staticBlocksPageLexiconFilename]);
        }

        $Mpc->grabber->createLexicons($lexiconsFiltered);
    }

    /**
     * @param Mpc $Mpc
     * @return void
     */
    public function filterContactsLexicons(Mpc $Mpc): void
    {
        $contactsPageId = $Mpc->grabber->properties['contactsPageId'];
        $contactsPageLexiconFilename = $Mpc->grabber->properties['contactsPageLexiconFilename'];
        $lexicons[$contactsPageLexiconFilename] = $Mpc->grabber->getLexicons($contactsPageLexiconFilename, $Mpc->grabber->properties['basePathToLexiconFile']);
        if (empty($lexicons[$contactsPageLexiconFilename])) {
            return;
        }
        $resource = $this->modx->getObject('modResource', $contactsPageId);
        if (!$contacts = $resource->getTVValue($Mpc->grabber->properties['contactsTvName'])) {
            return;
        }
        $lexiconsFiltered[$contactsPageLexiconFilename] = [];
        $config = json_decode($contacts, true);
        foreach ($config as $item) {
            $result = $this->filterByPrefix($lexicons[$contactsPageLexiconFilename], 'contact_'.$item['ckey']);
            $lexiconsFiltered[$contactsPageLexiconFilename] = array_merge($result, $lexiconsFiltered[$contactsPageLexiconFilename]);
        }

       $Mpc->grabber->createLexicons($lexiconsFiltered);
    }

    /**
     * @param \modResource $resource
     * @param Mpc $Mpc
     * @param int $typeResourceId
     * @return void
     */
    private function manageResourceLexicons(\modResource $resource, Mpc $Mpc, int $typeResourceId): void
    {
        if (!$Mpc->grabber->properties['useLexicons']) {
            return;
        }

        if (!$config = $resource->getTVValue($Mpc->grabber->properties['commonConfigTvName'])) {
            return;
        }
        $config = json_decode($config, true);
        $resourceLexiconFilename = $Mpc->grabber->getResourceIdentifierById($resource->get('id'));
        $typeResourceLexiconFilename = $Mpc->grabber->getResourceIdentifierById($typeResourceId);
        $staticBlocksPageLexiconFilename = $Mpc->grabber->properties['staticBlocksPageLexiconFilename'];
        $lexicons[$resourceLexiconFilename] = $Mpc->grabber->getLexicons($resourceLexiconFilename, $Mpc->grabber->properties['basePathToLexiconFile']);
        $lexicons[$staticBlocksPageLexiconFilename] = $Mpc->grabber->getLexicons($staticBlocksPageLexiconFilename, $Mpc->grabber->properties['basePathToLexiconFile']);

        foreach ($config as $item) {
            $result = $this->filterByPrefix($Mpc->grabber->lexicons[$typeResourceLexiconFilename], $item['lexicon_prefix'] ?? $item['MIGX_formname']);
            if ($item['is_static']) {
                $lexicons[$staticBlocksPageLexiconFilename] = array_merge($result, $lexicons[$staticBlocksPageLexiconFilename]);
            } else {
                $lexicons[$resourceLexiconFilename] = array_merge($result, $lexicons[$resourceLexiconFilename]);
            }
        }
        $Mpc->grabber->createLexicons($lexicons);
    }

    /**
     * @param array $array
     * @param string $prefix
     * @return array
     */
    private function filterByPrefix(array $array, string $prefix): array
    {
        return array_filter($array, function ($key) use ($prefix) {
            return strpos($key, $prefix) === 0;
        }, ARRAY_FILTER_USE_KEY);
    }
}
