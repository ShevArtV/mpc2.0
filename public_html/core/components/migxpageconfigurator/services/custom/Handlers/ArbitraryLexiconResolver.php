<?php

namespace MpcServices\Handlers;

/**
 * Резолв топик-файла для произвольного лексиконного ключа при правке из
 * визуального редактора.
 *
 * Логика (как задал автор):
 *   1. topic указан → ищем ТОЛЬКО в нём; иначе сканируем топики из настройки
 *      mpc_arbitrary_lexicon_topics.
 *   2. Ищем ключ в файле ТЕКУЩЕГО языка → нашли: пишем туда.
 *   3. Не нашли → ищем в ДЕФОЛТНОМ языке → нашли: ключ создаём в одноимённом
 *      топике текущего языка (перевод). 'fromDefault' = true.
 *   4. Нет нигде → null («Ключ не найден»).
 *
 * Только чтение файлов; запись делает FieldWriter через LexiconWriter::set.
 */
final class ArbitraryLexiconResolver
{
    private string $base;
    private string $default;
    /** @var string[] */
    private array $topics;

    /**
     * @param string   $base    corePath + lexiconPath (каталог с подпапками-языками)
     * @param string   $default культура языка по умолчанию
     * @param string[] $topics  список топиков для скана (без явного топика)
     */
    public function __construct(string $base, string $default, array $topics)
    {
        $this->base    = rtrim($base, '/') . '/';
        $this->default = $default;
        $this->topics  = array_values(array_filter(
            array_map('strval', $topics),
            [ArbitraryLexicon::class, 'validTopic']
        ));
    }

    /**
     * @return array{topic:string,fromDefault:bool}|null null — ключ не найден
     */
    public function resolve(string $topic, string $key, string $culture): ?array
    {
        if (!ArbitraryLexicon::validKey($key)) {
            return null;
        }
        $candidates = ($topic !== '' && ArbitraryLexicon::validTopic($topic))
            ? [$topic]
            : $this->topics;

        // 1. Текущий язык.
        foreach ($candidates as $t) {
            if (array_key_exists($key, $this->load($t, $culture))) {
                return ['topic' => $t, 'fromDefault' => false];
            }
        }
        // 2. Дефолтный язык → создаём в текущем.
        if ($culture !== $this->default) {
            foreach ($candidates as $t) {
                if (array_key_exists($key, $this->load($t, $this->default))) {
                    return ['topic' => $t, 'fromDefault' => true];
                }
            }
        }
        return null;
    }

    /** Ключи топик-файла указанного языка ($_lang). [] — файла нет. */
    private function load(string $topic, string $culture): array
    {
        $culture = basename($culture); // отсекаем traversal из cookie mpc_lang
        $path = $this->base . $culture . '/' . $topic . '.inc.php';
        if (!is_file($path)) {
            return [];
        }
        $_lang = [];
        include $path;
        return is_array($_lang) ? $_lang : [];
    }
}
