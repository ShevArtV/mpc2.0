<?php
/**
 * Сервис для работы с модулем MigxPageConfigurator
 */

namespace MpcServices;

use MpcServices\Handlers\Grabber;
use MpcServices\Handlers\Cutter;
use MpcServices\Handlers\Render;
use MpcServices\Helpers\Logging;

/**
 * @author Arthur Shevchenko (https://t.me/ShevArtV)
 */
class Mpc
{
    /**
     * @var Logging
     */
    private Logging $logging;

    /**
     * @var Grabber
     */
    public Grabber $grabber;
    /**
     * @var Cutter
     */
    public Cutter $cutter;
    /**
     * @var \modX
     */
    private \modX $modx;
    /**
     * @var array
     */
    protected array $properties;
    public Render $render;

    /**
     * @param \modX $modx
     */
    public function __construct(\modX $modx)
    {
        $this->modx = $modx;
        $this->initialize();
    }

    /**
     * @return void
     */
    private function initialize()
    {
        $this->logging = new Logging();
        $logFileName = str_replace('\\', '-', self::class) . '.txt';
        $this->logging->setPath($logFileName);

        $this->properties = [
            'corePath' => $this->modx->getOption('core_path', null, ''),
            'pdotoolsElementsPath' => $this->modx->getOption('pdotools_elements_path', null, '{core_path}elements/'),
            'pathToDist' => $this->modx->getOption('mpc_path_to_dist', null, 'parsed/'),
            'extension' => $this->modx->getOption('mpc_tpl_file_extension', null, '.tpl'),
            'pathToSrc' => $this->modx->getOption('mpc_path_to_src', null, 'elements/templates/'),
            'lazyloadAttr' => $this->modx->getOption('mpc_lazyload_attr', null, ''),
            'pathToCreate' => $this->modx->getOption('mpc_path_to_create', null, 'create/'),
            'devMode' => $this->modx->getOption('mpc_dev_mode', null, false),
        ];

        $this->properties['pdotoolsElementsPath'] = str_replace('{core_path}', '', $this->properties['pdotoolsElementsPath']);
        $this->properties['pdotoolsElementsPath'] = str_replace('//', '/', $this->properties['pdotoolsElementsPath']);
        $this->properties['pdotoolsElementsPath'] = str_replace('\\', '/', $this->properties['pdotoolsElementsPath']);
        $this->properties['corePath'] = str_replace('\\', '/', $this->properties['corePath']);
        if (strpos($this->properties['pdotoolsElementsPath'], $this->properties['corePath']) === false) {
            $this->properties['pdotoolsElementsPath'] = $this->properties['corePath'] . $this->properties['pdotoolsElementsPath'];
        }

        $this->grabber = new Grabber($this->modx, $this->properties);
        $this->cutter = new Cutter($this->modx, $this->properties);
        $this->render = new Render($this->modx, $this->properties);
    }


    /**
     * @param string|null $fileName
     * @param bool|null $updContent
     * @return void
     */
    public function process(?string $fileName, ?bool $updContent)
    {
        if($this->properties['devMode']){
            $this->render->clearCache();
        }
        $this->grabber->updContent = $updContent ?? false;
        if (!$fileName) {
            $fileNames = scandir($this->properties['pdotoolsElementsPath'] . $this->properties['pathToSrc']);
            unset($fileNames[0], $fileNames[1]);
            foreach ($fileNames as $fileName) {
                $this->handleFile($fileName);
            }
        } else {
            $this->handleFile($fileName);
        }
    }

    /**
     * @param $fileName
     * @return void
     */
    public function handleFile($fileName)
    {
        $result = $this->grabber->handle($fileName);
        $this->cutter->handle($fileName);
        if ($result['data']['resource']) {
            $this->render->handle($result['data']['resource']);
        }
    }

    public function getParsedConfigPath(\modResource $resource)
    {
        $parsedPath = $this->properties['pdotoolsElementsPath'];
        $rid = $resource->get('id');
        $langKey = $this->modx->getPlaceholder('+lang');
        $langKeyDefault = $this->modx->getOption('polylang_visitor_default_language');
        if ($langKey && $langKey !== $langKeyDefault) {
            $path = $this->properties['pathToDist'] . $rid . $langKey . $this->properties['extension'];
            if (!file_exists($parsedPath . $path)) {
                $this->render->handlePolylangConfig($rid, $langKey);
            }
        } else {
            $path = $this->properties['pathToDist'] . $rid . $this->properties['extension'];
            if (!file_exists($parsedPath . $path)) {
                $this->render->handle($resource);
            }
        }

        if (file_exists($parsedPath . $path)) {
            return 'file:' . $path;
        }
        return '';
    }

