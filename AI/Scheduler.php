<?php
/**
 * Runs NPC decisions for the current TravianZ instance.
 *
 * cron.php already advances the world clock. This scheduler therefore NEVER
 * calls Automation.php itself; it only lets active NPC accounts play.
 *
 * DEBUG:
 * Writes a detailed execution trace to:
 *   <INSTANCE_RUNTIME_PATH>/ai/debug.log
 */

require_once __DIR__ . '/AI.php';
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/GameActions.php';
require_once __DIR__ . '/RaidLogic.php';
require_once __DIR__ . '/VillageLogic.php';

/**
 * Write an AI diagnostic line to the instance runtime log.
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

    $line = '[' . date('Y-m-d H:i:s') . '] [AI] ' . $message . PHP_EOL;

    @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
}

function travianz_run_ai(): void
{
    travianz_ai_debug('==================================================');
    travianz_ai_debug('Scheduler START');

    try {
        $db = new TravianAIDb();
        travianz_ai_debug('Database object created');

        $db->ensureSchema();
        travianz_ai_debug('AI schema checked');

        $baseUrl = defined('HOMEPAGE') && HOMEPAGE !== ''
            ? HOMEPAGE
            : (defined('SERVER') ? SERVER : '');

        travianz_ai_debug(
            'Base URL: ' . ($baseUrl !== '' ? $baseUrl : '[EMPTY]')
        );

        if ($baseUrl === '') {
            travianz_ai_debug('ABORT: base URL is empty');
            return;
        }

        $runtime = defined('INSTANCE_RUNTIME_PATH')
            ? INSTANCE_RUNTIME_PATH . DIRECTORY_SEPARATOR . 'ai'
            : sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'travianz-ai';

        travianz_ai_debug('Runtime path: ' . $runtime);

        $game = new TravianAIGame($baseUrl, $runtime);
        travianz_ai_debug('Game driver created');

        $logic = new TravianAIVillageLogic(
            $db,
            $game,
            new TravianAIRaid($db, $game)
        );

        travianz_ai_debug('Village logic created');

        $now = time();

        travianz_ai_debug(
            'Current timestamp: ' . $now . ' (' . date('Y-m-d H:i:s', $now) . ')'
        );

        $players = $db->all(
            'SELECT a.*, u.tribe
               FROM {p}ai_players a
               JOIN {p}users u ON u.id = a.uid
              WHERE u.access > 0
              ORDER BY a.id'
        );

        travianz_ai_debug('NPC players found: ' . count($players));

        if (count($players) === 0) {
            travianz_ai_debug('WARNING: no NPC players selected');
        }

        foreach ($players as $row) {
            $username = (string)($row['username'] ?? '[unknown]');

            travianz_ai_debug(
                '--------------------------------------------------'
            );

            travianz_ai_debug(
                'NPC selected: ' . $username .
                ' | id=' . (int)($row['id'] ?? 0) .
                ' | uid=' . (int)($row['uid'] ?? 0) .
                ' | behavior=' . (string)($row['behavior'] ?? '[none]') .
                ' | next_think=' . (int)($row['next_think'] ?? 0)
            );

            try {
                $ai = new TravianAI($row);

                travianz_ai_debug(
                    $username .
                    ' | nextThink=' . $ai->nextThink .
                    ' | now=' . $now
                );

                if (!$ai->shouldThink($now)) {
                    travianz_ai_debug(
                        $username . ' | SKIP: next_think not reached'
                    );
                    continue;
                }

                travianz_ai_debug(
                    $username . ' | THINK: next_think reached'
                );

                $nextInterval = $ai->nextInterval();

                travianz_ai_debug(
                    $username .
                    ' | next interval=' . $nextInterval . ' seconds'
                );

                // Reschedule before acting: a failed request must not hammer the game.
                $db->exec(
                    'UPDATE {p}ai_players SET next_think = ? WHERE id = ?',
                    [$now + $nextInterval, $ai->id]
                );

                travianz_ai_debug(
                    $username .
                    ' | next_think updated to ' .
                    ($now + $nextInterval) .
                    ' (' .
                    date('Y-m-d H:i:s', $now + $nextInterval) .
                    ')'
                );

                if (!$ai->willAct()) {
                    travianz_ai_debug(
                        $username . ' | SKIP: willAct() returned FALSE'
                    );
                    continue;
                }

                travianz_ai_debug(
                    $username . ' | WILL ACT'
                );

                travianz_ai_debug(
                    $username . ' | attempting login'
                );

                if (!$game->ensureLoggedIn(
                    $ai->username,
                    $ai->password
                )) {
                    travianz_ai_debug(
                        $username . ' | LOGIN FAILED'
                    );
                    continue;
                }

                travianz_ai_debug(
                    $username . ' | LOGIN OK'
                );

                try {
                    travianz_ai_debug(
                        $username . ' | VillageLogic START'
                    );

                    $result = $logic->run($ai);

                    if (!is_array($result)) {
                        travianz_ai_debug(
                            $username . ' | VillageLogic returned non-array result'
                        );
                        continue;
                    }

                    if (count($result) === 0) {
                        travianz_ai_debug(
                            $username . ' | VillageLogic completed: NO ACTION'
                        );
                    } else {
                        foreach ($result as $line) {
                            travianz_ai_debug(
                                $username . ' | ACTION: ' . (string)$line
                            );
                        }
                    }

                    travianz_ai_debug(
                        $username . ' | VillageLogic END'
                    );
                } catch (Throwable $e) {
                    travianz_ai_debug(
                        $username .
                        ' | VillageLogic ERROR: ' .
                        get_class($e) .
                        ' | ' .
                        $e->getMessage()
                    );

                    error_log(
                        'TravianZ AI [' .
                        $ai->username .
                        ']: ' .
                        $e->getMessage()
                    );
                }
            } catch (Throwable $e) {
                travianz_ai_debug(
                    $username .
                    ' | NPC ERROR: ' .
                    get_class($e) .
                    ' | ' .
                    $e->getMessage()
                );

                error_log(
                    'TravianZ AI [' .
                    $username .
                    ']: ' .
                    $e->getMessage()
                );
            }
        }

        travianz_ai_debug('Scheduler END');
    } catch (Throwable $e) {
        travianz_ai_debug(
            'FATAL SCHEDULER ERROR: ' .
            get_class($e) .
            ' | ' .
            $e->getMessage()
        );

        error_log(
            'TravianZ AI Scheduler: ' .
            $e->getMessage()
        );
    }
}
