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

        $tplData['content'] = !empty($tplData['include'])
            ? "{include '{$tplData['include']}'}"
            : "{include 'file:pages/index.tpl'}";

        $wrapperName         = $tplData['wrapper'] ?? 'wrapper';
        $tplData['description'] = "@FILE {$this->properties['pathToSections']}$wrapperName{$this->properties['extension']}";

        if (empty($tplData['icon'])) {
            $tplData['icon'] = 'icon-gears';
        }

        $Template = new Template($this->modx);
        if (!$template = $Template->update($tplData)) {
            return null;
        }

        if (!$resource = $this->modx->getObject('modResource', [
            'pagetitle' => 'Шаблон ' . $tplData['templatename'],
            'parent'    => $this->properties['staticBlocksPageId'],
        ])) {
            $resource = $this->modx->newObject('modResource');
        }

        $resource->fromArray([
            'pagetitle' => 'Шаблон ' . $tplData['templatename'],
            'parent'    => $this->properties['staticBlocksPageId'],
            'template'  => $template->get('id'),
            'alias'     => $resource->cleanAlias('tpl-' . mb_strtolower($tplData['templatename'])),
            'hidemenu'  => 1,
        ]);

        if (!$resource->save()) {
            return null;
        }

        $Template->addTemplateVariables($template->get('id'), $tplData['template_var_ids']);

        return $resource;
    }
}
