<?php
/**
 * Логирование MigxPageConfigurator через mxLogger.
 */

namespace MpcServices\Helpers;

/**
 * @author Arthur Shevchenko (https://t.me/ShevArtV)
 * @description Делегирует записи компоненту mxLogger (если установлен); если mxLogger
 * не установлен — не логирует (штатное файловое логирование заменено на mxLogger).
 * @example
 *      $logging = new \MpcServices\Helpers\Logging($modx);
 *      $logging->write(__METHOD__, 'Test', ['class' => $className]);
 */
class Logging
{
    /** Уровни-наследие (числовые) — маппятся на уровни mxLogger. */
    public const DEBUG = 0;
    public const WARN  = 1;
    public const ERROR = 2;

    /** Базовые тэги: имя пакета + домен (конфигуратор страниц). */
    private const TAG = 'migxpageconfigurator';
    private const CHAIN_TAG = 'mpc';

    /** Наследие → имена уровней mxLogger. */
    private const LEVEL_MAP = array(
        self::DEBUG => 'debug',
        self::WARN  => 'warning',
        self::ERROR => 'error',
    );

    private const LEVELS = array(
        'debug'   => 10,
        'info'    => 20,
        'warning' => 30,
        'error'   => 40,
    );

    /** @var string Совместимость со старым API (раньше путь файла лога). */
    public string $path = '';

    /** @var \modX */
    private $modx;
    /** @var bool Включено ли логирование (mpc_debug). */
    private $debug;
    /** @var string Минимальный уровень (mpc_log_level). */
    private $minLevel;
    /** @var string|null process_uid воронки. */
    private $processUid = null;
    /** @var \mxLogger|null|false */
    private $mxl = false;

    /**
     * @param \modX       $modx
     * @param bool|null   $debug    Гейт вкл/выкл. Если null — из настройки mpc_debug.
     * @param string|null $minLevel Если null — из настройки mpc_log_level (дефолт debug).
     */
    public function __construct(\modX $modx, ?bool $debug = null, ?string $minLevel = null)
    {
        $this->modx = $modx;
        $this->debug = $debug === null ? (bool) $modx->getOption('mpc_debug', null, false) : (bool) $debug;
        $this->minLevel = $this->normalizeLevel(
            $minLevel === null ? $modx->getOption('mpc_log_level', null, 'debug') : $minLevel
        );
    }

    private function normalizeLevel($level): string
    {
        $level = strtolower((string) $level);
        return isset(self::LEVELS[$level]) ? $level : 'debug';
    }

    private function levelEnabled(string $level): bool
    {
        return self::LEVELS[$this->normalizeLevel($level)] >= self::LEVELS[$this->minLevel];
    }

    /** Наследие файлового лога — больше ничего не делает. */
    public function setPath(?string $fileName = '', ?string $dir = '')
    {
        $this->path = (string) $fileName;
    }

    /**
     * Задать process_uid воронки. Пустое/null — сбросить.
     *
     * @param string|null $uid
     * @return void
     */
    public function setProcess($uid)
    {
        $this->processUid = ($uid !== null && $uid !== '') ? (string) $uid : null;
    }

    /**
     * Записать лог. Сигнатура сохранена (позиционные method/msg/data/noDate/level);
     * добавлен $tags. Числовой $level (DEBUG/WARN/ERROR) маппится на уровень mxLogger.
     *
     * @param string       $method Метод-источник (context.source).
     * @param string       $msg    Текст сообщения.
     * @param array        $data   Контекст.
     * @param bool         $noDate Не используется (наследие файлового лога).
     * @param int          $level  self::DEBUG|WARN|ERROR.
     * @param string|array $tags   Доп. тэг(и) к базовым «migxpageconfigurator»/«mpc».
     * @return void
     */
    public function write($method, $msg, $data = array(), $noDate = false, int $level = self::DEBUG, $tags = array())
    {
        if (!$this->debug) {
            return;
        }
        $levelName = isset(self::LEVEL_MAP[$level]) ? self::LEVEL_MAP[$level] : 'debug';
        if (!$this->levelEnabled($levelName)) {
            return;
        }

        $mxl = $this->resolveMxLogger();
        if ($mxl === null) {
            return;
        }

        $context = is_array($data) ? $data : array('data' => $data);
        if ($method !== '' && $method !== null) {
            $context['source'] = (string) $method;
        }

        $allTags = array(self::TAG, self::CHAIN_TAG);
        foreach ((array) $tags as $tag) {
            $tag = (string) $tag;
            if ($tag !== '' && !in_array($tag, $allTags, true)) {
                $allTags[] = $tag;
            }
        }

        $options = array('skip_classes' => array(__CLASS__, __NAMESPACE__ . '\\Response'));
        if ($this->processUid !== null && $this->processUid !== '') {
            $options['process_uid'] = $this->processUid;
        }
        $mxl->log($allTags, $levelName, (string) $msg, $context, $options);
    }

    /**
     * @return \mxLogger|null
     */
    private function resolveMxLogger()
    {
        if ($this->mxl !== false) {
            return $this->mxl;
        }
        if (isset($this->modx->mxlogger) && $this->modx->mxlogger instanceof \mxLogger) {
            return $this->mxl = $this->modx->mxlogger;
        }
        $corePath = $this->modx->getOption(
            'mxlogger.core_path',
            null,
            $this->modx->getOption('core_path') . 'components/mxlogger/'
        );
        if (is_file($corePath . 'model/mxlogger/mxlogger.class.php')) {
            $svc = $this->modx->getService('mxlogger', 'mxLogger', $corePath . 'model/mxlogger/');
            if ($svc instanceof \mxLogger) {
                return $this->mxl = $svc;
            }
        }

        $this->modx->log(
            \modX::LOG_LEVEL_ERROR,
            '[MigxPageConfigurator] Установите mxLogger или отключите логирование (настройка mpc_debug).'
        );

        return $this->mxl = null;
    }
}
