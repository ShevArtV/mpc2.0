<?php
/**
 * Сервис для работы с модулем MigxPageConfigurator
 */

namespace MpcServices;

use MpcServices\Handlers\Grabber;
use MpcServices\Handlers\Cutter;
use MpcServices\Handlers\Render;
use MpcServices\Helpers\Logging;
use MpcServices\Helpers\Response;

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
     * Request-scoped singleton (см. instance()).
     */
    private static ?self $instance = null;

    /**
     * @param \modX $modx
     */
    public function __construct(\modX $modx)
    {
        $this->modx = $modx;
        $this->initialize();
    }

    /**
     * Request-scoped singleton для ФРОНТ-ЧТЕНИЯ: рендер, lexicon-модификаторы Fenom
     * (reslexicons/lexiconsarr) и сниппеты (getstaticsection/mpccontacts/
     * getparsedconfigpath). Конструктор дорогой (поднимает Grabber/Cutter/Render +
     * пресеты/сэмплы), а на каталоге эти точки зовутся десятки раз — без шаринга это
     * N× тяжёлой инициализации и постоянно пустой parentResourceIdCache.
     *
     * Шаринг между ресурсами/секциями ОДНОГО запроса безопасен: инстанс
     * ресурс-нейтрален — ресурс везде передаётся аргументом (loadLexicons/
     * getResourceLexicons/getParsedConfigPath), берётся per-call из $modx->resource
     * (getstaticsection) или переустанавливается в начале Render::prepareResourceData
     * (currentTheme/wrapperTpl/contacts); кэши ключуются по resourceId/templateId
     * или инвариантны на запрос. В PHP-FPM статика живёт один HTTP-запрос.
     *
     * НЕ использовать для НАРЕЗКИ (process): Grabber/Cutter держат per-file
     * состояние — для CLI и OnDocFormSave создавайте отдельный new Mpc().
     */
    public static function instance(\modX $modx): self
    {
        return self::$instance ??= new self($modx);
    }

    /**
     * @return void
     */
    private function initialize()
    {
        $this->logging = new Logging($this->modx);
        $this->logging->setProcess('migxpageconfigurator_' . substr(md5((string) session_id()), 0, 12));

        $this->properties = [
            'corePath' => $this->modx->getOption('core_path', null, ''),
            'pdotoolsElementsPath' => $this->modx->getOption('pdotools_elements_path', null, '{core_path}elements/'),
            'pathToDist' => $this->modx->getOption('mpc_path_to_dist', null, 'parsed/'),
            'extension' => $this->modx->getOption('mpc_tpl_file_extension', null, '.tpl'),
            'pathToSrc' => $this->modx->getOption('mpc_path_to_src', null, 'templates/'),
            'lazyloadAttr' => $this->modx->getOption('mpc_lazyload_attr', null, ''),
            'expandAttr' => $this->modx->getOption('mpc_expand_attr', null, ''),
            'devMode' => $this->modx->getOption('mpc_dev_mode', null, false),
            'lazyloadEnabled' => $this->modx->getOption('mpc_lazyload_enabled', null, true),
            'expandEnabled' => $this->modx->getOption('mpc_expand_enabled', null, true),
            'lexiconsNamespace' => $this->modx->getOption('mpc_lexicons_namespace', null, 'migxpageconfigurator'),
            'useLexicons' => $this->modx->getOption('mpc_use_lexicons', '', false),
            'themesSubdir' => trim((string)$this->modx->getOption('mpc_themes_subdir', null, '_themes/')),
        ];

        $this->properties['pdotoolsElementsPath'] = str_replace('{core_path}', '', $this->properties['pdotoolsElementsPath']);
        $this->properties['pdotoolsElementsPath'] = str_replace('//', '/', $this->properties['pdotoolsElementsPath']);
        $this->properties['pdotoolsElementsPath'] = str_replace('\\', '/', $this->properties['pdotoolsElementsPath']);
        $this->properties['corePath'] = str_replace('\\', '/', $this->properties['corePath']);
        if (strpos($this->properties['pdotoolsElementsPath'], $this->properties['corePath']) === false) {
            $this->properties['pdotoolsElementsPath'] = $this->properties['corePath'] . $this->properties['pdotoolsElementsPath'];
        }

        // Один Logging/Response на запрос — инжектим в хендлеры (DI), вместо
        // создания по экземпляру в каждом Base::initialize.
        $response = new Response($this->logging);
        $this->grabber = new Grabber($this->modx, $this->properties, $this->logging, $response);
        $this->cutter = new Cutter($this->modx, $this->properties, $this->logging, $response);
        $this->render = new Render($this->modx, $this->properties, $this->logging, $response);
    }


    /**
     * Нарезка одного файла или всех (fileName=null → all). Возвращает сводку:
     * ['success'=>хоть один файл реально нарезан, 'processed'=>сколько пытались,
     *  'ok'=>успешно, 'failed'=>сколько не нарезано, 'messages'=>причины неудач].
     * success=false, когда резать было нечего (файл не найден/пуст; для all — нет
     * файлов шаблонов) — чтобы вызывающий (CLI) не рапортовал ложный успех.
     *
     * @param string|null $fileName
     * @param bool|null $updContent
     * @return array
     */
    public function process(?string $fileName, ?bool $updContent, string $theme = ''): array
    {
        if ($this->properties['devMode']) {
            $this->render->clearCache();
        }

        // Нарезка темы: тот же Cutter, но исходник читается из подпапки темы, а
        // вёрстка пишется в подпапку темы. Grabber/Render НЕ запускаем — контент
        // (mpc_config/лексиконы/медиа) общий для всех тем, его не дублируем и не
        // перетираем. Без темы — обычный полный пайплайн (Grabber→Cutter→Render).
        $isTheme = trim($theme) !== '';
        if ($isTheme) {
            $this->cutter->setTheme($theme, $this->properties['themesSubdir']);
        } else {
            $this->grabber->updContent = $updContent ?? false;
        }

        $results = [];
        if (!$fileName) {
            $templatePath = $this->properties['pdotoolsElementsPath'] . $this->properties['pathToSrc'];
            if ($isTheme) {
                $templatePath .= rtrim($this->properties['themesSubdir'], '/') . '/' . trim($theme) . '/';
            }
            foreach ($this->getFilesList($templatePath) as $fn) {
                $results[$fn] = $isTheme ? $this->handleThemeFile($fn) : $this->handleFile($fn);
            }
        } else {
            $results[$fileName] = $isTheme ? $this->handleThemeFile($fileName) : $this->handleFile($fileName);
        }

        if ($isTheme) {
            // Тема сменила вёрстку — старые parsed пересоберутся лениво под тему.
            $this->render->clearCache();
        }
        $this->refreshSiteCache();

        $ok = 0;
        $messages = [];
        foreach ($results as $r) {
            if (!empty($r['success'])) {
                $ok++;
            } elseif (!empty($r['message'])) {
                $messages[] = $r['message'];
            }
        }
        return [
            'success'   => $ok > 0,
            'processed' => count($results),
            'ok'        => $ok,
            'failed'    => count($results) - $ok,
            'messages'  => $messages,
        ];
    }

    /**
     * Сохранить ОДИН контакт по HTML-фрагменту (data-mpc-contact + data-mpc-cfield)
     * — пер-полевая правка контакта из визуального редактора. Переиспользует
     * грабер-логику (ContactUpdater) + сбрасывает кэш, чтобы перевод подхватился.
     */
    public function saveContact(string $html): void
    {
        $this->grabber->updContent = true;
        $this->grabber->handleContactsHtml($html);
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
     * Нарезать один файл. Возвращает результат грабера ['success','message','data']:
     * для пустого/несуществующего файла грабер отдаёт success=false — тогда резать
     * нечего, cutter/render пропускаем (раньше гонялись вхолостую, а CLI всё равно
     * рапортовал успех).
     *
     * @param string $fileName
     * @return array
     */
    public function handleFile($fileName): array
    {
        $result = $this->grabber->handle($fileName);
        if (empty($result['success'])) {
            return $result;
        }
        $this->cutter->handle($fileName);
        if (!empty($result['data']['resource'])) {
            $this->render->handle($result['data']['resource']->toArray());
        }
        return $result;
    }

    /**
     * Нарезать один файл В ТЕМУ: только Cutter (вёрстка → подпапка темы), без
     * Grabber/Render. Контент не трогается. Cutter::setTheme должен быть вызван
     * заранее (Mpc::process). Возвращает результат cutter ['success','message'].
     *
     * @param string $fileName
     * @return array
     */
    public function handleThemeFile($fileName): array
    {
        return $this->cutter->handle($fileName);
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

    /**
     * Настройки пакета в новый контекст — только те, которых там ещё нет.
     *
     * Контекст создают и копированием существующего (ядровой `context/duplicate`), и тогда
     * часть mpc-настроек в нём уже есть — от донора, со своими значениями. Прежний код
     * всегда делал `newObject()->save()`, то есть INSERT: MySQL отвечал `Duplicate entry
     * '<ctx>-mpc_default_language' for key 'PRIMARY'`, и на каждый новый контекст в
     * `core/cache/logs/error.log` падало по три ошибки (замечено 2026-07-31 на проекте,
     * где контексты заводятся копированием).
     *
     * Существующие записи не трогаем: значение донора осмысленно (язык, список языков), а
     * задача метода — донести недостающие настройки, а не переписать настроенное.
     */
    public function copySystemSettingsToNewContext(\modContext $context)
    {
        $contextKey = $context->get('key');
        $settings = $this->modx->getIterator('modSystemSetting', array('namespace' => 'migxpageconfigurator'));
        foreach ($settings as $setting) {
            $setting = $setting->toArray();
            $setting['context_key'] = $contextKey;

            if ($this->modx->getCount('modContextSetting', array('context_key' => $contextKey, 'key' => $setting['key']))) {
                continue;
            }

            $ctxSetting = $this->modx->newObject('modContextSetting');
            $ctxSetting->fromArray($setting, '', true);
            $ctxSetting->save();
        }
    }

    public function loadWebScripts()
    {
        $jsFlags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT;
        if ($this->properties['expandAttr'] && $this->properties['expandEnabled']) {
            $expandAttr = json_encode((string)$this->properties['expandAttr'], $jsFlags);
            $this->modx->regClientScript(
                "
                <script>
                window.mpcExpandAttr = {$expandAttr};
                </script>
                <script type=\"module\" src=\"assets/components/migxpageconfigurator/js/web/expand.js\"></script>",
                true
            );
        }
        if ($this->properties['lazyloadAttr'] && $this->properties['lazyloadEnabled']) {
            $lazyAttr = json_encode((string)$this->properties['lazyloadAttr'], $jsFlags);
            $this->modx->regClientScript(
                "
                <script>
                window.mpcLazyLoadAttr = {$lazyAttr};
                </script>
                <script type=\"module\" src=\"assets/components/migxpageconfigurator/js/web/lazyload.js\"></script>",
                true
            );
        }

        if ($this->properties['useLexicons']) {
            // Имя/домен cookie языка прокидываем во фронт, чтобы JS-селектор
            // ставил cookie ровно так же, как PHP (одно имя, один домен) —
            // иначе на поддоменах появляются два конфликтующих cookie.
            $cookieName   = json_encode($this->getContextSettingValue('mpc_lang_cookie_name') ?: 'mpc_lang', $jsFlags);
            $cookieDomain = json_encode((string)$this->getContextSettingValue('mpc_lang_cookie_domain'), $jsFlags);
            $this->modx->regClientScript(
                "
                <script>
                window.mpcLangConfig = {cookieName: {$cookieName}, cookieDomain: {$cookieDomain}};
                </script>
                <script type=\"module\" src=\"assets/components/migxpageconfigurator/js/web/languages.js\"></script>",
                true
            );
        }
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
        // cultureKey идёт в путь к файлу лексикона — basename отсекает traversal
        // (defense-in-depth: значение могло прийти не только из mpc_lang-cookie).
        $cultureKey = basename((string)$this->modx->getOption('cultureKey'));
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

        // Топики произвольных лексиконов (data-mpc-lexicon): грузим их, чтобы
        // {'key'|lexicon} резолвился на лайве (mpc-модификатор отдаёт пусто для
        // незагруженного ключа) и значения читались редактором.
        foreach (($this->grabber->properties['arbitraryLexiconTopics'] ?? []) as $topic) {
            if (\MpcServices\Handlers\ArbitraryLexicon::validTopic((string)$topic)) {
                $names[] = (string)$topic;
            }
        }

        return array_values(array_unique(array_filter($names, 'strlen')));
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
        // Имя и домен cookie языка — из настроек (контекстно-переопределяемых).
        // Имя: одинаковое на домене/поддоменах → язык общий; разное (контекстная
        // настройка) → независимый. Домен: пусто → текущий http_host; задан
        // (например .site.ru) → cookie доступна на всех поддоменах.
        $cookieName   = $this->getContextSettingValue('mpc_lang_cookie_name') ?: 'mpc_lang';
        $cookieDomain = $this->getContextSettingValue('mpc_lang_cookie_domain') ?: $this->getContextSettingValue('http_host');

        $availableLanguages = array_map('trim', explode(',', (string)$this->getContextSettingValue('mpc_available_languages')));
        $defaultLang        = $this->getContextSettingValue('mpc_default_language');

        // Точка расширения: проект может изменить набор языков / язык по умолчанию /
        // имя cookie или вовсе пропустить установку (skip=true) — например, когда
        // язык определяется самим проектом после switchContext. Возврат — через
        // $modx->event->returnedValues (idiom MODX): available[], default, cookieName, skip.
        $this->modx->invokeEvent('mpcOnBeforeSetLanguageSettings', [
            'available'  => $availableLanguages,
            'default'    => $defaultLang,
            'cookieName' => $cookieName,
            'Mpc'        => $this,
        ]);
        $rv = (isset($this->modx->event->returnedValues) && is_array($this->modx->event->returnedValues))
            ? $this->modx->event->returnedValues : [];
        if (!empty($rv['skip'])) {
            return;
        }
        if (!empty($rv['available'])) {
            $availableLanguages = is_array($rv['available'])
                ? $rv['available']
                : array_map('trim', explode(',', (string)$rv['available']));
        }
        if (isset($rv['default']) && $rv['default'] !== '') {
            $defaultLang = $rv['default'];
        }
        if (!empty($rv['cookieName'])) {
            $cookieName = $rv['cookieName'];
        }

        if (!isset($_COOKIE[$cookieName]) || !in_array($_COOKIE[$cookieName], $availableLanguages, true)) {
            setcookie($cookieName, $defaultLang, 0, '/', $cookieDomain);
            $_COOKIE[$cookieName] = $defaultLang;
        }

        if (!empty($_COOKIE[$cookieName])) {
            // cookie клиентский → попадает в cultureKey, а тот в путь к файлу
            // лексикона (getResourceLexicons). basename режет traversal, не
            // ломая формат culture (как в FieldWriter, решение S12).
            $this->modx->setOption('cultureKey', basename((string)$_COOKIE[$cookieName]));
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
