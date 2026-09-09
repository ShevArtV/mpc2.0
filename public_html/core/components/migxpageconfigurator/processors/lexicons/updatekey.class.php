<?php
/**
 * Updates a single lexicon key in one language file.
 */
class MigxpageconfiguratorLexiconsUpdatekeyProcessor extends modProcessor
{
    /**
     * Правка ключа словаря — пишущая операция: требуется mpc_lexicon_manage.
     * Одного mpc_view (право «посмотреть словарь») недостаточно.
     * Коннектор проверяет лишь сессию.
     */
    public function checkPermissions()
    {
        return $this->modx->hasPermission('mpc_lexicon_manage');
    }

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

        // Validate lang looks like a language code (anchored)
        if (!preg_match('/^[a-z]{2,8}$/', $lang)) {
            return $this->failure($this->modx->lexicon('mpc_err_invalid_lang'));
        }

        $lexiconBase = $this->modx->getOption('core_path')
            . $this->modx->getOption('mpc_lexicon_path', null, 'components/migxpageconfigurator/lexicon/');

        $corePath = $this->modx->getOption('migxpageconfigurator_core_path', null,
            $this->modx->getOption('core_path') . 'components/migxpageconfigurator/');
        require_once $corePath . 'services/vendor/autoload.php';

        // Запись идёт через общий писатель: та же блокировка и та же сверка
        // ожидаемого значения, что у импорта книги. Свойство `expected` — то
        // значение, которое видел менеджер в форме; если файл с тех пор
        // изменился, правка НЕ применяется и возвращается актуальное значение.
        $service  = \MpcServices\Handlers\Lexicon\LexiconBatchService::fromModx($this->modx);
        $store    = $service->store();
        $hasExpected = $this->getProperty('expected', null) !== null;

        $result = $store->withLock(function ($s) use ($lang, $filename, $key, $value, $hasExpected) {
            $current = $s->read($lang, $filename);
            $actual  = array_key_exists($key, $current) ? (string)$current[$key] : null;
            if ($hasExpected) {
                $expected = (string)$this->getProperty('expected', '');
                if ($actual !== $expected) {
                    return ['stale' => true, 'actual' => $actual];
                }
            }
            $op = [
                'lang' => $lang, 'rid' => $filename, 'key' => $key,
                'current' => $actual, 'desired' => $value,
                'action' => \MpcServices\Handlers\Lexicon\LexiconMerge::isClear($value)
                    ? \MpcServices\Handlers\Lexicon\LexiconMerge::CLEAR
                    : \MpcServices\Handlers\Lexicon\LexiconMerge::WRITE,
                'reason' => 'updatekey',
            ];
            return $s->apply([$op], ['tag' => 'updatekey']);
        });

        if (!empty($result['stale'])) {
            // Устаревшая форма: значение поменял другой писатель.
            return $this->failure(
                $this->modx->lexicon('mpc_err_lexicon_stale'),
                ['actual' => $result['actual']]
            );
        }
        if (!empty($result['failed'])) {
            return $this->failure($this->modx->lexicon('mpc_err_write_failed'));
        }

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
