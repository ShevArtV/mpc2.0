<?php

/**
 * Тонкий фасад: оркестрирует специализированные подклассы из Grabber/.
 */

namespace MpcServices\Handlers;

use MpcServices\Handlers\Grabber\ContactUpdater;
use MpcServices\Handlers\Grabber\ContentParser;
use MpcServices\Handlers\Grabber\FieldValueExtractor;
use MpcServices\Handlers\Grabber\InformationUpdater;
use MpcServices\Handlers\Grabber\LexiconManager;
use MpcServices\Handlers\Grabber\MediaDownloader;
use MpcServices\Handlers\Grabber\SectionProcessor;
use MpcServices\Handlers\Grabber\TemplateUpdater;

/**
 * @author Arthur Shevchenko (https://t.me/ShevArtV)
 */
class Grabber extends Base
{
    public bool  $updContent    = false;
    public bool  $fromPlugin    = false;
    public array $resourceValues = [];

    private string $fileName = '';

    private LexiconManager      $lexiconManager;
    private MediaDownloader     $mediaDownloader;
    private FieldValueExtractor $fieldValueExtractor;
    private ContentParser       $contentParser;
    private SectionProcessor    $sectionProcessor;
    private InformationUpdater  $informationUpdater;
    private ContactUpdater      $contactUpdater;
    private TemplateUpdater     $templateUpdater;

    // -----------------------------------------------------------------------
    // Обратная совместимость: $grabber->lexicons
    // -----------------------------------------------------------------------

    public function __get(string $name)
    {
        if ($name === 'lexicons') {
            return $this->lexiconManager->lexicons;
        }
        return null;
    }

    public function __set(string $name, $value): void
    {
        if ($name === 'lexicons') {
            $this->lexiconManager->lexicons = $value;
        }
    }

    // -----------------------------------------------------------------------
    // Инициализация
    // -----------------------------------------------------------------------

    protected function initialize(): void
    {
        parent::initialize();
        // excludeLexiconFields подгружается в Base::initialize() — он же
        // используется Cutter-ом через тот же путь.

        $downloadPaths = $this->modx->getOption('mpc_download_paths', '', ['images' => '', 'videos' => '', 'audios' => '', 'others' => '']);
        $downloadPaths = is_array($downloadPaths) ? $downloadPaths : (json_decode($downloadPaths, true) ?: []);

        $defaultMimeToExt = '{"image/jpeg":"jpg","image/png":"png","image/gif":"gif","image/webp":"webp","image/svg+xml":"svg","image/avif":"avif","video/mp4":"mp4","video/webm":"webm","video/ogg":"ogv","audio/mpeg":"mp3","audio/ogg":"ogg","audio/wav":"wav","audio/webm":"webm","text/plain":"txt","application/pdf":"pdf"}';
        $mimeToExt = $this->modx->getOption('mpc_mime_to_ext', '', $defaultMimeToExt);
        $mimeToExt = is_array($mimeToExt) ? $mimeToExt : (json_decode($mimeToExt, true) ?: []);

        $this->properties = array_merge($this->properties, [
            'startPageId'             => $this->modx->getOption('site_start', null, 1),
            'phoneRegExp'             => $this->modx->getOption('mpc_phone_regexp', '', '/(\d)(\d{3})(\d{3})(\d{2})(\d{2})$/'),
            'phoneFormat'             => $this->modx->getOption('mpc_phone_format', '', '8 (\2) \3-\4-\5'),
            'downloadPaths'           => $downloadPaths,
            'mediaSourceId'           => (int)$this->modx->getOption('mpc_media_source', null, 0),
            'mediaPath'               => $this->modx->getOption('mpc_media_path', null, 'assets/components/migxpageconfigurator/media/'),
            'lexiconPath'             => $this->modx->getOption('mpc_lexicon_path', '', 'components/migxpageconfigurator/lexicon/'),
            'resourceLexiconKeysPath' => $this->modx->getOption('mpc_resource_lexicon_keys_path', '', 'components/migxpageconfigurator/services/resource_lexicon_keys.inc.php'),
            'allowModxTags'           => $this->modx->getOption('mpc_allow_modx_tags', '', false),
            'downloadExtensions'      => explode(',', $this->modx->getOption('mpc_download_extensions', '', '')),
            'mimeToExt'               => $mimeToExt,
            'allowedTags'             => explode(',', $this->modx->getOption('mpc_allowed_tags', '', '')),
            'lexiconFilenameField'    => $this->modx->getOption('mpc_lexicon_filename_field', '', 'id'),
        ]);

        $this->modx->addPackage('migx', $this->properties['corePath'] . 'components/migx/model/');

        // LexiconManager создаётся первым — нужен для getResourceIdentifierById в initialize
        $this->lexiconManager = new LexiconManager($this->modx, $this->properties);

        if ($this->properties['useLexicons']) {
            $basePath = $this->properties['corePath']
                . $this->properties['lexiconPath']
                . $this->properties['defaultLanguageKey'] . '/';

            if (!file_exists($basePath)) {
                mkdir($basePath, 0755, true);
            }

            $staticId   = $this->lexiconManager->getResourceIdentifierById((int)$this->properties['staticBlocksPageId']);
            $contactsId = $this->lexiconManager->getResourceIdentifierById((int)$this->properties['contactsPageId']);

            $this->properties['basePathToLexiconFile']           = $basePath;
            $this->properties['staticBlocksPageLexiconFilename'] = $staticId;
            $this->properties['contactsPageLexiconFilename']     = $contactsId;

            $this->lexiconManager->updateProperties($this->properties);
            $this->lexiconManager->lexicons[$staticId]   = $this->lexiconManager->getLexicons($staticId, $basePath);
            $this->lexiconManager->lexicons[$contactsId] = $this->lexiconManager->getLexicons($contactsId, $basePath);
        }

        $this->mediaDownloader     = new MediaDownloader($this->modx, $this->properties);
        $this->fieldValueExtractor = new FieldValueExtractor($this->properties, $this->mediaDownloader, $this->lexiconManager, $this->parser);
        $this->contentParser       = new ContentParser($this->parser, $this->fieldValueExtractor);
        $this->sectionProcessor    = new SectionProcessor($this->modx, $this->properties, $this->parser, $this->contentParser, $this->lexiconManager, $this->mediaDownloader, $this->response);
        $this->informationUpdater  = new InformationUpdater($this->modx, $this->properties, $this->parser);
        $this->contactUpdater      = new ContactUpdater($this->modx, $this->properties, $this->parser, $this->fieldValueExtractor, $this->lexiconManager);
        $this->templateUpdater     = new TemplateUpdater($this->modx, $this->properties);

        if ($this->debug) {
            $this->logging->write(__METHOD__, 'Properties:', $this->properties);
        }
    }

