<?php
declare(strict_types=1);

namespace Davyn\Config;

class Config
{
    private string $appName;
    private string $appEnv;
    private string $appSecret;
    private string $baseUrl;
    private string $dbDriver;
    private string $dbPath;
    private string $sessionName;
    private bool   $cookieSecure;
    private string $cookieSameSite;
    private int    $backupRetentionDays;
    private int    $backupMinKeep;
    private string $instanceName;
    private string $defaultLocale;
    private string $defaultTheme;
    private string $accentColor;
    private int    $maxContactsPerUser;
    private int    $maxEventsPerUser;
    private int    $maxVcardSize;
    private int    $maxIcsSize;
    private int    $holidayYearsBack;
    private int    $holidayYearsAhead;
    private bool   $holidayAutoGenerate;
    private string $holidayDefaultLocale;
    private int    $sessionActiveWindowMinutes;
    private int    $sessionCleanupRevokedDays;
    private int    $sessionCleanupInactiveDays;
    private string $certDir;
    private string $certName;
    private string $keyName;
    private int    $httpPort;
    private int    $httpsPort;

    public function __construct()
    {
        $this->appName       = $this->env('APP_NAME',     'Davyn');
        $this->appEnv        = $this->env('APP_ENV',      'dev');
        $this->appSecret     = $this->env('APP_SECRET',   '');
        $this->baseUrl       = $this->env('BASE_URL',     '');
        $this->dbDriver      = $this->env('DB_DRIVER',    'sqlite');
        $this->dbPath        = $this->env('DB_PATH',      '/var/www/data/davyn.sqlite');
        $this->sessionName   = $this->env('SESSION_NAME', 'davyn_session');
        $this->backupRetentionDays = max(1, (int) $this->env('BACKUP_RETENTION_DAYS', '30'));
        $this->backupMinKeep       = max(1, (int) $this->env('BACKUP_MIN_KEEP',       '5'));
        $this->instanceName  = $this->env('INSTANCE_NAME',    'Davyn');
        $this->defaultLocale = $this->env('DEFAULT_LOCALE',   'en');
        $this->defaultTheme  = $this->env('DEFAULT_THEME',    'system');
        $this->accentColor   = $this->env('DEFAULT_ACCENT_COLOR', '#1a56db');
        $this->maxContactsPerUser = max(0, (int) $this->env('MAX_CONTACTS_PER_USER', '0'));
        $this->maxEventsPerUser   = max(0, (int) $this->env('MAX_EVENTS_PER_USER',   '0'));
        $this->maxVcardSize       = max(1024, (int) $this->env('MAX_VCARD_SIZE',  (string) (256 * 1024)));
        $this->maxIcsSize         = max(1024, (int) $this->env('MAX_ICS_SIZE',    (string) (1024 * 1024)));
        $this->holidayYearsBack     = max(0, (int) $this->env('HOLIDAY_YEARS_BACK',  '1'));
        $this->holidayYearsAhead    = max(0, (int) $this->env('HOLIDAY_YEARS_AHEAD', '2'));
        $autoGen = strtolower($this->env('HOLIDAY_AUTO_GENERATE', 'true'));
        $this->holidayAutoGenerate  = !in_array($autoGen, ['0', 'false', 'off', 'no'], true);
        $this->holidayDefaultLocale = $this->env('HOLIDAY_DEFAULT_LOCALE', 'de_DE');
        $this->sessionActiveWindowMinutes  = max(5,  (int) $this->env('WEB_SESSION_ACTIVE_WINDOW_MINUTES', '120'));
        $this->sessionCleanupRevokedDays   = max(1,  (int) $this->env('WEB_SESSION_CLEANUP_REVOKED_DAYS',  '30'));
        $this->sessionCleanupInactiveDays  = max(1,  (int) $this->env('WEB_SESSION_CLEANUP_INACTIVE_DAYS', '90'));
        $this->certDir   = rtrim($this->env('CERT_DIR', '/var/www/config/certs'), '/');
        $this->certName  = basename($this->env('CERT_FILE', 'davyn.crt'));
        $this->keyName   = basename($this->env('KEY_FILE',  'davyn.key'));
        $this->httpPort  = max(1, (int) $this->env('HTTP_PORT',  '8080'));
        $this->httpsPort = max(1, (int) $this->env('HTTPS_PORT', '8443'));
        $this->cookieSameSite = $this->validateSameSite(
            $this->env('COOKIE_SAMESITE', 'Lax')
        );

        $secureEnv = $this->env('COOKIE_SECURE', '');
        $this->cookieSecure = match (true) {
            $secureEnv === '1', strtolower($secureEnv) === 'true'  => true,
            $secureEnv === '0', strtolower($secureEnv) === 'false' => false,
            default => $this->appEnv !== 'dev',
        };

        // Fail fast in production if no usable application secret is configured.
        if ($this->isProd() && !$this->hasSecret()) {
            throw new \RuntimeException(
                'APP_SECRET must be set to at least 32 characters in production.'
            );
        }
    }

