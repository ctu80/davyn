<?php
declare(strict_types=1);

namespace Davyn\Tls;

/**
 * Manages the internal HTTPS certificate that Caddy serves on the HTTPS port.
 *
 * All crypto goes through PHP's openssl_* functions — never the shell — so there
 * is no command injection surface. Writes are atomic (temp file in the same
 * directory, validated, then rename()) and the previous cert/key is copied to a
 * timestamped backup first, so a failed install never clobbers a working cert.
 *
 * This class only reads/writes files under the cert directory. It never restarts
 * Caddy: activating a new cert is a manual "restart the Caddy container" step,
 * surfaced to the admin in the UI.
 */
class CertificateManager
{
    /** Status values returned by inspect()['status']. */
    public const STATUS_MISSING      = 'missing';
    public const STATUS_VALID        = 'valid';
    public const STATUS_INVALID      = 'invalid';
    public const STATUS_EXPIRED      = 'expired';
    public const STATUS_NOT_YET      = 'not_yet_valid';
    public const STATUS_KEY_MISMATCH = 'key_mismatch';

    /** Max accepted PEM size (per file) — generous for a fullchain, still bounded. */
    public const MAX_PEM_BYTES = 256 * 1024;

    public function __construct(
        private readonly string $certDir,
        private readonly string $certName = 'davyn.crt',
        private readonly string $keyName  = 'davyn.key',
        private readonly string $caName   = 'davyn-ca.crt',
    ) {}

    public function certPath(): string   { return $this->certDir . '/' . $this->certName; }
    public function keyPath(): string    { return $this->certDir . '/' . $this->keyName; }
    public function caPath(): string     { return $this->certDir . '/' . $this->caName; }
    public function backupDir(): string  { return $this->certDir . '/backups'; }

    /**
     * The "force HTTPS" marker. Its presence tells the Caddy entrypoint to redirect
     * plain HTTP to HTTPS (only honoured by Caddy when a working cert is also present).
     */
    public function httpDisabledMarkerPath(): string { return $this->certDir . '/.http-disabled'; }

    public function setHttpDisabledMarker(bool $disabled): void
    {
        $path = $this->httpDisabledMarkerPath();
        if ($disabled) {
            $this->ensureDirs();
            @file_put_contents($path, "Plain HTTP is disabled; Caddy redirects :8080 to HTTPS.\n");
        } elseif (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * Whether plain HTTP may be disabled (redirected to HTTPS). Forcing HTTPS is
     * only safe once HTTPS is actually configured: a non-http TLS mode AND a
     * currently-valid certificate. Used as the precondition gate before the
     * /api/admin/tls/http-mode endpoint accepts "redirect".
     */
    public static function canForceHttps(string $tlsMode, string $certStatus): bool
    {
        return $tlsMode !== 'http' && $certStatus === self::STATUS_VALID;
    }

    /** Create the cert directory tree with restrictive permissions if missing. */
    public function ensureDirs(): void
    {
        foreach ([$this->certDir, $this->backupDir()] as $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0750, true);
            }
            @chmod($dir, 0750);
        }
    }

    public function certExists(): bool
    {
        return is_file($this->certPath());
    }

