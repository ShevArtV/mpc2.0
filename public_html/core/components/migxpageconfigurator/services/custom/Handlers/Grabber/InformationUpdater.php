<?php

namespace MpcServices\Handlers\Grabber;

use MpcServices\Handlers\Parser;

/**
 * Обновление системных/контекстных настроек по [data-mpc-info].
 */
class InformationUpdater
{
    private \modX  $modx;
    private array  $properties;
    private Parser $parser;

    public function __construct(\modX $modx, array $properties, Parser $parser)
    {
        $this->modx       = $modx;
        $this->properties = $properties;
        $this->parser     = $parser;
    }

    public function handleInformation(string $html, bool $updContent): void
    {
        if (!$updContent) {
            return;
        }

        $items = $this->parser->findByAttribute($html, '[data-mpc-info]');
        if (!count($items)) {
            return;
        }

        foreach ($items as $item) {
            $infoKey = $item->getAttribute('data-mpc-info');
            $ctx     = $item->getAttribute('data-mpc-ctx') ?: $this->modx->context->get('key') ?: 'web';

            if (!$item->hasAttribute('data-mpc-ctx')) {
                if (!$setting = $this->modx->getObject('modSystemSetting', ['key' => $infoKey])) {
                    if (!$setting = $this->getClientConfigSetting($infoKey)) {
                        continue;
                    }
                }
            } else {
                if (!$setting = $this->modx->getObject('modContextSetting', ['key' => $infoKey, 'context_key' => $ctx])) {
                    if (!$setting = $this->modx->getObject('modSystemSetting', ['key' => $infoKey])) {
                        if (!$setting = $this->getClientConfigSetting($infoKey, $ctx)) {
                            continue;
                        }
                    }
                }
            }

            $data = ['context' => $ctx, 'context_key' => $ctx, 'key' => $infoKey, 'value' => ''];

            switch ($item->tagName()) {
                case 'link':
                    $data['value'] = $item->getAttribute('href');
                    break;
                case 'img':
                    $data['value'] = $item->getAttribute('src');
                    break;
                default:
                    $data['value'] = str_replace('{', '{ ', $item->nodeValue);
                    break;
            }

            $setting->fromArray($data, '', true);
            $setting->save();
        }
    }

    private function getClientConfigSetting(string $key, ?string $ctx = null): ?object
    {
        if ($ctx) {
            $q = $this->modx->newQuery('cgContextValue');
            $q->leftJoin('cgSetting', 'Setting');
            $q->where(['Setting.key' => $key, 'cgContextValue.context' => $ctx]);
            return $this->modx->getObject('cgContextValue', $q);
        }
        $q = $this->modx->newQuery('cgSetting');
        $q->where(['`key`' => $key]);
        return $this->modx->getObject('cgSetting', $q);
    }
}
