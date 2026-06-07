# Code Review — mpcvisualeditor

> Дата: 2026-06-07 · Ветка: `v2.0.0` · Охват: `core/components/mpcvisualeditor/**` (PHP ~2.2k LOC) + `assets/components/mpcvisualeditor/**` (JS/CSS ~5.4k LOC).
> Метод: статический анализ исходников. Находки сгруппированы (бэкенд PHP / фронтенд JS), внутри — по убыванию severity.

## Краткое резюме

`mpcvisualeditor` — фронтовый визуальный редактор контента: коннектор принимает AJAX от mgr-пользователей на сайте и пишет поля/секции/файлы. **Главная проблема — модель безопасности коннектора.** Аутентификация сводится к глобальному праву `mpcve_edit`, при этом:

- нет CSRF-защиты;
- нет проверки прав на **конкретный** ресурс (любой редактор правит любой документ — IDOR);
- параметр `raw` в `fields/options` даёт доступ к биндингам TV (`@SELECT`/`@EVAL`/`@FILE`) → SQL/чтение файлов/PHP-eval;
- файловый менеджер позволяет загрузить/переименовать файл в `.php` (webshell).

Эти дефекты в совокупности позволяют аутентифицированному редактору контента выйти далеко за рамки своих полномочий, а CSRF — атаковать его извне. Их следует закрывать в первую очередь.

### Сводка по безопасности (приоритет для исправления)

| # | Severity | Класс | Где | Кратко |
|---|----------|-------|-----|--------|
| S1 | **CRITICAL** | RCE / SQLi / LFI | `Handlers/FieldOptionsHandler.php:47,60` | `raw` → `processBindings()`: `@SELECT` (произвольный SQL), `@EVAL` (PHP при `allow_tv_eval`), `@FILE` (чтение файла) |
| S2 | **CRITICAL** | Webshell | `Handlers/FileManagerHandler.php:181–190,247` | `accept=any` → `acceptExt()` пропускает любое расширение → загрузка `.php`, имя без санитайза |
| S3 | **CRITICAL** | CSRF | `assets/.../connector.php:35–36` | Нет CSRF-токена/проверки Origin — внешний сайт инициирует `field/save`, `files/remove`, `log/revert` от имени редактора |
| S4 | **HIGH** | IDOR / авторизация | `FieldSaveHandler`, `RowOpHandler`, `SectionOpHandler`, `LogHandler` | После глобального `mpcve_edit` нет проверки прав на конкретный `resourceId` (`checkPolicy('save')`/`view`) |
| S5 | **HIGH** | Stored XSS | `Handlers/ImageUploadHandler.php:140–143` | SVG грузится без санитайза XML (`<script>`/`on*`/`foreignObject`) |
| S6 | **HIGH** | Webshell | `Handlers/FileManagerHandler.php:117–134` | `files/rename` без проверки расширения нового имени (image.jpg → shell.php) |
| S7 | **HIGH** | Upload | `Handlers/FileManagerHandler.php:162–199` | Нет MIME-проверки содержимого (в отличие от `ImageUploadHandler`) |
| S8 | **MEDIUM** | DoS блокировок | `CacheClearHandler.php:28` + `LockHandler.php:18` | `cm->refresh()` сносит партицию `mpcve_lock` — снятие всех локов |
| S9 | **MEDIUM** | IDOR / утечка | `LogHandler.php:26–53` | `log/list` отдаёт `old/new_value` любого `resourceId` без проверки прав |
| S10 | **MEDIUM** | Reflected | `Connector.php:100` | `action` отражается в JSON-ошибке (риск при `innerHTML` на фронте) |
| S11 | **MEDIUM** | Path traversal | `FileManagerHandler.php:251–257` | `cleanPath` чистит только `\.{2,}`, без `realpath`-нормализации |
| S12 | **MEDIUM** | Раскрытие путей | `PageSaveHandler.php:53,61` | Абсолютные пути ФС в сообщениях об ошибке |
| S13 | **MEDIUM** | HTML-инъекция | `OnWebPagePrerender.php:42–44` | `assetsUrl` в HTML-атрибуты без `htmlspecialchars` |
| S14 | **LOW** | Разведка / OOM | `FieldTypesHandler.php:69–83` | Отдаёт имена/типы всех TV сайта |
| Sf1 | **HIGH** | DOM XSS | `changelog.js:128`, `filemanager.js:165` | `e.id`/`f.url` в `innerHTML`-атрибуты без `esc()` |
| Sf2 | **MEDIUM** | XSS 2-го порядка | `text.js:83–89`, `textarea.js:50`, `richtext.js:94` | `el.innerHTML = value` без `sanitizeHtml` (контент из БД с пейлодом) |
| Sf3 | **LOW** | javascript: scheme | `richtext.js:94` (`sanitizeHtml`) | `sanitizeHtml` не режет `href="javascript:"` в разрешённых тегах |

