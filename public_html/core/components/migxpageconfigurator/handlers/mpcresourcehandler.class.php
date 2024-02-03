<?php

require_once dirname(__FILE__) . '/mpcbasehandler.class.php';

/**
 *
 */
class MpcResourceHandler extends MpcBaseHandler
{
    /**
     * @param array $properties
     * @return void
     */
    protected function setProperties(array $properties)
    {
        $this->properties['sbpId'] = (int)$this->modx->getOption('mpc_static_block_page_id', null, 1);
        $this->properties['configTvId'] = (int)$this->modx->getOption('mpc_config_tv_id', null, 1);
        $this->properties['commonConfigName'] = $this->modx->getOption('mpc_common_config_name', null, 'config');
        $this->properties['copyConfigTvname'] = $this->modx->getOption('mpc_copy_config_tvname', null, 'copy_sections');
        $this->properties = array_merge($this->properties, [
            'parent' => $this->properties['sbpId'],
            'published' => true,
            'deleted' => false,
            'hidemenu' => false,
            'createdon' => time(),
            'template' => 1,
            'isfolder' => !empty($data['resources']),
            'uri_override' => false,
            'richtext' => false,
            'searchable' => true,
        ]);
        if (empty($properties['uri'])) {
            $parentData = $this->getObjectData('modResource', ['id' => $this->properties['parent']]);
            $this->properties['uri'] = $parentData['object']['uri'];
        }
        $this->properties = array_merge($this->properties, $properties);
    }

    /**
     * @return array|object|string
     */
    public function handle()
    {
        if (!$resource = $this->modx->getObject('modResource', array('pagetitle' => $this->properties['pagetitle']))) {
            $resource = $this->modx->newObject('modResource');
        }

        $alias = !empty($this->properties['alias']) ? $this->properties['alias'] : $resource->cleanAlias($this->properties['pagetitle']);
        $this->properties['uri'] .= $alias;

        $resource->fromArray($this->properties, '', true, true);

        if (!empty($this->properties['groups'])) {
            foreach ($this->properties['groups'] as $group) {
                $resource->joinGroup($group);
            }
        }

        if (!$resource->save()) {
            $this->modx->error->addError('[MpcResourceHandler::handle] Не удалось сохранить ресурс.');
            return $this->modx->error->failure('', $this->properties);
        }

        $this->copyConfig($resource);

        if (!empty($this->properties['tvs'])) {
            foreach ($this->properties['tvs'] as $k => $v) {
                $resource->setTVValue($k, $v);
            }
        }

        if (!empty($this->properties['resources'])) {
            $menuindex = 0;
            foreach ($this->properties['resources'] as $item) {
                if (!$item['pagetitle']) continue;
                $item['menuindex'] = $menuindex++;
                $item['parent'] = $resource->get('id');
                $item['uri'] = $this->properties['uri'] . '/';
                $this->setProperties($item);
                $this->handle();
            }
        }

        return $this->modx->error->success('', ['rid' => $resource->get('id')]);
    }

    /**
     *
     * @param modResource $resource
     */
    public function copyConfig(modResource $resource)
    {
        $template = $resource->get('template');
        $parent = $resource->get('parent');
        if ($parent !== $this->properties['sbpId']) {
            if ($template && $this->modx->getCount('modTemplateVarTemplate', array('tmplvarid' => $this->properties['configTvId'], 'templateid' => $template))) {
                if ($donor = $this->modx->getObject('modResource', array('template' => $template, 'parent' => $this->properties['sbpId']))) {
                    if ($donor_config = $donor->getTVValue($this->properties['commonConfigName'])) {
                        $config = $resource->getTVValue($this->properties['commonConfigName']);
                        $copy_all = $resource->getTVValue($this->properties['copyConfigTvname']);
                        if (!$config && $copy_all) {
                            $resource->setTVValue($this->properties['commonConfigName'], $donor_config); // копируем конфиг полностью
                            $resource->setTVValue($this->properties['copyConfigTvname'], false);
                        } else {
                            // копируем содержимое отдельных секций
                            $flag = false;
                            $config = json_decode($config, 1) ?: [];
                            $donor_config = $this->reformatConfig(json_decode($donor_config, 1));
                            if (!empty($config)) {
                                foreach ($config as $key => $item) {
                                    if ($item['copy_from_origin'] && $donor_config[$item['section_name']]) {
                                        $flag = true;
                                        $config[$key] = array_merge($item, $donor_config[$item['section_name']]);
                                    }
                                }
                            }
                            if ($flag) {
                                $config = json_encode($config);
                                $resource->setTVValue($this->properties['commonConfigName'], $config);
                            }
                        }
                    }
                }
            }
        }
    }
}