# mpc CLI

Декларативное управление MODX из проектных манифестов — чтобы не лазить в
админку. Разработчик описывает желаемое состояние в PHP-файле, одна команда
приводит админку к нему (idempotent apply).

## Запуск

Через тонкую обёртку `console/mpc` (не нужно писать полный путь к `mpc.php`):

```
./console/mpc <группа> <действие> [аргументы] [флаги]
```

Обёртка берёт php из переменной `MPC_PHP`, иначе из `PATH`. На hostland:

```
export MPC_PHP=/usr/local/php/php-7.4/bin/php
```

Прямой вызов тоже работает: `php core/components/migxpageconfigurator/console/mpc.php …`.

## Группы и команды

| Команда | Что делает |
|---|---|
| `resources apply [файл]` | Дерево ресурсов (ЕДИНЫЙ движок с self-seed пакета). Идемпотентно по `context_key + pagetitle`. Создаёт/обновляет, не удаляет. `--only=<pagetitle\|alias>` — точечно. |
| `cut <файл.tpl\|all> [--upd]` | Нарезка (`Mpc::process`) в контексте `--ctx` (по умолч. `web`). Два режима: **без `--upd`** — нарезка + умный мерж (ручные правки сохраняются); **с `--upd`** — нарезка + полная перезапись контента секций и переводов из вёрстки. `--dry-run` — показать без нарезки. |
| `elements <type\|all>` | Создать/обновить элементы из `elements/create/` (snippet/tv/plugin/resource…). |
| `configs sync` | Применить сид MIGX-конфигов (`migx_configs.json`, merge: новые поля + сохранение правок). |
| `cache clear [id,…]` | Очистить запечённые `parsed/` (без id — все; безопасно, регенерируются). |
| `settings apply [файл]` | Настройки MODX: системные (`modSystemSetting`) и контекстные (`modContextSetting`, per-key `'context'` в манифесте). Upsert по ключу. |
| `settings list [--namespace=ns] [--context=web] [--key=часть]` | Список настроек: системных или контекстных (с `--context`), фильтр по `--namespace` и/или по части ключа `--key` (LIKE). |
| `clientconfig apply [файл]` | Настройки ClientConfig (`cgSetting` + `cgContextValue` при `'context'`). `'group'` — группа для новой настройки. Требует установленного ClientConfig. |
| `clientconfig list [--group=имя] [--key=часть]` | Список настроек ClientConfig по группе (id или label) и/или части ключа `--key` (LIKE). |
| `events apply [файл]` | Привязки плагинов к событиям. Декларативно: набор приводится к указанному (bind недостающих + unbind лишних) для каждого перечисленного плагина. |
| `packages apply [файл]` | Установка (локальный `.transport.zip` или провайдер по имени) / удаление пакетов. Деструктив → `--force`. |
| `lexicon export-all` | Экспорт всех лексиконов «всё одним файлом» (XLSX). |
| `lexicon export-untranslated <filename>` | Экспорт только непереведённых ключей ресурса. |
| `lexicon list` | Список лексикон-файлов с заголовками. |
| `help` | Справка. |

## Флаги

- `--dry-run` — только показать план, без записи. **Рекомендуется перед боевым запуском.**
- `--force` — выполнить деструктив (нужен для `packages`).
- `--only=<ref>` — точечно (только указанный ресурс), для `resources`.
- `--json` — машинный вывод (план + результат в JSON).

## Манифесты

PHP-файлы, возвращающие массив (`return [...]`). Шаблоны — в `console/examples/`:
`resources.example.php`, `settings.example.php`, `clientconfig.example.php`,
`events.example.php`, `packages.example.php`.

Настройки (`settings`/`clientconfig`) поддерживают per-key `'context'` в спеке —
запись идёт в контекстную таблицу (`modContextSetting` / `cgContextValue`);
без `'context'` — системная/базовая. Для `clientconfig` `'context'` необязателен.

### База манифестов и дефолты имён

Боевые манифесты кладите в **базовую папку** — тогда путь можно не передавать.
База определяется в порядке убывания приоритета:

1. переменная окружения `MPC_MANIFESTS_PATH`;
2. системная настройка `mpc_manifests_path`;
3. дефолт `components/migxpageconfigurator/console/manifests/`.

Относительный путь резолвится от папки `core/` MODX (как и значение дефолта).

Аргумент `apply` теперь необязателен:

| Вызов | Какой файл |
|---|---|
| `settings apply` | `{base}/settings.php` (дефолт по имени группы) |
| `settings apply prod` | `{base}/prod.php` (профиль/окружение) |
| `settings apply ./my/path.php` | указанный файл как есть (совместимость) |

Имя без расширения дополняется `.php`. Существующий файл по абсолютному или
относительному пути всегда берётся напрямую, минуя базу.

## Безопасность

- Деструктив (`packages` install/remove) — только с `--force`.
- `resources`/`settings` НЕ удаляют отсутствующие в манифесте сущности (аддитивно).
- `events` — полная синхронизация набора для перечисленных плагинов (лишнее отвяжется!) — всегда сверяйтесь с `--dry-run`.
- Манифест — исполняемый PHP: держите его в доверенном репозитории проекта.
