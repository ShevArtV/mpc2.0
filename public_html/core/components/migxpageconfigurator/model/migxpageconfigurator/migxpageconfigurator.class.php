<?php

require_once dirname(__FILE__, 3) . '/libs/phpQuery/phpQuery.php';

/**
 *
 */
class MigxPageConfigurator
{
    /** @var modX $modx */
    public $modx;

    /** @var pdoTools $pdo */
    public $pdo;

    /** @var Polylang $polylang */
    public $polylang;

    /** @var int $sbp_id */
    public $sbp_id;

    /** @var int $cp_id */
    public $cp_id;

    /** @var string $path_to_dist */
    public $path_to_dist;

    /** @var string $core_path */
    public $core_path;

    /** @var string $path_to_sections */
    public $path_to_sections;

    /** @var string $extension */
    public $extension;

    /** @var string $base_section_name */
    public $base_section_name;

    /** @var string $section_chunk_prefix */
    public $section_chunk_prefix;

    /** @var string $path_to_src */
    public $path_to_src;

    /** @var string $common_config_name */
    public $common_config_name;

    /** @var string $tmplvar_ids */
    public $tmplvar_ids;

    /** @var string $fake_img_path */
    public $fake_img_path;

    /** @var string $path_to_chunks */
    public $path_to_chunks;

    /** @var int $config_tv_id */
    public $config_tv_id;

    /** @var int $base_tpl_id */
    public $base_tpl_id;

    /** @var string $lazyload_attr */
    public $lazyload_attr;

    /** @var string $exlazyload_attr */
    public $exlazyload_attr;

    /** @var string $exclude_fields_path */
    public $exclude_fields_path;

    /** @var array $exclude_fields */
    public $exclude_fields;

    /** @var string $path_to_presets */
    public $path_to_presets;

    /** @var string $path_to_forms */
    public $path_to_forms;

    /** @var string $path_to_calls */
    public $path_to_calls;

    /** @var string $http_host */
    public $http_host;

    /** @var string $pattern */
    public $pattern;

    /** @var string $thumb_format */
    public $thumb_format;

    /** @var string $table_prefix */
    public $table_prefix;

    /** @var string $path_to_create */
    public $path_to_create;

    /** @var string $dev_mode */
    public $dev_mode;

    /** @var string $wrapper_name */
    public $wrapper_name;

    /** @var string $pdotools_elements_path */
    public $pdotools_elements_path;

    /** @var array $template_var_ids */
    public $template_var_ids;

    /** @var string $contacts_tvname */
    public $contacts_tvname;

    /** @var string $phone_format */
    public $phone_format;

    /** @var string $phone_regexp */
    public $phone_regexp;

    /** @var string $copy_config_tvname */
    public $copy_config_tvname;

    /** @var string $default_lang_key */
    public $default_lang_key;

    /** @var array $chunk_names */
    public $chunk_names;

    /** @var array $presets */
    public $presets;

    /**
     * @param modX $modx
     */
    public function __construct(modX $modx)
    {
        $this->modx = $modx;
        $this->pdo = $this->modx->getService('pdoTools');
        $this->core_path = $this->modx->getOption('core_path', null, '');
        $this->modx->addPackage('migxpageconfigurator', $this->core_path . 'components/migxpageconfigurator/model/');
        $this->modx->addPackage('migx', $this->core_path . 'components/migx/model/'); // подключаем модель объекта MIGX
        $this->http_host = $this->modx->getOption('http_host', null, '');
        $this->table_prefix = $this->modx->getOption('table_prefix', null, 'modx_');
        $this->default_lang_key = $this->modx->getOption('polylang_visitor_default_language', '', false);
        $this->pdotools_elements_path = str_replace($this->core_path, '', $this->modx->getOption('pdotools_elements_path', null, '{core_path}elements/'));

        $this->sbp_id = (int)$this->modx->getOption('mpc_static_block_page_id', null, 1);
        $this->cp_id = (int)$this->modx->getOption('mpc_contacts_page_id', null, 1);
        $this->path_to_dist = $this->modx->getOption('mpc_path_to_dist', null, 'parsed/');
        $this->path_to_sections = $this->modx->getOption('mpc_path_to_sections', null, 'sections/');
        $this->path_to_src = $this->modx->getOption('mpc_path_to_src', null, 'elements/templates/');
        $this->path_to_chunks = $this->modx->getOption('mpc_path_to_chunks', null, 'chunks/');
        $this->path_to_forms = $this->modx->getOption('mpc_path_to_forms', null, 'chunks/forms/');
        $this->path_to_calls = $this->modx->getOption('mpc_path_to_calls', null, 'calls/');
        $this->path_to_presets = $this->modx->getOption('mpc_path_to_presets', null, 'components/migxpageconfigurator/elements/presets/');
        $this->path_to_create = $this->modx->getOption('mpc_path_to_create', null, 'components/migxpageconfigurator/elements/create/');
        $this->extension = $this->modx->getOption('mpc_tpl_file_extension', null, '.tpl');
        $this->base_section_name = $this->modx->getOption('mpc_base_section_name', null, 'base');
        $this->common_config_name = $this->modx->getOption('mpc_common_config_name', null, 'config');
        $this->copy_config_tvname = $this->modx->getOption('mpc_copy_config_tvname', null, 'copy_sections');
        $this->tmplvar_ids = $this->modx->getOption('mpc_tmplvar_ids', null, '1,5');
        $this->config_tv_id = $this->modx->getOption('mpc_config_tv_id', null, 1);
        $this->base_tpl_id = $this->modx->getOption('mpc_base_tpl_id', null, 1);
        $this->lazyload_attr = $this->modx->getOption('mpc_lazyload_attr', null, '');
        $this->exlazyload_attr = 'data-mpc-exlazy';
        $this->thumb_format = $this->modx->getOption('mpc_thumb_format', null, 'png');
        $this->dev_mode = $this->modx->getOption('mpc_dev_mode', null, '');
        $this->wrapper_name = $this->modx->getOption('mpc_wrapper_name', null, 'wrapper');
        $this->fake_img_path = $this->modx->getOption('mpc_fake_img_path', null, 'assets/components/migxpageconfigurator/images/fake-img.png');
        $this->exclude_fields_path = $this->modx->getOption('mpc_exclude_fields_path', null, $this->core_path . 'components/migxpageconfigurator/elements/fields/exclude_fields.json');
        $this->pattern = '/(\s)*?data-mpc-(exlazy|sff|copy|symbol|form|preset|cond|static|name|item|unwrap|section|snippet|chunk|include|parse|remove|attr|field)(-){0,1}([0-9]*)(=".*?"){0,1}(\s)*?/';
        $this->section_chunk_prefix = '@FILE ' . $this->path_to_sections;
        $this->exclude_fields = json_decode(file_get_contents($this->exclude_fields_path), 1);
        $this->contacts_tvname = $modx->getOption('mpc_contacts_tvname', '', 'contacts');
        $this->phone_format = $modx->getOption('mpc_phone_format', '', '8 (\2) \3-\4-\5');
        $this->phone_regexp = $modx->getOption('mpc_phone_regexp', '', '/(\d)(\d{3})(\d{3})(\d{2})(\d{2})$/');

        $this->loadLexicons();
        $this->initialize();
    }

    /**
     * @return void
     */
    public function loadLexicons()
    {
        $this->modx->lexicon->load('migxpageconfigurator:default');
    }


    /**
     * @return void
     * @throws Exception
     */
    private function initialize()
    {
        $path_to_sections = $this->core_path . $this->pdotools_elements_path . $this->path_to_sections;
        if (!is_dir($path_to_sections)) {
            mkdir($path_to_sections, 0777, true);
        }
        $path_to_dist = $this->core_path . $this->pdotools_elements_path . $this->path_to_dist;
        if (!is_dir($path_to_dist)) {
            mkdir($path_to_dist, 0777, true);
        }
        if (is_dir($this->core_path . 'components/polylang')) {
            $this->polylang = $this->modx->getService('polylang', 'Polylang');
        }
        $this->template_var_ids = explode(',', $this->tmplvar_ids); // массив id TV которые нужно привязать к шаблону
        $this->getPresets();
    }