    /**
     * Inspect the cert/key currently on disk.
     *
     * Always returns parsed metadata when the certificate is parseable, so the
     * UI can show subject/SANs/expiry even for an expired or mismatched cert.
     */
    public function inspect(): array
    {
        $base = [
            'status'             => self::STATUS_MISSING,
            'has_certificate'    => false,
            'has_key'            => is_file($this->keyPath()),
            'subject_cn'         => null,
            'sans'               => [],
            'issuer'             => null,
            'self_signed'        => null,
            'valid_from'         => null,
            'valid_until'        => null,
            'days_remaining'     => null,
            'fingerprint_sha256' => null,
            'serial'             => null,
        ];

        if (!$this->certExists()) {
            return $base;
        }
        $base['has_certificate'] = true;

        $pem = @file_get_contents($this->certPath());
        $x509 = $pem !== false ? @openssl_x509_read($pem) : false;
        if ($x509 === false) {
            $base['status'] = self::STATUS_INVALID;
            return $base;
        }

        $parsed = @openssl_x509_parse($x509);
        if (!is_array($parsed)) {
            $base['status'] = self::STATUS_INVALID;
            return $base;
        }

        $from  = $parsed['validFrom_time_t'] ?? null;
        $until = $parsed['validTo_time_t'] ?? null;
        $now   = time();

        $base['subject_cn']  = $parsed['subject']['CN'] ?? null;
        $base['issuer']      = $this->dnToString($parsed['issuer'] ?? []);
        $base['self_signed'] = ($parsed['subject'] ?? null) == ($parsed['issuer'] ?? null);
        $base['sans']        = $this->parseSans($parsed['extensions']['subjectAltName'] ?? '');
        $base['valid_from']  = $from ? gmdate('Y-m-d\TH:i:s\Z', $from) : null;
        $base['valid_until'] = $until ? gmdate('Y-m-d\TH:i:s\Z', $until) : null;
        $base['days_remaining'] = $until ? (int) floor(($until - $now) / 86400) : null;

        $fp = @openssl_x509_fingerprint($x509, 'sha256');
        $base['fingerprint_sha256'] = $fp ? $this->colonize($fp) : null;
        if (isset($parsed['serialNumberHex'])) {
            $base['serial'] = strtoupper($parsed['serialNumberHex']);
        }

        // Determine effective status (validity window first, then key match).
        if ($from !== null && $now < $from) {
            $base['status'] = self::STATUS_NOT_YET;
        } elseif ($until !== null && $now > $until) {
            $base['status'] = self::STATUS_EXPIRED;
        } elseif (is_file($this->keyPath()) && !$this->keyMatchesCert($x509)) {
            $base['status'] = self::STATUS_KEY_MISMATCH;
        } else {
            $base['status'] = self::STATUS_VALID;
        }

        return $base;
    }

    /**
     * Generate a self-signed certificate (with SANs) and install it atomically.
     *
     * @param string[] $dnsSans
     * @param string[] $ipSans
     */
    public function generateSelfSigned(
        string $cn,
        array $dnsSans,
        array $ipSans,
        int $days,
        ?string $org = null,
    ): void {
        if (!function_exists('openssl_csr_sign')) {
            throw new \RuntimeException('OpenSSL is not available in this PHP build.');
        }

        // De-dupe and ensure the CN is also present as a SAN (modern clients
        // ignore CN and require a matching SAN).
        $dnsSans = array_values(array_unique(array_filter($dnsSans)));
        $ipSans  = array_values(array_unique(array_filter($ipSans)));
        if ($cn !== '' && filter_var($cn, FILTER_VALIDATE_IP)) {
            if (!in_array($cn, $ipSans, true)) $ipSans[] = $cn;
        } elseif ($cn !== '' && !in_array($cn, $dnsSans, true)) {
            $dnsSans[] = $cn;
        }
        if ($dnsSans === [] && $ipSans === []) {
            throw new \InvalidArgumentException('At least one SAN (DNS name or IP) is required.');
        }

        $cnfPath = tempnam(sys_get_temp_dir(), 'davyn-openssl-');
        if ($cnfPath === false) {
            throw new \RuntimeException('Could not create a temporary OpenSSL config.');
        }

        try {
            file_put_contents($cnfPath, $this->buildOpensslConfig($cn, $org, $dnsSans, $ipSans));

            $conf = [
                'config'           => $cnfPath,
                'digest_alg'       => 'sha256',
                'private_key_bits' => 2048,
                'private_key_type' => OPENSSL_KEYTYPE_RSA,
                'req_extensions'   => 'v3_req',
                'x509_extensions'  => 'v3_req',
            ];

            $pkey = openssl_pkey_new($conf);
            if ($pkey === false) {
                throw new \RuntimeException('Key generation failed: ' . $this->opensslErrors());
            }

            $dn = ['commonName' => $cn !== '' ? $cn : ($dnsSans[0] ?? $ipSans[0])];
            if ($org !== null && $org !== '') $dn['organizationName'] = $org;

            $csr = openssl_csr_new($dn, $pkey, $conf);
            if ($csr === false) {
                throw new \RuntimeException('CSR creation failed: ' . $this->opensslErrors());
            }

            $serial = random_int(1, PHP_INT_MAX);
            $cert   = openssl_csr_sign($csr, null, $pkey, $days, $conf, $serial);
            if ($cert === false) {
                throw new \RuntimeException('Self-signing failed: ' . $this->opensslErrors());
            }

            $certPem = '';
            $keyPem  = '';
            openssl_x509_export($cert, $certPem);
            openssl_pkey_export($pkey, $keyPem, null, $conf);

            $this->atomicInstall($certPem, $keyPem, null);
        } finally {
            @unlink($cnfPath);
        }
    }

