<?php
declare(strict_types=1);

namespace Davyn\Http;

class ClientIp
{
    /**
     * Best-effort client IP. Behind the bundled Caddy reverse proxy the real
     * client address arrives in X-Forwarded-For; fall back to REMOTE_ADDR.
     * Returns null if nothing usable is present.
     */
    public static function get(): ?string
    {
        $fwd = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if ($fwd !== '') {
            $first = trim(explode(',', $fwd)[0]);
            if ($first !== '' && filter_var($first, FILTER_VALIDATE_IP)) {
                return $first;
            }
        }
        $remote = $_SERVER['REMOTE_ADDR'] ?? '';
        return $remote !== '' ? $remote : null;
    }
}
