<?php
namespace APP\plugins\generic\swordv3\classes;

use DateTime;
use PKP\config\Config;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Throwable;

class Logger implements LoggerInterface
{
    protected string $filepath;

    public function __construct(
        protected int $contextId,
        protected int $submissionId,
        protected int $publicationId,
    )
    {
        $this->filepath = Config::getVar('files', 'files_dir') . '/swordv3.log';
    }

    public function log(mixed $level, string|\Stringable $message, array $context = []): void
    {
        $time = (new DateTime())->format('Y-m-d h:i:s');
        $deposit = "{$this->contextId}-{$this->submissionId}-{$this->publicationId}";
        $finalMessage  = $this->interpolate($message, $context);
        try {
            file_put_contents(
                $this->filepath,
                "\n[{$time}] [{$deposit}] {$level} {$finalMessage}",
                FILE_APPEND
            );
        } catch (Throwable $e) {
            error_log($e->getMessage());
        }
    }

    protected function interpolate(string $message, array $context = []): string
    {
        $replace = [];
        foreach ($context as $key => $value) {
            if (!is_array($value) && (!is_object($value) || method_exists($value, '__toString'))) {
                $replace['{' . $key . '}'] = $value;
            }
        }
        return strtr($message, $replace);
    }

    public function emergency($message, array $context = []): void
    {
        $this->log(LogLevel::EMERGENCY, $message, $context);
    }

    public function alert($message, array $context = []): void
    {
        $this->log(LogLevel::ALERT, $message, $context);
    }

    public function critical($message, array $context = []): void
    {
        $this->log(LogLevel::CRITICAL, $message, $context);
    }

    public function error($message, array $context = []): void
    {
        $this->log(LogLevel::ERROR, $message, $context);
    }

    public function warning($message, array $context = []): void
    {
        $this->log(LogLevel::WARNING, $message, $context);
    }

    public function notice($message, array $context = []): void
    {
        $this->log(LogLevel::NOTICE, $message, $context);
    }

    public function info($message, array $context = []): void
    {
        $this->log(LogLevel::INFO, $message, $context);
    }

    public function debug($message, array $context = []): void
    {
        $this->log(LogLevel::DEBUG, $message, $context);
    }
}