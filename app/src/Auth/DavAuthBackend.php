<?php
declare(strict_types=1);

namespace Davyn\Auth;

use Davyn\User\UserRepository;
use Sabre\DAV\Auth\Backend\AbstractBasic;

class DavAuthBackend extends AbstractBasic
{
    protected $realm = 'Davyn DAV';

    public function __construct(
        private readonly UserRepository       $users,
        private readonly AppPasswordRepository $appPasswords,
        private readonly ?RateLimiter          $rateLimiter = null,
    ) {}

    protected function validateUserPass($username, $password): bool
    {
        $username = (string) $username;

        // Throttle repeated failures per account to slow online brute force.
        if ($this->rateLimiter !== null && $this->rateLimiter->isBlocked('dav', $username)) {
            return false;
        }

        $ok = $this->users->verifyPassword($username, (string) $password)
            || $this->appPasswords->verify($username, (string) $password);

        if ($this->rateLimiter !== null) {
            if ($ok) {
                $this->rateLimiter->recordSuccess('dav', $username);
            } else {
                $this->rateLimiter->recordFailure('dav', $username);
            }
        }

        return $ok;
    }
}
