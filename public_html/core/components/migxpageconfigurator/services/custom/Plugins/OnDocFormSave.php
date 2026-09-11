<?php

/**
 * Сервис для обработки события OnDocFormSave
 */

namespace MpcServices\Plugins;

use MpcServices\Handlers\Grabber\LexiconPrefixMatcher;
use MpcServices\Mpc;

/**
 * @author Arthur Shevchenko (https://t.me/ShevArtV)
 */
class OnDocFormSave extends PluginHandler
{
    /**
     * @return void
     */
    public function run(): void
    {
        // Аудит правок из админки в лог изменений — ДО re-cut (handleFile его бы
        // перетёр своим ре-грабом). Диффит со снимком OnBeforeDocFormSave.
        $resource = $this->scriptProperties['resource'] ?? null;
        if (!$resource instanceof \modResource) {
            return; // событие без ресурса (или не modResource) — нечего обрабатывать
        }
        (new \MpcServices\Handlers\AdminAudit($this->modx))->logChanges(
            $resource,
            (int)$this->modx->getOption('mpc_static_block_page_id', null, 1)
        );

        $ctx = $resource->get('context_key');
        if ($this->modx->context->get('key') !== $ctx) {
            $this->modx->switchContext($ctx);
        }
        $Mpc = new Mpc($this->modx);
        $Mpc->render->copyConfig($this->scriptProperties['resource']);
        $Mpc->grabber->fromPlugin = true;
        if ($typeResource = $this->modx->getObject('modResource', [
            'template' => $this->scriptProperties['resource']->get('template'),
            'parent' => $Mpc->cutter->properties['staticBlocksPageId']
        ])) {
            // Имя файла типа: новое каноничное поле file_name (mpc_type),
            // фоллбэк на introtext для ещё не перенарезанных типов.
            $fileName = $typeResource->get('file_name') ?: $typeResource->get('introtext');

            /* Сам ли сохраняемый ресурс — тип страницы. Проверяем по class_key, а
             * не по родителю: контекстные клоны типов лежат под клоном коллекции
             * (у latam-ex это 25320, а не 472), поэтому поиск типа по parent из
             * настройки всегда отдаёт веб-оригинал, и сравнение id для клона врёт.
             * Типу наследовать не от кого — ресурсный словарь за него не пишем:
             * алиас у клона тот же, что у оригинала, и запись уходила прямиком в
             * словарь типа страницы (#2609-151). */
            $rid = (int)$this->scriptProperties['id'];
            $isTypeItself = $this->scriptProperties['resource']->get('class_key') === 'mpcType'
                || (int)$typeResource->get('id') === $rid;

            /* Конфиг, которым страница рендерится: тип — база, ресурс перекрывает
             * одноимённые секции (как в Render::parseConfig). Решение о статике
             * писатель и читатель обязаны принимать по одному конфигу. */
            $config = $isTypeItself
                ? $Mpc->cutter->getSectionConfig($rid)
                : $Mpc->cutter->getMergedSectionConfig((int)$typeResource->get('id'), $rid);

            $Mpc->cutter->staticSectionNames = $Mpc->grabber->staticSectionNames = $Mpc->cutter->getStaticSectionNamesFromConfig($config);

            /* Сохранение в не-web контексте режет тот же HTML-шаблон, где тексты
             * на базовом языке. Показываем граберу, какие ключи в культуре этого
             * контекста уже переведены выше ресурса — словарём типа страницы и
             * словарём статичных блоков: их значения вёрстка не подменяет
             * (#2609-155). */
            if (!$isTypeItself && $Mpc->grabber->isForeignCulture()) {
                $basePath = (string)$Mpc->grabber->properties['basePathToLexiconFile'];
                $typeLexiconFilename = $Mpc->grabber->getResourceIdentifierById((int)$typeResource->get('id'));
                $Mpc->grabber->setCultureBaseline(array_keys(
                    $Mpc->grabber->getLexicons($typeLexiconFilename, $basePath)
                    + $Mpc->grabber->getLexicons(
                        (string)$Mpc->grabber->properties['staticBlocksPageLexiconFilename'],
                        $basePath
                    )
                ));
            }

            $Mpc->handleFile($fileName);

            if (!$isTypeItself) {
                $this->manageResourceLexicons($this->scriptProperties['resource'], $Mpc, $config);
            }
        }
        if ($this->scriptProperties['id'] === $Mpc->grabber->properties['staticBlocksPageId']) {
            $this->filterStaticSectionsLexicons($Mpc);
        }
        if ($this->scriptProperties['id'] === $Mpc->grabber->properties['contactsPageId']) {
            $this->filterContactsLexicons($Mpc);
        }

        // Сносим parsed: нестатичные секции держат ЗАПЕЧЁННЫЕ значения, а файл
        // регенерится только при отсутствии. Правка ресурса-ТИПА (донор, parent =
        // staticBlocksPage) влияет на наследующие → сносим весь parsed; иначе —
        // файл этого ресурса.
        $sbp = (int)$Mpc->cutter->properties['staticBlocksPageId'];
        if ((int)$this->scriptProperties['resource']->get('parent') === $sbp) {
            $Mpc->render->clearCache();
        } else {
            $Mpc->render->deleteParsedConfigFile((int)$this->scriptProperties['id']);
        }
    }

