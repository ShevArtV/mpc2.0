<?php

namespace MpcServices\Handlers;

/**
 * Сайдкар-реестр НЕПЕРЕВЕДЁННЫХ ключей лексикона по языкам (подход «явный
 * pending-list»). Источник истины для экспорта «только непереведённое»: вместо
 * эвристики `lang[key] === default[key]` (ложные срабатывания на одинаковых
 * терминах — Telegram/Email/имена собственные) ведём явный список ключей,
 * требующих перевода.
 *
 * Жизненный цикл ключа:
 *  - синк языков на нарезке ({@see Grabber\LexiconManager::syncOtherLanguages})
 *    добавляет НОВЫЙ ключ (его раньше не было в файле языка) в pending;
 *  - ввод перевода в CMP (updatekey) / импорт XLSX снимает ключ с pending;
 *  - удалённый из дефолтного набора ключ (orphan) выкидывается на синке.
 *
 * Хранилище: `<lexiconBase>/<lang>/.pending/<rid>.json` — JSON-массив ключей.
 * Точка-префикс и `.json` делают файлы невидимыми для существующих сканов
 * лексиконов (они фильтруют `*.inc.php` и `GLOB_ONLYDIR` по языковым папкам).
 *
 * Поведение forward-looking: ключи, уже лежавшие в файлах языка ДО внедрения
 * реестра (value==default), в pending не попадают — реестр наполняется новыми
 * ключами с момента включения. Это сознательный компромисс выбранного подхода.
 *
 * PURE file-IO, без зависимости от modX — юнит-тестируемо.
 */
class PendingTranslations
{
    private string $lexiconBasePath;

    public function __construct(string $lexiconBasePath)
    {
        $this->lexiconBasePath = rtrim($lexiconBasePath, '/') . '/';
    }

    /** Путь к сайдкар-файлу pending для языка/ресурса. */
    private function path(string $lang, string $rid): string
    {
        // Компоненты пути приходят из вызывающего кода — отсекаем traversal.
        // basename безопасен: lang = [a-z]{2,8}, rid санитизируется до
        // [a-zA-Z0-9_-] выше по стеку, так что для валидных значений это no-op.
        return $this->lexiconBasePath . basename($lang) . '/.pending/' . basename($rid) . '.json';
    }

    /** Список непереведённых ключей языка/ресурса (пусто, если файла нет). */
    public function load(string $lang, string $rid): array
    {
        $p = $this->path($lang, $rid);
        if (!is_file($p)) {
            return [];
        }
        $data = json_decode((string)file_get_contents($p), true);
        if (!is_array($data)) {
            return [];
        }
        return array_values(array_filter($data, 'is_string'));
    }

    /** Перезаписать список (пустой → удалить сайдкар-файл). */
    public function save(string $lang, string $rid, array $keys): void
    {
        $keys = array_values(array_unique(array_filter($keys, 'is_string')));
        $p    = $this->path($lang, $rid);

        if (empty($keys)) {
            if (is_file($p)) {
                unlink($p);
            }
            return;
        }

        $dir = dirname($p);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($p, json_encode($keys, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
    }

    /** Снять ключ с pending (перевод введён). No-op, если ключа там нет. */
    public function remove(string $lang, string $rid, string $key): void
    {
        $keys     = $this->load($lang, $rid);
        $filtered = array_values(array_filter($keys, static fn($k) => $k !== $key));
        if (count($filtered) !== count($keys)) {
            $this->save($lang, $rid, $filtered);
        }
    }

    /**
     * Пересчёт pending на синке языков. Ключ остаётся/становится pending, если он
     * НОВЫЙ (есть в дефолтном наборе, но не было в файле языка) ИЛИ уже был
     * pending и всё ещё присутствует в дефолтном наборе. Orphan-ключи (pending,
     * но ушли из дефолтного набора) выкидываются.
     *
     * @param string[] $defaultKeys  ключи дефолтного языка (полный набор после нарезки)
     * @param string[] $existingKeys ключи, что были в файле ЭТОГО языка ДО синка
     */
    public function sync(string $lang, string $rid, array $defaultKeys, array $existingKeys): void
    {
        $prev     = array_flip($this->load($lang, $rid));
        $existing = array_flip($existingKeys);

        $out = [];
        foreach ($defaultKeys as $k) {
            if (!isset($existing[$k]) || isset($prev[$k])) {
                $out[] = $k;
            }
        }
        $this->save($lang, $rid, $out);
    }
}
