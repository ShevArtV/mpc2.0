<?php

namespace MpcServices\Handlers;

/**
 * Синхронизация лексикон-ключей между языками + ведение списка непереведённых
 * ({@see PendingTranslations}). ЕДИНЫЙ сервис для нарезки и редактора:
 *
 *  - нарезка вызывает {@see syncResource()} на каждый ресурс (полный набор
 *    ключей дефолтного языка → во все языки, новые ключи → pending);
 *  - редактор (FieldWriter) вызывает {@see syncKey()} при сохранении одного
 *    значения: перевод (не дефолтный язык) снимает ключ с pending; новый
 *    оригинал (дефолтный язык) распространяет ключ-плейсхолдер по языкам + pending.
 *
 * Значения приходят уже очищенными (из файла дефолтного языка либо после
 * LexiconWriter::set) — повторный sanitize не нужен, файл пишется безопасным
 * var_export.
 */
class LexiconSync
{
    private string $baseLexiconPath;
    private string $defaultLang;
    /** @var string[] */
    private array $languages;
    private PendingTranslations $pending;

    /**
     * @param string   $baseLexiconPath абсолютный путь к корню лексиконов (…/lexicon/)
     * @param string   $defaultLang     культура языка по умолчанию
     * @param string[] $languages       все доступные языки (mpc_available_languages)
     */
    public function __construct(string $baseLexiconPath, string $defaultLang, array $languages)
    {
        $this->baseLexiconPath = rtrim($baseLexiconPath, '/') . '/';
        $this->defaultLang     = $defaultLang;
        $this->languages       = array_values(array_filter(array_map('trim', $languages)));
        $this->pending         = new PendingTranslations($this->baseLexiconPath);
    }

    /** Языки кроме дефолтного. */
    private function otherLanguages(): array
    {
        return array_values(array_diff($this->languages, [$this->defaultLang, '']));
    }

    /** Прочитать лексикон языка/ресурса (пусто, если файла нет). */
    private function readLexicon(string $lang, string $identifier): array
    {
        $_lang = [];
        $file  = $this->baseLexiconPath . basename($lang) . '/' . basename($identifier) . '.inc.php';
        if (is_file($file)) {
            include $file;
        }
        return is_array($_lang) ? $_lang : [];
    }

    /** Записать лексикон языка/ресурса (пустой набор → удалить файл). */
    private function writeLexicon(string $lang, string $identifier, array $entries): void
    {
        $dir  = $this->baseLexiconPath . basename($lang) . '/';
        $file = $dir . basename($identifier) . '.inc.php';
        if (empty($entries)) {
            if (is_file($file)) {
                unlink($file);
            }
            return;
        }
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $content = '<?php' . PHP_EOL;
        foreach ($entries as $k => $v) {
            $content .= '$_lang[' . var_export((string)$k, true) . '] = '
                . var_export((string)$v, true) . ';' . PHP_EOL;
        }
        file_put_contents($file, $content, LOCK_EX);
    }

    /**
     * Полный синк одного ресурса (нарезка): набор ключей дефолтного языка
     * раскладывается по всем остальным языкам (существующие переводы сохраняются,
     * новых ключей значение = дефолтное как плейсхолдер), pending пересчитывается.
     *
     * @param string $identifier имя файла лексикона ресурса (alias/id)
     * @param array  $defaultLex полный набор key=>value дефолтного языка
     */
    public function syncResource(string $identifier, array $defaultLex): void
    {
        if (empty($defaultLex)) {
            return;
        }
        foreach ($this->otherLanguages() as $lang) {
            $existing = $this->readLexicon($lang, $identifier);

            $out = [];
            foreach ($defaultLex as $k => $defVal) {
                $out[$k] = array_key_exists($k, $existing) ? $existing[$k] : $defVal;
            }
            $this->writeLexicon($lang, $identifier, $out);

            $this->pending->sync(
                $lang,
                $identifier,
                array_map('strval', array_keys($defaultLex)),
                array_map('strval', array_keys($existing))
            );
        }
    }

    /**
     * Синхронизация одного ключа из редактора.
     *
     * @param string $identifier  имя файла лексикона ресурса
     * @param string $key         ключ лексикона
     * @param string $value       записанное значение (на дефолтном языке — оригинал)
     * @param string $currentLang язык, на котором сохраняли
     */
    public function syncKey(string $identifier, string $key, string $value, string $currentLang): void
    {
        // Перевод (не дефолтный язык) — значение уже записано в файл этого языка
        // вызывающим кодом; здесь снимаем ключ с pending (переведено).
        if ($currentLang !== $this->defaultLang) {
            $this->pending->remove($currentLang, $identifier, $key);
            return;
        }

        // Оригинал на дефолтном языке: новый ключ распространяем по остальным
        // языкам плейсхолдером (= значение дефолта) и помечаем непереведённым.
        // Существующий ключ не трогаем — чужие переводы сохраняются.
        foreach ($this->otherLanguages() as $lang) {
            $existing = $this->readLexicon($lang, $identifier);
            if (array_key_exists($key, $existing)) {
                continue;
            }
            $existing[$key] = $value;
            $this->writeLexicon($lang, $identifier, $existing);

            $keys   = $this->pending->load($lang, $identifier);
            $keys[] = $key;
            $this->pending->save($lang, $identifier, $keys);
        }
    }
}