    /**
     * @param string $type
     */
    public function manageElement($type = '')
    {
        $dir = $this->core_path . $this->path_to_create;
        if (!is_dir($dir)) {
            $this->error('[MigxPageConfigurator::manageElement] Директория ' . $dir . ' не существует.');
        }

        $files = scandir($dir);
        unset($files[0], $files[1]);

        if (empty($files)) {
            $this->error('[MigxPageConfigurator::manageElement] Директория ' . $dir . ' пустая.');
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
                    $this->error('[MigxPageConfigurator::runProcessor] Метод ' . $method . ' не существует.');
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
        /** @var modResource $resource */
        if (!$resource = $this->modx->getObject('modResource', array('pagetitle' => $data['pagetitle']))) {
            $resource = $this->modx->newObject('modResource');
        }
        unset($data['uri']);
        $alias = $data['alias'] ?: $resource->cleanAlias($data['pagetitle']);
        $uri .= $alias;
        $result = $data['file_name'] ? $this->runTemplateProcessor($data['file_name']) : array('template_id' => 0);

        $resource->fromArray(array_merge([
            'alias' => $alias,
            'parent' => $parent,
            'published' => true,
            'deleted' => false,
            'hidemenu' => false,
            'createdon' => time(),
            'template' => $result['template_id'],
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
            $this->error('[MigxPageConfigurator::manageResource] Не удалось сохранить ресурс со следующими данными: ', $data);
        }

        $this->copyConfig($resource);

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
            /** @var modPlugin $plugin */
            if (!$plugin = $this->modx->getObject('modPlugin', array('name' => $name))) {
                $plugin = $this->modx->newObject('modPlugin');
            }
            $plugin->fromArray(array_merge([
                'name' => $name,
                'description' => @$data['description'],
                'category' => $this->getCategoryId($data['categoryName']),
                'plugincode' => file_get_contents($this->core_path . 'elements/plugins/' . $data['file'] . '.php'),
                'source' => 1,
                'static_file' => 'core/elements/plugins/' . $data['file'] . '.php',
            ], $data), '', true, true);

            $events = [];

            if (!empty($data['events'])) {
                foreach ($data['events'] as $event_name => $event_data) {
                    /** @var modPluginEvent $event */
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
                $this->error('[MigxPageConfigurator::runPluginProcessor] Не удалось сохранить плагин ' . $name . ' со следующими данными ', $data);
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
            /** @var modSnippet[] $snippet */
            if (!$snippet = $this->modx->getObject('modSnippet', array('name' => $name))) {
                $snippet = $this->modx->newObject('modSnippet');
            }
            $data = array_merge([
                'name' => $name,
                'description' => @$data['description'],
                'snippet' => file_get_contents($this->core_path . 'elements/snippets/' . $data['file'] . '.php'),
                'source' => 1,
                'category' => $this->getCategoryId($data['categoryName']),
                'static_file' => 'core/elements/snippets/' . $data['file'] . '.php',
            ], $data);

            $snippet->fromArray($data, '', true, true);
            $properties = [];
            foreach (@$data['properties'] as $k => $v) {
                $properties[] = array_merge([
                    'name' => $k,
                    'desc' => $this->config['name_lower'] . '_prop_' . $k,
                    'lexicon' => $this->config['name_lower'] . ':properties',
                ], $v);
            }
            $snippet->setProperties($properties);
            if (!$snippet->save()) {
                $this->error('[MigxPageConfigurator::runSnippetProcessor] Не удалось сохранить сниппет ' . $name . ' со следующими данными ', $data);
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
                $this->error('[MigxPageConfigurator::runTvProcessor] ' . $response->getMessage());
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
        if (!$templateName) {
            return 0;
        }

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

        $id = $obCategory->get('id');
        return $id;
    }

    /**
     * @param $ids
     */
    public function clearCache($ids = '')
    {
        $basePath = $this->core_path . $this->pdotools_elements_path . $this->path_to_dist;
        $langs = false;
        if ($this->polylang instanceof Polylang) {
            $langs = $this->modx->getCollection('PolylangLanguage');
        }
        if ($ids) {
            $ids = explode(',', $ids);
            foreach ($ids as $id) {
                $this->deleteParsedConfigFile($id, $langs);
            }
        } else {
            $fileNames = scandir($basePath);
            unset($fileNames[0], $fileNames[1]);
            foreach ($fileNames as $fileName) {
                unlink($basePath . $fileName);
            }
        }
    }

    /**
     *
     * @param string|bool $file_names
     * @param string|bool $section_names
     * @param string|bool $section_ids
     * @param bool $upd_content
     * @return false|void
     * @throws Exception
     */
    public function manageTemplates($file_names = false, $upd_content = false)
    {
        $file_names = $this->getFileNames($file_names);
        foreach ($file_names as $file_name) {
            if (!$result = $this->runTemplateProcessor($file_name)) {
                continue;
            }
            $resource = $this->createTemplateResource(array(
                'parent' => $this->sbp_id,
                'pagetitle' => $result['pagetitle'],
                'template' => $result['template_id'],
                'hidemenu' => 1));

            if (!$this->getTemplateSections($result['template_html'], $resource, $upd_content)) {
                continue;
            }

            $this->prepareToParseConfig($resource);
            if ($this->dev_mode) {
                $this->clearCache();
                $this->modx->cacheManager->refresh();
            }
        }
    }

    /**
     * @param $file_names
     * @return array|false|string[]
     */
    private function getFileNames($file_names)
    {
        if ($file_names) {
            $file_names = explode(',', $file_names);
        } else {
            $file_names = scandir($this->core_path . $this->path_to_src); // собираем все имена файлов в массив
            unset($file_names[0], $file_names[1]); // удаляем ненужные нам элементы массива, которые возвращает функция scandir()
        }

        if (!$file_names) {
            $this->error('[MigxPageConfigurator::getFileNames] Шаблоны не найдены.');
        }
        return $file_names;
    }

    /**
     * @param array $resource_data
     * @return xPDOObject
     */
    private function createTemplateResource(array $resource_data)
    {
        if (!$resource = $this->modx->getObject('modResource', array('pagetitle' => $resource_data['pagetitle'], 'parent' => $this->sbp_id))) {
            $resource = $this->createObject('modResource', $resource_data);
        }
        $resource->set('template', $resource_data['template']);
        if (!$resource->save()) {
            $this->error('[MigxPageConfigurator::manageTemplates] Не удалось сохранить ресурс со следующими данными: ', $resource_data);
        }
        return $resource;
    }

    /**
     * @param $file_names
     * @return void
     */
    public function sliceTemplates($file_names = false)
    {
        $file_names = $this->getFileNames($file_names);
        foreach ($file_names as $file_name) {
            $path_to_file = $this->core_path . $this->path_to_src . $file_name;
            $template_html = phpQuery::newDocument(file_get_contents($path_to_file));
            $sections = $template_html->find('[data-mpc-section]');
            if ($sections) {
                foreach ($sections as $section) {
                    $section_name = pq($section)->attr('data-mpc-section');
                    $this->createSectionFiles(pq($section), $section_name);
                }
            }
        }
    }

    /**
     * @param $file_name
     * @return array|false
     */
    private function runTemplateProcessor($file_name)
    {
        $base_tpl_data = $this->getObjectData('modTemplate', array('id' => $this->base_tpl_id)); // получаем базовый шаблон
        $path_to_file = $this->core_path . $this->path_to_src . $file_name;
        $template_html = phpQuery::newDocument(file_get_contents($path_to_file));
        preg_match('/<!--##(.*?)##-->/', $template_html, $tpl_data_json);
        if (!$tpl_data_json[1]) {
            $this->modx->log(1, '[MigxPageConfigurator::runTemplateProcessor] Не удалось получить данные для создания шаблона.');
            return false;
        }
        $tpl_data = json_decode($tpl_data_json[1], 1);
        $tpl_data = array_merge($base_tpl_data, $tpl_data);
        $template_var_ids = $tpl_data['template_var_ids'] ? array_merge($this->template_var_ids, explode(',', $tpl_data['template_var_ids'])) : $this->template_var_ids;
        $template_var_ids = array_unique($template_var_ids);

        if (!$template = $this->modx->getObject('modTemplate', array('templatename' => $tpl_data['templatename']))) {
            $template = $this->createObject('modTemplate', $tpl_data);
        }
        $tpl_data = array_merge($template->toArray(), $tpl_data);
        $template->fromArray($tpl_data, '', 1);
        if (!$template->save()) {
            $this->error('[MigxPageConfigurator::runTemplateProcessor] Не удалось сохранить шаблон со следующими данными: ', $tpl_data);
        }

        foreach ($template_var_ids as $template_var_id) {
            $template_var_data = array('tmplvarid' => $template_var_id, 'templateid' => $template->get('id'), 'rank' => 0);
            if (!$this->modx->getCount('modTemplateVarTemplate', array('tmplvarid' => $template_var_id, 'templateid' => $template->get('id')))) {
                $this->createObject('modTemplateVarTemplate', $template_var_data);
            }
        }

        return array('template_id' => $template->get('id'), 'template_html' => $template_html, 'pagetitle' => $tpl_data['pagetitle']);
    }


    /**
     * @param string $class
     * @param array $conditions
     * @return void
     */
    private function getObjectData(string $class, array $conditions)
    {
        if ($data = $this->modx->getObject($class, $conditions)) { // получаем объект
            $data = $data->toArray(); // преобразуем в массив
            unset($data['id']); // удаляем id, т.к. он задается автоматически при создании объекта
            return $data;
        } else {
            $this->error('[MigxPageConfigurator::getObjectData] Не удалось получить данные объекта класса ' . $class . ' со следующими условиями:', $conditions);
        }
    }

    /**
     *
     * @param string $class
     * @param array $data
     * @return xPDOObject
     */
    private function createObject(string $class, array $data)
    {
        $obj = $this->modx->newObject($class);
        $obj->fromArray($data, '', true);
        if (!$obj->save()) {
            $this->error('Не сохранить объект класса ' . $class . ' со следующими данными:', $data);
        }
        return $obj;
    }

    /**
     *
     * @param phpQueryObject $template_html
     * @param modResource $resource
     * @param bool $upd_content
     */
    private function getTemplateSections(phpQueryObject $template_html, modResource $resource, $upd_content = false)
    {
        $sections = $template_html->find('[data-mpc-section]');
        $section_names = $this->getSectionNames($sections);
        $section_values = array();
        $sbp_resource = $this->modx->getObject('modResource', $this->sbp_id);
        $sbp_resource_config = $sbp_resource->getTVValue($this->common_config_name);
        $sbp_section_values = json_decode($sbp_resource_config, 1) ?: array();

        $default_config_data = $this->getObjectData('migxConfig', array('name' => $this->base_section_name)); // получаем базовую конфигурацию
        $common_config = $this->modx->getObject('migxConfig', array('name' => $this->common_config_name));
        $common_config_data = $common_config->toArray(); // получаем конфигурацию конфигураций
        $multiple_formtabs = explode('||', $common_config_data['extended']['multiple_formtabs']);
        $default_form_tabs = json_decode($default_config_data['formtabs'], 1); // базовая вкладка формы
        $i = 1;

        if (empty($section_names)) {
            return false;
        }

        foreach ($sections as $section) {
            $section_name = pq($section)->attr('data-mpc-section');
            $is_copy = pq($section)->attr('data-mpc-copy') ?: false;
            if (in_array($section_name, $section_names)) {
                $section_caption = pq($section)->attr('data-mpc-name');
                $section_is_static = pq($section)->attr('data-mpc-static');
                $section_id = $section_name . '_' . str_replace(['.', ',', ' '], '', microtime(true));
                $file_name = $section_name . $this->extension;
                $file_name_vis = 'core/' . $this->pdotools_elements_path . $this->path_to_sections . $file_name;

                // заполняем содержимое полей
                $section_fields_values = $this->getSectionFieldsValues(pq($section)) ?: array();

                $section_fields_values['is_static'] = (boolean)$section_is_static;
                $standart_values = array(
                    'MIGX_id' => $i,
                    'MIGX_formname' => $section_name,
                    'id' => $section_id,
                    'section_name' => $section_caption,
                    'file_name' => $file_name_vis,
                );
                $section_values = $this->updateSectionValues($section_values, $section_fields_values, $standart_values, $i);

                if ($section_is_static && !$is_copy) {
                    $sbp_section_values = $this->updateStaticSectionValues($sbp_section_values, $section_fields_values, $standart_values, $i, $section_name);
                }
                if (empty($section_ids)) {
                    $i++;
                }


                if (!$is_copy) {
                    // обновляем или создаём конфигурацию для секции.
                    $multiple_formtabs[] = $this->createSectionConfig(pq($section), $default_form_tabs, $file_name_vis, $section_name);
                    // если секция НЕ является копией аналогичной из другого шаблона - создаем для неё файлы
                    $this->createSectionFiles(pq($section), $section_name);
                }
            }
        }

        // обновляем или заполняем контент типовой страницы.
        if ($upd_content) {
            $resource->setTVValue($this->common_config_name, json_encode($section_values));
            if (!$resource->save()) {
                $this->error('[MigxPageConfigurator::getTemplateSections] Не удалось сохранить ресурс с ID ' . $resource->get('id'));
            }
            $sbp_resource->setTVValue($this->common_config_name, json_encode($sbp_section_values));
            if (!$sbp_resource->save()) {
                $this->error('[MigxPageConfigurator::getTemplateSections] Не удалось сохранить ресурс с ID ' . $sbp_resource->get('id'));
            }
        }

        $common_config_data['extended']['multiple_formtabs'] = implode('||', array_unique($multiple_formtabs));
        $common_config->fromArray($common_config_data);
        if (!$common_config->save()) {
            $this->error('[MigxPageConfigurator::getTemplateSections] Не удалось сохранить общую конфигурацию.');
        }
        return true;
    }

    /**
     *
     * @param phpQueryObject $section
     * @return array
     */
    private function getSectionNames(phpQueryObject $sections): array
    {
        $result = array();
        foreach ($sections as $key => $section) {
            $result[] = pq($section)->attr('data-mpc-section');
        }
        return $result;
    }

    /**
     *
     * @param phpQueryObject $section
     * @return array
     */
    private function getSectionFieldsValues(phpQueryObject $section): array
    {
        $fields = $this->parseHTML($section);
        foreach ($fields as $k => $v) {
            if (is_array($v)) {
                $fields[$k] = json_encode($v);
            }
        }
        return $fields;
    }

    /**
     *
     * @param phpQueryObject $html
     * @param string $field_attr_name
     * @param string $item_attr_name
     * @param int $level
     * @return array
     */
    private function parseHTML(phpQueryObject $html, $field_attr_name = 'data-mpc-field', $item_attr_name = 'data-mpc-item', $level = 0): array
    {
        $entries = $html->find('[' . $field_attr_name . ']');
        $fields = array();
        $level++;
        $next_field_attr = 'data-mpc-field-' . $level;
        $next_item_attr = 'data-mpc-item-' . $level;
        foreach ($entries as $key => $row) {
            $field_name = pq($row)->attr($field_attr_name);
            $items = pq($row)->find('[' . $item_attr_name . ']');
            if (count($items) === 0) {
                $fields[$field_name] = $this->getFieldsData(pq($row));

                if ($field_name === 'img') {
                    $fields['img_w'] = pq($row)->attr('width');
                    $fields['img_h'] = pq($row)->attr('height');
                }
                if ($field_name === 'img_mob') {
                    $fields['img_mob_w'] = pq($row)->attr('width');
                    $fields['img_mob_h'] = pq($row)->attr('height');
                }
            } else {
                foreach ($items as $k => $item) {
                    $fields[$field_name][$k]['MIGX_id'] = $k + 1;
                    $fields[$field_name][$k] = array_merge($fields[$field_name][$k], $this->parseHTML(pq($item), $next_field_attr, $next_item_attr, $level));
                }

            }
        }
        return $fields;
    }

    /**
     *
     * @param phpQueryObject $row
     * @return string
     */
    private function getFieldsData(phpQueryObject $row): string
    {
        $result = '';
        if ($src = $row->attr('src')) {
            $result = $src;
        } elseif ($href = $row->attr('href')) {
            $result = $href;
        } else {
            $result = trim($row->html());
        }

        return $result;
    }

    /**
     *
     * @param array $section_values
     * @param array $section_fields_values
     * @param array $standart_values
     * @param int $i
     * @return array
     */
    private function updateSectionValues(array $section_values, array $section_fields_values, array $standart_values, int $i): array
    {
        $section_values[$i] = array_merge($standart_values, $section_fields_values);

        return $section_values;
    }

    /**
     *
     * @param array $section_values
     * @param array $section_fields_values
     * @param int $i
     * @param array $standart_values
     * @param string $section_name
     * @return array
     */
    private function updateStaticSectionValues(array $section_values, array $section_fields_values, array $standart_values, int $i, string $section_name): array
    {
        $upd = false;
        if (!empty($section_values)) {
            foreach ($section_values as $k => $section_value) {
                if ($section_value['MIGX_formname'] === $section_name) {
                    $section_values[$k] = array_merge($section_value, $section_fields_values);
                    $upd = true;
                }
            }
        }
        if (!$upd) {
            $section_values[$i] = array_merge($standart_values, $section_fields_values);
        }

        return $section_values;
    }

    /**
     *
     * @param phpQueryObject $section
     * @param array $default_form_tabs
     * @param string $file_name_vis
     * @param string $section_name
     * @return int
     */
    private function createSectionConfig(phpQueryObject $section, array $default_form_tabs, string $file_name_vis, string $section_name): int
    {

        $default_form_tabs[1]['fields'] = $this->getSectionFields($section, $default_form_tabs[1]['fields']);
        $default_form_tabs[0]['fields'][2]['default'] = $file_name_vis; // устанавливаем имя файла секции
        $default_form_tabs[0]['fields'][2]['useDefaultIfEmpty'] = 1;
        $default_form_tabs[0]['fields'][1]['default'] = pq($section)->attr('data-mpc-name'); // устанавливаем имя секции
        $default_form_tabs[0]['fields'][1]['useDefaultIfEmpty'] = 1;
        $default_form_tabs[0]['fields'][0]['default'] = $section_name; // устанавливаем id секции
        $default_form_tabs[0]['fields'][0]['useDefaultIfEmpty'] = 1;

        $default_config_data['formtabs'] = json_encode($default_form_tabs);
        $default_config_data['name'] = $section_name;
        $default_config_data['extended']['multiple_formtabs_optionstext'] = pq($section)->attr('data-mpc-name');
        $default_config_data['editedon'] = date('Y-m-d H:i:s');

        if (!$config = $this->modx->getObject('migxConfig', array('name' => $section_name))) {
            $config = $this->modx->newObject('migxConfig');
        }

        $config->fromArray($default_config_data);
        if (!$config->save()) {
            $this->error('[MigxPageConfigurator::createSectionConfig] Не удалось сохранить конфигурацию секции с именем ' . $section_name);
        }

        return (int)$config->get('id');
    }

    /**
     * @param phpQueryObject $section
     * @param string $section_name
     * @return void
     */
    private function createSectionFiles(phpQueryObject $section, string $section_name)
    {
        $file_name = $section_name . $this->extension;
        $path_to_file = $this->core_path . $this->pdotools_elements_path . $this->path_to_sections . $file_name;
        $is_static = $section->attr('data-mpc-static') ?: false;
        $innerChunks = pq($section)->find('[data-mpc-chunk]');
        $this->chunk_names = [];
        $this->parseInnerChunks($innerChunks);

        $this->putToFile($section, $path_to_file, $is_static);
    }

    private function parseInnerChunks($innerChunks)
    {
        foreach ($innerChunks as $innerChunk) {
            if (!property_exists($innerChunk, 'nodeValue')) continue;
            $chunk_name = pq($innerChunk)->attr('data-mpc-chunk');

            if (in_array($chunk_name, $this->chunk_names)) continue;
            $this->chunk_names[] = $chunk_name;
            if ($subInnerChunks = pq($innerChunk)->find('[data-mpc-chunk]')) {
                $this->parseInnerChunks($subInnerChunks);
            }

            $dir_name = explode('/', $chunk_name);
            if (count($dir_name) > 1) {
                unset($dir_name[count($dir_name) - 1]);
                $dir_name = implode('/', $dir_name);
            }
            $dir = $this->core_path . $this->pdotools_elements_path . $this->path_to_chunks . $dir_name;
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
            $path = $this->core_path . $this->pdotools_elements_path . $this->path_to_chunks . $chunk_name;

            $this->putToFile(pq($innerChunk), $path);
        }
    }

    /**
     *
     * @param phpQueryObject $section
     * @param array $default_fields
     * @return array
     */
    private function getSectionFields(phpQueryObject $section, array $default_fields): array
    {
        $result = array();
        $entries = $section->find('[data-mpc-field]');
        if (count($entries)) {
            foreach ($entries as $entry) {
                $field_name = pq($entry)->attr('data-mpc-field');
                $width = pq($entry)->attr('width');
                $height = pq($entry)->attr('height');
                $result[] = $field_name;
                if ($field_name === 'img') {
                    $result[] = $width ? 'img_w' : '';
                    $result[] = $height ? 'img_h' : '';
                }
                if ($field_name === 'img_mob') {
                    $result[] = $width ? 'img_mob_w' : '';
                    $result[] = $height ? 'img_mob_h' : '';
                }
            }
        }
        return $this->deleteUndueFields($default_fields, $result);
    }

    /**
     *
     * @param array $default_fields
     * @param array $need_fields
     * @return array
     */
    private function deleteUndueFields(array $default_fields, array $need_fields): array
    {
        $fields = array();

        foreach ($default_fields as $k => $v) {
            if (in_array($v['field'], $need_fields)) {
                $fields[] = $v;
            }
        }

        return $fields;
    }

    /**
     *
     * @param phpQueryObject $html
     * @param string $path_to_file
     * @param bool $is_static
     */
    private function putToFile(phpQueryObject $html, string $path_to_file, $is_static = false)
    {
        $section_name = pq($html)->attr('data-mpc-section');
        $html = $this->setModxTags($html, $is_static);
        $html = $this->setSnippetTags(pq($html));
        $html = $this->setParseChunks(pq($html));
        $html = $this->setIncludeChunks(pq($html));
        $html = $this->setParseForm(pq($html));
        $html = $this->removeHiddenPlaceholders(pq($html));
        $is_copy = pq($html)->attr('data-mpc-copy');
        $attrs = pq($html)->find('[data-mpc-attr]');

        $attr_replaces = array();
        if (count($attrs)) {
            foreach ($attrs as $attr) {
                $attr_value = pq($attr)->attr('data-mpc-attr');

                $attr_replaces[$attr_value] = 'data-mpc-attr="' . $attr_value . '"';
            }
        }
        if (pq($html)->attr('data-mpc-unwrap')) {
            $html = pq($html)->html();
        }

        $html = html_entity_decode($html);
        $html = urldecode($html);
        if (!empty($attr_replaces)) {
            foreach ($attr_replaces as $replacement => $replace) {
                $html = str_replace($replace, $replacement, $html);
            }
        }
        $html = preg_replace($this->pattern, '', $html);
        if ($section_name === $this->wrapper_name) {
            $file_name = $section_name . $this->extension;
            $path_to_tpl = $this->core_path . $this->path_to_src . $file_name;
            $content = file_get_contents($path_to_tpl);
            $html = preg_replace('/<body(.*?)>(.*?)<\/body>/s', '<body\1>' . $html . '</body>', $content);
        }

        if (!$is_copy) {
            file_put_contents($path_to_file, $html);
        }
    }


    /**
     *
     * @param phpQueryObject $section
     * @param bool $is_static
     * @param array $attributes
     * @return phpQueryObject
     */
    private function setModxTags(phpQueryObject $section, $is_static = false, $attributes = array()): phpQueryObject
    {
        $field_attr_name = !empty($attributes) ? $attributes['data-mpc-field'] : 'data-mpc-field';
        $item_attr_name = !empty($attributes) ? $attributes['data-mpc-item'] : 'data-mpc-item';
        $level = !empty($attributes) ? $attributes['level'] : 0;
        $fields = $section->find('[' . $field_attr_name . ']');
        $first_symbol = $is_static ? '##' : '{';
        if (count($fields)) {
            foreach ($fields as $field) {
                $item = pq($field)->find('[' . $item_attr_name . ']:first');
                $cond = pq($item)->attr('data-mpc-cond');
                $field_name = pq($field)->attr($field_attr_name);

                if (!count($item)) {
                    $this->setPlaceholder(pq($field), $field_name, $level, $is_static);
                } else {
                    $attributes = array(
                        'level' => ($level + 1),
                        'data-mpc-field' => 'data-mpc-field-' . ($level + 1),
                        'data-mpc-item' => 'data-mpc-item-' . ($level + 1),
                    );
                    $this->setModxTags(pq($item), $is_static, $attributes);
                    $prefix = $level ? '$item' . $level . '.' : '$';
                    $unwrap = pq($item)->attr('data-mpc-unwrap');

                    if ($unwrap) {
                        $item = pq($item)->html();
                    }

                    $chunks = pq($field)->find('[data-mpc-chunk]');
                    if (count($chunks)) {
                        foreach ($chunks as $chunk) {
                            $this->setModxTags(pq($chunk), $is_static, $attributes);
                        }
                    }
                    if ($cond) {
                        if (strpos($cond, '{') === 0) {
                            $item = $cond . $item . '{/if}';
                        } else {
                            $item = '{if ' . $cond . '}' . $item . '{/if}';
                        }
                    }
                    $i = !$level ? '$i' : '$i' . $level;
                    $l = !$level ? '$l' : '$l' . $level;
                    $replacement = $first_symbol . 'foreach ' . $prefix . $field_name . ' as $item' . ($level + 1) . ' index=' . $i . ' last=' . $l . '}
                    ' . $item . $first_symbol . '/foreach}
                    ';
                    if (pq($field)->attr('data-mpc-unwrap')) {
                        pq($field)->replaceWith($replacement);
                    } else {
                        pq($field)->html($replacement);
                    }

                }
            }
        }

        return $section;
    }


    /**
     * @param phpQueryObject $field
     * @param string $field_name
     * @param int $level
     * @param $is_static
     * @return phpQueryObject|void
     */
    private function setPlaceholder(phpQueryObject $field, string $field_name, int $level, $is_static = false)
    {
        if (!$field_name) {
            return $field;
        }
        $first_symbol = $is_static ? '##' : '{';
        $level = $level ? '$item' . $level . '.' : '$';
        $src = $field->attr('src');
        $srcParts = explode('.', $src);
        $imgExtension = $srcParts[count($srcParts) - 1];
        $pls = $level . $field_name;
        if ($src) {
            $width = $field->attr('width');
            $height = $field->attr('height');
            $value = $first_symbol . $pls . '}';
            if ($width && $height) {
                $width_field = $level . $field_name . '_w';
                $height_field = $level . $field_name . '_h';
                $field->attr('width', $first_symbol . $width_field . '}');
                $field->attr('height', $first_symbol . $height_field . '}');
                if ($imgExtension !== 'svg') {
                    $crop_params = $first_symbol . 'set $params = \'w=\'~' . $width_field . '~\'&h=\'~' . $height_field . '~\'&zc=1&ra=1&bg=&f=' . $this->thumb_format . '\'}';
                    $value = $crop_params . $first_symbol . $pls . ' | pThumb:$params}';
                }
            }
            if ($this->lazyload_attr && !$field->attr($this->exlazyload_attr)) {
                $field->attr('src', trim($this->fake_img_path));
                $field->attr($this->lazyload_attr, trim($value));
            } else {
                $field->attr('src', trim($value));
            }
            $alt = '(' . $level . 'title?:' . $level . 'content)';
            $field->attr('alt', $first_symbol . $alt . ' | notags}');
        } elseif ($field->attr('href')) {
            if ((int)$field->attr('href')) {
                $field->attr('href', $first_symbol . $pls . ' | resource: \'uri\'}');
            } else {
                $field->attr('href', $first_symbol . $pls . '}');
            }
        } else {
            if ($field->attr('data-mpc-unwrap')) {
                $field->replaceWith($first_symbol . $pls . '}');
            } else {
                $field->html($first_symbol . $pls . '}');
            }
        }
    }

    /**
     * @param $html
     * @return mixed
     */
    private function setSnippetTags($html)
    {
        $snippets = $html->find('[data-mpc-snippet]');
        foreach ($snippets as $snippet) {
            $first_symbol = trim(pq($snippet)->attr('data-mpc-symbol')) ?: '##';
            if ($value = pq($snippet)->attr('data-mpc-snippet')) {
                $call = $this->getSnippetCall($value, $first_symbol);
                if (pq($snippet)->attr('data-mpc-unwrap')) {
                    pq($snippet)->replaceWith($call);
                } else {
                    pq($snippet)->html($call);
                }
            }
        }
        return $html;
    }

    /**
     *
     * @param string $value
     * @param string $first_symbol
     * @return string
     */
    public function getSnippetCall(string $value, string $first_symbol): string
    {
        $params = '';
        $value = explode('|', $value);
        $snippet_name = $value[0];
        $preset_key = str_replace('!', '', strtolower($value[0]));
        $preset_name = $value[1];
        if(isset($this->presets[$preset_key]) && isset($this->presets[$preset_key][$preset_name])){
            $preset = $this->presets[$preset_key][$preset_name];
            if($preset['extends']){
                $extendsPreset = $this->getExtends($preset['extends'],[]);
                $preset = array_merge($extendsPreset, $preset);
            }

            foreach ($preset as $k => $v) {
                if ($k == 'toPls') {
                    $first_symbol = $first_symbol . 'set $' . $v . ' = ';
                }
                if (is_array($v)) {
                    $v = json_encode($v);
                    $v = str_replace('{', '{ ', $v);
                    $v = str_replace('##', '{', $v);
                }
                if (strpos($v, '#/') === 0) {
                    $v = str_replace('#/', '@FILE ' . $this->path_to_chunks, $v);
                }

                if (strpos($v, '$') === 0 || strpos($v, '[') === 0 || strpos($v, '{$') === 0 || strpos($v, '"') === 0) {
                    $params .= "'$k' => $v," . PHP_EOL;
                } else {
                    $params .= "'$k' => '$v'," . PHP_EOL;
                }
            }
        }


        if ($params) {
            $call = "$first_symbol'$snippet_name' | snippet: [
                        $params]}";
        } else {
            $call = "$first_symbol'$snippet_name' | snippet: []}";
        }
        return $call;
    }

    private function getPresets(){
        $this->presets = [];
        if(file_exists($this->core_path . $this->path_to_presets)){
            $files = scandir($this->core_path . $this->path_to_presets);
            unset($files[0], $files[1]);
            if(count($files)){
                foreach($files as $file){
                    $presets_file_path = $this->core_path . $this->path_to_presets . $file;
                    $this->presets[str_replace('.inc.php', '', $file)] =  include($presets_file_path);
                }
            }
        }
    }


    private function getExtends($preset, $extends){
        $preset = explode('.', $preset);
        $presetData = $this->presets[$preset[0]][$preset[1]];
        if($presetData && is_array($presetData)){
            $extends = array_merge($extends, $presetData);
            if($presetData['extends']){
                $extends = $this->getExtends($presetData['extends'], $extends);
            }
        }
        return $extends;
    }

    /**
     *
     * @param phpQueryObject $section
     * @return string
     */
    private function setParseChunks(phpQueryObject $section): string
    {
        $parses = $section->find('[data-mpc-parse]');
        if (count($parses)) {
            foreach ($parses as $parse) {
                $symbol = trim(pq($parse)->attr('data-mpc-symbol')) ?: '##';
                $params = pq($parse)->attr('data-mpc-parse');
                $path = $this->path_to_chunks . pq($parse)->attr('data-mpc-chunk');
                pq($parse)->replaceWith($symbol . '$_modx->parseChunk("@FILE ' . $path . '", ' . $params . ')}');
            }
        }
        return str_replace('&gt;', '>', $section);
    }

    /**
     *
     * @param phpQueryObject $section
     * @return phpQueryObject
     */
    private function setIncludeChunks(phpQueryObject $section): phpQueryObject
    {
        $includes = $section->find('[data-mpc-include]');
        if (count($includes)) {
            foreach ($includes as $include) {
                $path = $this->path_to_chunks . pq($include)->attr('data-mpc-chunk');
                pq($include)->replaceWith('{include "file:' . $path . '"}');
            }
        }
        return $section;
    }

    /**
     *
     * @param phpQueryObject $section
     * @return phpQueryObject
     */
    private function setParseForm(phpQueryObject $section): phpQueryObject
    {
        $forms = $section->find('[data-mpc-form]');
        $formData = array();
        if (count($forms)) {
            foreach ($forms as $form) {
                $this->updateFormList();
                $symbol = trim(pq($form)->attr('data-mpc-symbol')) ?: '##';
                $formPath = explode('/', pq($form)->attr('data-mpc-chunk'));
                $formData['formName'] = pq($form)->attr('data-mpc-name');
                $formData['fid'] = str_replace(['.tpl', '.html'], '', $formPath[count($formPath) - 1]);
                $formData['preset'] = pq($form)->attr('data-mpc-preset');
                $defautlFormParams = $this->prepareDefaultFormParams();
                if (!empty($defautlFormParams['file'][$formData['preset']]) && pq($form)->attr('data-mpc-sff')) {
                    $c = 1;
                    $params = array();
                    foreach ($defautlFormParams['file'][$formData['preset']] as $name => $value) {
                        $params[] = array(
                            'MIGX_id' => $c,
                            'title' => $name,
                            'content' => $value,
                        );
                        $c++;
                    }
                    $formData['params'] = json_encode($params, 1);
                }
                $formData = $this->updateFormListConfig($formData);
                $path = $this->getAjaxFormCall($formData, $defautlFormParams, $symbol);
                if (!pq($form)->attr('data-mpc-form')) {
                    pq($form)->replaceWith('{include "file:' . $path . '"}');
                } else {
                    pq($form)->replaceWith('{"' . $this->pdotools_elements_path . $path . '" | include }');
                }

            }
        }
        return $section;
    }

    public function getFormLexicons($fid, $lexicons, $langKey = '')
    {
        $lexicons = !is_array($lexicons) ? json_decode($lexicons, 1) : $lexicons;
        $output = [];
        if ($this->default_lang_key && $langKey !== $this->default_lang_key) {
            if ($tv = $this->modx->getObject('modTemplateVar', ['name' => 'form_list'])) {
                $polyTv = $this->modx->getObject('PolylangTv', ['content_id' => $this->cp_id, 'tmplvarid' => $tv->get('id'), 'culture_key' => $langKey]);
                $value = json_decode($polyTv->get('value'), 1);
                $value = $this->reformatArray($value, 'fid');
                if (!empty($value[$fid]) && !empty($value[$fid]['params'])) {
                    $params = json_decode($value[$fid]['params'], 1);
                    $params = $this->reformatArray($params, 'title');
                    foreach ($lexicons as $k) {
                        if (!empty($params[$k])) {
                            $output[$k] = $params[$k]['content'];
                        }
                    }
                }
            }
        }

        return $output;
    }

    public function reformatArray($array, $key)
    {
        $output = [];
        foreach ($array as $item) {
            $output[$item[$key]] = $item;
        }
        return $output;
    }

    /**
     * @return void
     */
    public function updateFormList()
    {
        $formsPath = $this->core_path . $this->pdotools_elements_path . $this->path_to_forms;
        $presetsFile = $this->core_path . $this->path_to_presets . 'ajaxformitlogin.inc.php';
        $preset_keys = [];
        $presets = [];
        if (file_exists($presetsFile)) {
            $presets = include($presetsFile);
            $preset_keys = array_keys($presets);
        }

        if (!is_dir($formsPath)) return true;
        $fileNames = scandir($formsPath); // собираем все имена файлов в массив
        unset($fileNames[0], $fileNames[1]); // удаляем ненужные нам элементы массива, которые возвращает функция scandir()
        $inputOptionValues = array();
        if (!empty($fileNames)) {
            $list_form = $this->modx->getObject('migxConfig', array('name' => 'form_list')); // получаем объект
            $contacts = $this->modx->getObject('migxConfig', array('name' => 'contacts')); // получаем объект
            $list_form_data = $list_form->toArray(); // преобразуем в массив
            $contacts_data = $contacts->toArray(); // преобразуем в массив
            $forms_formtabs = json_decode($list_form_data['formtabs'], 1);
            $contacts_formtabs = json_decode($contacts_data['formtabs'], 1);
            foreach ($fileNames as $name) {
                $inputOptionValues[] = str_replace(['.tpl', '.html'], '', $name);
            }

            $fids = array_unique($inputOptionValues);
            $emptyPls = $this->modx->lexicon('mpc_pls_no_choose');
            $forms = array_merge(array($emptyPls), $preset_keys, $fids);
            $preset_ids = array_merge(array($emptyPls), $preset_keys);

            foreach ($forms_formtabs[0]['fields'] as $k => $field) {
                if ($field['field'] === 'fid') {
                    $forms_formtabs[0]['fields'][$k]['inputOptionValues'] = implode('||', $fids);
                }
            }
            foreach ($forms_formtabs[1]['fields'] as $k => $field) {
                if ($field['field'] === 'preset' && !empty($preset_keys)) {
                    $forms_formtabs[1]['fields'][$k]['inputOptionValues'] = implode('||', $preset_ids);
                }
            }
            foreach ($contacts_formtabs[0]['fields'] as $k => $field) {
                if ($field['field'] === 'form') {
                    $contacts_formtabs[0]['fields'][$k]['inputOptionValues'] = implode('||', $forms);
                }
            }
            $contacts_data['formtabs'] = json_encode($contacts_formtabs);
            $contacts->fromArray($contacts_data);
            $list_form_data['formtabs'] = json_encode($forms_formtabs);
            $list_form->fromArray($list_form_data);
            if (!$list_form->save()) {
                $this->error('[MigxPageConfigurator::createSectionConfig] Не удалось сохранить конфигурацию списка форм.');
            }
            if (!$contacts->save()) {
                $this->error('[MigxPageConfigurator::createSectionConfig] Не удалось сохранить конфигурацию списка контактов.');
            }
        }
    }

    /**
     *
     * @param array $formData
     * @return array
     */
    private function updateFormListConfig(array $formData): array
    {
        $add = true;
        if ($contact_page = $this->modx->getObject('modResource', $this->cp_id)) {
            $form_list = json_decode($contact_page->getTVValue('form_list'), 1) ?: array();
            if (empty($form_list)) {
                $formData['MIGX_id'] = 1;
                $form_list[] = $formData;
            } else {
                $formData['MIGX_id'] = count($form_list) + 1;
                foreach ($form_list as $k => $form) {
                    $form['MIGX_id'] = $k + 1;
                    if ($form['fid'] === $formData['fid'] && $form['preset'] === $formData['preset']) {
                        $form_list[$k] = $formData = array_merge($form, $formData);
                        $add = false;
                    }
                }
                if ($add) {
                    $form_list[] = $formData;
                }
            }

            $contact_page->setTVValue('form_list', json_encode($form_list));
            if (!$contact_page->save()) {
                $this->error('[MigxPageConfigurator::updateFormListConfig] Не удалось сохранить ресурс со списком форм.');
            }
        }
        return $formData;
    }

    /**
     *
     * @return array
     */
    public function prepareDefaultFormParams(): array
    {
        $presetsFile = $this->core_path . $this->path_to_presets . 'ajaxformitlogin.inc.php';
        $params['default'] = array();
        $params['file'] = array();
        $emailTo = $this->modx->getOption('mpc_email') ?: $this->modx->getOption('ms2_email_manager');

        if (!$emailTo) {
            $emailTo = 'info@' . $this->http_host;
        }
        $params['default']['emailFrom'] = 'noreply@' . $this->http_host;
        if ($this->modx->getOption('mail_use_smtp')) {
            $params['default']['emailFrom'] = $this->modx->getOption('mail_smtp_user');
            if (strpos($params['default']['emailFrom'], '@') === false) {
                $smtpHosts = explode(',', $this->modx->getOption('mail_smtp_hosts'));
                $params['default']['emailFrom'] = $params['default']['emailFrom'] . '@' . str_replace('smtp.', '', $smtpHosts[0]);
            }
        }

        $params['default']['emailTo'] = $emailTo;
        if (file_exists($presetsFile)) {
            $params['file'] = include($presetsFile);
        }

        return $params;
    }

    /**
     *
     * @param int $rid
     * @param string $tvname
     * @param string $string
     * @return array
     */
    public function getContacts($rid = false, $tvname = false, $lang_key = false): array
    {
        $output = array();
        $rid = $rid ?: $this->cp_id;

        $tvname = $tvname ?: $this->contacts_tvname;
        if (!$tv = $this->modx->getObject('modTemplateVar', ['name' => $tvname])) {
            $this->error('[MigxPageConfigurator::getContacts] Не удалось получить TV со списком контактов.');
        }
        if ($resource = $this->modx->getObject('modResource', $rid)) {
            $polylangContacts = $lang_key ? $this->getPolylangConfig($rid, $lang_key, $tv->get('id')) : [];
            $contacts = !empty($polylangContacts) ? $polylangContacts : $resource->getTVValue($tvname);
            $contacts = json_decode($contacts, 1);
            if (is_array($contacts) && !empty($contacts)) {
                foreach ($contacts as $k => $item) {
                    if ($item['type'] == 'phone') {
                        $item['formattedValue'] = preg_replace($this->phone_regexp, $this->phone_format, trim($item['value']));
                    }
                    $output[$item['type']][$item['caption']] = $item;

                    if ($item['groups']) {
                        $groups = explode(',', $item['groups']);
                        foreach ($groups as $group) {
                            $output[$group][$item['caption']] = $item;
                        }
                    }
                }
            }
        }

        return $output;
    }


    /**
     * @param array $formData
     * @param array $defautlFormParams
     * @param string $symbol
     * @return string
     */
    public function getAjaxFormCall(array $formData, array $defautlFormParams, string $symbol = '##'): string
    {
        $callsPath = $this->core_path . $this->pdotools_elements_path . $this->path_to_calls;
        $params_str = '';
        $userParams = json_decode($formData['params'], 1) ?: array();
        $userParams = $this->getUserParams($userParams);
        $fileParams = $defautlFormParams['file'][$formData['preset']] ?: array('validate' => '');
        $file_name = ($formData['preset'] ?: $formData['fid']) . $this->extension;
        $defautlFormParams['default']['form'] = '@FILE ' . $this->path_to_forms . $formData['fid'] . $this->extension;
        $userParams['fid'] = $formData['fid'];
        $userParams['formName'] = $formData['formName'];
        $userParams['validate'] = $userParams['validate'] ?: $fileParams['validate'];
        $emails = array();
        $contacts = $this->getContacts();
        if (!empty($contacts['emails'])) {
            foreach ($contacts['emails'] as $item) {
                if ($item['form'] === $formData['preset'] || $item['form'] === $formData['fid']) {
                    $emails[] = $item['value'];
                }
            }
            if (!empty($emails)) {
                $defautlFormParams['default']['emailTo'] = implode(',', array_unique($emails));
            }
        }
        $all_params = array_merge($fileParams, $defautlFormParams['default'], $userParams);


        foreach ($all_params as $key => $value) {
            if (strpos($value, '#/') === 0) {
                $value = str_replace('#/', '@FILE ' . $this->path_to_chunks, $value);
            }
            if (is_string($value) && strpos($value, '$') !== false) {
                $params_str .= "'" . $key . "'" . " => " . $value . "," . PHP_EOL;
            } else {
                $params_str .= "'" . $key . "'" . " => " . "'" . $value . "'," . PHP_EOL;
            }
        }

        $call = $symbol . "'!AjaxFormitLogin' | snippet:[$params_str]}";
        if (!is_dir($callsPath)) {
            mkdir($callsPath);
        }
        file_put_contents($callsPath . $file_name, $call);
        return $this->path_to_calls . $file_name;
    }

    /**
     *
     * @param array $userParams
     * @return array
     */
    private function getUserParams(array $userParams): array
    {
        $params = array('validate' => '');
        if (!empty($userParams)) {
            foreach ($userParams as $param) {
                $params[$param['title']] = $param['content'];
            }
        }
        return $params;
    }

    /**
     *
     * @param phpQueryObject $section
     * @return phpQueryObject
     */
    private function removeHiddenPlaceholders(phpQueryObject $section): phpQueryObject
    {
        $hiddenPls = $section->find('[data-mpc-remove]');
        if (count($hiddenPls)) {
            foreach ($hiddenPls as $hidden) {
                pq($hidden)->remove();
            }
        }
        return $section;
    }

    /**
     *
     * @param int $rid
     */
    public function deleteParsedConfigFile(int $rid, $langs = false)
    {
        $basePath = $this->core_path . $this->pdotools_elements_path . $this->path_to_dist;
        if (file_exists($basePath . $rid . $this->extension)) {
            unlink($basePath . $rid . $this->extension);
        }
        if ($langs) {
            foreach ($langs as $lang) {
                if (file_exists($basePath . $rid . $lang->get('culture_key') . $this->extension)) {
                    unlink($basePath . $rid . $lang->get('culture_key') . $this->extension);
                }
            }
        }
    }

    /**
     *
     * @param modResource $resource
     * @return bool
     */
    public function prepareToParseConfig(modResource $resource): bool
    {
        $resource_data = $resource->toArray();
        $resource_data['tvs'] = [];
        foreach ($resource_data as $k => $v) {
            if (strpos($k, 'tv') === 0) {
                unset($resource_data[$k]);
            }
        }
        $rid = $resource_data['id'];
        $donor_config = '';
        $static_config = '';

        if ($donor = $this->modx->getObject('modResource', array('parent' => $this->sbp_id, 'template' => $resource_data['template']))) {
            $donor_config = $donor->getTVValue($this->common_config_name);
            foreach ($resource_data as $k => $v){
                if(!$v) unset($resource_data[$k]);
            }
            $resource_data = array_merge($donor->toArray(), $resource_data);
            $resource_data['tvs'] = $this->getResourceTVs($donor->get('id'));
        }
        if ($static_resource = $this->modx->getObject('modResource', $this->sbp_id)) {
            $static_config = $static_resource->getTVValue($this->common_config_name);
        }

        $resource_data['tvs'] = array_merge($resource_data['tvs'], $this->getResourceTVs($rid));

        $config = $resource->getTVValue($this->common_config_name) ?: $donor_config;
        if ($config) { // если в ресурсе есть поле с конфигурацией
            $this->parseConfig($config, $rid, $resource_data, $donor_config, $static_config); // парсим её и генерируем файл
        } else { // если конфигурации нет
            $path_to_file = $this->core_path . $this->pdotools_elements_path . $this->path_to_dist . $rid . $this->extension;
            if (file_exists($path_to_file)) { // проверяем есть ли файл с распарсенной конфигурацией
                unlink($path_to_file); // и удаляем его
            }
        }
        return true;
    }

    /**
     *
     * @param modResource $resource
     * @return bool
     */
    public function prepareToParsePolylangConfig($rid, $lang_key): bool
    {
        $resource_data['id'] = $rid;
        $resource_data['tvs'] = [];
        if ($polylangContent = $this->modx->getObject('PolylangContent', ['content_id' => $rid, 'culture_key' => $lang_key])) {
            $resource_data = array_merge($polylangContent->toArray(), $resource_data);
        }

        if ($resource = $this->modx->getObject('modResource', $rid)) {
            $resource_data = array_merge($resource->toArray(), $resource_data);
        }

        $donor_config = '';
        if ($donor = $this->modx->getObject('modResource', array('parent' => $this->sbp_id, 'template' => $resource_data['template']))) {
            $donor_config = $this->getPolylangConfig($donor->get('id'), $lang_key);
            foreach ($resource_data as $k => $v){
                if(!$v) unset($resource_data[$k]);
            }
            $resource_data = array_merge($donor->toArray(), $resource_data);
            $resource_data['tvs'] = $this->getResourceTVs($donor->get('id'), $lang_key);
        }
        if (!$donor_config) {
            if ($donor = $this->modx->getObject('modResource', array('parent' => $this->sbp_id, 'template' => $resource_data['template']))) {
                $donor_config = $donor->getTVValue($this->common_config_name);
            }
        }
        if (!$static_config = $this->getPolylangConfig($this->sbp_id, $lang_key)) {
            if ($static_resource = $this->modx->getObject('modResource', $this->sbp_id)) {
                $static_config = $static_resource->getTVValue($this->common_config_name);
            }
        }

        $resource_data['tvs'] = array_merge($resource_data['tvs'], $this->getResourceTVs($rid, $lang_key));

        $config = $this->getPolylangConfig($rid, $lang_key) ?: $donor_config;
        if ($config) { // если в ресурсе есть поле с конфигурацией
            $this->parseConfig($config, $rid, $resource_data, $donor_config, $static_config, $lang_key); // парсим её и генерируем файл
        } else { // если конфигурации нет
            $path_to_file = $this->core_path . $this->pdotools_elements_path . $this->path_to_dist . $rid . $lang_key . $this->extension;
            if (file_exists($path_to_file)) { // проверяем есть ли файл с распарсенной конфигурацией
                unlink($path_to_file); // и удаляем его
            }
        }
        return true;
    }

    public function getPolylangConfig($rid, $lang_key, $tv_id = false)
    {
        if ($config_polylang = $this->modx->getObject('PolylangTv', [
            'tmplvarid' => $tv_id ?: $this->config_tv_id,
            'culture_key' => $lang_key,
            'content_id' => $rid
        ])) {
            return $config_polylang->get('value');
        }
        return '';
    }

    /**
     *
     * @param int $rid
     * @param string $tvvaltable
     * @param string $contentid
     * @return array
     */
    public function getResourceTVs(int $rid, $langKey = ''): array
    {
        $resourceTvs = array();
        $polylangTvs = array();

        $sqlDefault = "SELECT {$this->table_prefix}site_tmplvars.name, {$this->table_prefix}site_tmplvar_contentvalues.value FROM {$this->table_prefix}site_tmplvars 
                LEFT JOIN {$this->table_prefix}site_tmplvar_contentvalues 
                ON {$this->table_prefix}site_tmplvars.id = {$this->table_prefix}site_tmplvar_contentvalues.tmplvarid
                WHERE {$this->table_prefix}site_tmplvar_contentvalues.contentid = {$rid} AND {$this->table_prefix}site_tmplvar_contentvalues.tmplvarid != {$this->config_tv_id}";

        if ($this->default_lang_key && $langKey !== $this->default_lang_key) {
            $sqlPolylang = "SELECT {$this->table_prefix}site_tmplvars.name, {$this->table_prefix}polylang_tv.value FROM {$this->table_prefix}site_tmplvars 
                LEFT JOIN {$this->table_prefix}polylang_tv 
                ON {$this->table_prefix}site_tmplvars.id = {$this->table_prefix}polylang_tv.tmplvarid
                WHERE {$this->table_prefix}polylang_tv.content_id = {$rid} AND {$this->table_prefix}polylang_tv.tmplvarid != {$this->config_tv_id} AND {$this->table_prefix}polylang_tv.culture_key = '{$langKey}'";
            if ($statement = $this->modx->query($sqlPolylang)) {
                $tvs = $statement->fetchAll(PDO::FETCH_ASSOC);
                foreach ($tvs as $tv) {
                    $polylangTvs[$tv['name']] = $tv['value'];
                }
            }
        }


        if ($statement = $this->modx->query($sqlDefault)) {
            $tvs = $statement->fetchAll(PDO::FETCH_ASSOC);
            foreach ($tvs as $tv) {
                $resourceTvs[$tv['name']] = $tv['value'];
            }
        }

        return array_merge($resourceTvs, $polylangTvs);
    }

    /**
     *
     * @param string $config
     * @param int $rid
     * @param array $resource_data
     * @param string $lang_key
     */
    private function parseConfig(string $config, int $rid, array $resource_data, string $donor_config, string $static_config, string $lang_key = '')
    {
        $config = $this->reformatConfig(json_decode($config, 1)); // декодируем конфиг в массив
        if($static_config){
            $static_config = $this->reformatConfig(json_decode($static_config, 1)); // декодируем конфиг в массив
        }

        if(file_exists($this->core_path . 'components/minishop2/')){
            if ($files = $this->modx->getCollection('msProductFile', ['product_id' => $resource_data['id'], 'parent:!=' => 0])) {
                foreach ($files as $file) {
                    $fileData = $file->toArray();
                    $type = str_replace('image/', '', $fileData['properties']['mime']);
                    $width = $fileData['properties']['width'];
                    $height = $fileData['properties']['height'];
                    $resource_data['gallery']["{$width}x{$height}"][$type][] = $file->toArray();
                }
            }
        }

        $donor_config = $donor_config ? $this->reformatConfig(json_decode($donor_config, 1)) : array(); // декодируем конфиг в массив
        $sections = array_merge($donor_config, $config);
        uasort($sections, function ($a, $b) {
            if ($a['position'] == $b['position']) {
                return 0;
            }
            return ($a['position'] < $b['position']) ? -1 : 1;
        });
        $path_to_dist = $this->core_path . $this->pdotools_elements_path . $this->path_to_dist . $rid . $lang_key . $this->extension; // формируем имя файла
        $html = ''; // готовим переменную для содержимого файла
        $i = 1;

        if (!empty($sections)) {
            foreach ($sections as $section) {
                // пропускаем базовую секцию или ту, которую нужно скрыть
                if ($section['MIGX_formname'] === $this->base_section_name || $section['hide_section']) {
                    continue;
                }

                if($section['is_static'] && $static_config){

                    $section = $static_config[$section['section_name']];
                }
                $section['contacts'] = $this->getContacts();
                $section['rid'] = $rid; // передаем на страницу id текущего ресурса
                $section['idx'] = $i; // передаем на страницу порядковый номер секции
                $section['sbp_id'] = $this->sbp_id; // передаем на страницу id ресурса со статичными блоками
                $section['cp_id'] = $this->cp_id; // передаем на страницу id ресурса с контактами

                $sets = "{set \$section = '!getStaticSection'| snippet:['section_name' => '{$section['MIGX_formname']}', 'lang_key' => '{$lang_key}']}{if \$section}";

                foreach ($section as $key => $value) {
                    if (is_string($value) && strpos($value, '[{') !== false) {
                        $section[$key] = $this->jsonDecodeValue(json_decode($value, 1)); // преобразуем поля типа migx в массив
                    }

                    if ($section['is_static']) {
                        $keys = array_keys($this->exclude_fields);
                        if (!in_array($key, $keys)) {
                            $sets .= "{set \$$key = \$section.$key}";
                        }
                    }
                }
                $sets .= '{/if}';
                $section['resource'] = $resource_data; // передаем на страницу все поля ресурса
                /** TODO Переключение со статичной на не статичную секции через админку. Сейчас это не работает потому что в разметке есть ## */
                $chunkName = $section['MIGX_formname']; // получаем имя чанка
                $chunk = $this->section_chunk_prefix . strtolower($chunkName) . $this->extension; // получаем путь к чанку
                $tmp = $this->pdo->parseChunk($chunk, $section); // парсим чанк
                if ($section['is_static']) {
                    $tmp = $sets . $tmp;
                }
                $html .= str_replace('##', '{', $tmp); // чтобы на фронте работал парсер pdoTools
                $i++;
            }

            // генерируем файл
            if (!file_put_contents($path_to_dist, $html)) {
                $this->error('[MigxPageConfigurator::parseConfig] Не удалось создать файл ' . $path_to_dist);
            }
        }
    }


    /**
     * @param $value
     * @return mixed
     */
    public function jsonDecodeValue($value)
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                if (!is_array($item)) {
                    continue;
                }
                foreach ($item as $k => $v) {
                    if (is_string($v) && strpos($v, '[{') !== false) {
                        $item[$k] = json_decode($v, 1); // преобразуем поля типа migx в массив
                        $this->jsonDecodeValue($value[$k]);
                    }
                }
                $value[$key] = $item;
            }
        }

        return $value;
    }


