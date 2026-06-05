<?php
declare(strict_types=1);

namespace Davyn\Setup;

use RuntimeException;

/** Thrown when first-run setup is attempted but Davyn already has an active admin. */
final class SetupAlreadyDoneException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Davyn is already initialized.');
    }
}