    public function appName(): string       { return $this->appName; }
    public function appEnv(): string        { return $this->appEnv; }
    public function appSecret(): string     { return $this->appSecret; }
    public function baseUrl(): string       { return $this->baseUrl; }
    public function dbDriver(): string      { return $this->dbDriver; }
    public function dbPath(): string        { return $this->dbPath; }
    public function sessionName(): string   { return $this->sessionName; }
    public function cookieSecure(): bool    { return $this->cookieSecure; }
    public function cookieSameSite(): string { return $this->cookieSameSite; }

    public function instanceName(): string        { return $this->instanceName; }
    public function defaultLocale(): string       { return $this->defaultLocale; }
    public function defaultTheme(): string        { return $this->defaultTheme; }
    public function accentColor(): string         { return $this->accentColor; }
    public function maxContactsPerUser(): int     { return $this->maxContactsPerUser; }
    public function maxEventsPerUser(): int       { return $this->maxEventsPerUser; }
    public function maxVcardSize(): int           { return $this->maxVcardSize; }
    public function maxIcsSize(): int             { return $this->maxIcsSize; }
    public function holidayYearsBack(): int       { return $this->holidayYearsBack; }
    public function holidayYearsAhead(): int      { return $this->holidayYearsAhead; }
    public function holidayAutoGenerate(): bool   { return $this->holidayAutoGenerate; }
    public function holidayDefaultLocale(): string { return $this->holidayDefaultLocale; }
    public function sessionActiveWindowMinutes(): int  { return $this->sessionActiveWindowMinutes; }
    public function sessionCleanupRevokedDays(): int   { return $this->sessionCleanupRevokedDays; }
    public function sessionCleanupInactiveDays(): int  { return $this->sessionCleanupInactiveDays; }
    public function certDir(): string                  { return $this->certDir; }
    public function certName(): string                 { return $this->certName; }
    public function keyName(): string                  { return $this->keyName; }
    public function httpPort(): int                    { return $this->httpPort; }
    public function httpsPort(): int                   { return $this->httpsPort; }

    public function isProd(): bool                { return $this->appEnv === 'prod' || $this->appEnv === 'production'; }
    public function hasSecret(): bool             { return strlen($this->appSecret) >= 32; }
    public function backupRetentionDays(): int    { return $this->backupRetentionDays; }
    public function backupMinKeep(): int          { return $this->backupMinKeep; }

    private function validateSameSite(string $value): string
    {
        $allowed = ['Lax', 'Strict', 'None'];
        $normalized = ucfirst(strtolower($value));
        return in_array($normalized, $allowed, true) ? $normalized : 'Lax';
    }

    private function env(string $key, string $default): string
    {
        $value = getenv($key);
        return ($value !== false && $value !== '') ? $value : $default;
    }
}
