<?php

/**
 * TravianZ cron setup helper.
 *
 * This is primarily useful for an already-installed development server. The
 * normal installer calls the same class automatically from end.tpl.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require_once __DIR__ . '/include/CronInstaller.php';
require_once __DIR__ . '/../GameEngine/Instance/Resolver.php';

$instance = null;
foreach ($argv as $argument) {
    if (strpos($argument, '--instance=') === 0) {
        $instance = InstanceResolver::sanitize(substr($argument, 11));
        break;
    }
}

if ($instance === null) {
    fwrite(STDERR, "Usage: php install/cron_setup.php --instance=s1\n");
    exit(2);
}

$result = TravianZCronInstaller::install($instance);
echo ($result['success'] ? 'OK: ' : 'ERROR: ') . $result['message'] . PHP_EOL;
if (!empty($result['details'])) {
    foreach ($result['details'] as $key => $value) {
        echo $key . ': ' . $value . PHP_EOL;
    }
}
exit($result['success'] ? 0 : 1);
