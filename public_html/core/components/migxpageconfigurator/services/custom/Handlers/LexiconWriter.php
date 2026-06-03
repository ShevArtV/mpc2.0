<?php

namespace MpcServices\Handlers;

use MpcServices\Handlers\Grabber\LexiconManager;

/**
 * Точечная запись значения лексикона из write-API (визуальный редактор).
 *
 * В лексиконном режиме поле mpc_config хранит КЛЮЧ, а перевод — в файле
 * `{core}/{lexiconPath}/{culture}/{identifier}.inc.php` как `$_lang[key]=value`.
 * Здесь обновляем ОДНУ запись read-modify-write, сохраняя остальные ключи
 * (в отличие от LexiconManager::createLexicons, который пересобирает файл с
 * $_rlang-фильтром — для апдейта опасно). Идентификатор файла, чтение и
 * санитайз переиспользуем из LexiconManager.
 */
class LexiconWriter
{
    private \modX $modx;
    private LexiconManager $lm;
    private string $basePath;

    /**
     * @param array $properties culture, corePath, lexiconPath, lexiconFilenameField,
     *                          allowedTags(array), allowModxTags(bool)
     */
    public function __construct(\modX $modx, array $properties)
    {
        $this->modx = $modx;
        $this->basePath = rtrim((string)$properties['corePath'], '/') . '/'
            . trim((string)$properties['lexiconPath'], '/') . '/'
            . (string)$properties['culture'] . '/';
        $this->lm = new LexiconManager($modx, [
            'lexiconFilenameField' => $properties['lexiconFilenameField'] ?? 'id',
            'allowedTags'          => $properties['allowedTags'] ?? [''],
            'allowModxTags'        => $properties['allowModxTags'] ?? false,
            'useLexicons'          => true,
        ]);
    }

    /** Идентификатор файла лексикона ресурса (id/alias/uri — как в гребере). */
    public function identifier(int $rid): string
    {
        return (string)$this->lm->getResourceIdentifierById($rid);
    }

    /** Есть ли уже такой ключ в файле лексикона ресурса (т.е. значение — ключ). */
    public function has(string $identifier, string $key): bool
    {
        if ($identifier === '' || $key === '') {
            return false;
        }
        return array_key_exists($key, $this->lm->getLexicons($identifier, $this->basePath));
    }

    /**
     * Полная карта key→value лексикона ресурса. Для показа ЗНАЧЕНИЙ (а не ключей)
     * в панели скрытых полей редактора. Пусто, если файла/идентификатора нет.
     */
    public function all(string $identifier): array
    {
        if ($identifier === '') {
            return [];
        }
        $map = $this->lm->getLexicons($identifier, $this->basePath);
        return is_array($map) ? $map : [];
    }

    /** Обновляет значение ключа, сохраняя остальные записи файла. */
    public function set(string $identifier, string $key, string $value): bool
    {
        if ($identifier === '' || $key === '') {
            return false;
        }
        $entries = $this->lm->getLexicons($identifier, $this->basePath);
        $entries[$key] = $value;

        $content = '<?php' . PHP_EOL;
        foreach ($entries as $k => $v) {
            $content .= '$_lang[\'' . $k . '\'] = \'' . $this->lm->sanitizeValue((string)$v) . '\';' . PHP_EOL;
        }

        if (!is_dir($this->basePath)) {
            @mkdir($this->basePath, 0755, true);
        }
        $ok = file_put_contents($this->basePath . $identifier . '.inc.php', $content) !== false;

        // Сброс кэша лексиконов — best-effort, на результат записи не влияет.
        if ($ok) {
            try {
                $cm = method_exists($this->modx, 'getCacheManager') ? $this->modx->getCacheManager() : null;
                if ($cm && method_exists($cm, 'refresh')) {
                    $cm->refresh(['lexicon_topics' => []]);
                }
            } catch (\Throwable $e) {
                // игнорируем: файл уже записан
            }
        }
        return $ok;
    }
}
