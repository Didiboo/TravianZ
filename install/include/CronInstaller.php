<?php

/**
 * TravianZ - OS cron integration.
 *
 * cron.php remains the single automation engine. This class only connects the
 * already-installed world to the scheduler supplied by the operating system.
 */
class TravianZCronInstaller
{
    public static function install($instanceId)
    {
        $instanceId = preg_replace('/[^a-z0-9_-]/i', '', (string) $instanceId);
        if ($instanceId === '') {
            return self::result(false, 'Invalid instance identifier.');
        }

        $root = dirname(__DIR__, 2);
        $runtime = $root . DIRECTORY_SEPARATOR . 'instances' . DIRECTORY_SEPARATOR . $instanceId . DIRECTORY_SEPARATOR . 'runtime';
        if (!is_dir($runtime) && !@mkdir($runtime, 0755, true)) {
            return self::result(false, 'Unable to create the instance runtime directory.');
        }

        if (PHP_OS_FAMILY === 'Windows') {
            return self::installWindows($root, $runtime, $instanceId);
        }

        if (PHP_OS_FAMILY === 'Linux') {
            return self::installLinux($root, $runtime, $instanceId);
        }

        return self::result(false, 'Automatic cron installation is not implemented for this operating system (' . PHP_OS_FAMILY . ').');
    }

    private static function installWindows($root, $runtime, $instanceId)
    {
        if (!function_exists('exec')) {
            return self::result(false, 'PHP exec() is disabled; Windows Task Scheduler cannot be configured automatically.');
        }

        $php = self::findWindowsPhp();
        if ($php === null) {
            return self::result(false, 'Could not locate the PHP CLI executable next to the PHP runtime used by WAMP/Apache.');
        }

        $schtasks = getenv('SystemRoot');
        $schtasks = ($schtasks !== false && $schtasks !== '')
            ? $schtasks . DIRECTORY_SEPARATOR . 'System32' . DIRECTORY_SEPARATOR . 'schtasks.exe'
            : 'schtasks.exe';

        $taskName = 'TravianZ Cron - ' . $instanceId;
        $cron = $root . DIRECTORY_SEPARATOR . 'cron.php';

        // A small .cmd wrapper keeps quoting reliable on Windows and gives the
        // administrator a persistent log without changing cron.php itself.
        $launcher = $runtime . DIRECTORY_SEPARATOR . 'cron-windows.cmd';
        $log = $runtime . DIRECTORY_SEPARATOR . 'cron-windows.log';
        $cmd = "@echo off\r\n"
            . 'cd /d ' . self::windowsQuote($root) . "\r\n"
            . self::windowsQuote($php) . ' ' . self::windowsQuote($cron)
            . ' --instance=' . $instanceId
            . ' >> ' . self::windowsQuote($log) . " 2>&1\r\n";

        if (@file_put_contents($launcher, $cmd, LOCK_EX) === false) {
            return self::result(false, 'Unable to create the Windows cron launcher: ' . $launcher);
        }

        $command = self::windowsQuote($schtasks)
            . ' /Create /SC MINUTE /MO 5'
            . ' /TN ' . self::windowsQuote($taskName)
            . ' /TR ' . self::windowsQuote($launcher)
            . ' /F';

        $output = array();
        $exitCode = 1;
        @exec($command . ' 2>&1', $output, $exitCode);

        if ($exitCode !== 0) {
            self::writeStatus($runtime, array(
                'status' => 'ERROR',
                'platform' => 'Windows',
                'task' => $taskName,
                'php' => $php,
                'launcher' => $launcher,
                'message' => implode(PHP_EOL, $output),
            ));
            return self::result(false, 'Windows Task Scheduler rejected the cron task. ' . implode(' ', $output));
        }

        // Verify that Windows can actually see the task we just registered.
        $verifyOutput = array();
        $verifyCode = 1;
        @exec(self::windowsQuote($schtasks)
            . ' /Query /TN ' . self::windowsQuote($taskName)
            . ' /FO LIST /NH 2>&1', $verifyOutput, $verifyCode);

        if ($verifyCode !== 0) {
            self::writeStatus($runtime, array(
                'status' => 'ERROR',
                'platform' => 'Windows',
                'task' => $taskName,
                'php' => $php,
                'launcher' => $launcher,
                'message' => 'Task creation returned success, but the task could not be queried.',
            ));
            return self::result(false, 'The Windows cron task was created but could not be verified.');
        }

        // Start the newly registered task once so a fresh installation does not
        // have to wait up to five minutes for its first scheduled execution.
        $runOutput = array();
        $runCode = 1;
        @exec(self::windowsQuote($schtasks) . ' /Run /TN ' . self::windowsQuote($taskName) . ' 2>&1', $runOutput, $runCode);

        self::writeStatus($runtime, array(
            'status' => $runCode === 0 ? 'REGISTERED_AND_STARTED' : 'REGISTERED',
            'platform' => 'Windows',
            'task' => $taskName,
            'schedule' => 'Every 5 minutes',
            'instance' => $instanceId,
            'php' => $php,
            'launcher' => $launcher,
            'log' => $log,
            'registered_at' => date('c'),
            'initial_run_requested' => ($runCode === 0),
        ));

        return self::result(true, 'Windows Task Scheduler configured automatically.', array(
            'task' => $taskName,
            'php' => $php,
            'launcher' => $launcher,
            'log' => $log,
        ));
    }