    /**
     * Validate and install an admin-supplied certificate + key (+ optional chain).
     *
     * Returns an array of non-fatal warnings (e.g. expired, host not in SAN).
     * Throws on fatal problems (unparseable PEM, key/cert mismatch) and leaves
     * any existing cert untouched.
     *
     * @return string[] warning codes
     */
    public function installCustom(
        string $certPem,
        string $keyPem,
        ?string $chainPem = null,
        ?string $expectedHost = null,
    ): array {
        foreach (['certificate' => $certPem, 'private key' => $keyPem] as $label => $pem) {
            if (strlen($pem) > self::MAX_PEM_BYTES) {
                throw new \InvalidArgumentException("The $label is too large.");
            }
        }
        if ($chainPem !== null && strlen($chainPem) > self::MAX_PEM_BYTES) {
            throw new \InvalidArgumentException('The chain/CA bundle is too large.');
        }

        $x509 = @openssl_x509_read($certPem);
        if ($x509 === false) {
            throw new \InvalidArgumentException('The certificate could not be parsed (expected PEM).');
        }
        $key = @openssl_pkey_get_private($keyPem);
        if ($key === false) {
            throw new \InvalidArgumentException('The private key could not be parsed (expected PEM).');
        }
        if (!openssl_x509_check_private_key($x509, $key)) {
            throw new \InvalidArgumentException('The private key does not match the certificate.');
        }
        if ($chainPem !== null && $chainPem !== '' && @openssl_x509_read($chainPem) === false) {
            // Chains may contain multiple certs; openssl_x509_read parses the first.
            throw new \InvalidArgumentException('The chain/CA bundle could not be parsed (expected PEM).');
        }

        $warnings = [];
        $parsed = @openssl_x509_parse($x509) ?: [];
        $now    = time();
        $from   = $parsed['validFrom_time_t'] ?? null;
        $until  = $parsed['validTo_time_t'] ?? null;
        if ($from !== null && $now < $from)  $warnings[] = self::STATUS_NOT_YET;
        if ($until !== null && $now > $until) $warnings[] = self::STATUS_EXPIRED;

        if ($expectedHost !== null && $expectedHost !== '') {
            $sans = $this->parseSans($parsed['extensions']['subjectAltName'] ?? '');
            $cn   = $parsed['subject']['CN'] ?? null;
            $names = array_map('strtolower', array_merge($sans, $cn !== null ? [$cn] : []));
            if (!$this->hostMatches(strtolower($expectedHost), $names)) {
                $warnings[] = 'host_mismatch';
            }
        }

        $this->atomicInstall($certPem, $keyPem, ($chainPem !== null && $chainPem !== '') ? $chainPem : null);

        return $warnings;
    }

    /** Back up and remove the active cert/key (switches Caddy back to HTTP-only on restart). */
    public function remove(): void
    {
        $this->backupCurrent();
        foreach ([$this->certPath(), $this->keyPath(), $this->caPath()] as $f) {
            if (is_file($f)) @unlink($f);
        }
    }

    /** Public certificate PEM for download (never the key). Null if absent. */
    public function publicCertPem(): ?string
    {
        if (!$this->certExists()) return null;
        $pem = @file_get_contents($this->certPath());
        return $pem === false ? null : $pem;
    }

    // ---- internals -------------------------------------------------------

    private function atomicInstall(string $certPem, string $keyPem, ?string $chainPem): void
    {
        $this->ensureDirs();
        $this->backupCurrent();

        $tmpCert = $this->certPath() . '.tmp';
        $tmpKey  = $this->keyPath() . '.tmp';

        if (file_put_contents($tmpCert, $certPem) === false || file_put_contents($tmpKey, $keyPem) === false) {
            @unlink($tmpCert);
            @unlink($tmpKey);
            throw new \RuntimeException('Could not write certificate files (check directory permissions).');
        }

        // Re-validate what we just wrote before swapping it in.
        $checkCert = @openssl_x509_read(@file_get_contents($tmpCert) ?: '');
        $checkKey  = @openssl_pkey_get_private(@file_get_contents($tmpKey) ?: '');
        if ($checkCert === false || $checkKey === false || !openssl_x509_check_private_key($checkCert, $checkKey)) {
            @unlink($tmpCert);
            @unlink($tmpKey);
            throw new \RuntimeException('Written certificate failed re-validation; active cert left unchanged.');
        }

        @chmod($tmpKey, 0600);
        @chmod($tmpCert, 0644);

        if (!@rename($tmpCert, $this->certPath()) || !@rename($tmpKey, $this->keyPath())) {
            @unlink($tmpCert);
            @unlink($tmpKey);
            throw new \RuntimeException('Could not activate the new certificate (rename failed).');
        }

        if ($chainPem !== null) {
            @file_put_contents($this->caPath(), $chainPem);
            @chmod($this->caPath(), 0644);
        } elseif (is_file($this->caPath())) {
            @unlink($this->caPath());
        }
    }

