# Code Review — верификация исправлений (2-й проход)

> Дата: 2026-06-07 · Ветка: `v2.0.0`
> Проверяющий: ревьюер (повторный проход по фактическому коду, без доверия к пометкам ✅/⏸️ в исходных отчётах).
> Источники заявлений: `CODE-REVIEW-migxpageconfigurator.md`, `CODE-REVIEW-mpcvisualeditor.md`.
> Метод: 6 независимых сверок кластеров заявленных фиксов с актуальным кодом.

## Резюме

Ядро безопасности закрыто полностью и корректно: RCE через лексиконы (`var_export` во всех 5 точках записи), CSRF-nonce, IDOR (`checkPolicy` на конкретном ресурсе), авторизация процессоров, публичные экспорты (стрим через коннектор), `MIGX_formname`-инъекция, `htmlEncode` в гриде, `LOCK_EX`, ZIP-slip, токен импорта.

Однако при сверке выявлено: **7 пунктов, помеченных как ✅ исправлено, по факту закрыты лишь частично; 1 пункт не исправлен; 1 фикс внёс регрессию.** Ниже — только расхождения. Всё, что не упомянуто, подтверждено как CONFIRMED.

Легенда вердиктов: `CONFIRMED` — фикс на месте и корректен · `PARTIAL` — основное закрыто, остался обходимый вектор · `NOT FIXED` — заявлено, но в коде нет · `REGRESSION` — фикс сломал поведение · `JUSTIFIED` — «оставлено by-design» обосновано.

---

## 🔴 Остаточные дыры безопасности (заявлено ✅, фактически PARTIAL/NOT FIXED)

### V1 · [PARTIAL] mpcVE — `data:image/svg+xml` обходит `sanitizeHtml` (новый вектор)
**Файл:** `assets/components/mpcvisualeditor/js/editors/rte.js:347` (`scrubAttrs`)
**Суть:** атрибутный санитайзер построен на DOM-обходе (это правильно, mXSS через вложение/кодирование закрыт), но URL-ветка пропускает любой `data:image/...`:
```js
v.indexOf('data:image/') !== 0   // не блокирует data:image/svg+xml
```
`<img src="data:image/svg+xml,<svg onload=alert(1)>">` проходит фильтр и выполняет JS при рендере. Связано с Sf3, который помечен ✅.
**Рекомендация:** whitelist конкретных растровых типов — `data:image/(png|jpeg|gif|webp)`; `svg+xml` в data-URI запретить.

### V2 · [PARTIAL] mpcVE — двойное расширение `shell.php.jpg` обходит блок-лист (S2)
**Файл:** `services/custom/Handlers/FileManagerHandler.php` (`extOf`, `isBlockedExt`, `upload`/`rename`)
**Суть:** расширение извлекается через `pathinfo(..., PATHINFO_EXTENSION)` → для `shell.php.jpg` вернёт `jpg`, блок-лист видит только последний сегмент. Если веб-сервер исполняет PHP по любому сегменту имени (`AddHandler ... .php`) — webshell проходит. Базовые векторы (`.php`, `.phtml`, `.phar`, `htaccess`) закрыты корректно.
**Рекомендация:** проверять всё имя по сегментам: `in_array('php*', explode('.', strtolower($name)))` или блок при `strpos(strtolower($name), '.php') !== false`.

### V3 · [PARTIAL] mpcVE — `sanitizeSvg` на регулярках, неполное покрытие (S5)
**Файл:** `services/custom/Handlers/ImageUploadHandler.php` (`sanitizeSvg`)
**Суть:** `<script>`, `on*`, `javascript:`, `<!DOCTYPE/ENTITY` вырезаются. НЕ покрыты: `<use xlink:href="...">`/`<use href>` (SSRF/XSS), `<animate>`/`<set attributeName="href" values="javascript:...">`, обфускация через неймспейсы (`<svg:script>`). `mimeOk` для SVG доверяет расширению.
**Рекомендация:** перейти на XML-парсер (`DOMDocument`) или библиотеку `enshrined/svg-sanitize`; либо запретить загрузку SVG с фронта.

