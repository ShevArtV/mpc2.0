<?php

namespace MpcServices\Handlers\Grabber;

use MpcServices\Processors\Template;

/**
 * Создание/обновление MODX-шаблона по данным из HTML-комментария <!--## ... ##-->.
 * Возвращает созданный/обновлённый ресурс или null.
 */
class TemplateUpdater
{
    private \modX $modx;
    private array $properties;

    public function __construct(\modX $modx, array $properties)
    {
        $this->modx       = $modx;
        $this->properties = $properties;
    }

    public function handleTemplate(string $html): ?object
    {
        preg_match('/<!--##(.*?)##-->/', $html, $tplDataJson);
        if (empty($tplDataJson[1])) {
            return null;
        }

        $tplData = json_decode($tplDataJson[1], true);
        if (!is_array($tplData) || empty($tplData) || empty($tplData['templatename'])) {
            return null;
        }

        // Значения из JSON-комментария вёрстки вставляются в Fenom — фильтруем,
        // чтобы кавычка/`}`/`$`/`..` не дали инъекцию Fenom или path traversal.
        $include = preg_replace('/[^a-zA-Z0-9_\/.:\-]/', '', (string)($tplData['include'] ?? ''));
        $include = str_replace('..', '', $include);
        $tplData['content'] = $include !== ''
            ? "{include '{$include}'}"
            : "{include 'file:pages/index.tpl'}";

        $wrapperName = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)($tplData['wrapper'] ?? '')) ?: 'wrapper';
        $tplData['description'] = "@FILE {$this->properties['pathToSections']}$wrapperName{$this->properties['extension']}";

        if (empty($tplData['icon'])) {
            $tplData['icon'] = 'icon-gears';
        }

        $Template = new Template($this->modx);

        // Атомарность: шаблон + тип-ресурс + TV. Без транзакции сбой
        // resource->save() оставлял «шаблон без типа». Все операции —
        // fromArray+save на одном соединении (Base::update), своих коммитов
        // нет → rollBack откатывает всё. $tx=false (соединение не поднялось)
        // → деградируем к прежнему не-транзакционному поведению.
        $tx = $this->modx->beginTransaction();

        if (!$template = $Template->update($tplData)) {
            if ($tx) {
                $this->modx->rollBack();
            }
            return null;
        }

        // Ресурс-тип страницы (class_key = mpcType): ищем по шаблону под коллекцией
        // (staticBlocksPage). Служебка хранится в mpc_type (type_name/file_name),
        // не в pagetitle/introtext.
        $typeName = 'Шаблон ' . $tplData['templatename'];
        if (!$resource = $this->modx->getObject('modResource', [
            'parent'   => $this->properties['staticBlocksPageId'],
            'template' => $template->get('id'),
        ])) {
            $resource = $this->modx->newObject('mpcType');
        }

        $resource->fromArray([
            'class_key' => 'mpcType',
            'pagetitle' => $typeName,
            'parent'    => $this->properties['staticBlocksPageId'],
            'template'  => $template->get('id'),
            'alias'     => $resource->cleanAlias('tpl-' . mb_strtolower($tplData['templatename'])),
            'hidemenu'  => 1,
            'type_name' => $typeName, // служебка → mpc_type (вместо pagetitle)
        ]);

        if (!$resource->save()) {
            if ($tx) {
                $this->modx->rollBack();
            }
            return null;
        }

        $Template->addTemplateVariables($template->get('id'), $tplData['template_var_ids']);

        if ($tx) {
            $this->modx->commit();
        }

        return $resource;
    }
}
