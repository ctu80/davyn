<?php
declare(strict_types=1);

namespace Davyn\Sharing;

use RuntimeException;

/** The targeted calendar/addressbook does not exist (maps to HTTP 404). */
final class CollectionNotFoundException extends RuntimeException {}