### V4 · [PARTIAL] migx — SSRF: DNS-rebinding + IPv6 `::1` на PHP 7.4 (S5)
**Файл:** `services/custom/Handlers/Grabber/MediaDownloader.php` (`isSafeUrl`, `curlSecurityOpts`)
**Суть:** основная защита внедрена и корректна — резолв DNS, `FILTER_FLAG_NO_PRIV_RANGE | NO_RES_RANGE`, `CURLOPT_PROTOCOLS`/`REDIR_PROTOCOLS` (http/https), `SSL_VERIFYPEER` возвращён, content-sniffing (`finfo` + сигнатуры). Остаточные векторы:
1. **DNS-rebinding (TOCTOU):** `isSafeUrl()` резолвит DNS отдельно, curl — заново при запросе. Домен, отдающий публичный IP на проверке и внутренний на запросе, обходит фильтр.
2. **IPv6-loopback `::1`:** не помечается `FILTER_FLAG_NO_RES_RANGE` **до PHP 8.1**. Стенд/прод hostland — **php-7.4** → реальный обход, если хост резолвится в `::1`.
**Рекомендация:** зафиксировать проверенный IP через `CURLOPT_RESOLVE`; явно блокировать `::1` и `::ffff:127.0.0.0/104`.

### V5 · [PARTIAL] migx — `SpecialTagProcessor`: абсолютный путь + неэкранированный `$params` (S7)
**Файл:** `services/custom/Handlers/Cutter/SpecialTagProcessor.php` (`setParseChunks` ~90, `setIncludeChunks` ~119)
**Суть:** блокируются `..` и `"`, но:
- `data-mpc-chunk="/etc/passwd"` (ведущий `/`) проходит в `$_modx->parseChunk("@FILE /etc/passwd", ...)`.
- `$params` из `data-mpc-parse` вставляется в Fenom-вызов без экранирования — `'` или `}` позволяет выйти из вызова и инжектировать Fenom.
**Рекомендация:** блок ведущего `/` (`$chunk[0] !== '/'`); экранировать `$params` перед вставкой в строку Fenom.

### V6 · [PARTIAL] migx — `TemplateUpdater`: traversal через `....//` (S7)
**Файл:** `services/custom/Handlers/Grabber/TemplateUpdater.php:36–43`
**Суть:** `str_replace('..', '', $include)` не рекурсивен → `....//` схлопывается в `../`. Fenom-инъекция кавычкой закрыта (whitelist символов), но path traversal в `{include '...'}` остаётся. `$wrapperName` — чистый whitelist, безопасен.
**Рекомендация:** `basename()`/`realpath()`-граница вместо вырезания подстроки `..`.

### V7 · [PARTIAL] migx — `ExcelFileHandler`: граница `assetsPath` не проверяется (S4)
**Файл:** `services/custom/Helpers/ExcelFileHandler.php` (`getDataFromFile` ~108, `createFile` ~68)
**Суть:** `Base.php` (parный фикс) — исправлен корректно (`realpath` + `strpos`-граница). Но в `ExcelFileHandler`:
- `getDataFromFile` проверяет только расширение `.xlsx` и `is_file`, без realpath-границы.
- `createFile` пишет по `filePath` из события `mpcOnBeforeSaveExcel` без проверки границы `assetsPath`.
Отчёт заявил «проверка `assetsPath`» — её в коде нет.
**Рекомендация:** `realpath()` + `str_starts_with($real, realpath($assetsPath))` для обоих путей.

---

## 🟠 Функциональные расхождения

### V8 · [NOT FIXED] migx — `Base.php`: `json_decode` без guard перед `foreach`
**Файл:** `services/custom/Handlers/Base.php:195–201`
**Суть:** заявлено `json_decode(...) ?: []`, в коде отсутствует:
```php
if (!$config) { return []; }            // пропускает невалидный непустой JSON
$config = json_decode($config, true);   // результат не проверяется
foreach ($config as $item) { ... }      // null/строка → TypeError на PHP 8
```
**Рекомендация:** `$config = json_decode($config, true); if (!is_array($config)) { return []; }`.

### V9 · [REGRESSION] mpcVE — `choiceDialog` не закрывается по Escape
**Файл:** `assets/components/mpcvisualeditor/js/dom.js` (`choiceDialog.onKey` ~94)
**Суть:** фикс утечки keydown ввёл регрессию. `onKey` проверяет:
```js
if (document.querySelector('.mpcve-confirm')) { return; }
```
но сам `choiceDialog` создаёт элемент с классом `.mpcve-confirm` и уже добавлен в DOM → `querySelector` находит сам себя → Escape не закрывает диалог. `confirmDialog`/`promptDialog`/`panels.js` — корректны (этой ошибки нет). Диалог используется для выбора наследуемой секции.
**Рекомендация:** сравнивать `document.querySelector('.mpcve-confirm') !== ov` (исключить собственный оверлей).

