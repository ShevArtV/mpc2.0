<?php
/**
 * Сервис для обработки события pdoToolsOnFenomInit
 * Для добавления нового модификатора необходимо добавить его в массив $modifiers и создать новый приватный метод с таким же именем.
 */

namespace MpcServices\Plugins;

use MpcServices\Mpc;

/**
 * @author Arthur Shevchenko (https://t.me/ShevArtV)
 */
class pdoToolsOnFenomInit extends PluginHandler
{
    /**
     * @var \Fenom $fenom
     */
    private \Fenom $fenom;

    private array $modifiers = [
        'version',
        'include',
        'lexicon',
        'reslexicons',
        'lexiconsarr',
    ];

    public function run()
    {
        if (!$this->fenom = $this->scriptProperties['fenom']) {
            return;
        }
        $this->addModifiers();

    }

    private function addModifiers()
    {
        if (!empty($this->modifiers)) {
            foreach ($this->modifiers as $modifier) {
                if (method_exists($this, $modifier)) {
                    $this->fenom->addModifier($modifier, function (...$args) use ($modifier) {
                        return $this->$modifier($args);
                    });
                }
            }
        }
    }

    private function lexicon($args)
    {
        $key = $args[0];
        $language = $args[1] ?? $this->modx->getOption('cultureKey');
        $params = $args[2] ?? [];
        $result = $this->modx->lexicon($key, $params, $language);
        if($result === $key){
            return '';
        }
        return $result;
    }

    private function reslexicons($args)
    {
        $id = $args[0];
        $template = $args[1];
        // Request-scoped singleton: модификатор зовётся per-resource (десятки раз
        // на каталоге), а конструктор Mpc дорогой. См. Mpc::instance().
        Mpc::instance($this->modx)->loadLexicons($id, $template);
    }

    private function lexiconsarr($args)
    {
        $id = $args[0];
        $template = $args[1];
        return Mpc::instance($this->modx)->getResourceLexicons($id, $template);
    }

    private function version($args)
    {
        $path = $args[0];
        $dir = $args[1] ?? 'basePath';
        // whitelist свойства: иначе $this->$dir дал бы доступ к любому свойству
        // (modx, scriptProperties, …) через аргумент Fenom.
        if (!in_array($dir, ['basePath', 'corePath'], true)) {
            $dir = 'basePath';
        }
        $filepath = ($this->$dir ?? '') . rtrim(trim($path), '/');
        if (file_exists($filepath)) {
            $path .= '?v=' . date('dmYHis', filemtime($filepath));
        }
        return $path;
    }

    private function include($args)
    {
        $path = (string)$args[0];
        // Читаем ПРОВЕРЕННЫЙ путь внутри corePath (а не сырой $path) и держим
        // границу каталога через realpath — иначе LFI (../../, абсолютный путь).
        $filepath = rtrim($this->corePath, '/') . '/' . ltrim($path, '/');
        $real     = realpath($filepath);
        $baseReal = realpath($this->corePath);
        if ($real !== false && $baseReal !== false
            && strpos($real, rtrim($baseReal, '/') . '/') === 0 && is_file($real)) {
            return str_replace('##', '{', (string)file_get_contents($real));
        }
        return '';
    }
}
