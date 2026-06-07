# Code Review — migxpageconfigurator

> Дата: 2026-06-07 · Ветка: `v2.0.0` · Охват: `core/components/migxpageconfigurator/**` (PHP ~21k LOC) + `assets/components/migxpageconfigurator/**` (JS/CSS).
> Метод: статический анализ исходников. Находки сгруппированы по подсистемам, внутри — по убыванию severity (`CRITICAL > HIGH > MEDIUM > LOW > NIT`).

## Краткое резюме

Пакет функционально богат, но имеет **несколько критичных дефектов безопасности**, общих по архитектуре: запись пользовательских данных в исполняемые `.inc.php` (лексиконы) без экранирования, `include`/`require` путей из настроек/манифестов/шаблонов без проверки границ каталога, скачивание медиа по URL без защиты от SSRF, отсутствие проверки прав в mgr-процессорах. Эти проблемы повторяются в нескольких файлах и часто исправляются точечно в одной общей функции-генераторе.

### Сводка по безопасности (приоритет для исправления)

| # | Severity | Класс | Где | Кратко |
|---|----------|-------|-----|--------|
| S1 | **CRITICAL** | RCE | `processors/lexicons/updatekey.class.php`, `import.class.php`; `Handlers/LexiconManager.php`; `Handlers/LexiconWriter.php` | Ключ/значение лексикона пишется в `$_lang['...'] = '...'` без экранирования `'` и `\` → инъекция PHP-кода в `.inc.php`, выполняется при `include` |
| S2 | **CRITICAL** | Path traversal / webshell | `processors/lexicons/import.class.php` | Заголовок языковой колонки XLSX (`preg_match('/^[a-z]{2}/')` без якоря, без `basename`) → запись `.inc.php` в произвольный каталог (в т.ч. `assets/`) |
| S3 | **CRITICAL** | RCE | `services/custom/Cli/ManifestLoader.php` | `require $path` манифеста; путь из argv/настройки/env без проверки принадлежности базовому каталогу + path traversal через `..` |
| S4 | **CRITICAL** | LFI / RCE | `services/custom/Plugins/pdoToolsOnFenomInit.php`; `Handlers/Base.php`; `Helpers/ExcelFileHandler.php` | `include`/`file_get_contents` путей из шаблона/настройки без `realpath`-границы; `file_get_contents($path)` вместо `$filepath` |
| S5 | **HIGH** | SSRF | `Handlers/Grabber/MediaDownloader.php`, `ResourceFieldGrabber.php` | curl/`file_get_contents` по URL из шаблона без валидации схемы и адреса (internal/`file://`/`169.254.169.254`), `SSL_VERIFYPEER=false` |
| S6 | **HIGH** | Авторизация | все `processors/lexicons/*`, `processors/resource/copysections.class.php` | Нет `checkPermissions()`/`hasPermission()` — любой mgr-пользователь с сессией вызывает напрямую через коннектор |
| S7 | **HIGH** | Инъекция Fenom | `Handlers/Grabber/TemplateUpdater.php`; `Handlers/Cutter/SpecialTagProcessor.php` | `include`/`@FILE`-путь и параметры из атрибутов шаблона вставляются в генерируемый Fenom без экранирования |
| S8 | **HIGH** | Перезапись системных настроек | `Handlers/Grabber/InformationUpdater.php` | `data-mpc-info` пишет в произвольный `modSystemSetting`/`modContextSetting` без whitelist · ⏸️ ОСТАВЛЕНО: blacklist `isProtectedSetting` + запись только в CLI `--upd` (см. §2) |
| S9 | **HIGH** | Раскрытие данных | `processors/lexicons/export*.class.php` | Экспортные XLSX/ZIP в `assets/.../lexicons-export/` доступны по предсказуемому URL без аутентификации · ✅ ИСПРАВЛЕНО: стрим через коннектор (`ExportStreamer`), публичный файл убран |
| S10 | **HIGH** | Динамический класс | `elements/snippets/snippet.widgethandler.php` | Инстанцирование произвольного `className`/`method` из `$scriptProperties` без whitelist |
| S11 | **MEDIUM** | Stored XSS | `assets/.../js/mgr/lexicons.js` | Колонки грида без `htmlEncode`-рендерера |
| S12 | **MEDIUM** | Path traversal | `FieldWriter.php` (cookie `mpc_lang`); `Mpc.php`/`PendingTranslations.php` (`cultureKey`/`lang`/`rid` в пути) | Значения подставляются в путь к файлу лексикона без валидации · ✅ FieldWriter: `basename()`+guard (`Mpc.php`/`PendingTranslations.php` — остаётся) |
| S13 | **MEDIUM** | XSS / JS-инъекция | `Mpc.php::loadWebScripts` | `mpc_*_attr` вставляются в `<script>` без `json_encode` |

---

## 1. Cutter (нарезка шаблонов)

`Handlers/Cutter.php`, `Cutter/SpecialTagProcessor.php`, `Cutter/SnippetCallBuilder.php`, `Cutter/SectionFileWriter.php`, `Cutter/PlaceholderProcessor.php`