    public function copySystemSettingsToNewContext(\modContext $context)
    {
        $settings = $this->modx->getIterator('modSystemSetting', array('namespace' => 'migxpageconfigurator'));
        foreach ($settings as $setting) {
            $setting = $setting->toArray();
            $setting['context_key'] = $context->get('key');
            $ctxSetting = $this->modx->newObject('modContextSetting');
            $ctxSetting->fromArray($setting, '', true);
            $ctxSetting->save();
        }
    }


    public function loadWebScripts()
    {
        if ($this->properties['lazyloadAttr']) {
            $this->modx->regClientScript("
                <script>window.mpcLazyLoadAttr = '{$this->properties['lazyloadAttr']}';</script>
                <script type=\"module\" src=\"assets/components/migxpageconfigurator/js/web/lazyload.js\"></script>", true);
        }
    }

    /**
     * @param string $type
     */
    public function manageElement($type = '')
    {
        $dir = $this->properties['pdotoolsElementsPath'] . $this->properties['pathToCreate'];
        if (!is_dir($dir)) {
            $this->logging->write(__METHOD__, "Directory $dir not found.");
            return;
        }

        $files = scandir($dir);
        unset($files[0], $files[1]);

        if (empty($files)) {
            $this->logging->write(__METHOD__, "Directory $dir is empty.");
            return;
        }

        if ($type) {
            $file = $type . '.inc.php';
            if (in_array($file, $files)) {
                $this->runProcessor($dir, $file, $type);
            }
        } else {
            foreach ($files as $file) {
                $type = str_replace('.inc.php', '', $file);
                $this->runProcessor($dir, $file, $type);
            }
        }

    }

    /**
     * @param $dir
     * @param $file
     * @param $type
     * @return void
     */
    private function runProcessor($dir, $file, $type)
    {
        if ($elements = include_once($dir . $file)) {
            if (is_array($elements) && !empty($elements)) {
                $method = 'run' . ucfirst($type) . 'Processor';
                if (method_exists($this, $method)) {
                    $this->$method($elements);
                } else {
                    $this->logging->write(__METHOD__, "Method $method not exists.");
                }
            }
        }
    }

    /**
     * @param $resources
     * @return void
     */
    private function runResourceProcessor($resources)
    {
        foreach ($resources as $context => $items) {
            $menuindex = 0;
            foreach ($items as $item) {
                if (!$item['pagetitle']) continue;
                $item['context_key'] = $context;
                $item['menuindex'] = $menuindex++;
                $this->manageResource($item);
            }
        }
    }

    /**
     * @param array $data
     * @param $uri
     * @param $parent
     * @return void
     */
    private function manageResource(array $data, $uri = '', $parent = 0)
    {
        /** @var \modResource $resource */
        if (!$resource = $this->modx->getObject('modResource', ['pagetitle' => $data['pagetitle']])) {
            $resource = $this->modx->newObject('modResource');
        }
        unset($data['uri']);
        $alias = $data['alias'] ?: $resource->cleanAlias($data['pagetitle']);
        $uri .= $alias;

        $resource->fromArray(array_merge([
            'alias' => $alias,
            'parent' => $parent,
            'published' => true,
            'deleted' => false,
            'hidemenu' => false,
            'createdon' => time(),
            'isfolder' => !empty($data['isfolder']) || !empty($data['resources']),
            'uri' => $uri,
            'uri_override' => false,
            'richtext' => false,
            'searchable' => true,
        ], $data), '', true, true);

        if (!empty($data['groups'])) {
            foreach ($data['groups'] as $group) {
                $resource->joinGroup($group);
            }
        }
        if (!$resource->save()) {
            $this->logging->write(__METHOD__, "Failed to save resource with the following data:", $data);
        }

        $this->render->copyConfig($resource);

        if ($data['tvs'] && !empty($data['tvs'])) {
            foreach ($data['tvs'] as $k => $v) {
                $resource->setTVValue($k, $v);
            }
        }

        if (!empty($data['resources'])) {
            $menuindex = 0;
            foreach ($data['resources'] as $item) {
                if (!$item['pagetitle']) continue;
                $item['context_key'] = $data['context_key'];
                $item['menuindex'] = $menuindex++;
                $this->manageResource($item, $uri . '/', $resource->get('id'));
            }
        }
    }


