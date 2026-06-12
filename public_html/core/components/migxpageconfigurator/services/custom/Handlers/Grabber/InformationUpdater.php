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
            $infoKey = (string)$item->getAttribute('data-mpc-info');
            // data-mpc-ctx нет → системная/ClientConfig; есть → контекстная (пустое
            // значение атрибута = текущий контекст).
            $ctx = $item->hasAttribute('data-mpc-ctx')
                ? ($item->getAttribute('data-mpc-ctx') ?: ($this->modx->context->get('key') ?: 'web'))
                : null;

            // Значение по виду элемента: link → href, img → src, иначе содержимое
            // (DiDom: text(); nodeValue у element-ноды по DOM-спеке всегда null).
            switch ($item->tagName()) {
                case 'link': $value = (string)$item->getAttribute('href'); break;
                case 'img':  $value = (string)$item->getAttribute('src'); break;
                default:     $value = (string)$item->text(); break;
            }

            $res = $this->saveSetting($infoKey, $value, $ctx);
            if (empty($res['success'])) {
                $this->modx->log(\modX::LOG_LEVEL_WARN, '[MPC info] ' . ($res['message'] ?? 'не сохранено') . ': ' . $infoKey);
            }
        }
    }

    /**
     * Записать значение служебной настройки по ключу (data-mpc-info) в системную /
     * контекстную / ClientConfig-настройку. Пишет только в СУЩЕСТВУЮЩИЕ настройки
     * (как и грабер). Защищённые (isProtectedSetting) — отказ. Переиспользуется
     * грабером и фронт-редактором (mpcVisualEditor → info/save).
     *
     * @param string|null $ctx null/'' → системная (или ClientConfig); иначе контекстная.
     * @return array ['success'=>bool, 'message'=>string]
     */
    public function saveSetting(string $key, string $value, ?string $ctx = null): array
    {
        if ($key === '' || $this->isProtectedSetting($key)) {
            return ['success' => false, 'message' => 'защищённая или пустая настройка'];
        }
        // Ломаем Fenom `{` и MODX-теги `[[`, чтобы значение не исполнялось при рендере.
        $value  = str_replace(['{', '[['], ['{ ', '[ ['], $value);
        $ctxKey = ($ctx === null || $ctx === '') ? '' : $ctx;

        if ($ctxKey === '') {
            $setting = $this->modx->getObject('modSystemSetting', ['key' => $key])
                ?: $this->getClientConfigSetting($key);
        } else {
            $setting = $this->modx->getObject('modContextSetting', ['key' => $key, 'context_key' => $ctxKey])
                ?: $this->modx->getObject('modSystemSetting', ['key' => $key])
                ?: $this->getClientConfigSetting($key, $ctxKey);
        }
        if (!$setting) {
            return ['success' => false, 'message' => 'настройка не найдена'];
        }

        $setting->fromArray(['context' => $ctxKey, 'context_key' => $ctxKey, 'key' => $key, 'value' => $value], '', true);
        if (!$setting->save()) {
            return ['success' => false, 'message' => 'не удалось сохранить настройку'];
        }
        // Сброс системного кэша — чтобы $_modx->config подхватил новое значение.
        if (method_exists($this->modx, 'getCacheManager') && ($cm = $this->modx->getCacheManager())) {
            $cm->refresh(['system_settings' => []]);
        }
        return ['success' => true, 'message' => 'ok'];
    }

    /**
     * Мета настройки для редактора: эффективное значение + xtype (тип виджета) ИЗ
     * БД (modSystemSetting / ClientConfig cgSetting). Единый источник типа — не
     * вёрстка, поэтому работает и для настроек, удалённых из страницы
     * (data-mpc-remove). null — настройки нет (нельзя править из редактора).
     *
     * @return array|null ['value'=>string, 'xtype'=>string, 'source'=>'system'|'clientconfig', 'protected'=>bool]
     */
    public function settingMeta(string $key, ?string $ctx = null): ?array
    {
        if ($key === '') {
            return null;
        }
        $sys = $this->modx->getObject('modSystemSetting', ['key' => $key]);
        $cg  = $sys ? null : $this->getClientConfigSetting($key);
        if (!$sys && !$cg) {
            return null;
        }
        $xtype = (string)($sys ? $sys->get('xtype') : $cg->get('xtype'));
        // Эффективное значение (контекст коннектора учитывается getOption).
        $fallback = $sys ? $sys->get('value') : $cg->get('value');
        $value = $this->modx->getOption($key, null, $fallback);
        return [
            'value'     => (string)($value ?? ''),
            'xtype'     => $xtype !== '' ? $xtype : 'textfield',
            'source'    => $sys ? 'system' : 'clientconfig',
            'protected' => $this->isProtectedSetting($key),
        ];
    }

    /**
     * Защита: настройки безопасности нельзя перезаписывать через контент
     * (data-mpc-info). Blacklist префиксов/подстрок security-критичных ключей.
     * Для более строгой модели — whitelist через системную настройку.
     */
    private function isProtectedSetting(string $key): bool
    {
        return $key === '' || (bool)preg_match(
            '/(allow_manager|allow_forward|forgot|session|password|principal_targets|rb_base|manager_|filemanager|allow_multiple_emails|proxy|http_host|allow_tags_in|mail_|modx_charset|^cache_|register_enabled)/i',
            $key
        );
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
