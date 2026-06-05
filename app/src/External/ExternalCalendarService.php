<?php
declare(strict_types=1);

namespace Davyn\External;

class ExternalCalendarService
{
    private const GENERATED_TYPE = 'external';
    private const FETCH_TIMEOUT  = 15;
    private const MAX_SIZE       = 5 * 1024 * 1024; // 5 MB

    public function __construct(private \PDO $pdo) {}

    public function add(int $userId, string $uri, string $displayName, string $sourceUrl): int
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $this->pdo->prepare(
            'INSERT INTO external_calendars (user_id, uri, display_name, source_url, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$userId, $uri, $displayName, $sourceUrl, $now, $now]);
        return (int) $this->pdo->lastInsertId();
    }

    public function refresh(int $userId, string $uri, ?string $localFilePath = null): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM external_calendars WHERE user_id = ? AND uri = ?'
        );
        $stmt->execute([$userId, $uri]);
        $ext = $stmt->fetch();
        if (!$ext) {
            throw new \RuntimeException("External calendar '$uri' not found.");
        }

        $icsContent = null;
        $error      = null;

        if ($localFilePath !== null) {
            // Local file for testing
            if (!is_readable($localFilePath)) {
                throw new \RuntimeException("Cannot read file: $localFilePath");
            }
            $icsContent = file_get_contents($localFilePath);
        } else {
            try {
                $icsContent = $this->fetchUrl($ext['source_url']);
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        }

        $now = gmdate('Y-m-d\TH:i:s\Z');

        if ($error !== null || $icsContent === false || $icsContent === null) {
            $this->pdo->prepare(
                'UPDATE external_calendars SET last_error = ?, updated_at = ? WHERE user_id = ? AND uri = ?'
            )->execute([$error ?? 'Fetch failed', $now, $userId, $uri]);
            throw new \RuntimeException("Refresh failed: " . ($error ?? 'Fetch returned empty'));
        }

        if (!str_contains($icsContent, 'BEGIN:VCALENDAR')) {
            $this->pdo->prepare(
                'UPDATE external_calendars SET last_error = ?, updated_at = ? WHERE user_id = ? AND uri = ?'
            )->execute(['Response does not contain BEGIN:VCALENDAR', $now, $userId, $uri]);
            throw new \RuntimeException("Invalid ICS: missing BEGIN:VCALENDAR");
        }

        $calendarId = $this->ensureCalendar($userId, $uri, $ext['display_name']);
        $imported   = $this->importIcs($calendarId, $icsContent);

        $this->pdo->prepare(
            'UPDATE external_calendars SET last_refresh_at = ?, last_error = NULL, updated_at = ? WHERE user_id = ? AND uri = ?'
        )->execute([$now, $now, $userId, $uri]);

        return ['calendar_id' => $calendarId, 'imported' => $imported];
    }

    public function fetchUrl(string $url): string
    {
        return $this->fetchWithRedirects($url, 3);
    }

    /**
     * Fetch a URL with manual, individually-validated redirects. Each hop is
     * SSRF-checked and the connection is pinned to the validated IP (via
     * CURLOPT_RESOLVE) so DNS rebinding between check and connect cannot occur.
     */
    private function fetchWithRedirects(string $url, int $redirectsLeft): string
    {
        $parsed = parse_url($url);
        $scheme = strtolower($parsed['scheme'] ?? '');
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new \RuntimeException('Only http/https URLs are allowed.');
        }
        $host = $parsed['host'] ?? '';
        if ($host === '') {
            throw new \RuntimeException('Invalid URL host.');
        }
        $port = (int) ($parsed['port'] ?? ($scheme === 'https' ? 443 : 80));

        // Resolve + validate every candidate IP, then pin to a safe one.
        $ip = $this->resolveAndValidate($host);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::FETCH_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'Davyn/1.0 ICS-Fetcher',
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_RESOLVE        => ["$host:$port:$ip"],
        ]);
        $body        = curl_exec($ch);
        $httpCode    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $redirectUrl = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        $curlErr     = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new \RuntimeException("cURL error: $curlErr");
        }

        // Handle redirects ourselves so the target gets re-validated.
        if ($httpCode >= 300 && $httpCode < 400 && is_string($redirectUrl) && $redirectUrl !== '') {
            if ($redirectsLeft <= 0) {
                throw new \RuntimeException('Too many redirects.');
            }
            return $this->fetchWithRedirects($redirectUrl, $redirectsLeft - 1);
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            throw new \RuntimeException("HTTP $httpCode from $url");
        }
        if (strlen($body) > self::MAX_SIZE) {
            throw new \RuntimeException('Response too large (max ' . self::MAX_SIZE . ' bytes)');
        }
        return $body;
    }

    /**
     * Resolve a host to all its A/AAAA addresses, reject if any is
     * private/reserved/loopback/link-local, and return a safe IP to pin to.
     */
    private function resolveAndValidate(string $host): string
    {
        // A literal IP in the URL is validated directly (covers decimal/hex
        // encodings that parse_url leaves as the host string).
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (!$this->isPublicIp($host)) {
                throw new \RuntimeException("Blocked address: $host");
            }
            return $host;
        }

        $lower = strtolower($host);
        if ($lower === 'localhost' || str_ends_with($lower, '.localhost') || $lower === 'localhost.localdomain') {
            throw new \RuntimeException("Blocked host: $host");
        }

        $ips = [];
        foreach ((@dns_get_record($host, DNS_A) ?: []) as $r) {
            if (!empty($r['ip'])) { $ips[] = $r['ip']; }
        }
        foreach ((@dns_get_record($host, DNS_AAAA) ?: []) as $r) {
            if (!empty($r['ipv6'])) { $ips[] = $r['ipv6']; }
        }
        if (!$ips) {
            $resolved = gethostbyname($host);
            if ($resolved && filter_var($resolved, FILTER_VALIDATE_IP)) {
                $ips[] = $resolved;
            }
        }
        if (!$ips) {
            throw new \RuntimeException("Could not resolve host: $host");
        }
        foreach ($ips as $ip) {
            if (!$this->isPublicIp($ip)) {
                throw new \RuntimeException("Blocked private/reserved IP: $ip ($host)");
            }
        }
        return $ips[0];
    }

    private function isPublicIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
        if ($ip === '0.0.0.0' || $ip === '::') {
            return false;
        }
        // IPv4 link-local incl. cloud metadata 169.254.169.254.
        if (str_starts_with($ip, '169.254.')) {
            return false;
        }
        // IPv4-mapped IPv6 (e.g. ::ffff:127.0.0.1) — validate the embedded v4.
        if (stripos($ip, '::ffff:') === 0) {
            $v4 = substr($ip, 7);
            return (bool) filter_var($v4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
                && $this->isPublicIp($v4);
        }
        return true;
    }

    private function ensureCalendar(int $userId, string $uri, string $name): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT id FROM calendars WHERE user_id = ? AND uri = ? AND generated_type = ?"
        );
        $stmt->execute([$userId, $uri, self::GENERATED_TYPE]);
        $row = $stmt->fetch();
        if ($row) return (int) $row['id'];

        $now = gmdate('Y-m-d\TH:i:s\Z');
        $this->pdo->prepare(
            'INSERT INTO calendars (user_id, uri, display_name, color, sync_token, created_at, updated_at, generated_type)
             VALUES (?, ?, ?, ?, 1, ?, ?, ?)'
        )->execute([$userId, $uri, $name, '#0891b2', $now, $now, self::GENERATED_TYPE]);
        return (int) $this->pdo->lastInsertId();
    }

    private function importIcs(int $calendarId, string $icsContent): int
    {
        $events   = $this->splitVevents($icsContent);
        $imported = 0;
        $keepUris = [];

        foreach ($events as $eventIcs) {
            $uid = '';
            if (preg_match('/^UID:([^\r\n]+)/m', $eventIcs, $m)) {
                $uid = trim($m[1]);
            }
            if (!$uid) continue;

            $fullIcs = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Davyn//External//EN\r\n$eventIcs\r\nEND:VCALENDAR\r\n";
            $uri     = 'ext-' . md5($uid) . '.ics';
            $keepUris[] = $uri;

            $etag = '"' . sha1($fullIcs) . '"';
            $size = strlen($fullIcs);
            $now  = gmdate('Y-m-d\TH:i:s\Z');

            $existing = $this->pdo->prepare(
                'SELECT id, etag FROM calendar_objects WHERE calendar_id = ? AND uri = ? AND deleted_at IS NULL'
            );
            $existing->execute([$calendarId, $uri]);
            $row = $existing->fetch();

            if ($row) {
                if ($row['etag'] === $etag) { $keepUris[] = $uri; continue; }
                $this->pdo->prepare(
                    'UPDATE calendar_objects SET ics = ?, etag = ?, size = ?, updated_at = ?, component_type = "VEVENT"
                     WHERE calendar_id = ? AND uri = ? AND deleted_at IS NULL'
                )->execute([$fullIcs, $etag, $size, $now, $calendarId, $uri]);
            } else {
                $this->pdo->prepare(
                    'INSERT INTO calendar_objects (calendar_id, uri, uid, ics, etag, size, component_type, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, "VEVENT", ?, ?)'
                )->execute([$calendarId, $uri, $uid, $fullIcs, $etag, $size, $now, $now]);
            }
            $imported++;
        }

        // Soft-delete removed events
        $now  = gmdate('Y-m-d\TH:i:s\Z');
        $stmt = $this->pdo->prepare(
            "SELECT uri FROM calendar_objects WHERE calendar_id = ? AND deleted_at IS NULL AND uri LIKE 'ext-%'"
        );
        $stmt->execute([$calendarId]);
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $uri) {
            if (!in_array($uri, $keepUris, true)) {
                $this->pdo->prepare(
                    'UPDATE calendar_objects SET deleted_at = ?, updated_at = ? WHERE calendar_id = ? AND uri = ? AND deleted_at IS NULL'
                )->execute([$now, $now, $calendarId, $uri]);
            }
        }

        // Bump sync token
        $tokStmt = $this->pdo->prepare('SELECT sync_token FROM calendars WHERE id = ?');
        $tokStmt->execute([$calendarId]);
        $token = (int) $tokStmt->fetchColumn() + 1;
        $this->pdo->prepare('UPDATE calendars SET sync_token = ?, updated_at = ? WHERE id = ?')
            ->execute([$token, $now, $calendarId]);

        return $imported;
    }

    private function splitVevents(string $ics): array
    {
        $events = [];
        if (preg_match_all('/(BEGIN:VEVENT.*?END:VEVENT)/s', $ics, $matches)) {
            foreach ($matches[1] as $block) {
                $events[] = trim($block);
            }
        }
        return $events;
    }
}
