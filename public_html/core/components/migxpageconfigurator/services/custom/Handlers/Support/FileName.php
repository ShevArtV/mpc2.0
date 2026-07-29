<?php

namespace MpcServices\Handlers\Support;

/**
 * ЕДИНАЯ точка нормализации имён файлов и папок для всех потоков записи обоих
 * пакетов: грабер вёрстки (MediaDownloader), запись по URL (RemoteMediaIngestor)
 * и файловый менеджер редактора (mpcVE FileManagerHandler — upload/mkdir/rename/
 * download-url). До этого политика была задублирована в трёх местах с разным
 * поведением, и проект, которому нужны свои правила именования, мог только
 * пропатчить код пакета — так и разъехались пакет и его копия на сайте.
 *
 * Порядок работы, ОДИНАКОВЫЙ для всех потоков:
 *   1) defaultPolicy() — политика пакета (ASCII, lowercase, kebab для файлов,
 *      snake для папок, лимит длины, fallback);
 *   2) событие mpcOnSanitizeFileName — проект целиком переопределяет имя;
 *   3) harden() — ОБЯЗАТЕЛЬНЫЙ security-постфильтр.
 *
 * Третий шаг не пропускается никогда: точка расширения даёт проекту власть над
 * СТИЛЕМ имени, но не над безопасностью — плагин не может вернуть '../shell.php'
 * или имя, схлопывающееся в исполняемое расширение. Дедуп имени (коллизии) идёт
 * в вызывающем коде ПОСЛЕ нормализации — по той же причине: имя, назначенное
 * проектом, тоже не должно затирать чужой файл.
 */
class FileName
{
    public const KIND_FILE = 'file';
    public const KIND_DIR  = 'dir';

    /** Контексты вызова — передаются плагину, чтобы правила зависели от потока. */
    public const CTX_GRABBER         = 'grabber';
    public const CTX_GRABBER_SECTION = 'grabber-section';
    public const CTX_EDITOR_UPLOAD   = 'editor-upload';
    public const CTX_EDITOR_URL      = 'editor-url';
    public const CTX_FM_MKDIR        = 'filemanager-mkdir';
    public const CTX_FM_RENAME       = 'filemanager-rename';

    /** Событие пакета: единственная штатная точка расширения правил именования. */
    public const EVENT = 'mpcOnSanitizeFileName';

    /**
     * Лимит длины БАЗЫ имени (без расширения). Не про красоту: у ext4 предел
     * имени 255 БАЙТ, а конвертеры и превью-генераторы дописывают к базе свои
     * суффиксы, поэтому запас нужен заметный.
     */
    public const MAX_BASE_LENGTH = 100;

    public const FALLBACK_FILE = 'file';
    public const FALLBACK_DIR  = 'folder';

