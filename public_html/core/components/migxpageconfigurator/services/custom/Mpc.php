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

    private array $parentResourceIdCache = [];

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
            'expandAttr' => $this->modx->getOption('mpc_expand_attr', null, ''),
            'pathToCreate' => $this->modx->getOption('mpc_path_to_create', null, 'create/'),
            'devMode' => $this->modx->getOption('mpc_dev_mode', null, false),
            'lazyloadEnabled' => $this->modx->getOption('mpc_lazyload_enabled', null, true),
            'expandEnabled' => $this->modx->getOption('mpc_expand_enabled', null, true),
            'lexiconsNamespace' => $this->modx->getOption('mpc_lexicons_namespace', null, 'migxpageconfigurator'),
            'useLexicons' => $this->modx->getOption('mpc_use_lexicons', '', false),
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
        if ($this->properties['devMode']) {
            $this->render->clearCache();
        }
        $this->grabber->updContent = $updContent ?? false;
        if (!$fileName) {
            $templatePath = $this->properties['pdotoolsElementsPath'] . $this->properties['pathToSrc'];
            $fileNames = $this->getFilesList($templatePath);
            foreach ($fileNames as $fileName) {
                $this->handleFile($fileName);
            }
        } else {
            $this->handleFile($fileName);
        }

        $this->refreshSiteCache();
    }

    /**
     * Инвалидация кэша после нарезки. Грабер уже сбрасывает lexicon_topics
     * (LexiconManager::createLexicons), но НЕ resource-кэш: закэшированные
     * страницы держат СТАРЫЕ значения лексикона (рендер резолвит `{key|lexicon}`
     * и кэширует результат) → переводы не подхватываются на сайте до ручной
     * очистки кэша из админки. Сбрасываем то же, что точечная запись редактора
     * (FieldWriter::afterSave): lexicon_topics + resource-кэш текущего контекста.
     */
    private function refreshSiteCache(): void
    {
        $cm = null;
        if (method_exists($this->modx, 'getCacheManager')) {
            $cm = $this->modx->getCacheManager();
        } elseif (isset($this->modx->cacheManager)) {
            $cm = $this->modx->cacheManager;
        }
        if (!$cm || !method_exists($cm, 'refresh')) {
            return;
        }
        $context = (string)($this->modx->context ? $this->modx->context->get('key') : '') ?: 'web';
        $cm->refresh([
            'lexicon_topics' => [],
            'resource' => ['contexts' => [$context]],
        ]);
    }

    /**
     * @param string $directory
     * @return array
     */
    public function getFilesList(string $directory): array
    {
        $files = [];
        $directory = rtrim($directory, '/\\');
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        $basePathLength = mb_strlen($directory) + 1;

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $relativePath = substr($file->getPathname(), $basePathLength);
                $files[] = str_replace('\\', '/', $relativePath);
            }
        }

        return $files;
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
            $resourceData = $result['data']['resource']->toArray();
            $this->render->handle($resourceData);
        }
    }

    public function getParsedConfigPath(\modResource $resource): string
    {
        $parsedPath = $this->properties['pdotoolsElementsPath'];
        $resourceData = $resource->toArray();
        $path = $this->properties['pathToDist'] . $resourceData['id'] . $this->properties['extension'];

        if (!file_exists($parsedPath . $path)) {
            $this->render->handle($resourceData);
        }

        return file_exists($parsedPath . $path) ? 'file:' . $path : '';
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
        if ($this->properties['expandAttr'] && $this->properties['expandEnabled']) {
            $this->modx->regClientScript(
                "
                <script>                
                window.mpcExpandAttr = '{$this->properties['expandAttr']}';
                </script>
                <script type=\"module\" src=\"assets/components/migxpageconfigurator/js/web/expand.js\"></script>",
                true
            );
        }
        if ($this->properties['lazyloadAttr'] && $this->properties['lazyloadEnabled']) {
            $this->modx->regClientScript(
                "
                <script>
                window.mpcLazyLoadAttr = '{$this->properties['lazyloadAttr']}';               
                </script>
                <script type=\"module\" src=\"assets/components/migxpageconfigurator/js/web/lazyload.js\"></script>",
                true
            );
        }

        if ($this->properties['useLexicons']) {
            $this->modx->regClientScript(
                "                
                <script type=\"module\" src=\"assets/components/migxpageconfigurator/js/web/languages.js\"></script>",
                true
            );
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
        // Делегируем в ЕДИНЫЙ движок (тот же, что и проектный CLI `resources apply`),
        // чтобы не было двух реализаций. Seed-формат ['context' => [items…]]
        // приводим к формату манифеста; дети — в ключе 'resources' (движок его
        // поддерживает). render передаём для copyConfig (наследование конфига типа).
        $engine = new \MpcServices\Cli\Apply\ResourcesApply($this->modx, $this->render);
        foreach ($resources as $context => $items) {
            if (!is_array($items)) {
                continue;
            }
            $engine->apply(['context' => (string)$context, 'resources' => $items], false);
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
        if ($templateName == null) {
            return 0;
        }
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

    public function loadLexicons(int $rid, ?int $templateId = 0)
    {
        $lexiconsNamespace = $this->properties['lexiconsNamespace'] . ':';
        foreach ($this->getLexiconFilenames($rid, $templateId ?: 0) as $filename) {
            $this->modx->lexicon->load($lexiconsNamespace . $filename);
        }
    }

    public function getResourceLexicons(int $rid, ?int $templateId): array
    {
        $lang = $_lang = [];
        $cultureKey = $this->modx->getOption('cultureKey');
        $baseLexiconPath = $this->properties['corePath'] . 'components/' . $this->properties['lexiconsNamespace'] . '/lexicon/' . $cultureKey . '/';
        foreach ($this->getLexiconFilenames($rid, $templateId ?: 0) as $filename) {
            $path = $baseLexiconPath . $filename . '.inc.php';
            if (!file_exists($path)) {
                continue;
            }
            include $path;
            $lang = array_merge($lang, $_lang);
        }
        return $lang;
    }

    private function getLexiconFilenames(int $rid, int $templateId): array
    {
        $names = [];
        $names[] = $this->grabber->properties['staticBlocksPageLexiconFilename'];
        $names[] = $this->grabber->properties['contactsPageLexiconFilename'];
        if ($parentResourceId = $this->getParentResourceId($templateId)) {
            if ($parentResourceId !== $rid) {
                $names[] = $this->grabber->getResourceIdentifierById($parentResourceId);
            }
        }
        $names[] = $this->grabber->getResourceIdentifierById($rid);
        return $names;
    }

    public function getParentResourceId(int $templateId): int
    {
        if (isset($this->parentResourceIdCache[$templateId])) {
            return $this->parentResourceIdCache[$templateId];
        }
        $q = $this->modx->newQuery('modResource');
        $q->select('id');
        $q->where(['template' => $templateId, 'parent' => $this->grabber->properties['staticBlocksPageId']]);
        $q->prepare();
        $result = $q->stmt->execute() ? (int)$q->stmt->fetchColumn() : 0;
        return $this->parentResourceIdCache[$templateId] = $result;
    }

    public function setLanguageSettings()
    {
        $availableLanguages = $this->getContextSettingValue('mpc_available_languages');
        $availableLanguages = explode(',', $availableLanguages);
        if (!isset($_COOKIE['mpc_lang']) || !in_array($_COOKIE['mpc_lang'], $availableLanguages)) {
            $defaultLang = $this->getContextSettingValue('mpc_default_language');
            $host = $this->getContextSettingValue('http_host');
            setcookie('mpc_lang', $defaultLang, 0, '/', $host);
            $_COOKIE['mpc_lang'] = $defaultLang;
        }

        if (!empty($_COOKIE['mpc_lang'])) {
            $this->modx->setOption('cultureKey', $_COOKIE['mpc_lang']);
        }
    }

    private function getContextSettingValue($settingKey)
    {
        // Глобальное значение — база; контекстная настройка переопределяет её,
        // только если реально задана. Иначе fetchColumn() на пустой выборке
        // вернул бы false и затёр глобальное значение (был такой баг → настройки
        // из глобалки/модалки не подхватывались без context-override).
        $settingValue = $this->modx->getOption($settingKey);
        $q = $this->modx->newQuery('modContextSetting');
        $q->select('value');
        $q->where(['key' => $settingKey, 'context_key' => $this->modx->context->get('key')]);
        $q->prepare();
        if ($q->stmt->execute()) {
            $ctxValue = $q->stmt->fetchColumn();
            if ($ctxValue !== false) {
                $settingValue = $ctxValue;
            }
        }
        return $settingValue;
    }
}
