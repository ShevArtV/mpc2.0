<?php
/**
 * Мёртвые ключи словаря: список кандидатов и их удаление.
 *
 * Нарезка больше не удаляет ключи, которых не встретила (иначе сбой разбора
 * вёрстки уносил принятые переводы), а записывает их в реестр `.orphan`.
 * Удаление — отдельное явное действие: здесь показывается список и удаляется
 * только то, что менеджер отметил, с бэкапом и сверкой значения.
 *
 *  - mode=list  — кандидаты по всем языкам (или по одному, если задан lang);
 *  - mode=prune — удаление отмеченных (требует прав на запись).
 */
class MigxpageconfiguratorLexiconsOrphansProcessor extends modProcessor
{
    public function checkPermissions()
    {
        // Список — чтение словаря; удаление — те же права, что у импорта:
        // операция разрушающая и идёт сразу по нескольким ресурсам.
        return $this->getProperty('mode', 'list') === 'prune'
            ? $this->modx->hasPermission('mpc_lexicon_manage') && $this->modx->hasPermission('save_document')
            : $this->modx->hasPermission('mpc_view');
    }

    public function process()
    {
        $this->modx->lexicon->load('migxpageconfigurator:default');

        $corePath = $this->modx->getOption('migxpageconfigurator_core_path', null,
            $this->modx->getOption('core_path') . 'components/migxpageconfigurator/');
        require_once $corePath . 'services/vendor/autoload.php';

        $service = \MpcServices\Handlers\Lexicon\LexiconBatchService::fromModx($this->modx);
        $lang    = basename((string)$this->getProperty('lang', ''));
        $langs   = $lang !== '' ? [$lang] : $service->store()->languages();

        return $this->getProperty('mode', 'list') === 'prune'
            ? $this->doPrune($service, $langs)
            : $this->doList($service, $langs);
    }

    private function doList(\MpcServices\Handlers\Lexicon\LexiconBatchService $service, array $langs)
    {
        $rows = [];
        foreach ($langs as $l) {
            foreach ($service->orphans()->all((string)$l) as $rid => $entries) {
                foreach ($entries as $key => $meta) {
                    $rows[] = [
                        'address' => (string)$l . '|' . $rid . '|' . $key,
                        'lang'    => (string)$l,
                        'rid'     => (string)$rid,
                        'key'     => (string)$key,
                        'value'   => (string)($meta['value'] ?? ''),
                        'seen_at' => (string)($meta['seen_at'] ?? ''),
                    ];
                }
            }
        }
        return $this->success('', ['rows' => $rows, 'total' => count($rows)]);
    }

    private function doPrune(\MpcServices\Handlers\Lexicon\LexiconBatchService $service, array $langs)
    {
        // Адреса вида lang|rid|key — ровно те, что отдал список.
        $raw = $this->getProperty('addresses', '[]');
        $addresses = is_array($raw) ? $raw : json_decode((string)$raw, true);
        if (!is_array($addresses) || empty($addresses)) {
            return $this->failure($this->modx->lexicon('mpc_err_missing_params'));
        }

        $byLang = [];
        foreach ($addresses as $address) {
            $parts = explode('|', (string)$address, 3);
            if (count($parts) !== 3) {
                continue;
            }
            $byLang[basename($parts[0])][basename($parts[1])][] = $parts[2];
        }

        $cleared = 0;
        $stale   = 0;
        $backups = [];
        foreach ($byLang as $l => $only) {
            $res      = $service->prune((string)$l, $only, false);
            $cleared += (int)($res['cleared'] ?? 0);
            $stale   += count($res['stale'] ?? []);
            if (!empty($res['backup'])) {
                $backups[] = (string)$res['backup'];
            }
        }

        $this->modx->getCacheManager()->refresh(['lexicon_topics' => []]);

        return $this->success(
            'Удалено ключей: ' . $cleared
            . ($stale > 0 ? ', пропущено изменённых: ' . $stale : ''),
            ['cleared' => $cleared, 'stale' => $stale, 'backups' => $backups]
        );
    }
}
return 'MigxpageconfiguratorLexiconsOrphansProcessor';
