<?php

namespace MpcServices\Cli;

/**
 * Вывод CLI: человекочитаемый текст или JSON (--json). Накапливает строки/итог
 * и печатает в STDOUT/STDERR. Коды выхода: 0 ок, 1 ошибка.
 */
class Output
{
    private bool $json;
    private bool $quiet;

    public function __construct(bool $json = false, bool $quiet = false)
    {
        $this->json  = $json;
        $this->quiet = $quiet;
    }

    public function line(string $msg): void
    {
        if (!$this->json && !$this->quiet) {
            fwrite(STDOUT, $msg . PHP_EOL);
        }
    }

    public function info(string $msg): void { $this->line($msg); }

    /** Печать результата и возврат кода выхода. $result: ['success','message','data'] */
    public function result(array $result): int
    {
        $ok = !empty($result['success']);
        if ($this->json) {
            fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL);
            return $ok ? 0 : 1;
        }
        $msg = (string)($result['message'] ?? '');
        if ($msg !== '') {
            fwrite($ok ? STDOUT : STDERR, ($ok ? '' : 'ОШИБКА: ') . $msg . PHP_EOL);
        }
        return $ok ? 0 : 1;
    }

    /** Печать списка настроек таблицей (колонки по наличию данных). */
    public function settings(array $rows): void
    {
        if ($this->json || $this->quiet || empty($rows)) {
            return;
        }

        // Набор колонок зависит от того, что есть в строках (context/namespace/group).
        $cols = ['key' => 'KEY'];
        $hasCtx = $hasNs = $hasGroup = false;
        foreach ($rows as $r) {
            $hasCtx   = $hasCtx   || (isset($r['context']) && $r['context'] !== '');
            $hasNs    = $hasNs    || array_key_exists('namespace', $r);
            $hasGroup = $hasGroup || array_key_exists('group_id', $r);
        }
        if ($hasCtx)   { $cols['context'] = 'CONTEXT'; }
        if ($hasNs)    { $cols['namespace'] = 'NAMESPACE'; }
        if ($hasGroup) { $cols['group_id'] = 'GROUP'; }
        $cols['value'] = 'VALUE';

        // Ячейки (value усекается), затем ширины колонок.
        $table = [];
        foreach ($rows as $r) {
            $cells = [];
            foreach ($cols as $field => $_) {
                $v = (string)($r[$field] ?? '');
                if ($field === 'value' && mb_strlen($v) > 60) {
                    $v = mb_substr($v, 0, 59) . '…';
                }
                $cells[$field] = $v;
            }
            $table[] = $cells;
        }
        $w = [];
        foreach ($cols as $field => $label) {
            $w[$field] = mb_strlen($label);
            foreach ($table as $cells) {
                $w[$field] = max($w[$field], mb_strlen($cells[$field]));
            }
        }

        $render = function (array $cells) use ($cols, $w): string {
            $parts = [];
            foreach ($cols as $field => $_) {
                $parts[] = $this->pad($cells[$field], $w[$field]);
            }
            return '  ' . rtrim(implode('  ', $parts));
        };

        $header = $sep = [];
        foreach ($cols as $field => $label) {
            $header[$field] = $label;
            $sep[$field] = str_repeat('-', $w[$field]);
        }
        $this->line($render($header));
        $this->line($render($sep));
        foreach ($table as $cells) {
            $this->line($render($cells));
        }
    }

    /** mb-безопасное дополнение строки пробелами справа до ширины. */
    private function pad(string $s, int $width): string
    {
        return $s . str_repeat(' ', max(0, $width - mb_strlen($s)));
    }

    /** Печать плана (список действий) человекочитаемо. */
    public function plan(array $actions): void
    {
        if ($this->json || $this->quiet) {
            return;
        }
        foreach ($actions as $a) {
            $verb = (string)($a['action'] ?? '');
            $tag  = ['create' => '+ создать', 'update' => '~ обновить', 'skip' => '= без изменений',
                     'bind' => '+ привязать', 'unbind' => '- отвязать', 'remove' => '- удалить',
                     'install' => '+ установить'][$verb] ?? $verb;
            $this->line(sprintf('  %-16s %s', $tag, (string)($a['ref'] ?? $a['label'] ?? '')));
        }
    }
}
