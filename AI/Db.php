<?php
/**
 * Small database reader/writer used by the NPC scheduler.
 * Game state changes are still made through real TravianZ HTTP endpoints.
 */
class TravianAIDb
{
    private mysqli $link;
    public string $prefix;

    public function __construct()
    {
        require_once dirname(__DIR__) . '/GameEngine/config.php';

        $this->link = @new mysqli(SQL_SERVER, SQL_USER, SQL_PASS, SQL_DB, SQL_PORT);
        if ($this->link->connect_errno) {
            throw new RuntimeException('AI DB connect failed: ' . $this->link->connect_error);
        }
        $this->prefix = TB_PREFIX;
        $this->link->set_charset('utf8mb4');
    }

    public function ensureSchema(): void
    {
        $this->exec('
            CREATE TABLE IF NOT EXISTS {p}raid_memory (
                id INT AUTO_INCREMENT PRIMARY KEY,
                ai_id INT NOT NULL,
                target_wref INT NOT NULL,
                last_success TINYINT NOT NULL DEFAULT 0,
                last_loot INT NOT NULL DEFAULT 0,
                last_attack INT NOT NULL DEFAULT 0,
                UNIQUE KEY ai_target (ai_id, target_wref)
            )
        ');
        $this->exec('
            CREATE TABLE IF NOT EXISTS {p}ai_players (
                id INT AUTO_INCREMENT PRIMARY KEY,
                uid INT NOT NULL UNIQUE,
                username VARCHAR(30) NOT NULL UNIQUE,
                password VARCHAR(100) NOT NULL,
                behavior VARCHAR(20) NOT NULL DEFAULT "balanced",
                build_bias TINYINT NOT NULL DEFAULT 4,
                next_think INT NOT NULL DEFAULT 0,
                created INT NOT NULL,
                INDEX idx_next_think (next_think)
            )
        ');

        $columns = $this->all('SHOW COLUMNS FROM {p}ai_players');
        $names = array_column($columns, 'Field');
        if (!in_array('behavior', $names, true)) {
            $this->exec('ALTER TABLE {p}ai_players ADD COLUMN behavior VARCHAR(20) NOT NULL DEFAULT "balanced" AFTER password');
        }
        if (!in_array('build_bias', $names, true)) {
            $this->exec('ALTER TABLE {p}ai_players ADD COLUMN build_bias TINYINT NOT NULL DEFAULT 4 AFTER behavior');
        }
        if (!in_array('next_think', $names, true)) {
            $this->exec('ALTER TABLE {p}ai_players ADD COLUMN next_think INT NOT NULL DEFAULT 0 AFTER build_bias');
        }
        if (!in_array('created', $names, true)) {
            $this->exec('ALTER TABLE {p}ai_players ADD COLUMN created INT NOT NULL DEFAULT 0 AFTER next_think');
        }
    }

    public function all(string $sql, array $params = []): array
    {
        $result = $this->run($sql, $params);
        return $result instanceof mysqli_result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }

    public function one(string $sql, array $params = []): ?array
    {
        $result = $this->run($sql, $params);
        if (!$result instanceof mysqli_result) {
            return null;
        }
        $row = $result->fetch_assoc();
        return $row ?: null;
    }

    public function exec(string $sql, array $params = []): void
    {
        $this->run($sql, $params);
    }

    private function run(string $sql, array $params = []): mysqli_result|bool
    {
        $sql = str_replace('{p}', $this->prefix, $sql);

        if ($params === []) {
            $result = $this->link->query($sql);
            if ($result === false) {
                throw new RuntimeException('AI query failed: ' . $this->link->error);
            }
            return $result;
        }

        $stmt = $this->link->prepare($sql);
        if ($stmt === false) {
            throw new RuntimeException('AI prepare failed: ' . $this->link->error);
        }

        $types = str_repeat('s', count($params));
        $stmt->bind_param($types, ...array_values($params));
        if (!$stmt->execute()) {
            throw new RuntimeException('AI execute failed: ' . $stmt->error);
        }

        $result = $stmt->get_result();
        return $result === false ? true : $result;
    }
}