    /**
     * @param Mpc $Mpc
     * @return void
     */
    public function filterStaticSectionsLexicons(Mpc $Mpc): void
    {
        $staticBlocksPageId = $Mpc->grabber->properties['staticBlocksPageId'];
        $staticBlocksPageLexiconFilename = $Mpc->grabber->properties['staticBlocksPageLexiconFilename'];
        $lexicons[$staticBlocksPageLexiconFilename] = $Mpc->grabber->getLexicons($staticBlocksPageLexiconFilename, $Mpc->grabber->properties['basePathToLexiconFile']);
        if (empty($lexicons[$staticBlocksPageLexiconFilename])) {
            return;
        }
        $resource = $this->modx->getObject('modResource', $staticBlocksPageId);
        if (!$resource || !$config = $resource->getTVValue($Mpc->grabber->properties['commonConfigTvName'])) {
            return;
        }
        $lexiconsFiltered[$staticBlocksPageLexiconFilename] = [];
        $config = json_decode($config, true) ?: [];
        // Реестр — префиксы всех секций этой же страницы: `features` не должна
        // забирать ключи соседней `features_aula`, иначе одна и та же запись
        // попадает в выдачу дважды и владелец ключа зависит от порядка обхода.
        $matcher = new LexiconPrefixMatcher($this->collectPrefixes($config));
        foreach ($config as $item) {
            $prefix = (string)($item['lexicon_prefix'] ?? $item['MIGX_formname'] ?? '');
            if ($prefix === '') {
                continue;
            }
            $result = $matcher->filter($lexicons[$staticBlocksPageLexiconFilename], $prefix);
            $lexiconsFiltered[$staticBlocksPageLexiconFilename] = array_merge($result, $lexiconsFiltered[$staticBlocksPageLexiconFilename]);
        }

        $Mpc->grabber->createLexicons($lexiconsFiltered);
    }

    /**
     * @param Mpc $Mpc
     * @return void
     */
    public function filterContactsLexicons(Mpc $Mpc): void
    {
        $contactsPageId = $Mpc->grabber->properties['contactsPageId'];
        $contactsPageLexiconFilename = $Mpc->grabber->properties['contactsPageLexiconFilename'];
        $lexicons[$contactsPageLexiconFilename] = $Mpc->grabber->getLexicons($contactsPageLexiconFilename, $Mpc->grabber->properties['basePathToLexiconFile']);
        if (empty($lexicons[$contactsPageLexiconFilename])) {
            return;
        }
        $resource = $this->modx->getObject('modResource', $contactsPageId);
        if (!$resource || !$contacts = $resource->getTVValue($Mpc->grabber->properties['contactsTvName'])) {
            return;
        }
        $lexiconsFiltered[$contactsPageLexiconFilename] = [];
        $config = json_decode($contacts, true) ?: [];
        $prefixes = [];
        foreach ($config as $item) {
            if (!empty($item['ckey'])) {
                $prefixes[] = 'contact_' . $item['ckey'];
            }
        }
        // Тот же спор коротких и длинных ключей, что у секций: `contact_phone`
        // не должен забирать ключи `contact_phone_extra`, если такой контакт есть.
        $matcher = new LexiconPrefixMatcher($prefixes);
        foreach ($prefixes as $prefix) {
            $result = $matcher->filter($lexicons[$contactsPageLexiconFilename], $prefix);
            $lexiconsFiltered[$contactsPageLexiconFilename] = array_merge($result, $lexiconsFiltered[$contactsPageLexiconFilename]);
        }

       $Mpc->grabber->createLexicons($lexiconsFiltered);
    }