### [HIGH] `wrapInCondition`: коллизия токена `html` при последовательном `str_replace`
**Файл:** `PlaceholderProcessor.php:575–582`
**Проблема:** `str_replace(['##','condition','html'], [$firstSymbol,$conditions,$html], ...)` применяет замены последовательно. Если `$conditions` содержит подстроку `html` (например `data-mpc-if="$item.html_content"`), третий шаг подменяет `html` внутри уже подставленного выражения реальным HTML-контентом → порча шаблона. Воспроизводимо для любого поля с `html` в имени под `data-mpc-if`.
**Рекомендация:** Уникальные сентинелы (`@@MPCIF_COND@@`, `@@MPCIF_HTML@@`) или `sprintf`/`substr_replace` по позициям.

### [HIGH] `preg_replace` wrapper-секции: инъекция обратных ссылок `$1`/`\1` из HTML
**Файл:** `SectionFileWriter.php:225–230`
**Проблема:** Строка замены конкатенируется с `$properties['html']`; PHP интерпретирует `$1..$99`/`\1..\99` в строке замены как backreference. Standalone `$1` в Fenom-выражении или `\1` после `urldecode` исказит вывод.
**Рекомендация:** `preg_replace_callback` + `addcslashes($html, '\\$')`, либо `str_replace` с ручным поиском `<body...>`.

### [HIGH] `handleContacts`: разыменование `null[0]` → TypeError на PHP 8
**Файл:** `Cutter.php:257`
**Проблема:** `getItems(...)[0]` при пустом результате `getItems()` возвращает `null`; `null[0]` на PHP 8 — fatal `TypeError`, валит каттер если контакт без `data-mpc-cfield="value"`.
**Рекомендация:** Проверять результат до индексации, `continue` при пустом.

### [HIGH] `SnippetCallBuilder::getExtends`: нет защиты от циклического `extends`
**Файл:** `SnippetCallBuilder.php:152–165`
**Проблема:** `extends: A→B→A` → бесконечная рекурсия → переполнение стека.
**Рекомендация:** Передавать `array &$visited`, проверять `in_array($preset, $visited, true)`.

### [MEDIUM] Path traversal через `data-mpc-chunk`/`data-mpc-section`
**Файл:** `SectionFileWriter.php:44–71, 80–91`
**Проблема:** Имя чанка/секции из атрибута DOM подставляется в путь без санитизации → `../../...` пишет файл вне базового каталога.
**Рекомендация:** `realpath`-проверка, что итог внутри `$baseDir`.

### [MEDIUM] Инъекция Fenom через `data-mpc-chunk`/`data-mpc-parse`
**Файл:** `SpecialTagProcessor.php:91, 114`
**Проблема:** Значение атрибута вставляется напрямую в генерируемый `$_modx->parseChunk("@FILE ...")` без экранирования — возможна инъекция произвольного Fenom-кода и path traversal в `@FILE`.
**Рекомендация:** Экранировать `"`, проверять границу `pathToChunks`; экранировать `$params`.

### [MEDIUM] `getPresets`: `include()` всех файлов из каталога без фильтра расширения
**Файл:** `Cutter.php:130–137`
**Проблема:** `scandir` отдаёт `.DS_Store`, бэкапы редактора, поддиректории — `include` упадёт parse-error и прервёт нарезку.
**Рекомендация:** `if (!str_ends_with($file,'.inc.php')) continue;` + `is_file()`.

### [MEDIUM] `wrapInCondition`/foreach-сэмплы: та же коллизия токена `html`/`limit`/`offset`
**Файл:** `PlaceholderProcessor.php:143–147`
**Проблема:** Аналогично HIGH-находке — токен в `.tpl` совпадает с подстрокой в HTML/именах полей.
**Рекомендация:** Уникальные сентинелы во всех `.tpl`-сэмплах.

### [MEDIUM] `setImgPlaceholder`: лишний суффикс `[0]` для медиа-списков на вложенных уровнях
**Файл:** `PlaceholderProcessor.php:230, 93`
**Проблема:** `$complexName .= '[0]'` даёт `$list_images[0].img[0]` (уровень 0) и `$item1.list_images[0].img[0]` на вложенных.
**Рекомендация:** Добавлять `[0]` только для не-list путей.

### [MEDIUM] `SectionFileWriter::putToFile`: игнор результата `file_put_contents`
**Файл:** `SectionFileWriter.php:254` (и `createSectionFiles:95`)
**Проблема:** При ошибке записи каттер рапортует success — тихая порча.
**Рекомендация:** Бросать `RuntimeException` при `=== false`.

### [MEDIUM] `getSnippetCall`: модификация `$firstSymbol` зависит от порядка ключей `$preset`
**Файл:** `SnippetCallBuilder.php:84–86`
**Проблема:** Обработка `toPls` внутри основного цикла меняет поведение в зависимости от позиции ключа в массиве.
**Рекомендация:** Обработать `toPls` отдельным проходом до основного цикла.

### [LOW] Одинарные кавычки в значениях/ключах пресетов не экранируются
**Файл:** `SnippetCallBuilder.php:81, 122, 146`
**Проблема:** `'it's'` ломает синтаксис генерируемого Fenom.
**Рекомендация:** `str_replace("'","\\'",...)` для `$k`/`$v`.

### [LOW] `handleContacts`: двойной парсинг DOM одного и того же HTML
**Файл:** `Cutter.php:253, 257` — кэшировать `getHTMLString($item)`.

### [LOW] `mpcOnGetNewHtml`: ключ события называется `Grabber`, передаётся `PlaceholderProcessor`
**Файл:** `PlaceholderProcessor.php:166` — нарушение контракта публичного API.

