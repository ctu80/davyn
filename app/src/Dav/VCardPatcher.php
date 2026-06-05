<?php
declare(strict_types=1);

namespace Davyn\Dav;

use Sabre\VObject\Component\VCard;
use Sabre\VObject\Reader;

/**
 * Lossless read/patch helper for address-book objects (vCard).
 *
 * Same golden rule as {@see IcsPatcher}: when editing, load the original vCard
 * and patch only the fields the WebUI manages. Properties the UI does not know
 * about — PHOTO, IMPP, GEO, X-* (incl. Android/DAVx5 extensions), and any other
 * custom data — are preserved verbatim.
 *
 * EMAIL / TEL / ADR are fully managed by the WebUI (multi-value with types), so
 * they are rewritten from the submitted list. Their TYPE parameters round-trip
 * because the read side reports them back to the form.
 */
final class VCardPatcher
{
    private const EMAIL_TYPES = ['home', 'work', 'other'];
    private const PHONE_TYPES = ['mobile', 'home', 'work', 'other'];
    private const ADDR_TYPES  = ['home', 'work', 'other'];

    /**
     * @return array<string,mixed>
     */
    public static function read(string $raw): array
    {
        $base = [
            'fn'         => '',
            'first_name' => '',
            'last_name'  => '',
            'nickname'   => '',
            'org'        => '',
            'title'      => '',
            'note'       => '',
            'bday'       => '',
            'url'        => '',
            'categories' => [],
            'emails'     => [],
            'phones'     => [],
            'addresses'  => [],
            'has_photo'  => false,
            // convenience flattened fields kept for the existing list/search UI
            'email'      => '',
            'tel'        => '',
        ];

        try {
            /** @var VCard $vc */
            $vc = Reader::read($raw);
        } catch (\Throwable) {
            return $base;
        }

        $base['fn']       = (string) ($vc->FN ?? '');
        $base['nickname'] = (string) ($vc->NICKNAME ?? '');
        $base['title']    = (string) ($vc->TITLE ?? '');
        $base['note']     = (string) ($vc->NOTE ?? '');
        $base['url']      = (string) ($vc->URL ?? '');

        if (isset($vc->N)) {
            $parts = $vc->N->getParts();
            $base['last_name']  = trim((string) ($parts[0] ?? ''));
            $base['first_name'] = trim((string) ($parts[1] ?? ''));
        }

        if (isset($vc->ORG)) {
            $parts = $vc->ORG->getParts();
            $base['org'] = trim((string) ($parts[0] ?? (string) $vc->ORG));
        }

        if (isset($vc->BDAY)) {
            $base['bday'] = self::isoDate((string) $vc->BDAY->getValue());
        }

        if (isset($vc->CATEGORIES)) {
            $cats = [];
            foreach ($vc->select('CATEGORIES') as $c) {
                foreach ($c->getParts() as $p) {
                    $p = trim((string) $p);
                    if ($p !== '') $cats[] = $p;
                }
            }
            $base['categories'] = array_values(array_unique($cats));
        }

        foreach ($vc->select('EMAIL') as $e) {
            $val = trim((string) $e->getValue());
            if ($val === '') continue;
            $base['emails'][] = ['type' => self::typeFrom($e, self::EMAIL_TYPES, 'home'), 'value' => $val];
        }
        foreach ($vc->select('TEL') as $t) {
            $val = trim((string) $t->getValue());
            if ($val === '') continue;
            $base['phones'][] = ['type' => self::phoneType($t), 'value' => $val];
        }
        foreach ($vc->select('ADR') as $a) {
            $p = $a->getParts();
            $addr = [
                'type'    => self::typeFrom($a, self::ADDR_TYPES, 'home'),
                'street'  => trim((string) ($p[2] ?? '')),
                'city'    => trim((string) ($p[3] ?? '')),
                'region'  => trim((string) ($p[4] ?? '')),
                'code'    => trim((string) ($p[5] ?? '')),
                'country' => trim((string) ($p[6] ?? '')),
            ];
            if ($addr['street'] || $addr['city'] || $addr['region'] || $addr['code'] || $addr['country']) {
                $base['addresses'][] = $addr;
            }
        }

        $base['has_photo'] = isset($vc->PHOTO);
        $base['email']     = $base['emails'][0]['value'] ?? '';
        $base['tel']       = $base['phones'][0]['value'] ?? '';

        return $base;
    }

