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
            $Mpc->cutter->staticSectionNames = $Mpc->grabber->staticSectionNames = $Mpc->cutter->getStaticSectionNames($this->scriptProperties['resource']);
            $Mpc->handleFile($fileName);

            $this->manageResourceLexicons($this->scriptProperties['resource'], $Mpc, $typeResource->get('id'));
        }
        if ($this->scriptProperties['id'] === $Mpc->grabber->properties['staticBlocksPageId']) {
            $this->filterStaticSectionsLexicons($Mpc);
        }
    }

    /**
     * @param Mpc $Mpc
     * @return void
     */
    public function filterStaticSectionsLexicons(Mpc $Mpc): void
    {
        $staticBlocksPageId = $Mpc->grabber->properties['staticBlocksPageId'];
        $lexicons[$staticBlocksPageId] = $Mpc->grabber->getLexicons($staticBlocksPageId, $Mpc->grabber->properties['basePathToLexiconFile']);
        if (empty($lexicons[$staticBlocksPageId])) {
            return;
        }
        $resource = $this->modx->getObject('modResource', $staticBlocksPageId);
        if (!$config = $resource->getTVValue($Mpc->grabber->properties['commonConfigTvName'])) {
            return;
        }
        $lexiconsFiltered[$staticBlocksPageId] = [];
        $config = json_decode($config, true);
        foreach ($config as $item) {
            $result = $this->filterByPrefix($lexicons[$staticBlocksPageId], $item['lexicon_prefix'] ?? $item['MIGX_formname']);
            $lexiconsFiltered[$staticBlocksPageId] = array_merge($result, $lexiconsFiltered[$staticBlocksPageId]);
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
        $rid = $resource->get('id');
        $staticBlocksPageId = $Mpc->grabber->properties['staticBlocksPageId'];
        $lexicons[$rid] = $Mpc->grabber->getLexicons($rid, $Mpc->grabber->properties['basePathToLexiconFile']);
        $lexicons[$staticBlocksPageId] = $Mpc->grabber->getLexicons($staticBlocksPageId, $Mpc->grabber->properties['basePathToLexiconFile']);

        foreach ($config as $item) {
            $result = $this->filterByPrefix($Mpc->grabber->lexicons[$typeResourceId], $item['lexicon_prefix'] ?? $item['MIGX_formname']);
            if ($item['is_static']) {
                $lexicons[$staticBlocksPageId] = array_merge($result, $lexicons[$staticBlocksPageId]);
            } else {
                $lexicons[$rid] = array_merge($result, $lexicons[$rid]);
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
