<?php
declare(strict_types=1);

require_once __DIR__ . '/_auth.php';

use Davyn\Http\PublicUrl;
use Davyn\Sharing\SharingService;

apiMethodGuard('GET');
['pdo' => $pdo, 'user' => $user, 'config' => $config] = apiUserGuard();

$svc  = new SharingService($pdo);
$rows = $svc->listAccessibleAddressBooksForUser((int) $user->id);

$base = PublicUrl::base($config, $pdo);
$un   = $user->username;

$result = array_map(function ($r) use ($base, $un) {
    $isOwned = ($r['permission'] === 'owner');
    $davUri  = $isOwned
        ? $r['uri']
        : 'shared-' . $r['owner_username'] . '-' . $r['uri'];
    return [
        'id'             => (int) $r['id'],
        'uri'            => $davUri,
        'display_name'   => $isOwned ? $r['display_name'] : $r['display_name'] . ' (shared by ' . $r['owner_username'] . ')',
        'owner_username' => $r['owner_username'],
        'permission'     => $r['permission'],
        'shared'         => !$isOwned,
        'dav_url'        => $base . '/dav/addressbooks/' . $un . '/' . $davUri . '/',
    ];
}, $rows);

apiJson(['addressbooks' => $result]);