### [NIT]
- `SectionFileWriter.php:262–265` — объявление `$fullHtml` после использующих методов.
- `Cutter.php:68` — `(\s)*?` вместо `\s*`.
- `SpecialTagProcessor.php:91` — пустой `data-mpc-chunk` даёт путь без файла.

---

## 2. Grabber (сборка данных обратно в ресурсы/TV/MIGX)

`Handlers/Grabber.php` + `Grabber/{SectionProcessor,FieldValueExtractor,MediaDownloader,ResourceFieldGrabber,ContactUpdater,ContentParser,TvProvisioner,TemplateUpdater,InformationUpdater}.php`

### [CRITICAL] SSRF: нет валидации URL перед curl
**Файл:** `MediaDownloader.php:91–109, 259–278` (и `detectExtensionByContentType:40`)
**Проблема:** `$url` из шаблона уходит в `curl_init()` без проверки схемы/адреса: `file:///etc/passwd`, `http://169.254.169.254/...`, `gopher://`, `dict://`. `CURLOPT_SSL_VERIFYPEER=false` усугубляет.
**Рекомендация:** Разрешать только http/https; блокировать RFC-1918/loopback/link-local; `CURLOPT_PROTOCOLS`/`REDIR_PROTOCOLS`; вернуть верификацию SSL.

### [CRITICAL] SSRF: `ResourceFieldGrabber::resolveMedia` вызывает download без проверки схемы
**Файл:** `ResourceFieldGrabber.php:195–200`
**Проблема:** В отличие от `FieldValueExtractor`, здесь нет проверки `strpos($src,'http')` — `<img src="file:///...">` уходит в download напрямую.
**Рекомендация:** Та же централизованная проверка `isExternalUrl()`.

### [HIGH] Инъекция Fenom через `tplData['include']`/`['wrapper']`/`['templatename']`
**Файл:** `TemplateUpdater.php:34–39, 66`
**Проблема:** Значения из JSON-комментария `<!--##...##-->` шаблона вставляются дословно в `{include '...'}` и `@FILE ...` → произвольная Fenom-директива/путь.
**Рекомендация:** Whitelist-фильтр `[a-zA-Z0-9_/.-]` без `..{}$`; путь через `realpath`/`basename`.

### [HIGH] Path traversal в legacy `download()`
**Файл:** `MediaDownloader.php:80–109`
**Проблема:** `$path` от вызывающего без нормализации → `file_put_contents(baseDir.'../../...')`. Метод `public`.
**Рекомендация:** `realpath`-проверка границы; понизить до `protected`.

### [HIGH] Неограниченная запись системных настроек через `data-mpc-info` — ⏸️ ОСТАВЛЕНО
**Файл:** `InformationUpdater.php:34–90`
**Проблема:** Любой ключ из атрибута перезаписывает `modSystemSetting`/`modContextSetting`/clientconfig (например `allow_manager_login_forgot_password`) значением из `nodeValue`.
**Решение:** Оставлено как есть. Защита достаточна для реальной модели угроз: (1) blacklist `isProtectedSetting()` закрывает security-критичные ключи (`allow_manager`/`forgot`/`session`/`password`/`manager_`/`filemanager`/…); (2) запись гейтится `updContent` — срабатывает только в CLI-нарезке с флагом `--upd` (web-путь `OnDocFormSave` идёт через `handleFile()` без `process()`, `updContent=false` → ранний `return`). Легитимные кейсы (телефон/email/соцсети в системных или clientconfig-настройках) blacklist не задевает. Whitelist отложен как опция для более строгой модели.

### [HIGH] Нет проверки MIME скачанного контента (content sniffing)
**Файл:** `MediaDownloader.php:197–208`
**Проблема:** Расширение из URL/`Content-Type` (оба под контролем атакующего); `script.php` под видом `image.jpg`.
**Рекомендация:** Проверять магические байты через `finfo_buffer()`; отклонять PHP/script-магик.

### [HIGH] `ContactUpdater`: неинициализированные ключи `$tmp` → Undefined index
**Файл:** `ContactUpdater.php:72–93` (и пустой ключ `$contacts['']` на 127).
**Рекомендация:** Инициализировать все ключи (`value/key/fvalue/caption/attributes`).

### [HIGH] `FieldValueExtractor`: прямой доступ к `downloadMethodsByTagName[$tag]` без проверки
**Файл:** `FieldValueExtractor.php:86, 177`
**Проблема:** Тег вне `picture/video/audio` → Undefined array key.
**Рекомендация:** `?? null` + `continue`.

### [MEDIUM] `SectionProcessor`: `sectionValues` сериализуется как JSON-объект, не массив
**Файл:** `SectionProcessor.php:92–169` — ключи с 1 → `{"1":{...}}` ломает `foreach`/MIGX-рендер. Использовать `array_values()`.

### [MEDIUM] `updateStaticSectionValues`: `$i = ++$k` — перезапись существующей секции
**Файл:** `SectionProcessor.php:848` — индекс новой секции через `count()`/`max(array_keys)+1`.

### [MEDIUM] Regex `background-image` ловит только `url('...')`
**Файл:** `FieldValueExtractor.php:222` — обобщить на `"`/без кавычек.

### [MEDIUM] `getPictureValue`: `$options['idx']` без `??`
**Файл:** `FieldValueExtractor.php:115`.

