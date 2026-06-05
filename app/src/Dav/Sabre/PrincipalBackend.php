<?php
declare(strict_types=1);

namespace Davyn\Dav\Sabre;

use Davyn\Dav\PrincipalRepository;
use Sabre\DAV\PropPatch;
use Sabre\DAVACL\PrincipalBackend\AbstractBackend;

class PrincipalBackend extends AbstractBackend
{
    public function __construct(private readonly PrincipalRepository $principals) {}

    public function getPrincipalsByPrefix($prefixPath): array
    {
        if ($prefixPath !== 'principals') {
            return [];
        }

        return array_map(
            fn(array $p) => [
                'uri'               => $p['uri'],
                '{DAV:}displayname' => $p['display_name'],
            ],
            $this->principals->listPrincipals()
        );
    }

    public function getPrincipalByPath($path): ?array
    {
        if (!str_starts_with($path, 'principals/')) {
            return null;
        }

        $username = substr($path, strlen('principals/'));
        $p = $this->principals->findPrincipalByUsername($username);

        if ($p === null) {
            return null;
        }

        return [
            'uri'               => $p['uri'],
            '{DAV:}displayname' => $p['display_name'],
        ];
    }

    public function getGroupMemberSet($principal): array
    {
        return [];
    }

    public function getGroupMembership($principal): array
    {
        return [];
    }

    public function updatePrincipal($path, PropPatch $propPatch): void {}

    public function searchPrincipals($prefixPath, array $searchProperties, $test = 'allof'): array
    {
        if ($prefixPath !== 'principals') {
            return [];
        }

        $matches = [];
        foreach ($this->principals->listPrincipals() as $p) {
            $hit = false;
            foreach ($searchProperties as $prop => $value) {
                $match = match ($prop) {
                    '{DAV:}displayname' => str_contains(
                        strtolower($p['display_name']),
                        strtolower($value)
                    ),
                    default => false,
                };
                if ($test === 'anyof' && $match) {
                    $hit = true;
                    break;
                }
                if ($test === 'allof' && !$match) {
                    $hit = false;
                    break;
                }
                $hit = $match;
            }
            if ($hit) {
                $matches[] = $p['uri'];
            }
        }

        return $matches;
    }

    public function setGroupMemberSet($principal, array $members): void {}
}
