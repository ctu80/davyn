<?php
declare(strict_types=1);

require_once __DIR__ . '/../_auth.php';
require_once __DIR__ . '/../_collection_resolver.php';

use Davyn\Audit\ActivityLog;
use Davyn\Http\Csrf;
use Davyn\ImportExport\ImportService;

apiMethodGuard('POST');
['pdo' => $pdo, 'user' => $user, 'session' => $session] = apiUserGuard();

$csrf = new Csrf($session);
if (!$csrf->verify($_POST['csrf_token'] ?? null)) {
    apiError('Invalid CSRF token', 403);
}

$maxSize  = 25 * 1024 * 1024; // 25 MB per file
$type     = $_POST['type']       ?? ''; // 'calendar' or 'addressbook'
$colUri   = trim($_POST['collection'] ?? '');

if (!in_array($type, ['calendar', 'addressbook'], true)) {
    apiError("type must be 'calendar' or 'addressbook'", 400);
}
if (!$colUri) {
    apiError('collection is required', 400);
}

// Resolve the collection (own or shared-*) and require write access. Importing
// is a write, so read-only shares are rejected — mirrors events/contacts saves.
if ($type === 'calendar') {
    $resolved = resolveAccessibleCalendar($pdo, (int) $user->id, $colUri);
    $collectionId = $resolved !== null ? (int) $resolved['cal']['id'] : null;
} else {
    $resolved = resolveAccessibleAddressBook($pdo, (int) $user->id, $colUri);
    $collectionId = $resolved !== null ? (int) $resolved['ab']['id'] : null;
}
if ($resolved === null) {
    apiError("Collection '$colUri' not found or not accessible", 403);
}
if (!in_array($resolved['permission'], ['owner', 'read_write'], true)) {
    apiError("You do not have write access to '$colUri'", 403);
}
// Generated calendars (holidays, birthdays) are read-only even to their owner —
// the DAV layer blocks PUTs there, so the import path must too.
if ($type === 'calendar' && !empty($resolved['cal']['generated_type'])) {
    apiError("'$colUri' is a read-only generated calendar", 403);
}

// Normalise $_FILES['file'] — accepts a single upload (scalar form) or several
// (array form, posted as file[]). Yields a flat list of per-file arrays.
if (!isset($_FILES['file'])) {
    apiError('No file uploaded', 400);
}
$raw = $_FILES['file'];
$files = [];
if (is_array($raw['name'])) {
    foreach (array_keys($raw['name']) as $i) {
        $files[] = [
            'name'     => $raw['name'][$i],
            'tmp_name' => $raw['tmp_name'][$i],
            'error'    => $raw['error'][$i],
            'size'     => $raw['size'][$i],
        ];
    }
} else {
    $files[] = $raw;
}
if ($files === []) {
    apiError('No file uploaded', 400);
}

$allowedExt = $type === 'calendar' ? 'ics' : 'vcf';
$marker     = $type === 'calendar' ? 'BEGIN:VCALENDAR' : 'BEGIN:VCARD';

$svc   = new ImportService();
$total = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []];
$perFile = [];

foreach ($files as $file) {
    $origName = basename($file['name'] ?? '');
    $entry    = ['name' => $origName, 'created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []];

    $fail = static function (string $msg) use (&$entry, &$total, &$perFile): void {
        $entry['errors'][] = $msg;
        $total['errors'][] = $entry['name'] . ': ' . $msg;
        $perFile[] = $entry;
    };

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $fail('upload failed (error code ' . ($file['error'] ?? -1) . ')');
        continue;
    }
    if (($file['size'] ?? 0) > $maxSize) {
        $fail('file too large (max 25 MB)');
        continue;
    }
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    if ($ext !== $allowedExt) {
        $fail("invalid file type (expected .$allowedExt)");
        continue;
    }

    // Read the uploaded temp file once; the importer regex-splits the whole
    // document so there's nothing to gain from a second temp copy.
    $content = file_get_contents($file['tmp_name']);
    if ($content === false || strlen($content) === 0) {
        $fail('file is empty');
        continue;
    }
    if (!str_contains($content, $marker)) {
        $fail("invalid content (missing $marker)");
        continue;
    }

    try {
        $result = $type === 'calendar'
            ? $svc->importCalendarData($collectionId, $content, $pdo)
            : $svc->importAddressBookData($collectionId, $content, $pdo);
        $entry['created'] = $result['created'];
        $entry['updated'] = $result['updated'];
        $entry['skipped'] = $result['skipped'];
        $entry['errors']  = $result['errors'];
        $total['created'] += $result['created'];
        $total['updated'] += $result['updated'];
        $total['skipped'] += $result['skipped'];
        foreach ($result['errors'] as $err) {
            $total['errors'][] = $origName . ': ' . $err;
        }
        $perFile[] = $entry;
    } catch (\Throwable $e) {
        error_log('[davyn] import upload failed: ' . $e->getMessage());
        $fail('import failed');
    }
}

(new ActivityLog($pdo))->log(
    (int) $user->id,
    "user.$type.import",
    sprintf(
        'Imported %d file(s): created=%d updated=%d errors=%d',
        count($files),
        $total['created'],
        $total['updated'],
        count($total['errors']),
    ),
);

apiJson(['ok' => true, 'result' => $total + ['files' => $perFile]]);