---

## 🟡 Мелкие расхождения с формулировками (не критично)

### V10 · [PARTIAL] migx — `copysections`: проверка прав глобальная, не per-resource (S6)
**Файл:** `processors/resource/copysections.class.php:19–22`
`checkPermissions()` есть и корректен (`save_document || mpc_edit`), но это глобальное право пользователя, а не `$resource->checkPolicy('save')` на конкретный ресурс, как обещал отчёт. Не регрессия — право проверяется; но слабее заявленного.

### V11 · [PARTIAL] migx — `PlaceholderProcessor`: остаток коллизии + событие не переименовано
**Файл:** `services/custom/Handlers/Cutter/PlaceholderProcessor.php`
- Коллизия токенов foreach/if закрыта через `strtr` (однопроходный — эквивалентно сентинелам, заявленным в отчёте; формулировка разошлась, суть закрыта).
- `setMediaPlaceholder` (~364) остался на каскадном `str_replace(['##','complexName','html'],...)` — тот же риск повторной замены, если `$html` содержит `##`/`complexName`.
- Событие `mpcOnGetNewHtml` (~170): ключ `Grabber` **оставлен** legacy, добавлен второй `PlaceholderProcessor` (дополнен, не переименован).

### V12 · [PARTIAL] migx — `widgethandler`: namespace-проверка нестрогая (S10)
**Файл:** `elements/snippets/snippet.widgethandler.php:17`
`strpos($className, 'MpcServices\\') === 0` + `class_exists` + не-`_`-метод — основное закрыто. Остаток: `strpos` не гарантирует принадлежность только пакету при стороннем autoload в тот же неймспейс (теоретический риск).

---

## ✅ Обоснованно оставлено (проверено — состоятельно)

| Пункт | Файл | Подтверждение |
|-------|------|---------------|
| S3 ManifestLoader (`require`) | `Cli/ManifestLoader.php` | Достижим только из CLI (после §5-гардов); оператор и так исполняет PHP. JUSTIFIED |
| S8 InformationUpdater | `Grabber/InformationUpdater.php` | `isProtectedSetting()` реально существует и покрывает `allow_manager`/`forgot`/`session`/`password`/`manager_`/`filemanager` и др.; запись гейтится `updContent` (только CLI `--upd`, ранний `return`). Остаточный вектор (`site_url` и пр.) требует CLI-доступа → вне модели угроз. JUSTIFIED |
| Logging debug | `Helpers/Logging.php:30` | Дефолт `false`, все вызовы без аргумента → на проде не пишет. JUSTIFIED |
| mpcVE S8 локи | `CacheClearHandler`/`LockHandler` | Локи само-восстанавливаются heartbeat'ом; лок — UX, не барьер. JUSTIFIED |
| mpcVE S12 PageSave | `Connector.php`, `PageSaveHandler.php` | `page/save` отключён в коннекторе (`error(...)`), `PageSaveHandler` не достижим — мёртвый код. JUSTIFIED |

---

## Полностью подтверждённые фиксы (CONFIRMED)

**migxpageconfigurator:** S1 (var_export ×5 точек, ключ+значение), S2 (XLSX lang `basename`+якорь, ZIP-slip), токен импорта (`random_bytes`), traversal `mpcOnGetResourceIdentifier`, S12 culture (`basename`+guard в FieldWriter/Mpc/PendingTranslations), `LOCK_EX` ×6, `sanitizeValue` сохраняет `"0"`, S4 LFI (`pdoToolsOnFenomInit` читает `$real`, `Base.php` realpath), `version`-whitelist, S6 (`checkPermissions` во всех 9 процессорах, не пустые), S9 (нет записи в публичный assets, `ExportStreamer`, JS-токен), S11 (`htmlEncode` все колонки), S13 (`json_encode` + HEX-флаги), `MIGX_formname` экранирование, `fetchAll` spread, `clearCache` (`is_file`+`./..`), `elementsPath`/`tvs[commonConfigTvName]` guards, `is_string`/`is_scalar` guards, §1 Cutter (backreference `preg_replace_callback`, `null[0]` guard, `getPresets` фильтр, циклический `extends` `$seen`), §2 Grabber (background regex, `multiple_formtabs` guard, `updateStaticSectionValues` индекс, `array_values`, `ContentParser` сброс, `ContactUpdater` init+валидация, `TemplateUpdater` транзакция).