    private static function installLinux($root, $runtime, $instanceId)
{
    if (!function_exists('exec')) {
        return self::result(false, 'PHP exec() is disabled; the Linux scheduler cannot be configured automatically.');
    }

    $php = self::findLinuxPhp();
    if ($php === null) {
        return self::result(false, 'Could not locate the PHP CLI executable.');
    }

    $cron = $root . DIRECTORY_SEPARATOR . 'cron.php';

    /*
     * This is a USER crontab entry.
     * Unlike /etc/cron.d, user crontabs do NOT contain the username field.
     */
     // Correct:
     // */5 * * * * /usr/bin/php /var/www/travian/cron.php --instance=s1
     //   Incorrect in a user crontab:
     // */5 * * * * www-data /usr/bin/php ...
     
    $entry = '*/5 * * * * ' . self::shellQuote($php) . ' ' . self::shellQuote($cron)
        . ' --instance=' . $instanceId . ' >/dev/null 2>&1';

    /*
     * The TravianZ scheduler runs as www-data.
     * The installer is normally executed by the web server as www-data,
     * so this crontab belongs to the same account that owns the instances.
     */
    $cronUser = 'www-data';

    $current = array();
    $currentCode = 1;

    @exec('crontab -u ' . self::shellQuote($cronUser) . ' -l 2>/dev/null', $current, $currentCode);

    if ($currentCode !== 0) {
        $current = array();
    }

    /*
     * Remove only the TravianZ entry belonging to this instance.
     * Other TravianZ instances and unrelated cron jobs are preserved.
     */
    $marker = '# TravianZ automatic cron ' . $instanceId;
    $filtered = array();

    foreach ($current as $line) {
        if (strpos($line, $marker) === 0) {
            continue;
        }

        $filtered[] = $line;
    }

    /*
     * Avoid accumulating duplicate blank lines at the end.
     */
    while (!empty($filtered) && trim(end($filtered)) === '') {
        array_pop($filtered);
    }

    if (!empty($filtered)) {
        $filtered[] = '';
    }

    $filtered[] = $marker;
    $filtered[] = $entry;

    $tmp = $runtime . DIRECTORY_SEPARATOR . 'cron-install.txt';

    if (@file_put_contents($tmp, implode("\n", $filtered) . "\n", LOCK_EX) === false) {
        return self::result(false, 'Unable to create the temporary cron configuration file.');
    }

    $installOutput = array();
    $installCode = 1;

    @exec(
        'crontab -u ' . self::shellQuote($cronUser)
        . ' ' . self::shellQuote($tmp)
        . ' 2>&1',
        $installOutput,
        $installCode
    );

    @unlink($tmp);

    if ($installCode === 0) {
        self::writeStatus($runtime, array(
            'status' => 'REGISTERED',
            'platform' => 'Linux',
            'scheduler' => 'www-data crontab',
            'schedule' => 'Every 5 minutes',
            'instance' => $instanceId,
            'php' => $php,
            'registered_at' => date('c'),
        ));

        return self::result(
            true,
            'Linux user crontab configured automatically for www-data.'
        );
    }

    return self::result(
        false,
        'The installer could not register the Linux cron job in the www-data crontab.'
    );
}

    private static function findWindowsPhp()
    {
        $candidates = array();
        if (defined('PHP_BINARY')) {
            $binary = PHP_BINARY;
            $candidates[] = $binary;
            $candidates[] = dirname($binary) . DIRECTORY_SEPARATOR . 'php.exe';
        }
        if (defined('PHP_BINDIR')) {
            $candidates[] = PHP_BINDIR . DIRECTORY_SEPARATOR . 'php.exe';
        }

        foreach ($candidates as $candidate) {
            if (is_file($candidate) && preg_match('/(?:^|[\\\\\/])php(?:-cgi)?\.exe$/i', $candidate)) {
                if (preg_match('/php-cgi\.exe$/i', $candidate)) {
                    $candidate = preg_replace('/php-cgi\.exe$/i', 'php.exe', $candidate);
                }
                if (is_file($candidate)) {
                    return $candidate;
                }
            }
        }
        return null;
    }

    private static function findLinuxPhp()
    {
        if (defined('PHP_BINARY') && is_file(PHP_BINARY) && is_executable(PHP_BINARY)) {
            $base = basename(PHP_BINARY);
            if (($base === 'php' || strpos($base, 'php') === 0) && stripos($base, 'fpm') === false) {
                return PHP_BINARY;
            }
        }

        $output = array();
        $code = 1;
        @exec('command -v php 2>/dev/null', $output, $code);
        if ($code === 0 && !empty($output[0]) && is_file($output[0])) {
            return trim($output[0]);
        }
        return null;
    }

    private static function windowsQuote($value)
    {
        return '"' . str_replace('"', '\\"', (string) $value) . '"';
    }

    private static function shellQuote($value)
    {
        return function_exists('escapeshellarg') ? escapeshellarg($value) : "'" . str_replace("'", "'\\''", $value) . "'";
    }

    private static function writeStatus($runtime, array $data)
    {
        @file_put_contents(
            $runtime . DIRECTORY_SEPARATOR . 'cron_setup.txt',
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL,
            LOCK_EX
        );
    }

    private static function result($success, $message, array $details = array())
    {
        return array(
            'success' => (bool) $success,
            'message' => $message,
            'details' => $details,
        );
    }
}