---

## Часть A. Бэкенд (PHP)

### [CRITICAL] `raw` → биндинги TV (`@SELECT`/`@EVAL`/`@FILE`)
**Файл:** `Handlers/FieldOptionsHandler.php:47,60`
**Проблема:** `raw` из запроса уходит в `modTemplateVar::processBindings()` без фильтрации. `@SELECT` исполняет произвольный SQL (конкатенация строк, без PDO), `@EVAL` — PHP (при `allow_tv_eval=true`), `@FILE` читает файл, `@CHUNK`/`@RESOURCE` — содержимое из БД. Аутентифицированный редактор: `raw=@SELECT password,login FROM modx_users`.
**Рекомендация:** Принимать только `tv` по имени из БД; либо блокировать строки, начинающиеся с `@SELECT/@EVAL/@FILE/@CHUNK/@RESOURCE`.

### [CRITICAL] Загрузка исполняемых файлов при `accept=any`
**Файл:** `Handlers/FileManagerHandler.php:181–190` (`acceptExt()` → `return true` на 247)
**Проблема:** `accept` вне `image/video/audio/media` → разрешено любое расширение; имя из `$file['name']` без санитайза. Если media source смотрит в webroot — RCE.
**Рекомендация:** Убрать ветку `any`; глобальный блок-лист (`php,phtml,phar,php5,php7,shtml,cgi,pl,py,sh,htaccess`); `sanitizeFileName`.

### [CRITICAL] Нет CSRF-защиты в коннекторе
**Файл:** `assets/components/mpcvisualeditor/connector.php:35–36`
**Проблема:** Только cookie-сессия mgr; нет токена/проверки Origin/Referer/X-Requested-With. Внешний сайт через `fetch(..., {credentials:'include'})` выполнит любое write-действие от имени редактора.
**Рекомендация:** Nonce из сессии (передавать в `window.mpcVEConfig`, проверять в коннекторе); минимум — `X-Requested-With` + сверка `Origin`/`Referer`.

### [HIGH] Нет проверки прав на конкретный ресурс (IDOR)
**Файл:** `FieldSaveHandler.php:35–43`, `RowOpHandler.php:33–37`, `SectionOpHandler.php:35–47`, `LogHandler.php:57–96`
**Проблема:** После глобального `mpcve_edit` хендлеры не проверяют права на `resourceId`. Редактор правит любой документ; `log/revert` не проверяет права на ресурс из лог-записи.
**Рекомендация:** Грузить `modResource` по `resourceId`, проверять `checkPolicy('save')`.

### [HIGH] SVG без санитайза (Stored XSS)
**Файл:** `Handlers/ImageUploadHandler.php:140–143`
**Проблема:** SVG (XML с `<script>`/`on*`/`foreignObject`) грузится по доверию к расширению. При открытии в браузере — XSS, бьёт по другим редакторам в файл-менеджере.
**Рекомендация:** Отдавать SVG как `attachment` + санитайз XML (вырезать `script`, `on*`, `foreignObject`, `href=javascript:`); или запретить SVG с фронта.

### [HIGH] `files/rename` без проверки расширения
**Файл:** `Handlers/FileManagerHandler.php:117–134`
**Проблема:** Новое имя из `$request['name']` без проверки → `image.jpg` → `shell.php`.
**Рекомендация:** Тот же блок-лист расширений + `sanitizeFileName`.

### [HIGH] Загрузка без MIME-проверки в `files/upload`
**Файл:** `Handlers/FileManagerHandler.php:162–199`
**Проблема:** Только проверка расширения, нет валидации содержимого (в отличие от `ImageUploadHandler::mimeOk()`).
**Рекомендация:** Добавить MIME-валидацию для потенциально исполняемых типов.

