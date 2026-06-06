<?php

namespace MpcServices\Cli;

/**
 * Разбор argv в (группа, действие, позиционные аргументы, опции). PURE,
 * юнит-тестируемо. Поддержка форм:
 *   mpc <group> <action> [pos...] [--key=val] [--key] [--key val]
 *   mpc <group>:<action> ...
 * Опции: `--key=value`, `--key value`, флаг `--key` (=true). `--` завершает опции.
 */
class ArgvParser
{
    /** Опции, которые ВСЕГДА булевы (следующий токен не съедается как значение). */
    private const BOOL_FLAGS = ['force', 'json', 'dry-run', 'help', 'h', 'quiet', 'yes'];

    /**
     * @param array $argv весь $argv (argv[0] — имя скрипта, игнорируется)
     * @return array{group:string, action:string, args:string[], opts:array<string,mixed>}
     */
    public static function parse(array $argv): array
    {
        $tokens = array_slice($argv, 1);

        $group = '';
        $action = '';
        $args = [];
        $opts = [];

        // первый непустой не-опционный токен → group (и, возможно, group:action)
        $i = 0;
        $n = count($tokens);
        $noMoreOpts = false;

        // группа/действие — первые позиционные токены ДО опций
        while ($i < $n) {
            $t = $tokens[$i];
            if ($t === '--') { $noMoreOpts = true; $i++; break; }
            if (strncmp($t, '--', 2) === 0 || $group !== '' && $action !== '') {
                break;
            }
            if ($group === '') {
                if (strpos($t, ':') !== false) {
                    [$group, $action] = array_pad(explode(':', $t, 2), 2, '');
                } else {
                    $group = $t;
                }
            } elseif ($action === '') {
                $action = $t;
            }
            $i++;
        }

        for (; $i < $n; $i++) {
            $t = $tokens[$i];
            if (!$noMoreOpts && $t === '--') { $noMoreOpts = true; continue; }

            if (!$noMoreOpts && strncmp($t, '--', 2) === 0) {
                $body = substr($t, 2);
                if ($body === '') { continue; }
                if (strpos($body, '=') !== false) {
                    [$k, $v] = explode('=', $body, 2);
                    $opts[$k] = $v;
                    continue;
                }
                // флаг или `--key value`
                if (in_array($body, self::BOOL_FLAGS, true)) {
                    $opts[$body] = true;
                    continue;
                }
                $next = $tokens[$i + 1] ?? null;
                if ($next !== null && strncmp($next, '--', 2) !== 0) {
                    $opts[$body] = $next;
                    $i++;
                } else {
                    $opts[$body] = true;
                }
                continue;
            }

            $args[] = $t;
        }

        return ['group' => $group, 'action' => $action, 'args' => $args, 'opts' => $opts];
    }
}
