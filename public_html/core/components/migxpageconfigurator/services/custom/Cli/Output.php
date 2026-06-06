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