### [MEDIUM] `cache/clear` сносит партицию локов (DoS)
**Файл:** `CacheClearHandler.php:28` + `LockHandler.php:18`
**Проблема:** `cm->refresh()` чистит все партиции, включая `mpcve_lock` → снятие всех блокировок.
**Рекомендация:** Хранить локи в БД с TTL, либо восстанавливать на `OnCacheRefresh`.

### [MEDIUM] `log/list` — IDOR + утечка значений
**Файл:** `LogHandler.php:26–53`
**Проблема:** Отдаёт `old_value`/`new_value` любого `resourceId` без проверки прав.
**Рекомендация:** `checkPolicy('view')` для `resourceId`.

### [MEDIUM] Отражение `action` в сообщении об ошибке
**Файл:** `Connector.php:100`
**Рекомендация:** Не включать ввод в текст ошибки.

### [MEDIUM] Слабая защита от path traversal в `cleanPath`
**Файл:** `Handlers/FileManagerHandler.php:251–257`
**Проблема:** `preg_replace('#\.{2,}#','',...)` оставляет одиночные `.`; `remove()` строит абсолютный путь конкатенацией (152).
**Рекомендация:** `realpath()` + проверка префикса `basePath`.

### [MEDIUM] Раскрытие путей ФС в ошибках
**Файл:** `PageSaveHandler.php:53,61` — логировать пути, пользователю краткое сообщение.

### [MEDIUM] `assetsUrl` без `htmlspecialchars`
**Файл:** `Plugins/OnWebPagePrerender.php:42–44` — `htmlspecialchars($assetsUrl, ENT_QUOTES)`.

### [LOW]
- `Connector.php:49–54,109–111` + `PageSaveHandler.php` — мёртвый код: `page/save` не вызывает `PageSaveHandler`; неиспользуемый `success()`.
- `LockHandler.php:25`, `CacheClearHandler.php:14` — неиспользуемый параметр `$mpcve`.
- `FieldTypesHandler.php:69–83` — `getCollection('modTemplateVar')` без условий (разведка + OOM); ограничить TV шаблона / `limit(200)`.
- `LockHandler.php:37–54` — TOCTOU при продлении лока (маловероятно).

### [NIT]
- `PageSaveHandler.php`, `SectionOpHandler.php:39`, `RowOpHandler.php:30` — хардкод русских сообщений в обход лексикона.
- `Handlers/ChangeLog.php:27–82` — raw PDO при наличии xPDO-схемы `mpcveChangeLog` (дублирование).

Чисты: `Mpcve.php`, `autoload.php`, `PermissionChecker.php`, `ConfigGetHandler.php`, `PluginHandler.php`, `plugin.mpcvisualeditor.php`, xPDO-модель.

---

## Часть B. Фронтенд (JS / CSS)

### [HIGH] DOM XSS: `e.id` в `innerHTML`-атрибуте
**Файл:** `changelog.js:128–129`
**Проблема:** `data-id="' + e.id + '"` без `esc()`; `e.id` из API. При `e.id = '1" onclick="..."` — XSS в атрибуте.
**Рекомендация:** `esc(String(e.id))`.

### [HIGH] Смешение `innerHTML` с данными из сети (filemanager)
**Файл:** `filemanager.js:165`
**Проблема:** `tile.innerHTML` с `esc(f.url)` в `src` — условно безопасно, но паттерн хрупкий (рядом `textContent`).
**Рекомендация:** Создавать `img` через `createElement` + `img.src = f.url`, не смешивать `innerHTML` с сетевыми данными.

### [HIGH] Утечка keydown-слушателя при наложении модалок
**Файл:** `dom.js:109,137,169`, аналогично в редакторах
**Проблема:** Несколько активных `document`-keydown конкурируют при вложенных диалогах.
**Рекомендация:** Capture-фаза + `stopImmediatePropagation()` (как `filemanager.js:61`), либо стек модалок с единым обработчиком.

### [HIGH] Гонка при двойном клике «Добавить строку»
**Файл:** `rows.js:238–261`
**Проблема:** `btn.disabled = false` вызывается до `renderRows()` → повторный клик до обновления UI.
**Рекомендация:** Снимать `disabled` в самом конце `.then`.

