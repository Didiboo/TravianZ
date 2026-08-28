<?php

/**
 * Write an AI diagnostic line to the current instance runtime log.
 *
 * Log rotation:
 * - debug.log is the active file.
 * - Rotation occurs at 5 MiB.
 * - Up to 5 archived files are retained.
 */
function travianz_ai_debug(string $message): void
{
    $runtime = defined('INSTANCE_RUNTIME_PATH')
        ? INSTANCE_RUNTIME_PATH . DIRECTORY_SEPARATOR . 'ai'
        : sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'travianz-ai';

    if (!is_dir($runtime)) {
        @mkdir($runtime, 0777, true);
    }

    $file = $runtime . DIRECTORY_SEPARATOR . 'debug.log';
    $maxSize = 5 * 1024 * 1024;
    $maxArchives = 5;

    if (is_file($file) && filesize($file) >= $maxSize) {
        for ($i = $maxArchives; $i >= 1; --$i) {
            $source = $i === 1
                ? $file
                : $file . '.' . ($i - 1);

            $destination = $file . '.' . $i;

            if (is_file($destination)) {
                @unlink($destination);
            }

            if (is_file($source)) {
                @rename($source, $destination);
            }
        }
    }

    $line = '[' . date('Y-m-d H:i:s') . '] [AI] ' . $message . PHP_EOL;

    @file_put_contents(
        $file,
        $line,
        FILE_APPEND | LOCK_EX
    );
}