    /**
     * @param array<string,mixed> $f
     */
    public static function patch(?string $raw, string $uid, array $f): string
    {
        $vc = null;
        if ($raw !== null && trim($raw) !== '') {
            try { $vc = Reader::read($raw); } catch (\Throwable) { $vc = null; }
        }
        if (!$vc instanceof VCard) {
            $vc = new VCard();
            $vc->VERSION = '3.0';
            $vc->UID = $uid;
        } elseif (!isset($vc->UID)) {
            $vc->UID = $uid;
        }

        $first = trim((string) ($f['first_name'] ?? ''));
        $last  = trim((string) ($f['last_name'] ?? ''));
        $fn    = trim((string) ($f['fn'] ?? ''));

        // Preserve middle / prefix / suffix components of an existing N.
        $additional = $prefix = $suffix = '';
        if (isset($vc->N)) {
            $p = $vc->N->getParts();
            $additional = (string) ($p[2] ?? '');
            $prefix     = (string) ($p[3] ?? '');
            $suffix     = (string) ($p[4] ?? '');
        }
        if ($first !== '' || $last !== '' || !isset($vc->N)) {
            $vc->remove('N');
            $vc->add('N', [$last, $first, $additional, $prefix, $suffix]);
        }

        if ($fn === '') {
            $fn = trim($first . ' ' . $last);
            if ($fn === '') $fn = trim((string) ($f['org'] ?? '')) ?: 'Unnamed';
        }
        $vc->FN = $fn;

        self::setOrRemove($vc, 'NICKNAME', trim((string) ($f['nickname'] ?? '')));
        self::setOrRemove($vc, 'TITLE', trim((string) ($f['title'] ?? '')));
        self::setOrRemove($vc, 'NOTE', trim((string) ($f['note'] ?? '')));
        self::setOrRemove($vc, 'URL', trim((string) ($f['url'] ?? '')));

        // ORG: keep extra ORG components (department, …) if present.
        $org = trim((string) ($f['org'] ?? ''));
        if ($org === '') {
            $vc->remove('ORG');
        } else {
            $extra = [];
            if (isset($vc->ORG)) { $parts = $vc->ORG->getParts(); $extra = array_slice($parts, 1); }
            $vc->remove('ORG');
            $vc->add('ORG', array_merge([$org], $extra));
        }

        $bday = trim((string) ($f['bday'] ?? ''));
        $vc->remove('BDAY');
        if ($bday !== '') $vc->add('BDAY', self::isoDate($bday));

        $vc->remove('CATEGORIES');
        $cats = array_values(array_filter(array_map('trim', (array) ($f['categories'] ?? []))));
        if ($cats) $vc->add('CATEGORIES', $cats);

        // EMAIL / TEL / ADR are fully managed: replace from the submitted lists.
        $vc->remove('EMAIL');
        foreach ((array) ($f['emails'] ?? []) as $e) {
            $val = trim((string) ($e['value'] ?? ''));
            if ($val === '') continue;
            $type = self::normType((string) ($e['type'] ?? 'home'), self::EMAIL_TYPES, 'home');
            $vc->add('EMAIL', $val, ['TYPE' => strtoupper($type)]);
        }

        $vc->remove('TEL');
        foreach ((array) ($f['phones'] ?? []) as $t) {
            $val = trim((string) ($t['value'] ?? ''));
            if ($val === '') continue;
            $type = self::normType((string) ($t['type'] ?? 'mobile'), self::PHONE_TYPES, 'mobile');
            $vc->add('TEL', $val, ['TYPE' => $type === 'mobile' ? 'CELL' : strtoupper($type)]);
        }

        $vc->remove('ADR');
        foreach ((array) ($f['addresses'] ?? []) as $a) {
            $street  = trim((string) ($a['street'] ?? ''));
            $city    = trim((string) ($a['city'] ?? ''));
            $region  = trim((string) ($a['region'] ?? ''));
            $code    = trim((string) ($a['code'] ?? ''));
            $country = trim((string) ($a['country'] ?? ''));
            if ($street === '' && $city === '' && $region === '' && $code === '' && $country === '') continue;
            $type = self::normType((string) ($a['type'] ?? 'home'), self::ADDR_TYPES, 'home');
            $vc->add('ADR', ['', '', $street, $city, $region, $code, $country], ['TYPE' => strtoupper($type)]);
        }

        // REV bumps on every change (RFC 6350 / 2426).
        $vc->remove('REV');
        $vc->add('REV', gmdate('Y-m-d\TH:i:s\Z'));

        return $vc->serialize();
    }

    // ── internals ───────────────────────────────────────────────────────────

    private static function setOrRemove(VCard $vc, string $name, string $value): void
    {
        $vc->remove($name);
        if ($value !== '') $vc->add($name, $value);
    }

    /**
     * @param string[] $allowed
     */
    private static function typeFrom($prop, array $allowed, string $default): string
    {
        $types = $prop['TYPE'] ?? null;
        if ($types !== null) {
            foreach ($types->getParts() as $t) {
                $t = strtolower(trim((string) $t));
                if (in_array($t, $allowed, true)) return $t;
            }
        }
        return $default;
    }

    private static function phoneType($prop): string
    {
        $types = $prop['TYPE'] ?? null;
        if ($types !== null) {
            foreach ($types->getParts() as $t) {
                $t = strtolower(trim((string) $t));
                if ($t === 'cell') return 'mobile';
                if (in_array($t, ['home', 'work'], true)) return $t;
            }
        }
        return 'other';
    }

    /**
     * @param string[] $allowed
     */
    private static function normType(string $type, array $allowed, string $default): string
    {
        $type = strtolower(trim($type));
        return in_array($type, $allowed, true) ? $type : $default;
    }

    private static function isoDate(string $dt): string
    {
        if (preg_match('/^(\d{4})-?(\d{2})-?(\d{2})/', $dt, $m)) return "{$m[1]}-{$m[2]}-{$m[3]}";
        return $dt;
    }
}