### [MEDIUM] `ContactUpdater`: ответ `mpcOnHandleContact` не валидируется
**Файл:** `ContactUpdater.php:97–98` — проверять `is_array && isset($tmp['value'])`.

### [MEDIUM] `TvProvisioner`: тип TV без whitelist
**Файл:** `TvProvisioner.php:79, 126` — `resolveType()` возвращает незнакомый `ftype` как есть. Whitelist типов MODX.

### [MEDIUM] `TemplateUpdater`: частичное состояние при ошибке `$resource->save()`
**Файл:** `TemplateUpdater.php:71–75` — шаблон уже сохранён, ресурс/TV нет. Обернуть в транзакцию/откат.

### [MEDIUM] `SectionProcessor`: `extended['multiple_formtabs']` без проверки типа
**Файл:** `SectionProcessor.php:90`.

### [LOW]
- `MediaDownloader.php:43` — `SSL_VERIFYPEER=false` для HEAD.
- `InformationUpdater.php:64` — экранирование тегов только для `{`, не `[[`/`{$`.
- `SectionProcessor.php:452–463` — `gcAutoConfigs` грузит все авто-конфиги вместо фильтрации в выборке.
- `ContentParser.php:131` — `$lexiconOptions` не сбрасывается во вложенном цикле.

### [NIT]
- `MediaDownloader.php:104` — `!$content` не различает пустой ответ и `false` (HTTP 204).
- `SectionProcessor.php:176` — `sbpSectionValues` тоже без `array_values`.

`Grabber.php` (фасад) — чист, проблемы в делегатах.

---

## 3. Render + FieldWriter + ConfigFieldWriter

`Handlers/Render.php`, `Handlers/FieldWriter.php`, `Handlers/ConfigFieldWriter.php`

### [HIGH] Path traversal через cookie `mpc_lang` — ✅ ИСПРАВЛЕНО
**Файл:** `FieldWriter.php:75–82`
**Проблема:** `$_COOKIE['mpc_lang']` без валидации → `culture` → путь к файлу лексикона (`mpc_lang=../../etc`).
**Исправление:** `culture = basename($culture)` + guard на `''`/`.`/`..` → откат к `cultureKey`. Изначально применённый regex `^[a-z]{2}(_[A-Z]{2})?$` оказался регрессией (форсил `en` для нестандартных `cultureKey`); `basename()` режет traversal (culture — компонент каталога `{lexiconPath}/{culture}/`), не ломая форматы.

### [HIGH] Неэкранированный `MIGX_formname` в Fenom-разметке — ✅ ИСПРАВЛЕНО
**Файл:** `Render.php:329–332`
**Проблема:** `{set $section = '!getStaticSection'|snippet:['section_name'=>'{$section['MIGX_formname']}']}` — если имя содержит `']` или Fenom-код, возможна инъекция.
**Исправление:** `str_replace(['\\', "'"], ['\\\\', "\\'"], $formname)` — экранируем `\` и `'`. Имя вставляется в одинарно-кавыченный Fenom-литерал (внутри `'...'` нет интерполяции `{$...}`), так что единственный вектор — закрывающая кавычка. Вырезание символов (первый вариант) отклонено: ломало бы имена с пробелами/кириллицей → несовпадение в `getStaticSection`.

### [HIGH] `execute()`: `fetchAll(implode('|',$fetchType))` — строка вместо int-режима PDO
**Файл:** `Render.php:936`
**Проблема:** Несколько констант → `"7|0"` не кастится в корректный mode.
**Рекомендация:** `fetchAll($fetchType[0], ...array_slice($fetchType,1))`.

### [HIGH] `clearCache()`: `unlink` без `is_file()` + `unset($fileNames[0],[1])` опирается на порядок scandir
**Файл:** `Render.php:903–907`
**Рекомендация:** `if ($f!=='.'&&$f!=='..' && is_file(...)) unlink(...)`.

### [MEDIUM] Двойной/тройной `json_decode($configJson)` в `writeConfigField`
**Файл:** `FieldWriter.php:374–429` — декодировать один раз.

### [MEDIUM] `implode('\n', ...)` — литеральные `\n` вместо переноса
**Файл:** `Render.php:840` — заменить на `"\n"`/`PHP_EOL`.

### [MEDIUM] Ключ `tvs[commonConfigTvName]` без guard
**Файл:** `Render.php:260` — тихий сброс конфига при отсутствии TV.

### [MEDIUM] `pdo->config['elementsPath']` без проверки ключа
**Файл:** `Render.php:47` — при xPDO без pdoTools `''` ломает рендер.

### [MEDIUM] `staticConfig[$section['section_name']]`/`typeConfig[...]` без `??`
**Файл:** `Render.php:312, 871`.

### [MEDIUM] `writeResourceField`: `set($field,$value)` без проверки `is_scalar`
**Файл:** `FieldWriter.php:249` (в лексиконной ветке на 241 проверка есть).

### [MEDIUM] `jsonDecodeValue`: `strpos($v,...)` на non-string
**Файл:** `Render.php:810` — на PHP 8 `TypeError`. `is_string($v) && ...`.

### [LOW]
- `Render.php:704–706` — статический кэш `getCascadeFieldsMap` не инвалидируется в long-running.
- `FieldWriter.php:285,436,549,626,867,959` — игнор результата `setTVValue`.
- `FieldWriter.php:604–626` — режим `overwrite` не вызывает `afterSave`/инвалидацию parsed.
- `ConfigFieldWriter.php:248–250` — `moveRow` запрещает `to === count` (append в конец).
- `FieldWriter.php:949–961` — `saveConfig` без валидации структуры массива.
- `Render.php:831` — `mkdir(...,0777)`.

