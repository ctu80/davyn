<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Davyn\Auth\AppPasswordRepository;
use Davyn\Auth\DavAuthBackend;
use Davyn\Config\Config;
use Davyn\Database\ConnectionFactory;
use Davyn\Dav\AddressBookObjectRepository;
use Davyn\Dav\AddressBookRepository;
use Davyn\Dav\CalendarObjectRepository;
use Davyn\Dav\CalendarRepository;
use Davyn\Dav\PrincipalRepository;
use Davyn\Dav\Sabre\CalDavBackend;
use Davyn\Dav\Sabre\CardDavBackend;
use Davyn\Dav\Sabre\PrincipalBackend;
use Davyn\Sharing\SharingService;
use Davyn\User\UserRepository;
use Davyn\Version\ObjectVersionRepository;

$config = new Config();
$pdo    = ConnectionFactory::create($config);

// Maintenance mode pauses sync clients with a 503 while keeping the admin web UI
// (separate /api/admin/* PHP files) fully usable to turn it back off.
if (\Davyn\Maintenance\MaintenanceMode::fromConfig($config)->isEnabled()) {
    header('Retry-After: 3600');
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Service temporarily unavailable (maintenance).\n";
    exit;
}

// Client sync traffic is the heartbeat that drives request-triggered automatic backups.
\Davyn\Backup\BackupScheduler::arm($config, $pdo);

$users        = new UserRepository($pdo);
$appPasswords = new AppPasswordRepository($pdo);
$principalRepo       = new PrincipalRepository($users);
$calendarRepo        = new CalendarRepository($pdo);
$calendarObjRepo     = new CalendarObjectRepository($pdo);
$addressBookRepo     = new AddressBookRepository($pdo);
$addressBookObjRepo  = new AddressBookObjectRepository($pdo);
$sharingService      = new SharingService($pdo);
$versionRepo         = new ObjectVersionRepository($pdo);
$calendarObjRepo->setVersionRepository($versionRepo);
$addressBookObjRepo->setVersionRepository($versionRepo);
$calendarObjRepo->setQuotas($config->maxIcsSize(), $config->maxEventsPerUser());
$addressBookObjRepo->setQuotas($config->maxVcardSize(), $config->maxContactsPerUser());

$principalBackend = new PrincipalBackend($principalRepo);
$calDavBackend    = new CalDavBackend($calendarRepo, $calendarObjRepo, $users, $sharingService);
$cardDavBackend   = new CardDavBackend($addressBookRepo, $addressBookObjRepo, $users, $sharingService, $pdo);

$tree = [
    new \Sabre\CalDAV\Principal\Collection($principalBackend),
    new \Sabre\CalDAV\CalendarRoot($principalBackend, $calDavBackend),
    new \Sabre\CardDAV\AddressBookRoot($principalBackend, $cardDavBackend),
];

$server = new \Sabre\DAV\Server($tree);
$server->setBaseUri('/dav/');

$davRateLimiter = new \Davyn\Auth\RateLimiter($pdo);
$server->addPlugin(new \Sabre\DAV\Auth\Plugin(new DavAuthBackend($users, $appPasswords, $davRateLimiter)));
$server->addPlugin(new \Sabre\DAVACL\Plugin());
$server->addPlugin(new \Sabre\DAV\Sync\Plugin());
$server->addPlugin(new \Sabre\CalDAV\Plugin());
$server->addPlugin(new \Sabre\CardDAV\Plugin());
$server->addPlugin(new \Sabre\DAV\Browser\Plugin());

$server->exec();