    /**
     *
     * @param modResource $resource
     */
    public function copyConfig(modResource $resource)
    {
        $template = $resource->get('template');
        $parent = $resource->get('parent');
        if ($parent !== $this->sbp_id) {
            if ($template && $this->modx->getCount('modTemplateVarTemplate', array('tmplvarid' => $this->config_tv_id, 'templateid' => $template))) {
                if ($donor = $this->modx->getObject('modResource', array('template' => $template, 'parent' => $this->sbp_id))) {
                    if ($donor_config = $donor->getTVValue($this->common_config_name)) {
                        $config = $resource->getTVValue($this->common_config_name);
                        $copy_all = $resource->getTVValue($this->copy_config_tvname);
                        if (!$config && $copy_all) {
                            $resource->setTVValue($this->common_config_name, $donor_config); // копируем конфиг полностью
                            $resource->setTVValue($this->copy_config_tvname, false);
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
                                $resource->setTVValue($this->common_config_name, $config);
                            }
                        }
                    }
                }
            }
        }
    }

    public function copyPolylangConfig($rid, $lang_key)
    {
        $resource = $this->modx->getObject('modResource', $rid);
        if ($resource) {
            $polyLangTvParams = [
                'culture_key' => $lang_key,
                'content_id' => $rid
            ];
            $sql = "SELECT {$this->table_prefix}site_tmplvars.id, {$this->table_prefix}site_tmplvars.name FROM {$this->table_prefix}site_tmplvars                 
                WHERE {$this->table_prefix}site_tmplvars.name IN ('{$this->copy_config_tvname}','config', 'form_list', 'contacts')";

            if ($statement = $this->modx->query($sql)) {
                $tvs = $statement->fetchAll(PDO::FETCH_ASSOC);
                $data = [];
                foreach ($tvs as $tv) {
                    $data[$tv['name']] = $tv['id'];
                }
                if ($polylangTvCopyConfig = $this->modx->getObject('PolylangTv', array_merge($polyLangTvParams, ['tmplvarid' => $data[$this->copy_config_tvname]]))) {
                    foreach ($data as $name => $id) {
                        if ($name === $this->copy_config_tvname) continue;
                        if (!$polylangTvConfig = $this->modx->getObject('PolylangTv', array_merge($polyLangTvParams, ['tmplvarid' => $id]))) {
                            $polylangTvConfig = $this->modx->newObject('PolylangTv');
                        }
                        if ($tvValue = $resource->getTVValue($name)) {
                            $polylangTvConfig->fromArray(array_merge($polyLangTvParams, ['tmplvarid' => $id, 'value' => $tvValue]), '', 1);
                            $polylangTvConfig->save();
                        }
                    }
                    $polylangTvCopyConfig->set('value', 0);
                    $polylangTvCopyConfig->save();
                }
            }
        }
    }

    /**
     *
     * @param array $config
     * @return array
     */
    private function reformatConfig(array $config): array
    {
        $result = array();
        if (!empty($config)) {
            $c = 1;
            foreach ($config as $item) {
                $key = $item['section_name'];
                $item['position'] = (int)$item['position'] ?: $c++;
                $item['copy_from_origin'] = 0;
                $result[$key] = $item;
            }
        }
        return $result;
    }


    /**
     * @param $msg
     * @param $data
     * @return void
     */
    private function error($msg, $data = array())
    {
        $this->modx->log(1, $msg . print_r($data, 1));
        die();
    }
}