    // -----------------------------------------------------------------------
    // Основной метод обработки
    // -----------------------------------------------------------------------

    public function handle(string $fileName): array
    {
        $this->fileName = $fileName;

        if ($this->debug) {
            $this->logging->write(__METHOD__, "Handle file $this->fileName");
        }

        if (!$html = $this->getFileContent($this->fileName)) {
            return $this->response->error(__METHOD__, "File $this->fileName is empty");
        }

        $this->informationUpdater->handleInformation($html, $this->updContent);
        $this->contactUpdater->handleContacts($html, $this->updContent);

        if (strpos($this->fileName, 'wrapper') === false) {
            if ($newResource = $this->templateUpdater->handleTemplate($html)) {
                $this->properties['resource'] = $newResource;
                $this->lexiconManager->updateProperties(['resource' => $newResource]);
                $this->sectionProcessor->properties['resource'] = $newResource;
            }

            $this->sectionProcessor->properties['updContent'] = $this->updContent;
            $this->sectionProcessor->properties['fromPlugin']  = $this->fromPlugin;
            $this->sectionProcessor->properties['fileName']    = $this->fileName;
            $this->sectionProcessor->handleSections($html);
        }

        return $this->response->success(
            __METHOD__,
            "Processing of file $this->fileName is completed",
            ['resource' => $this->properties['resource']]
        );
    }

    /**
     * Явно нацелить грабер на ресурс — носитель mpc_config (текущая страница).
     * Нужно для grab-from-HTML (визуальный редактор): edit-mode HTML не несёт
     * authoring-маркер `<!--##…##-->`, поэтому handleTemplate не переключит
     * ресурс, и его надо задать руками. Прокидываем в подхэндлеры, как это
     * делает handle() в ветке смены типа.
     */
    public function setTargetResource(\modResource $resource): void
    {
        $this->properties['resource'] = $resource;
        $this->lexiconManager->updateProperties(['resource' => $resource]);
        $this->sectionProcessor->properties['resource'] = $resource;
    }

    // -----------------------------------------------------------------------
    // Публичные прокси-методы (обратная совместимость)
    // -----------------------------------------------------------------------

    public function setCurrentSectionName(string $name): void
    {
        $this->mediaDownloader->setCurrentSectionName($name);
    }

    public function checkDownloadExtension(string $attrValue): string
    {
        return $this->mediaDownloader->checkDownloadExtension($attrValue);
    }

    public function download(string $url, string $path): string
    {
        return $this->mediaDownloader->download($url, $path);
    }

    public function downloadImage(string $attrValue, string $language = ''): string
    {
        return $this->mediaDownloader->downloadImage($attrValue, $language);
    }

    public function downloadVideo(string $attrValue, string $language = ''): string
    {
        return $this->mediaDownloader->downloadVideo($attrValue, $language);
    }

    public function downloadAudio(string $attrValue, string $language = ''): string
    {
        return $this->mediaDownloader->downloadAudio($attrValue, $language);
    }

    public function downloadFile(string $attrValue, string $type = 'others', string $language = ''): string
    {
        return $this->mediaDownloader->downloadFile($attrValue, $type, $language);
    }

    public function sanitizeValue(?string $value = ''): string
    {
        return $this->lexiconManager->sanitizeValue($value);
    }

    public function getLexicons(string $rid, string $basePath): array
    {
        return $this->lexiconManager->getLexicons($rid, $basePath);
    }

    public function getResourceIdentifierById(int $rid): string
    {
        return $this->lexiconManager->getResourceIdentifierById($rid);
    }

    public function createLexicons(array $allLexicons, ?bool $overwrite = null): void
    {
        // По умолчанию уважаем updContent: без `1` существующие переводы не перезаписываем.
        $this->lexiconManager->createLexicons($allLexicons, $overwrite ?? $this->updContent);
    }
}