**mpcvisualeditor:** S1 (`raw` блок `@` + `ltrim`), S3 (CSRF nonce — генерация/хранение/проверка до роутинга, включая beacon/upload), S4/S9 (IDOR `canEdit/canViewResource` → `checkPolicy` на конкретном ресурсе во всех write+log), S10 (action не отражается), S13 (`htmlspecialchars` ENT_QUOTES), S14 (TV по шаблону ресурса + limit 500), Sf1 (`esc(String(e.id))` + все API-поля), Sf2 (`sanitizeHtml` применён в richtext/text/textarea), api.js (non-ok HTTP), filemanager navigate (`..`), rows (гонка клика + `dragFrom` reset), sidebar (rollback splice), panels (`closed`-флаг), rte (`promptDialog`), changelog (`flt` reset), Connector (мёртвый `success()` удалён), overlay.css (дубль убран).

---

## Приоритет доработки остатка

1. **V1** mpcVE `data:image/svg+xml` XSS — точечный фикс whitelist data-URI.
2. **V2** mpcVE двойное расширение — проверка имени по сегментам.
3. **V4** migx SSRF на php-7.4 (`::1` + rebinding) — актуально для целевого стека.
4. **V8** migx `Base.php` TypeError — однострочник.
5. **V9** mpcVE `choiceDialog` Escape — однострочник (регрессия).
6. **V5/V6/V7** migx (абсолютный путь/`$params`, `....//`, ExcelFileHandler граница) — вторым эшелоном.
7. **V3** mpcVE SVG-санитайзер — архитектурный (XML-парсер), по возможности.
8. **V10/V11/V12** — косметика/усиление, по желанию.

---

## Статус устранения расхождений (2026-06-07, задеплоено)

| # | Было | Что сделано | Статус |
|---|------|-------------|--------|
| V1 | data:image/svg+xml XSS | scrubAttrs: разрешены только растровые data-URI (png/jpg/gif/webp/avif/bmp) | ✅ |
| V2 | shell.php.jpg обход | `isBlockedName`: все dot-сегменты + любая `.php` в имени (upload+rename) | ✅ |
| V3 | sanitizeSvg неполный | +`<use>/<animate>/<set>/<image>/iframe/object` + неймспейс-префиксы + `*:href` js/vbscript/data | ✅ (regex усилен; для 100% — XML-парсер/блок SVG) |
| V4 | SSRF IPv6 `::1` на 7.4 | `isBlockedIp`: явный блок `::1`/`::`/ULA/link-local + норм. IPv4-mapped | ✅ (rebinding-TOCTOU — остаток, нужен CURLOPT_RESOLVE) |
| V5 | абсолютный chunk + `$params` | блок ведущего `/` в `data-mpc-chunk`; `$params` — trust-модель каттера (автор шаблона и так контролит Fenom) | ✅/JUSTIFIED |
| V6 | `....//` traversal | рекурсивный strip `..` + срез ведущего `/` (абсолютный `{include}`) | ✅ |
| V7 | ExcelFileHandler граница | `withinAssets` (realpath) в `getDataFromFile` + событие-override `createFile` | ✅ |
| V8 | Base.php TypeError | `is_array`-guard после `json_decode` | ✅ |
| V9 | choiceDialog Escape (регрессия) | исключён собственный `.mpcve-confirm` (`top !== ov`) | ✅ |
| V11 | setMediaPlaceholder каскад | `strtr` вместо каскадного `str_replace` | ✅ |
| V10 | copysections глобальное право | mgr-процессор, `save_document\|\|mpc_edit` — стандартный паттерн, не front-IDOR-поверхность | JUSTIFIED |
| V12 | widgethandler `strpos` namespace | теоретический риск только при стороннем autoload в `MpcServices\` | JUSTIFIED |

Все правки: `php -l`/`node --check` чисты, задеплоены на стенд, маркеры подтверждены. Остаток (документирован): V3 — полноценный XML-санитайзер; V4 — DNS-rebinding (CURLOPT_RESOLVE).
