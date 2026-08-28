<?php
/**
 * Drives TravianZ through its normal HTTP endpoints.
 */
class TravianAIGame
{
    private string $baseUrl;
    private string $cookieDir;
    private array $checkers = [];
    private array $lastStatuses = [];

    public function __construct(string $baseUrl, string $cookieDir)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->cookieDir = $cookieDir;
        if (!is_dir($cookieDir)) {
            @mkdir($cookieDir, 0777, true);
        }
    }

    public function debug(string $message): void
    {
        $file = $this->cookieDir . DIRECTORY_SEPARATOR . 'debug.log';
        $line = '[' . date('Y-m-d H:i:s') . '] [AI] ' . $message . PHP_EOL;
        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }

    public function login(string $user, string $password): bool
    {
        unset($this->checkers[$user]);
        $this->request('GET', '/login.php', null, $user);
        [$status, , $headers] = $this->request('POST', '/login.php', [
            'ft' => 'a4', 'user' => $user, 'pw' => $password,
        ], $user);

        $ok = preg_match('/^Location:\s*dorf1\.php/m', $headers) === 1;
        $this->debug($user . ' | LOGIN ' . ($ok ? 'OK' : 'FAILED') . ' | status=' . $status);
        return $ok;
    }

    public function ensureLoggedIn(string $user, string $password): bool
    {
        if ($this->checker($user) !== null) {
            return true;
        }
        return $this->login($user, $password);
    }

    public function upgradeField(string $user, int $slot): bool
    {
        $token = $this->checker($user);
        if ($token === null) {
            $this->debug($user . ' | BUILD FIELD FAILED | no checker token');
            return false;
        }
        [$status, $body, $headers] = $this->request('GET', "/dorf1.php?a={$slot}&c={$token}", null, $user);
        $ok = $status === 302;
        if ($ok) unset($this->checkers[$user]);
        $this->debug($user . ' | BUILD FIELD ATTEMPT | slot=' . $slot . ' | status=' . $status . ' | location=' . $this->headerValue($headers, 'Location') . ' | body=' . $this->bodySummary($body));
        return $ok;
    }

    public function upgradeBuilding(string $user, int $slot): bool
    {
        $token = $this->checker($user);
        if ($token === null) {
            $this->debug($user . ' | BUILD UPGRADE FAILED | no checker token');
            return false;
        }
        [$status, $body, $headers] = $this->request('GET', "/dorf2.php?a={$slot}&c={$token}", null, $user);
        $ok = $status === 302;
        if ($ok) unset($this->checkers[$user]);
        $this->debug($user . ' | BUILD UPGRADE ATTEMPT | slot=' . $slot . ' | status=' . $status . ' | location=' . $this->headerValue($headers, 'Location') . ' | body=' . $this->bodySummary($body));
        return $ok;
    }

    public function constructBuilding(string $user, int $slot, int $type): bool
    {
        $token = $this->checker($user);
        if ($token === null) {
            $this->debug($user . ' | BUILD CONSTRUCT FAILED | no checker token');
            return false;
        }
        [$status, $body, $headers] = $this->request('GET', "/dorf2.php?a={$type}&id={$slot}&c={$token}", null, $user);
        $ok = $status === 302;
        if ($ok) unset($this->checkers[$user]);
        $this->debug($user . ' | BUILD CONSTRUCT ATTEMPT | slot=' . $slot . ' | type=' . $type . ' | status=' . $status . ' | location=' . $this->headerValue($headers, 'Location') . ' | body=' . $this->bodySummary($body));
        return $ok;
    }

    public function trainTroops(string $user, int $barracksSlot, int $unitId, int $amount): bool
    {
        $this->debug($user . ' | TRAIN ATTEMPT | barracks=' . $barracksSlot . ' | unit=' . $unitId . ' | amount=' . $amount);
        [$status, $body, $headers] = $this->request('POST', '/build.php', [
            'id' => (string) $barracksSlot,
            'ft' => 't1',
            't' . $unitId => (string) $amount,
            's1' => 'ok',
        ], $user);
        $ok = $status === 200 || $status === 302;
        $this->debug($user . ' | TRAIN RESULT | status=' . $status . ' | ok=' . ($ok ? 'YES' : 'NO') . ' | location=' . $this->headerValue($headers, 'Location') . ' | body=' . $this->bodySummary($body));
        return $ok;
    }

    public function raid(string $user, int $x, int $y, int $troopSlot, int $amount): bool
    {
        // TravianZ flow is deliberately two-step:
        // 1) POST the destination/troop selection -> attack.tpl
        // 2) POST the hidden confirmation fields -> Units::sendTroops()
        $this->debug($user . ' | RAID OPEN | target=' . $x . '|' . $y . ' | troopSlot=' . $troopSlot . ' | amount=' . $amount);
        [$openStatus, $form, $openHeaders] = $this->request('POST', '/a2b.php', [
            'x' => (string)$x,
            'y' => (string)$y,
            'c' => '4',
            't' . $troopSlot => (string)$amount,
            's1' => 'ok',
        ], $user);
        $this->debug($user . ' | RAID OPEN RESULT | status=' . $openStatus . ' | location=' . $this->headerValue($openHeaders, 'Location') . ' | body=' . $this->bodySummary($form));

        if ($openStatus !== 200) {
            $this->debug($user . ' | RAID FAILED | open step did not return confirmation page');
            return false;
        }

        $token = $this->tokens($form);
        if ($token === null) {
            $this->debug($user . ' | RAID FAILED | confirmation page has no timestamp token');
            return false;
        }
        if (!preg_match('/name=["\\\']ckey["\\\'] value=["\\\']([^"\\\']+)["\\\']/', $form, $ckey)) {
            $this->debug($user . ' | RAID FAILED | confirmation page has no ckey');
            return false;
        }
        if (!preg_match('/name=["\\\']a["\\\'] value=["\\\'](\\d+)["\\\']/', $form, $a)) {
            $this->debug($user . ' | RAID FAILED | confirmation page has no action marker');
            return false;
        }

        $this->debug($user . ' | RAID CONFIRM | a=' . $a[1] . ' | ckey=' . $ckey[1] . ' | timestamp=' . $token['timestamp']);

        [$status, $body, $headers] = $this->request('POST', '/a2b.php', [
            'a' => $a[1],
            'c' => '3',
            'ckey' => $ckey[1],
            'id' => '39',
            's1' => 'ok',
            'timestamp' => $token['timestamp'],
            'timestamp_checksum' => $token['checksum'],
        ], $user);

        $ok = $status === 302;
        $this->debug($user . ' | RAID SEND RESULT | status=' . $status . ' | ok=' . ($ok ? 'YES' : 'NO') . ' | location=' . $this->headerValue($headers, 'Location') . ' | body=' . $this->bodySummary($body));
        return $ok;
    }

    private function checker(string $user): ?string
    {
        if (isset($this->checkers[$user])) return $this->checkers[$user];
        [, $body] = $this->request('GET', '/build.php?id=1', null, $user);
        if (preg_match('/[?&]c=([a-zA-Z0-9]{32})(?:[&\"\'\s]|$)/', $body, $m)) {
            $this->debug($user . ' | CHECKER FOUND | source=/build.php?id=1 | length=' . strlen($m[1]));
            return $this->checkers[$user] = $m[1];
        }
        $this->debug($user . ' | CHECKER NOT FOUND | source=/build.php?id=1 | status=' . $this->lastStatus($user));
        return null;
    }

    private function tokens(string $html): ?array
    {
        if (!preg_match('/name="timestamp" value="(\d+)"/', $html, $ts)) return null;
        if (!preg_match('/name="timestamp_checksum" value="([a-zA-Z0-9]+)"/', $html, $sum)) return null;
        return ['timestamp' => $ts[1], 'checksum' => $sum[1]];
    }

    private function request(string $method, string $path, ?array $post = null, ?string $user = null): array
    {
        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 15,
        ]);

        if ($user !== null) {
            $safe = preg_replace('/[^a-zA-Z0-9_-]/', '', $user);
            $jar = $this->cookieDir . '/' . $safe . '.txt';
            curl_setopt($ch, CURLOPT_COOKIEJAR, $jar);
            curl_setopt($ch, CURLOPT_COOKIEFILE, $jar);
        }

        if ($post !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
        }

        $raw = curl_exec($ch);
        if ($raw === false) {
            $errno = curl_errno($ch);
            $error = curl_error($ch);
            curl_close($ch);
            $this->debug('HTTP ERROR | ' . $method . ' ' . $path . ' | curl=' . $errno . ' ' . $error);
            return [0, '', ''];
        }

        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($user !== null) $this->lastStatuses[$user] = $status;
        $headerLen = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        return [$status, substr($raw, $headerLen), substr($raw, 0, $headerLen)];
    }

    private function lastStatus(string $user): int
    {
        return (int)($this->lastStatuses[$user] ?? 0);
    }

    private function headerValue(string $headers, string $name): string
    {
        if (preg_match('/^' . preg_quote($name, '/') . ':\s*(.*)$/mi', $headers, $m)) {
            return trim($m[1]);
        }
        return '-';
    }

    private function bodySummary(string $body): string
    {
        $text = trim(preg_replace('/\s+/', ' ', strip_tags($body)));
        if ($text === '') return '-';
        return mb_substr($text, 0, 180);
    }
}