    /**
     * Исполняемые/опасные расширения — ПЕРВОИСТОЧНИК списка для обоих пакетов
     * (mpcVE MediaLibrary::BLOCKED_EXT ссылается сюда, чтобы копии не разъехались).
     */
    public const BLOCKED_EXT = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'pht', 'phps', 'phar',
        'shtml', 'cgi', 'pl', 'py', 'sh', 'bash', 'htaccess', 'htpasswd', 'user.ini',
        'asp', 'aspx', 'jsp', 'jspx', 'exe', 'com', 'bat', 'cmd', 'msi', 'dll', 'so',
    ];

    /**
     * Фолбэк-транслитерация кириллицы: ext-intl есть не на каждом хостинге, а без
     * него transliterator_transliterate() недоступен и кириллическое имя схлопнулось
     * бы целиком в fallback ('file'), потеряв всякую связь с исходным.
     */
    private const TRANSLIT_MAP = [
        'а' => 'a',  'б' => 'b',  'в' => 'v',  'г' => 'g',  'д' => 'd',  'е' => 'e',
        'ё' => 'e',  'ж' => 'zh', 'з' => 'z',  'и' => 'i',  'й' => 'y',  'к' => 'k',
        'л' => 'l',  'м' => 'm',  'н' => 'n',  'о' => 'o',  'п' => 'p',  'р' => 'r',
        'с' => 's',  'т' => 't',  'у' => 'u',  'ф' => 'f',  'х' => 'h',  'ц' => 'c',
        'ч' => 'ch', 'ш' => 'sh', 'щ' => 'sch', 'ъ' => '',  'ы' => 'y',  'ь' => '',
        'э' => 'e',  'ю' => 'yu', 'я' => 'ya',
        'ә' => 'a',  'ғ' => 'g',  'қ' => 'k',  'ң' => 'n',  'ө' => 'o',  'ұ' => 'u',
        'ү' => 'u',  'һ' => 'h',  'і' => 'i',  'ї' => 'i',  'є' => 'e',  'ґ' => 'g',
    ];

    // --- Публичный API ------------------------------------------------------

    /**
     * База имени файла (БЕЗ расширения). Расширение отдаётся в $ctx['extension'] —
     * плагину оно нужно для решения, но менять его через событие нельзя: тип файла
     * уже провалидирован вызывающим по accept/mime, и подмена расширения здесь
     * обошла бы эту проверку.
     */
    public static function forFile(?\modX $modx, string $base, array $ctx = []): string
    {
        return self::normalize($modx, $base, ['kind' => self::KIND_FILE] + $ctx);
    }

    /** Имя папки (snake_case: папки в путях читают глазами, дефисы там хуже). */
    public static function forFolder(?\modX $modx, string $name, array $ctx = []): string
    {
        return self::normalize($modx, $name, ['kind' => self::KIND_DIR] + $ctx);
    }

    /**
     * Полное имя файла «база.расширение» → нормализованное полное имя. Для потоков,
     * где имя приходит целиком (rename в файловом менеджере, дескриптор файла после
     * нативного события). Расширение НЕ переопределяется плагином — см. forFile().
     */
    public static function forFileName(?\modX $modx, string $fileName, array $ctx = []): string
    {
        $fileName = self::stripPath($fileName);
        $ext      = strtolower((string)pathinfo($fileName, PATHINFO_EXTENSION));
        $base     = (string)pathinfo($fileName, PATHINFO_FILENAME);

        // Имя целиком из расширения ('.htaccess') — базы нет, всё уходит в ext.
        // Отдаём базу нормализатору, а расширение таким именем не считаем.
        if ($base === '' && $ext !== '') {
            $base = $ext;
            $ext  = '';
        }

        // Исполняемое расширение в итоговое имя не переносим НИКОГДА: harden()
        // защищает базу, но она склеивается с расширением уже здесь, и 'shell.php'
        // от плагина иначе дал бы безопасную базу с опасным хвостом. Сливаем
        // расширение в базу — файл остаётся неисполняемым, а имя узнаваемым.
        if ($ext !== '' && self::isBlockedExt($ext)) {
            $base = $base . '-' . $ext;
            $ext  = '';
        }

        $base = self::forFile($modx, $base, ['extension' => $ext] + $ctx);
        return $ext === '' ? $base : $base . '.' . $ext;
    }

    /**
     * Полный цикл: политика пакета → событие проекта → security-постфильтр.
     * $modx = null (юнит-тесты чистой политики) — шаг с событием пропускается.
     */
    public static function normalize(?\modX $modx, string $name, array $ctx = []): string
    {
        $kind    = ($ctx['kind'] ?? self::KIND_FILE) === self::KIND_DIR ? self::KIND_DIR : self::KIND_FILE;
        $default = self::defaultPolicy($name, $kind);

        $result = $modx instanceof \modX
            ? self::fireEvent($modx, $name, $default, $kind, $ctx)
            : $default;

        return self::harden($result, $kind, (string)($ctx['extension'] ?? ''));
    }

    // --- Шаг 1: политика пакета --------------------------------------------

    /**
     * Дефолт пакета: транслитерация в ASCII, lowercase, любой символ вне [a-z0-9]
     * (включая '_', пробел и ТОЧКУ) → разделитель, схлопывание повторов, срез по
     * краям, лимит длины, fallback при пустом результате.
     *
     * Точка схлопывается намеренно: она снимает double-extension ('shell.php.jpg'
     * → 'shell-php.jpg') ещё до блок-листа, а заодно убирает ведущую точку
     * скрытых файлов.
     */
    public static function defaultPolicy(string $name, string $kind = self::KIND_FILE): string
    {
        $sep  = $kind === self::KIND_DIR ? '_' : '-';
        $name = self::stripPath($name);
        $name = self::toAscii($name);
        $name = strtolower(trim($name));
        $name = (string)preg_replace('/[^a-z0-9]+/', $sep, $name);
        $name = trim($name, $sep);

        if ($name !== '' && strlen($name) > self::MAX_BASE_LENGTH) {
            $name = trim(substr($name, 0, self::MAX_BASE_LENGTH), $sep);
        }

        return $name !== '' ? $name : self::fallback($kind);
    }

    /**
     * Транслитерация в ASCII.
     *
     * Кириллица идёт по СОБСТВЕННОЙ таблице, и только остаток (диакритика прочих
     * алфавитов) — через ext-intl. Порядок именно такой, потому что результат
     * обязан быть одинаковым на любом сервере: intl даёт 'наши' → 'nasi', таблица
     * → 'nashi', а грабер дедупит уже скачанные файлы ПО ИМЕНИ. Отдай мы это на
     * откуп наличию расширения — переезд сайта на хостинг без intl тихо задвоил
     * бы всю медиатеку.
     */
    private static function toAscii(string $name): string
    {
        $lower = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
        $name  = strtr($lower, self::TRANSLIT_MAP);

        if (function_exists('transliterator_transliterate')) {
            $out = transliterator_transliterate('Any-Latin; Latin-ASCII', $name);
            if (is_string($out) && $out !== '') {
                return $out;
            }
        }
        return $name;
    }

    // --- Шаг 2: точка расширения проекта ------------------------------------

    /**
     * Событие mpcOnSanitizeFileName. Плагин возвращает новое имя через
     * $modx->event->returnedValues['name']; пустой возврат = «дефолт устраивает».
     *
     * returnedValues чистим ПЕРЕД вызовом: грабер зовёт нормализацию в цикле по
     * всем медиа страницы, и значение, оставшееся от предыдущего файла (плагин
     * сработал на одном и промолчал на следующем), иначе применилось бы повторно.
     */
    private static function fireEvent(\modX $modx, string $original, string $default, string $kind, array $ctx): string
    {
        // Плагин внутри обработчика вполне может позвать нормализацию снова
        // (напрямую или через MediaDownloader::sanitizeFileName) — без этого
        // флага получилась бы бесконечная рекурсия и падение по памяти прямо на
        // нарезке. Вложенный вызов просто получает дефолтную политику.
        if (self::$inEvent) {
            return $default;
        }
        self::$inEvent = true;

        try {
            return self::askPlugin($modx, $original, $default, $kind, $ctx);
        } finally {
            self::$inEvent = false;
        }
    }

    /** @var bool идёт ли уже обработка mpcOnSanitizeFileName (anti-recursion) */
    private static bool $inEvent = false;

    private static function askPlugin(\modX $modx, string $original, string $default, string $kind, array $ctx): string
    {
        if (isset($modx->event) && is_object($modx->event)) {
            $modx->event->returnedValues = [];
        }

        $modx->invokeEvent(self::EVENT, [
            'name'      => $original,
            'sanitized' => $default,
            'kind'      => $kind,
            'extension' => (string)($ctx['extension'] ?? ''),
            'directory' => (string)($ctx['directory'] ?? ''),
            'context'   => (string)($ctx['context'] ?? ''),
        ]);

        $returned = isset($modx->event->returnedValues) ? (array)$modx->event->returnedValues : [];
        $name     = isset($returned['name']) ? trim((string)$returned['name']) : '';

        return $name !== '' ? $name : $default;
    }

    // --- Шаг 3: security-постфильтр -----------------------------------------

    /**
     * Обязательная зачистка результата (в т.ч. пришедшего от плагина проекта):
     * срез пути и traversal, вычистка управляющих символов, схлопывание точек
     * (double-extension), лимит длины, fallback при пустоте. Стиль имени не
     * трогаем — пробелы и регистр, если проект их вернул осознанно, остаются.
     */
    public static function harden(string $name, string $kind = self::KIND_FILE, string $ext = ''): string
    {
        $name = self::stripPath($name);
        $name = (string)preg_replace('/[\x00-\x1f\x7f]+/', '', $name);
        $name = str_replace('.', $kind === self::KIND_DIR ? '_' : '-', $name);
        $name = trim($name, " \t\n\r\0\x0B-_");

        if ($name !== '' && strlen($name) > self::MAX_BASE_LENGTH) {
            $name = trim(substr($name, 0, self::MAX_BASE_LENGTH), " -_");
        }
        if ($name === '') {
            return self::fallback($kind);
        }
        // Точек в базе после схлопывания уже нет, поэтому double-extension здесь
        // невозможен и isBlockedName() применять НЕЛЬЗЯ: она ловит любое вхождение
        // 'php' и забраковала бы безобидное 'shell-php'. Проверяем ровно то, что
        // может стать хвостом имени: расширение — по блок-листу, а базу — точным
        // совпадением (у файла без расширения хвост — она сама, '.htaccess').
        if ($kind === self::KIND_FILE) {
            $tail = $ext !== '' ? $ext : $name;
            if ($ext !== '' ? self::isBlockedExt($tail) : in_array(strtolower($tail), self::BLOCKED_EXT, true)) {
                return self::fallback($kind);
            }
        }
        return $name;
    }

    // --- Общие хелперы ------------------------------------------------------

    /** Исполняемое/опасное расширение (любой вариант php-тега тоже). */
    public static function isBlockedExt(string $ext): bool
    {
        $ext = strtolower($ext);
        return in_array($ext, self::BLOCKED_EXT, true) || strpos($ext, 'php') !== false;
    }

    /**
     * Блок по ВСЕМУ имени: pathinfo берёт лишь последний сегмент, поэтому
     * shell.php.jpg прошёл бы блок-лист, а Apache с `AddHandler .php` исполнил бы
     * его. Проверяем каждый dot-сегмент + любую `.php` в имени.
     */
    public static function isBlockedName(string $name): bool
    {
        $name = strtolower($name);
        if (strpos($name, '.php') !== false) {
            return true;
        }
        foreach (explode('.', $name) as $seg) {
            if (self::isBlockedExt($seg)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Срез каталогов: имя не должно уводить запись из целевой папки. basename()
     * не годится — он зависит от локали и по-разному ведёт себя с '\' на разных
     * платформах, а сюда приходит и windows-разделитель ('..\shell').
     */
    private static function stripPath(string $name): string
    {
        $name = trim(str_replace(['\\', "\0"], ['/', ''], $name));
        $name = rtrim($name, '/');
        $pos  = strrpos($name, '/');
        return $pos === false ? $name : (string)substr($name, $pos + 1);
    }

    private static function fallback(string $kind): string
    {
        return $kind === self::KIND_DIR ? self::FALLBACK_DIR : self::FALLBACK_FILE;
    }
}