### [NIT]
- `Render.php:932` — закомментированный debug `toSQL()`.
- `Render.php:878` — `json_encode` без `JSON_UNESCAPED_UNICODE`.

---

## 4. Mpc.php + Lexicon-подсистема

`Mpc.php`, `Handlers/{LexiconManager,LexiconWriter,LexiconImport,LexiconContext,PendingTranslations}.php`

### [HIGH] Бэкслеш в значении лексикона разрушает `.inc.php` (см. S1)
**Файл:** `LexiconManager.php:563, 660`; `LexiconWriter.php:80`
**Проблема:** `sanitizeValue()` заменяет `'`→`&apos;`, но не экранирует `\`. Значение, заканчивающееся нечётным числом слешей, даёт `$_lang['k'] = 'value\';` → fatal при `include`.
**Рекомендация:** `var_export($v, true)` для значения (и ключа) — синтаксически корректно всегда.

### [HIGH] Ключ лексикона не экранируется при записи (см. S1)
**Файл:** `LexiconManager.php:563, 660`; `LexiconWriter.php:80`
**Проблема:** `$k` (в т.ч. из событий `mpcOnGetLexiconKey`/`mpcOnGetResourceIdentifier`) вставляется в `$_lang['...']` без экранирования → невалидный PHP или инъекция кода.
**Рекомендация:** `var_export($k, true)` + нормализация ключа `preg_replace('/[^a-zA-Z0-9_]/','_',$k)`.