    /**
     * Разложить свежие ключи страницы по словарям: статичные секции — в словарь
     * типов страниц, остальные — в словарь ресурса.
     *
     * Источник ключей — только то, что записал ЭТОТ прогон нарезки
     * (`getTouchedLexicons`). Раньше фильтровался `array_merge` по всем
     * `grabber->lexicons`, куда при инициализации граббера предзагружается
     * `page-types.inc.php` культуры целиком — словарь всех лендингов сразу, и его
     * ключи уезжали в словарь сохраняемой страницы (#2609-151).
     *
     * @param \modResource $resource
     * @param Mpc $Mpc
     * @param array $config конфиг секций страницы (тип + ресурс поверх)
     * @return void
     */
    private function manageResourceLexicons(\modResource $resource, Mpc $Mpc, array $config): void
    {
        if (!$Mpc->grabber->properties['useLexicons'] || empty($config)) {
            return;
        }

        $resourceLexiconFilename = $Mpc->grabber->getResourceIdentifierById($resource->get('id'));
        $staticBlocksPageLexiconFilename = $Mpc->grabber->properties['staticBlocksPageLexiconFilename'];
        $lexicons[$resourceLexiconFilename] = $Mpc->grabber->getLexicons($resourceLexiconFilename, $Mpc->grabber->properties['basePathToLexiconFile']);
        $lexicons[$staticBlocksPageLexiconFilename] = $Mpc->grabber->getLexicons($staticBlocksPageLexiconFilename, $Mpc->grabber->properties['basePathToLexiconFile']);

        $freshLexicons = $Mpc->grabber->getTouchedLexicons();
        $matcher = $this->getPrefixMatcher($Mpc, $config);

        foreach ($config as $item) {
            $prefix = $item['lexicon_prefix'] ?? $item['MIGX_formname'] ?? '';
            if ($prefix === '') {
                continue;
            }
            /* Секция пришла из конфига типа и ресурсом не перекрыта — её ключи
             * принадлежат словарю типа, который пишет сохранение самого типа.
             * Дубль в ресурсном словаре на рендере перекрывал бы тип, и в
             * культуре перевода это давало английский текст (#2609-155).
             * Статику пропускать нельзя: её ветка ниже чистит осиротевшие ключи
             * секции из ресурсного файла. */
            $ownedByResource = ($item[\MpcServices\Handlers\Base::SECTION_OWNER_FIELD] ?? 'resource') === 'resource';
            if (empty($item['is_static']) && !$ownedByResource) {
                continue;
            }
            $result = $matcher->filter($freshLexicons, $prefix);
            if (!empty($item['is_static'])) {
                $lexicons[$staticBlocksPageLexiconFilename] = array_merge($result, $lexicons[$staticBlocksPageLexiconFilename]);
                // Секция статична → её переводы живут на уровне page-types. При
                // рендере ресурсный лексикон перебивает page-types (resource > type
                // в Mpc::getLexiconFilenames), поэтому осиротевшие ключи этой секции
                // в ресурсном файле НАДО вычистить — иначе они маскируют значения из
                // page-types (значение «прилипало» к ресурсу после перевода секции в
                // статику). $lexicons[resource] засеян всем старым содержимым файла с
                // диска (выше), а createLexicons перепишет файл этим массивом.
                $lexicons[$resourceLexiconFilename] = array_diff_key(
                    $lexicons[$resourceLexiconFilename],
                    $matcher->filter($lexicons[$resourceLexiconFilename], $prefix)
                );
            } else {
                $lexicons[$resourceLexiconFilename] = array_merge($result, $lexicons[$resourceLexiconFilename]);
            }
        }
        $Mpc->grabber->createLexicons($lexicons);
    }

    /**
     * Матчер префиксов с реестром всех известных секций: конфиг страницы плюс
     * конфиг страницы статичных блоков. Без реестра граница ключа не спасает —
     * `features_aula_title` начинается с `features_`, но принадлежит секции
     * `features_aula`, и только более длинный известный префикс это показывает.
     *
     * @param Mpc $Mpc
     * @param array $config конфиг секций страницы
     * @return LexiconPrefixMatcher
     */
    private function getPrefixMatcher(Mpc $Mpc, array $config): LexiconPrefixMatcher
    {
        $prefixes = $this->collectPrefixes($config);
        $staticConfig = $Mpc->grabber->getSectionConfig((int)$Mpc->grabber->properties['staticBlocksPageId']);
        $prefixes = array_merge($prefixes, $this->collectPrefixes($staticConfig));

        return new LexiconPrefixMatcher(array_unique($prefixes));
    }

    /**
     * @param array $config конфиг секций
     * @return array префиксы лексиконов секций
     */
    private function collectPrefixes(array $config): array
    {
        $output = [];
        foreach ($config as $item) {
            if (!is_array($item)) {
                continue;
            }
            $prefix = (string)($item['lexicon_prefix'] ?? $item['MIGX_formname'] ?? '');
            if ($prefix !== '') {
                $output[] = $prefix;
            }
        }
        return $output;
    }
}
