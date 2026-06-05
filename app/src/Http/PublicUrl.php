<?php
declare(strict_types=1);

namespace Davyn\Http;

use Davyn\Config\Config;
use Davyn\Settings\SettingsRepository;

/**
 * Resolves the public/base URL used for generated links (DAV setup URLs, public
 * calendar links, QR codes). The admin-configurable `public_base_url` setting
 * takes precedence over the BASE_URL env default; an empty result means "let the
 * client derive it from the current origin".
 */
final class PublicUrl
{
    /** Trimmed (no trailing slash) base URL, or '' when none is configured. */
    public static function base(Config $config, \PDO $pdo): string
    {
        $fromSetting = trim((new SettingsRepository($pdo))->get('public_base_url'));
        $base = $fromSetting !== '' ? $fromSetting : $config->baseUrl();
        return rtrim($base, '/');
    }

    /** True for a syntactically acceptable absolute http(s) base URL (or empty). */
    public static function isValid(string $url): bool
    {
        if ($url === '') return true;
        if (!preg_match('#^https?://#i', $url)) return false;
        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }
}