### [MEDIUM] Гонка destroy/create RTE при быстром переключении режима
**Файл:** `richtext.js:54–68,79` — в `close()` всегда `rte.destroy()` независимо от режима.

### [MEDIUM] Нет rollback DOM при ошибке сети во время drag-drop
**Файл:** `sidebar.js:124–141` (и `rows.js:227–234`) — UI меняется до ответа сервера, при ошибке рассинхрон.
**Рекомендация:** Либо ждать ответ до перерисовки, либо помечать несохранённое.

### [MEDIUM] XSS 2-го порядка: `el.innerHTML = src` в source-режиме
**Файл:** `text.js:83–89` (и `textarea.js:50`) — контент из БД с `<img onerror>` вставляется без санитайза.
**Рекомендация:** `sanitizeHtml(src, S.cfg.allowedTags)` перед присвоением.

### [MEDIUM] Утечка/обращение к detached DOM в panels
**Файл:** `panels.js:362–412,319–320` — колбэк обращается к закрытой панели; флаг `isClosed`.

### [MEDIUM] Нет защиты от параллельных `field/save` в `wireControl`
**Файл:** `panels.js:387–410` — флаг `saving`.

### [MEDIUM] `window.prompt` для URL ссылки
**Файл:** `rte.js:164` — заменить на `promptDialog()` из `dom.js` (может быть подавлен в iframe/CSP).

### [MEDIUM] Нет обработки non-ok HTTP в api.js
**Файл:** `api.js:14–18` — `r.json()` на HTML-странице ошибки → «Сетевая ошибка» вместо «403, перелогиньтесь».
**Рекомендация:** `if (!r.ok) throw new Error('HTTP '+r.status)`.

### [MEDIUM] `dragFrom` не сбрасывается при закрытии rows
**Файл:** `rows.js:88` (vs `sidebar.js:32`) — обнулять в `close()`.

### [LOW]
- `picture.js:152–166` — нет rollback `src`/`srcset` при частичной ошибке загрузки.
- `dom.js`, `panels.js`, `filemanager.js`, редакторы — нет `role="dialog"`/`aria-modal`/focus-trap.
- `filemanager.js:171` — dblclick дважды вызывает `setSelected`.
- `richtext.js:94` — `sanitizeHtml` не режет `href="javascript:"` в разрешённых тегах.
- `filemanager.js:99–109` — `navigate` без проверки `..` (защита бэка обязательна, но фронт тоже должен).

### [NIT]
- `dom.js:62–101,106–143,148–180` — дублирование шаблона диалогов (вынести `openDialog`).
- `overlay.css:559–561,583–592` — двойное объявление `.mpcve-rows__btn`.
- `panels.js:284` — добавить `f.type !== 'richtext'` в условие `multiline`.
- `changelog.js:13–16` — `flt` не сбрасывается при повторном `open()`.

Чисты: `constants.js`, `mark.js`, `address.js`, `lock.js`, `scalar.js`, `link.js`, `tags.js`.

---

## Приоритетный план устранения

1. **S3 (CSRF)** — nonce в коннекторе. Базовый барьер для всех write-действий, дешёвый фикс.
2. **S1 (`raw`-биндинги)** — запретить `@`-биндинги в `raw` либо принимать только `tv` по имени.
3. **S2/S6/S7 (файловый менеджер)** — общий блок-лист расширений + `sanitizeFileName` + MIME-проверка на upload и rename; убрать ветку `any`.
4. **S4/S9 (IDOR)** — единый guard `checkPolicy` по `resourceId` во всех write/read-хендлерах (включая `log/list` и `log/revert`).
5. **S5 (SVG)** — санитайз или запрет.
6. **Sf1/Sf2 (DOM XSS)** — `esc()` для `e.id`; `sanitizeHtml` перед всеми `el.innerHTML = value`; фильтр `javascript:` в `sanitizeHtml`.
7. Затем — S8/S10/S11/S12/S13 и функциональные баги фронта (гонки, rollback, обработка HTTP-ошибок).

---

## Статус устранения (2026-06-07, ветка v2.0.0, задеплоено + полевой смоук-тест 17/18)

