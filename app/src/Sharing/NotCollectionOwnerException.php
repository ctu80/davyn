<?php
declare(strict_types=1);

namespace Davyn\Sharing;

use RuntimeException;

/** The caller is not the owner of the targeted collection (maps to HTTP 403). */
final class NotCollectionOwnerException extends RuntimeException {}
