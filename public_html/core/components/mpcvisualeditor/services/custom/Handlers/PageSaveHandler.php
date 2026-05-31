<?php

namespace MpcVEServices\Handlers;

use MpcVEServices\Mpcve;

/**
 * Экшен page/save: визуальный редактор присылает ОТРЕДАКТИРОВАННЫЙ HTML
 * секций (со всеми data-mpc-* маркерами) — мы пишем его во временный src-файл
 * и прогоняем через ГРАБЕР mpc (тот же путь, что mgr_tpl), но без каттера/
 * render-перенарезки. Грабер сам разберёт значения по типам полей, обработает
 * лексиконы/медиа и перезапишет mpc_config (static-секции → staticBlocksPage).
 * Затем регенерим прод-parsed текущего ресурса (не-статичные правки baked в
 * parsed; static — живые через getStaticSection).
 *
 * Почему весь HTML, а не одно поле: handleSections перезаписывает mpc_config
 * целиком из секций присланного HTML — частичная отправка стёрла бы остальные
 * секции. Это и есть «имитация чтения файла» как в mgr_tpl.
 */
class PageSaveHandler
{
    private \modX $modx;
    private Mpcve $mpcve;

    public function __construct(\modX $modx, Mpcve $mpcve)
    {
        $this->modx = $modx;
        $this->mpcve = $mpcve;
    }

    public function handle(array $request): array
    {
        $rid  = (int)($request['resourceId'] ?? 0);
        $html = (string)($request['html'] ?? '');

        if ($rid <= 0 || $html === '') {
            return $this->err('resourceId и html обязательны');
        }
        if (!class_exists('\\MpcServices\\Mpc')) {
            return $this->err('требуется migxpageconfigurator (mpc)');
        }
        $resource = $this->modx->getObject('modResource', $rid);
        if (!$resource) {
            return $this->err('ресурс не найден: ' . $rid);
        }

        $Mpc = new \MpcServices\Mpc($this->modx);
        $grabber = $Mpc->grabber;

        $srcDir = rtrim((string)$grabber->properties['pdotoolsElementsPath'], '/\\') . '/'
            . trim((string)$grabber->properties['pathToSrc'], '/\\') . '/';
        if (!is_dir($srcDir)) {
            return $this->err('src-каталог не найден: ' . $srcDir);
        }

        $ext     = (string)$this->modx->getOption('mpc_tpl_file_extension', null, '.tpl');
        $tmpName  = '_mpcve_' . $rid . '_' . uniqid() . $ext;
        $tmpPath  = $srcDir . $tmpName;

        if (@file_put_contents($tmpPath, $html) === false) {
            return $this->err('не удалось записать временный файл: ' . $tmpPath);
        }

        try {
            $grabber->updContent = true;
            $grabber->setTargetResource($resource);
            $grabber->handle($tmpName);
            // прод-parsed для не-статичных правок (static резолвится живым getStaticSection)
            $Mpc->render->handle($resource->toArray());
        } catch (\Throwable $e) {
            @unlink($tmpPath);
            $this->modx->log(\modX::LOG_LEVEL_ERROR, '[mpcVE] page/save grab failed: ' . $e->getMessage());
            return $this->err('ошибка обработки: ' . $e->getMessage());
        }
        @unlink($tmpPath);

        return ['success' => true, 'message' => 'Сохранено', 'data' => ['resourceId' => $rid]];
    }

    private function err(string $message): array
    {
        return ['success' => false, 'message' => $message, 'data' => []];
    }
}