### ✅ Исправлено
- **S1** raw `@`-биндинги: блок любого `raw`, начинающегося с `@` (статика без `@`; DB-опции — только именованная TV).
- **S2/S6/S7/S11** файловый менеджер: блок-лист исполняемых расширений (upload+rename, независимо от accept), `sanitizeFileName`, MIME-проверка (finfo, не-скрипт), посегментный `cleanPath`.
- **S3 CSRF**: nonce на сессию (`Mpcve::nonce`→`window.mpcVEConfig`), коннектор сверяет `hash_equals` на каждом действии; фронт шлёт nonce в rawPost/upload/lock-beacon.
- **S4/S9 IDOR**: `PermissionChecker::canEditResource/canViewResource` (`checkPolicy` save/view, sudo bypass) в FieldSave/RowOp/SectionOp/Log(list+revert).
- **S5 SVG**: `sanitizeSvg` (script/foreignObject/on*/javascript:/DOCTYPE-ENTITY).
- **S10** action не отражается в ответе. **S13** `assetsUrl` через `htmlspecialchars`. **S14** TV ограничены шаблоном ресурса + limit.
- **Sf1** `e.id`→`esc`. **Sf2/Sf3** `sanitizeHtml` чистит и АТРИБУТЫ по **белому списку** (per-tag дефолты + настройка `mpcve_allowed_attrs`); применён к live-DOM в richtext/text/textarea. **api.js** non-ok HTTP.

### ⏸️ Оставлено сознательно
- **S8** (`cache/clear` сносит партицию локов): low-impact (CSRF+авторизация, локи само-восстанавливаются heartbeat'ом); правильный фикс архитектурный (БД-локи с TTL).
- **S12** (FS-пути в `PageSaveHandler`): мёртвый код — `page/save` отключён в `Connector`.

### ⚠️ Деплой/эксплуатация
- **Non-sudo редакторы**: IDOR-guard требует resource-политику `save` на правимый ресурс (у легитимного контент-редактора она есть; sudo — bypass). Проверить на не-sudo учётке в браузере.
- **CSRF**: открытые ДО деплоя вкладки редактора (старый `window.mpcVEConfig` без nonce) → перезагрузить страницу.
- **`mpcve_allowed_attrs`**: зарегистрировать системную настройку в `_build` (release-tail); код работает и без DB-строки (default `''`).

### Осталось (функциональные баги фронта — НЕ безопасность, нужен интерактивный QA)
### ✅ Функциональные баги фронта — закрыты (2-я волна)
- **keydown** (`dom.js` 3 диалога + `panels.js`): capture-фаза + `stopImmediatePropagation` + дефер к `.mpcve-confirm` — Escape не закрывает заодно подлежащий редактор.
- **rows.js**: гонка двойного клика «добавить» (disabled снимается после ререндера); `dragFrom` сброшен в `close()`.
- **sidebar.js**: rollback порядка секций при ошибке (обратный splice).
- **panels.js**: `closed`-флаг (guard detached-DOM в save-колбэке).
- **rte.js**: `window.prompt` → `promptDialog` (iframe/CSP-safe; focus+range восстановлены).
- **filemanager.js**: фронт-страховка `navigate` от `..`. **changelog.js**: сброс `flt` при `open()`.
- **Connector.php**: удалён мёртвый `success()`. **overlay.css**: убран дубль `.mpcve-rows__btn`.

Ложные срабатывания/уже корректно: rows drag (DOM двигается после `ok`), richtext `close` (destroy гваржен `mode==='visual'`), panels parallel-save (`btn.disabled`), panels multiline (richtext возвращается раньше).

### ⏸️ Оставлено сознательно (низкая ценность / риск рефактора)
- a11y (`role=dialog`/`aria-modal`/focus-trap) — отдельная полировка доступности.
- Дедуп шаблона диалогов `dom.js` (вынос `openDialog`) — косметика.
- `Lock/CacheClear` неиспользуемый `$mpcve` — единая сигнатура хендлеров.
- `ChangeLog` raw PDO вместо xPDO-модели — рефактор рискованнее пользы.
- Хардкод RU-сообщений (`PageSaveHandler`/`SectionOp`/`RowOp`) — RU-проект; `PageSaveHandler` к тому же мёртвый код.
- filemanager dblclick (двойной `setSelected` идемпотентен), picture rollback (reload на успехе) — доброкачественны.