### [HIGH] Path traversal через `mpcOnGetResourceIdentifier`
**Файл:** `LexiconManager.php:228–229`
**Проблема:** `rid` из события → имя файла лексикона/`identifier` без фильтрации `..`,`/`,`\`.
**Рекомендация:** `basename(preg_replace('/[^a-zA-Z0-9_\-]/','',...))` либо `realpath`-проверка базы.

### [MEDIUM] `getTemplateId`: null pointer при ненайденном шаблоне
**Файл:** `Mpc.php:454–459` (и `runTvProcessor:403`) — `null->toArray()`. Проверка `if (!$template)`.

### [MEDIUM] Неопределённая `$templates` в `runTvProcessor`
**Файл:** `Mpc.php:398–408` — инициализировать `$templates = []` до `if`.

### [MEDIUM] Нет `LOCK_EX` при записи лексиконов (read-modify-write race)
**Файл:** `LexiconManager.php:565, 662`; `LexiconWriter.php:86`; `PendingTranslations.php:74`
**Рекомендация:** `LOCK_EX`; атомарная запись через `tempnam`+`rename`.

### [MEDIUM] XSS/JS-инъекция через настройки в `loadWebScripts`
**Файл:** `Mpc.php:211, 221` — `mpc_expand_attr`/`mpc_lazyload_attr` в `<script>` без `json_encode`.

### [MEDIUM] Загрязнение `$_lang` между итерациями `createLexicons`
**Файл:** `LexiconManager.php:551–557` — добавить `$_lang = []` перед `include` на 552.

### [MEDIUM] `strip_tags` с массивом тегов в формате `<p>` (а не `p`)
**Файл:** `Grabber.php:88`; `FieldWriter.php:83` — нормализовать `trim($t,'<> ')`.

### [LOW]
- `LexiconManager.php:243–246` — `if (!$value)` теряет валидное значение `"0"`.
- `LexiconManager.php:579` — `cacheManager->refresh()` без guard (в `Mpc::refreshSiteCache` guard есть).
- `Mpc.php:499–500` — `cultureKey` из настройки в путь без валидации (публичный метод).
- `PendingTranslations.php:40` — `$lang`/`$rid` в пути без валидации.

### [NIT]
- `Mpc.php:322,368,378` — подавление `@` на доступах к ключам массива.
- `LexiconImport.php:95–106` — `computeDiff` считает ключ с пустым переводом как `new`.

---

## 5. CLI (mpc + console)

`services/custom/Cli/**`, `console/{mpc,mgr_configs,mgr_elems,mgr_tpl,clear_cache,migrate_types}.php`

### [CRITICAL] Произвольное выполнение PHP через манифест (см. S3)
**Файл:** `ManifestLoader.php:30`
**Проблема:** `$data = require $path;` — путь из argv/`mpc_manifests_path`/`MPC_MANIFESTS_PATH` без проверки принадлежности базовому каталогу.
**Рекомендация:** `realpath($path)` начинается с `realpath($baseDir)`; задокументировать критичность настройки.

### [CRITICAL] Path traversal в `resolvePath` (`..` в имени профиля, см. S3)
**Файл:** `ManifestLoader.php:72–91` — запретить `..`, нормализовать `realpath` до `is_file`.

### [HIGH] `$argv[N]` без `??` в console-скриптах
**Файл:** `mgr_tpl.php:23–24`, `mgr_elems.php:24`, `clear_cache.php:24`, `mgr_configs.php:23` — undefined offset / fatal на PHP 8.

### [HIGH] Нет CLI-guard в legacy-скриптах (открыты к веб-запросу)
**Файл:** `mgr_configs.php`, `mgr_elems.php`, `mgr_tpl.php`, `clear_cache.php` (только `mpc.php` имеет `PHP_SAPI!=='cli'`).
**Рекомендация:** `if (PHP_SAPI!=='cli'){http_response_code(403);exit(1);}` в начале каждого.

### [HIGH] `PackagesApply`: скачивание по URL из XML провайдера без валидации (SSRF + path traversal target)
**Файл:** `PackagesApply.php:139,148–154` — `file_get_contents($location)`, `$foundSignature` в `$target`. Только https + `basename`+`realpath`.

### [HIGH] `EventsApply`: `success: true` при частичных ошибках
**Файл:** `EventsApply.php:95` — `'success' => empty($errors)`.

### [MEDIUM]
- `ManifestLoader.php:87` — `is_file($name)` для относительного имени (CWD-зависимость).
- `ClientConfigApply.php:82–107` — dry-run возвращает `create` без проверки `cgContextValue`.
- `PackagesApply.php:174` — regex `-[0-9].*` даёт false positive (`my-package-1.0` → `my`).
- `ResourcesApply.php:78–81,128–129` — dry-run дочерних узлов с `parentId=0`.
- `migrate_types.php:18` — bootstrap через `index.php` вместо API-mode.
- `mgr_configs.php:14` (и др.) — `$ctx` из argv без валидации контекста.
- `EventsApply.php:80–82` — двойной `refresh()` (полный сброс кэша без нужды).

### [LOW]
- `Cli.php:256–281` ↔ `mgr_configs.php:31–57` — дублирование sync MIGX-конфигов.
- `ArgvParser.php:71–76` — `--key value` неизвестного флага «съедает» позиционный аргумент.
- `SettingsApply.php:97,109,126,137` — игнор результата `save()`, нет накопления ошибок.
- `PackagesApply.php:97` — `copy()` без проверки успеха.
- `ClientConfigApply.php:64` — полный `refresh()`.
- `ResourcesApply.php:90` — `pagetitle` как единственный идентификатор (дубликаты).

### [NIT]
- `Output.php:38–40` — при `--json` всё в STDOUT.
- `Cli.php:331` — `$data['message']` без `??`.
- `mgr_tpl.php:7` и др. — смешение `\`/`/` в `MODX_CORE_PATH`.

---

## 6. Helpers / Plugins / Processors

### [CRITICAL] `pdoToolsOnFenomInit`: `file_get_contents($path)` вместо `$filepath` (LFI, см. S4)
**Файл:** `Plugins/pdoToolsOnFenomInit.php:95`
**Проблема:** Проверяется `file_exists($filepath)`, но читается `$path` (произвольный путь из шаблона, в т.ч. абсолютный). Нет защиты от `../`.
**Рекомендация:** Читать `$filepath` + `realpath`-граница внутри `corePath`.

### [CRITICAL] `include` пути из настройки без `realpath`-границы (LFI/RCE, см. S4)
**Файл:** `Handlers/Base.php:90` (`mpc_exclude_lexicons_filename`); аналогично `Helpers/ExcelFileHandler.php`
**Рекомендация:** `realpath()` + `str_starts_with($resolved, realpath($corePath))`.

### [HIGH] `version`-модификатор: `$this->$dir` из аргумента Fenom
**Файл:** `pdoToolsOnFenomInit.php:83` — доступ к любому свойству (`modx`, `scriptProperties`); whitelist `['basePath','corePath']`.

### [HIGH] `OnDocFormSave::run`: `$resource` не проверяется после строки 23
**Файл:** `Plugins/OnDocFormSave.php:31` (и 36,39,45,48,49,52,55,64) — fatal если не `modResource`. Ранний `return`.

### [HIGH] `OnDocFormSave::filterStaticSectionsLexicons`/`filterContactsLexicons`: `getObject` без null-проверки
**Файл:** `OnDocFormSave.php:83–84, 109–110` — `null->getTVValue()`.

### [HIGH] `ExcelFileHandler::getDataFromFile`: путь к файлу не валидируется
**Файл:** `Helpers/ExcelFileHandler.php:105–108` — `is_file` + граница `assetsPath` + расширение.

### [MEDIUM]
- `Handlers/Base.php:195–200` — `foreach (json_decode(...))` без `?: []` (TypeError на PHP 8).
- `OnDocFormSave.php:45` — `scriptProperties['id']` без `(int)` и `??`.
- `AdminAudit.php:128–156` — удалённые из конфига секции не логируются.
- `Helpers/Logging.php:30` — `debug=true` по умолчанию → пишет логи на проде.
- `Logging.php:49` — `mkdir(...,0777)`, `file_put_contents` без `LOCK_EX`.
- `ExcelFileHandler.php:68–69` — `filePath` из события без валидации.
- `OnDocFormSave.php:31–33` — `switchContext` без восстановления исходного контекста.
- `OnResourceUndelete` — наследует `run()` с бесполезным аудитом, лишняя нарезка.
- `pdoToolsOnFenomInit.php:63–69` — `reslexicons` не возвращает значение.
- `Processors/Template.php:29–30` — `explode` на пустой строке даёт `['']`.

### [LOW]
- `AdminAudit.php:195` — `username` без экранирования (downstream-XSS).
- `MigxConfigMerger.php:124–128` — поля без ключа дублируются в `$existingTail`.
- `Base.php:146–156` — `getItems` возвращает `null` в двух разных случаях.
- `Processors/Base.php:89–93` — `@return true` некорректен.
- `pdoToolsOnFenomInit.php:91` — метод `include` (зарезервированное слово).

### [NIT]
- `Processors/MigxConfig.php:31–39` — не использует `parent::create`, игнор `save()`.
- `OnDocFormSave.php:25`, `OnBeforeDocFormSave.php:19` — `new AdminAudit` на каждый вызов.
- `Parser.php:51–54` — `urldecode` может разрушить URL-encoded `%2B`.

Чисты: `OnCacheUpdate`, `OnContextSave`, `OnHandleRequest`, `OnMODXInit`, `TemplateVarTemplate`, `Response`, `TrackedFields` (для PHP-FPM).

---

## 7. Controllers / mgr-Processors / Model / Elements / Frontend

### [CRITICAL] PHP-инъекция через ключ лексикона (RCE, см. S1)
**Файл:** `processors/lexicons/updatekey.class.php:39–45`, `import.class.php:221–229`
**Проблема:** `$_lang['<key>'] = '<value>'` — ключ из ввода менеджера / ячейки XLSX без экранирования. Файлы регулярно `include`-ятся.
**Рекомендация:** Whitelist ключа `[a-zA-Z0-9_.-]` + `var_export()` для обоих компонентов.

### [CRITICAL] Path traversal через заголовок языковой колонки XLSX (webshell, см. S2)
**Файл:** `import.class.php:208–214`
**Проблема:** `preg_match('/^[a-z]{2}/',$lang)` без якоря и без `basename` → запись `.inc.php` вне `lexiconBase` (в `assets/`).
**Рекомендация:** `basename($lang)` + `preg_match('/^[a-z]{2,8}$/',$lang)` + `realpath`-проверка.

### [HIGH] Нет проверки прав в процессорах (см. S6)
**Файл:** все `processors/lexicons/*.class.php`, `processors/resource/copysections.class.php`
**Проблема:** Нет `checkPermissions()`/`hasPermission()`; коннектор требует лишь сессию. Кастомные `mpc_view`/`mpc_edit` проверяются только в контроллере CMP.
**Рекомендация:** `hasPermission('mpc_edit')` в `process()`; в `copysections` — право на конкретный ресурс.

### [HIGH] Экспортные XLSX/ZIP публично доступны (см. S9) — ✅ ИСПРАВЛЕНО
**Файл:** `export.class.php`, `exportall.class.php`, `exportallinone.class.php`; новый `Helpers/ExportStreamer.php`; `js/mgr/lexicons.js`
**Проблема:** `assets/.../lexicons-export/` без `.htaccess`/`index.php`, имена предсказуемы, каталог `0777`. `.htaccess` бесполезен на nginx — защита обязана жить в PHP.
**Исправление:** Запись в публичный assets убрана полностью. Файл стримится в браузер через сам коннектор (`ExportStreamer::xlsxWriterToBrowser`/`streamFileAndExit`), где `checkPolicy('load')` + `checkPermissions(mpc_view)` проверены ДО процессора → enforcement в PHP, одинаков на Apache/nginx. Скачивание — навигацией на коннектор с токеном `HTTP_MODAUTH` (GET-параметр, т.к. ExtJS-заголовок при `window.location` не уходит; заодно анти-CSRF). Внутренний temp OpenSpout/ZIP — в системном temp с TTL-свипом и `try/finally`-cleanup; публичного артефакта нет. UI: «не найдено» сохранено через probe-XHR перед навигацией.
**Деплой:** на стенде/проде вручную удалить старый каталог `assets/components/migxpageconfigurator/lexicons-export/` со скопившимися выгрузками (код туда больше не пишет, но ранее экспортированные файлы остаются доступны по URL).

### [HIGH] Слабая валидация языка (regex без якоря)
**Файл:** `updatekey.class.php:21`, `import.class.php:208` — `preg_match('/^[a-z]{2,8}$/',$lang)`.

### [MEDIUM] Предсказуемый токен импорта — ✅ ИСПРАВЛЕНО (частично)
**Файл:** `import.class.php:63`
**Проблема:** `uniqid('imp_')` предсказуем по microtime; файл лежит в `core/cache/mpc_import/` (под webroot, `.htaccess` нет → отдаётся по URL, подтверждено `HTTP 200`), живёт до часа. Перебор имени → чтение чужой выгрузки.
**Исправление:** Токен → `'imp_' . bin2hex(random_bytes(16))` — криптослучаен, перебор невозможен. Данные лексиконов и так публичны (рендерятся на сайте), поэтому риск признан низким; привязку к сессии и вынос temp за webroot не делали.

### [MEDIUM] ZIP-slip при импорте — ✅ ИСПРАВЛЕНО
**Файл:** `import.class.php` (`readWorkbook`)
**Проблема:** `$zip->extractTo($exDir)` извлекал ВСЕ записи; защита `extractTo` от `../` версионно-зависима → запись `.php` вне `$exDir` (webshell).
**Исправление:** `extractTo` убран; вручную перебираем `numFiles`, извлекаем только `*.xlsx` по `basename` имени записи (`../foo` → `foo`, из `$exDir` не выбраться), потоком через `getStream`/`stream_copy_to_stream`, индекс-префикс пути против перезаписи при совпадении basename.

### [MEDIUM] Stored XSS в гриде лексиконов (см. S11)
**Файл:** `js/mgr/lexicons.js:334–349` — добавить `renderer: Ext.util.Format.htmlEncode` ко всем колонкам.

### [MEDIUM] Игнор результата `file_put_contents`/`mkdir`
**Файл:** `updatekey.class.php:47`, `import.class.php:231`, `export.class.php:54–55`.

### [MEDIUM] `snippet.widgethandler`: динамический класс без whitelist (см. S10)
**Файл:** `elements/snippets/snippet.widgethandler.php:13–19` — whitelist/проверка namespace `MpcServices\\`.

### [LOW]
- `import.class.php:59–61` — fallback `@copy()` обходит `is_uploaded_file`.
- `js/web/languages.js:59–63` — `setCookie` неверно вычисляет домен для localhost/IP.
- `import.class.php:295–296` — неполная очистка временной директории ZIP.

### [NIT]
- `js/web/expand.js:138` — неверный `viewBox` (порядок width/height + нет пробела).
- `controllers/index.class.php:39` — polling `Ext.TaskMgr` каждые 400 мс.
- `updatekey.class.php:39` — значения не санируются (в отличие от import).

Чисты: контроллеры `mpctype*`, модели `mpctype`/`mpctypecollection`, `getlanguages/getlist/get`, `plugin.migxpageconfigurator.php`, сниппеты `getparsedconfigpath/getstaticsection/mpcthumb`, `js/web/lazyload.js`, `js/mgr/mpctype.fields.js`.

---

## Приоритетный план устранения

1. **S1 (RCE через лексиконы)** — заменить ручную сборку строки `$_lang[...]` на `var_export()` в единой функции-генераторе; затрагивает `LexiconManager`, `LexiconWriter`, `updatekey`, `import`. Один фикс закрывает 4 точки.
2. **S2 / S3 / S4 (path traversal + LFI/RCE)** — ввести общий хелпер `assertInsideBase($path, $base)` (через `realpath`) и применить ко всем `include`/`require`/`file_put_contents`/`file_get_contents` с внешним путём.
3. **S5 (SSRF)** — централизованный `isExternalUrl()` + блок internal-адресов + `CURLOPT_PROTOCOLS`, вернуть `SSL_VERIFYPEER`.
4. **S6 (авторизация)** — `hasPermission()` во всех mgr-процессорах.
5. **S8/S9/S10/S11** — whitelist системных настроек, перенос экспортов за webroot, whitelist класса, `htmlEncode` в гриде.
6. Затем — функциональные HIGH (циклический `extends`, `null[0]`, `MIGX_formname`-инъекция, `fetchAll`-режим) и далее по списку.

---

## Финальный статус зачистки (после прохода по всем разделам)

Все находки сверены с фактическим кодом и закрыты, кроме сознательно оставленных (с обоснованием). Каждая правка проверена на сохранение логики + `php -l`.

### ✅ Исправлено
- **Безопасность (CRITICAL/HIGH):** S1 (RCE `var_export`), S2 (webshell), S4 (LFI realpath), S5 (SSRF + content-sniffing), S6 (`hasPermission`), S7 (Fenom-инъекция шаблона), S9 (экспорты стримом, без публичного файла), S10 (whitelist класса), S11 (`htmlEncode`), S12 (culture `basename`), S13 (`json_encode`), MIGX_formname (экранирование), импорт: токен `random_bytes` + ZIP-slip.
- **§2 Grabber:** background-image regex (любые кавычки), `multiple_formtabs` is_string-гард, `ContentParser` сброс `$lexiconOptions`, `TemplateUpdater` транзакция, SSRF/content-sniffing/`array_values`/null-guards.
- **§3 Render/FieldWriter:** `elementsPath`/`tvs[commonConfigTvName]` null-guards, `fetchAll`-режим, `clearCache`, `is_scalar`/`is_string`-guards, `implode("\n")` (уже был).
- **§4 Mpc/Lexicon:** `var_export`, traversal identifier, `cultureKey` basename (cookie+место использования), `PendingTranslations` LOCK_EX + basename, `$_lang` reset, `LOCK_EX`, `sanitizeValue` сохраняет `"0"`, `getCacheManager()`.
- **§1 Cutter:** токены/backreference/extends/`null[0]`/traversal, экранирование кавычек в пресетах, событие `mpcOnGetNewHtml` (+`PlaceholderProcessor`).

### ⏸️ Оставлено сознательно (правка рискованнее проблемы)
- **S3 ManifestLoader** (CLI `require` явного пути): достижим только из CLI (после §5-гардов), оператор и так исполняет PHP; ужесточение ломает `apply <файл>`. By-design.
- **§6 Logging:** уже безопасен — `debug=false` по умолчанию, все вызовы без аргумента (на проде не пишет).
- **`setImgPlaceholder` `[0]`:** генерация имён плейсхолдеров, большой blast radius на рендер изображений; неочевидно, баг ли (img-поле = массив). Нужно полевое репро перед изменением.
- **`moveRow` `$to >= $n`:** ложное срабатывание — корректно для переупорядочивания (move в конец = `$to=$n-1` работает).
- **`writeConfigField` множественный `json_decode`:** микро-оптимизация, рефактор API `ConfigFieldWriter` рискован, выигрыш ничтожен (конфиг одного ресурса).
- **NIT:** двойной парс DOM (эффективность), `mkdir 0777` (perms web vs CLI в распространяемом пакете), instance-кэш `getCascadeFieldsMap` (per-request — корректен).
