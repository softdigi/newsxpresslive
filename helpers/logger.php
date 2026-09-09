<?php
/**
 * helpers/logger.php
 *
 * TIER 4 — DevOps: Structured application logger
 *
 * A lightweight PSR-3-inspired logger that writes structured JSON log lines
 * to a configurable file (or stderr).  Structured logs can be ingested by
 * Elastic, Loki, Datadog, etc. without regex parsing.
 *
 * Environment variables:
 *   LOG_LEVEL    — minimum level to record: debug|info|notice|warning|error|critical (default: info)
 *   LOG_FILE     — absolute path to log file (default: /var/log/newsxpresslive/app.log)
 *                  Use "stderr" to write to PHP stderr.
 *   LOG_FORMAT   — "json" (default) or "text" (human-readable)
 *   APP_ENV      — e.g. production, staging, local (included in every log line)
 *
 * Usage:
 *   require_once __DIR__ . '/logger.php';
 *   $log = AppLogger::getInstance();
 *
 *   $log->info('User logged in', ['user_id' => 42, 'ip' => '1.2.3.4']);
 *   $log->error('DB query failed', ['exception' => $e->getMessage()]);
 *   $log->warning('Slow query', ['ms' => 1200, 'query' => 'SELECT ...']);
 */

class AppLogger
{
    // PSR-3 level integers (higher = more severe)
    private const LEVELS = [
        'debug'    => 0,
        'info'     => 1,
        'notice'   => 2,
        'warning'  => 3,
        'error'    => 4,
        'critical' => 5,
    ];

    private static ?AppLogger $instance = null;

    private int    $minLevel;
    private string $file;
    private string $format;
    private string $env;
    private        $fp = null; // file pointer

    private function __construct()
    {
        $levelName     = strtolower(getenv('LOG_LEVEL') ?: 'info');
        $this->minLevel = self::LEVELS[$levelName] ?? self::LEVELS['info'];
        $this->file     = getenv('LOG_FILE')   ?: '/var/log/newsxpresslive/app.log';
        $this->format   = getenv('LOG_FORMAT') ?: 'json';
        $this->env      = getenv('APP_ENV')    ?: 'production';

        $this->_openFile();
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function debug(string $message, array $context = []): void
    {
        $this->_log('debug', $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->_log('info', $message, $context);
    }

    public function notice(string $message, array $context = []): void
    {
        $this->_log('notice', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->_log('warning', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->_log('error', $message, $context);
    }

    public function critical(string $message, array $context = []): void
    {
        $this->_log('critical', $message, $context);
    }

    /**
     * Log an exception at error level with full context.
     */
    public function exception(Throwable $e, string $message = '', array $extra = []): void
    {
        $this->error($message ?: $e->getMessage(), array_merge([
            'exception'  => get_class($e),
            'message'    => $e->getMessage(),
            'file'       => $e->getFile(),
            'line'       => $e->getLine(),
            'trace'      => $e->getTraceAsString(),
        ], $extra));
    }

    // ──────────────────────────────────────────────────────────────────────

    private function _log(string $level, string $message, array $context): void
    {
        if ((self::LEVELS[$level] ?? 0) < $this->minLevel) return;

        $record = [
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'level'     => $level,
            'env'       => $this->env,
            'message'   => $message,
        ];

        // Include request context for web requests
        if (isset($_SERVER['REQUEST_METHOD'])) {
            $record['request'] = [
                'method' => $_SERVER['REQUEST_METHOD'],
                // Truncate URI to prevent log-injection via crafted long URIs
                'uri'    => mb_substr($_SERVER['REQUEST_URI'] ?? '', 0, 512),
                'ip'     => $_SERVER['REMOTE_ADDR'] ?? '',
            ];
        }

        if (!empty($context)) {
            $record['context'] = $context;
        }

        $line = $this->format === 'text'
            ? $this->_formatText($record)
            : json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $this->_write($line . "\n");
    }

    private function _formatText(array $r): string
    {
        $ctx = !empty($r['context']) ? ' ' . json_encode($r['context']) : '';
        return "[{$r['timestamp']}] {$r['level']} {$r['message']}{$ctx}";
    }

    private function _write(string $line): void
    {
        if ($this->fp === null) {
            // Fall back to error_log if file not writable
            error_log(rtrim($line));
            return;
        }
        fwrite($this->fp, $line);
    }

    private function _openFile(): void
    {
        if ($this->file === 'stderr') {
            $this->fp = STDERR;
            return;
        }

        $dir = dirname($this->file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $this->fp = @fopen($this->file, 'a');
        if (!$this->fp) {
            // Cannot open log file — fall back to error_log
            $this->fp = null;
        }
    }

    public function __destruct()
    {
        if ($this->fp !== null && $this->fp !== STDERR) {
            fclose($this->fp);
        }
    }
}