    private function backupCurrent(): void
    {
        if (!$this->certExists()) return;
        $this->ensureDirs();
        $stamp = gmdate('Ymd-His');
        if (is_file($this->certPath())) {
            @copy($this->certPath(), $this->backupDir() . "/davyn-$stamp.crt");
        }
        if (is_file($this->keyPath())) {
            @copy($this->keyPath(), $this->backupDir() . "/davyn-$stamp.key");
            @chmod($this->backupDir() . "/davyn-$stamp.key", 0600);
        }
        if (is_file($this->caPath())) {
            @copy($this->caPath(), $this->backupDir() . "/davyn-$stamp-ca.crt");
        }
    }

    private function keyMatchesCert(\OpenSSLCertificate $x509): bool
    {
        $keyPem = @file_get_contents($this->keyPath());
        if ($keyPem === false) return false;
        $key = @openssl_pkey_get_private($keyPem);
        if ($key === false) return false;
        return openssl_x509_check_private_key($x509, $key);
    }

    private function buildOpensslConfig(string $cn, ?string $org, array $dnsSans, array $ipSans): string
    {
        $lines = [
            '[req]',
            'distinguished_name = dn',
            'x509_extensions = v3_req',
            'req_extensions = v3_req',
            'prompt = no',
            '[dn]',
            'CN = ' . ($cn !== '' ? $cn : ($dnsSans[0] ?? $ipSans[0])),
        ];
        if ($org !== null && $org !== '') $lines[] = 'O = ' . $org;
        $lines = array_merge($lines, [
            '[v3_req]',
            'basicConstraints = critical, CA:FALSE',
            'keyUsage = critical, digitalSignature, keyEncipherment',
            'extendedKeyUsage = serverAuth',
            'subjectKeyIdentifier = hash',
            'subjectAltName = @alt_names',
            '[alt_names]',
        ]);
        $i = 1;
        foreach ($dnsSans as $d) $lines[] = 'DNS.' . $i++ . ' = ' . $d;
        $j = 1;
        foreach ($ipSans as $p) $lines[] = 'IP.' . $j++ . ' = ' . $p;

        return implode("\n", $lines) . "\n";
    }

    /** "DNS:a, DNS:b, IP Address:1.2.3.4" → ['a','b','1.2.3.4'] */
    private function parseSans(string $raw): array
    {
        if ($raw === '') return [];
        $out = [];
        foreach (explode(',', $raw) as $part) {
            $part = trim($part);
            if ($part === '') continue;
            $colon = strpos($part, ':');
            $out[] = $colon === false ? $part : trim(substr($part, $colon + 1));
        }
        return array_values(array_filter($out));
    }

    private function hostMatches(string $host, array $names): bool
    {
        foreach ($names as $n) {
            if ($n === $host) return true;
            // Wildcard: *.example.com matches sub.example.com (single label).
            if (str_starts_with($n, '*.')) {
                $suffix = substr($n, 1); // ".example.com"
                if (str_ends_with($host, $suffix) && substr_count($host, '.') === substr_count($n, '.')) {
                    return true;
                }
            }
        }
        return false;
    }

    private function dnToString(array $dn): string
    {
        $parts = [];
        foreach ($dn as $k => $v) {
            $parts[] = is_array($v) ? "$k=" . implode('/', $v) : "$k=$v";
        }
        return implode(', ', $parts);
    }

    private function colonize(string $hex): string
    {
        return implode(':', str_split(strtoupper($hex), 2));
    }

    private function opensslErrors(): string
    {
        $errs = [];
        while (($e = openssl_error_string()) !== false) $errs[] = $e;
        return $errs === [] ? 'unknown error' : implode('; ', $errs);
    }
}