    /**
     * @param $plugins
     * @return void
     */
    private function runPluginProcessor($plugins)
    {
        foreach ($plugins as $name => $data) {
            /** @var \modPlugin $plugin */
            if (!$plugin = $this->modx->getObject('modPlugin', array('name' => $name))) {
                $plugin = $this->modx->newObject('modPlugin');
            }
            $plugin->fromArray(array_merge([
                'name' => $name,
                'description' => @$data['description'],
                'category' => $this->getCategoryId($data['categoryName']),
                'plugincode' => file_get_contents($this->properties['corePath'] . 'elements/plugins/' . $data['file'] . '.php'),
                'source' => 1,
                'static_file' => 'core/elements/plugins/' . $data['file'] . '.php',
            ], $data), '', true, true);

            $events = [];

            if (!empty($data['events'])) {
                foreach ($data['events'] as $event_name => $event_data) {
                    /** @var \modPluginEvent $event */
                    if (!$event = $this->modx->getObject('modPluginEvent', array('event' => $event_name, 'pluginid' => $plugin->get('id')))) {
                        $event = $this->modx->newObject('modPluginEvent');
                    }

                    $event->fromArray(array_merge([
                        'event' => $event_name,
                        'priority' => 0,
                        'propertyset' => 0,
                    ], $event_data), '', true, true);
                    $events[] = $event;
                }
            }
            if (!empty($events)) {
                $plugin->addMany($events);
            }
            if (!$plugin->save()) {
                $this->logging->write(__METHOD__, "Failed to save plugin $name with the following data", $data);
            }
        }
    }

    /**
     * @param $snippets
     * @return void
     */
    private function runSnippetProcessor($snippets)
    {
        foreach ($snippets as $name => $data) {
            /** @var \modSnippet $snippet */
            if (!$snippet = $this->modx->getObject('modSnippet', array('name' => $name))) {
                $snippet = $this->modx->newObject('modSnippet');
            }
            $data = array_merge([
                'name' => $name,
                'description' => @$data['description'],
                'snippet' => file_get_contents($this->properties['pdotoolsElementsPath'] . 'snippets/' . $data['file'] . '.php'),
                'source' => 1,
                'category' => $this->getCategoryId($data['categoryName']),
                'static_file' => 'core/elements/snippets/' . $data['file'] . '.php',
            ], $data);

            $snippet->fromArray($data, '', true, true);
            $properties = [];
            $nameLower = strtolower($name);
            foreach (@$data['properties'] as $k => $v) {
                $properties[] = array_merge([
                    'name' => $k,
                    'desc' => $nameLower . '_prop_' . $k,
                    'lexicon' => $nameLower . ':properties',
                ], $v);
            }
            $snippet->setProperties($properties);
            if (!$snippet->save()) {
                $this->logging->write(__METHOD__, "Failed to save snippet $name with the following data", $data);
            }
        }
    }

    /**
     * @param $tvs
     * @return void
     */
    private function runTvProcessor($tvs)
    {
        foreach ($tvs as $name => $data) {
            if ($data['templates'] && is_array($data['templates'])) {
                $templates = [];
                foreach ($data['templates'] as $template) {
                    $temp = $this->getTemplateId($template, true);
                    $templates['templates'][$temp['id']] = $temp;
                }
            }
            $data = array_merge(
                $data,
                $templates,
                ['name' => $name, 'category' => $this->getCategoryId($data['category'])]
            );
            if ($data['type'] == 'migx' && $data['input_properties']) {
                foreach ($data['input_properties'] as $key => $val) {
                    if ($key !== 'configs') {
                        $data['inopt_' . $key] = json_encode($val);
                    } else {
                        $data['inopt_' . $key] = $val;
                    }
                }
            }

            $obTv = $this->modx->getObject('modTemplateVar', ['name' => $name]);
            if (is_object($obTv)) {
                $data = array_merge(
                    $obTv->toArray(),
                    $data
                );
                $response = $this->modx->runProcessor('element/tv/update', $data);
            } else {
                $response = $this->modx->runProcessor('element/tv/create', $data);
            }

            if ($response->isError()) {
                $this->logging->write(__METHOD__, $response->getMessage());
            }

            if ($data['resources'] && is_array($data['resources'])) {
                foreach ($data['resources'] as $key => $val) {
                    $resource = $this->modx->getObject('modResource', ['alias' => $key]);
                    if (is_object($resource)) {
                        $resource->setTVValue($data['name'], $val);
                    }
                }
            }
        }
    }

    /**
     * @param $templateName
     * @param $full
     * @return array|bool[]|int
     */
    private function getTemplateId($templateName, $full = false)
    {
        $template = $this->modx->getObject('modTemplate', ['templatename' => $templateName]);
        if ($templateName == null) return 0;
        if ($full !== false) {
            return array_merge($template->toArray(), ['access' => true]);
        }

        return is_object($template) ? $template->get('id') : 0;
    }

    /**
     * @param $categoryName
     * @return false|mixed
     */
    private function getCategoryId($categoryName)
    {
        $obCategory = $this->modx->getObject('modCategory', ['category' => $categoryName]);
        if (!is_object($obCategory)) {
            $response = $this->modx->runProcessor('element/category/create', [
                'parent' => 0,
                'category' => $categoryName,
                'rank' => 0
            ]);

            if ($response->isError()) {
                return false;
            }
            return $response->response['object']['id'];
        }

        return $obCategory->get('id');
    }
}
