<?php
declare(strict_types=1);

namespace Davyn\Holiday;

/**
 * Allowlist + catalog of supported holiday providers.
 *
 * This is the single source of truth that maps a stable, Davyn-internal provider
 * key (e.g. "DE-BW", "CH-ZH", "FR") onto a concrete Yasumi provider class. Only keys
 * present here are accepted — UI free-text never reaches Yasumi's class loader.
 *
 * Every country/region listed below corresponds to a Yasumi provider that actually
 * exists in the bundled library. Countries are grouped for the UI (Popular / Europe /
 * North America / Other supported). Regions are only exposed for countries where
 * Yasumi ships civil subdivisions (Germany, Austria, Switzerland, United Kingdom,
 * Canada, Australia); the nationwide provider is always available alongside them.
 */
final class HolidayProviderRegistry
{
    /** Ordered UI groups. */
    public const GROUPS = ['Popular', 'Europe', 'North America', 'Other supported'];

    /**
     * country_code => [
     *   'label'    => display name,
     *   'group'    => one of GROUPS,
     *   'locale'   => default Yasumi locale,
     *   'locales'  => offered locales (default first),
     *   'national' => Yasumi class for the country-wide calendar,
     *   'regions'  => region_code => ['label' => ..., 'class' => Yasumi subdivision class],
     * ]
     */
    private const COUNTRIES = [
        // ── Popular ───────────────────────────────────────────────────────
        'DE' => [
            'label' => 'Germany', 'group' => 'Popular', 'national' => 'Germany',
            'locale' => 'de_DE', 'locales' => ['de_DE', 'en_US'],
            'regions' => [
                'BW' => ['label' => 'Baden-Württemberg',     'class' => 'Germany\\BadenWurttemberg'],
                'BY' => ['label' => 'Bayern',                 'class' => 'Germany\\Bavaria'],
                'BE' => ['label' => 'Berlin',                 'class' => 'Germany\\Berlin'],
                'BB' => ['label' => 'Brandenburg',            'class' => 'Germany\\Brandenburg'],
                'HB' => ['label' => 'Bremen',                 'class' => 'Germany\\Bremen'],
                'HH' => ['label' => 'Hamburg',                'class' => 'Germany\\Hamburg'],
                'HE' => ['label' => 'Hessen',                 'class' => 'Germany\\Hesse'],
                'MV' => ['label' => 'Mecklenburg-Vorpommern', 'class' => 'Germany\\MecklenburgWesternPomerania'],
                'NI' => ['label' => 'Niedersachsen',          'class' => 'Germany\\LowerSaxony'],
                'NW' => ['label' => 'Nordrhein-Westfalen',    'class' => 'Germany\\NorthRhineWestphalia'],
                'RP' => ['label' => 'Rheinland-Pfalz',        'class' => 'Germany\\RhinelandPalatinate'],
                'SL' => ['label' => 'Saarland',               'class' => 'Germany\\Saarland'],
                'SN' => ['label' => 'Sachsen',                'class' => 'Germany\\Saxony'],
                'ST' => ['label' => 'Sachsen-Anhalt',         'class' => 'Germany\\SaxonyAnhalt'],
                'SH' => ['label' => 'Schleswig-Holstein',     'class' => 'Germany\\SchleswigHolstein'],
                'TH' => ['label' => 'Thüringen',              'class' => 'Germany\\Thuringia'],
            ],
        ],
        'AT' => [
            'label' => 'Austria', 'group' => 'Popular', 'national' => 'Austria',
            'locale' => 'de_AT', 'locales' => ['de_AT', 'en_US'],
            'regions' => [
                'BGL' => ['label' => 'Burgenland',     'class' => 'Austria\\Burgenland'],
                'KTN' => ['label' => 'Kärnten',        'class' => 'Austria\\Carinthia'],
                'NOE' => ['label' => 'Niederösterreich','class' => 'Austria\\LowerAustria'],
                'SBG' => ['label' => 'Salzburg',       'class' => 'Austria\\Salzburg'],
                'STM' => ['label' => 'Steiermark',     'class' => 'Austria\\Styria'],
                'TIR' => ['label' => 'Tirol',          'class' => 'Austria\\Tyrol'],
                'OOE' => ['label' => 'Oberösterreich', 'class' => 'Austria\\UpperAustria'],
                'WIE' => ['label' => 'Wien',           'class' => 'Austria\\Vienna'],
                'VBG' => ['label' => 'Vorarlberg',     'class' => 'Austria\\Vorarlberg'],
            ],
        ],
        'CH' => [
            'label' => 'Switzerland', 'group' => 'Popular', 'national' => 'Switzerland',
            'locale' => 'de_CH', 'locales' => ['de_CH', 'fr_CH', 'it_CH', 'en_US'],
            // Yasumi tags Swiss cantonal public holidays as 'other' (only the National
            // Day is 'official'), so this provider opts into the 'other' type. Its
            // 'other' entries are all genuine cantonal holidays — unlike e.g. Germany,
            // where 'other' is filler (Whit Sunday, New Year's Eve).
            'types' => ['official', 'bank', 'other'],
            'regions' => [
                'ZH' => ['label' => 'Zürich',                 'class' => 'Switzerland\\Zurich'],
                'BE' => ['label' => 'Bern',                   'class' => 'Switzerland\\Bern'],
                'LU' => ['label' => 'Luzern',                 'class' => 'Switzerland\\Lucerne'],
                'UR' => ['label' => 'Uri',                    'class' => 'Switzerland\\Uri'],
                'SZ' => ['label' => 'Schwyz',                 'class' => 'Switzerland\\Schwyz'],
                'OW' => ['label' => 'Obwalden',               'class' => 'Switzerland\\Obwalden'],
                'NW' => ['label' => 'Nidwalden',              'class' => 'Switzerland\\Nidwalden'],
                'GL' => ['label' => 'Glarus',                 'class' => 'Switzerland\\Glarus'],
                'ZG' => ['label' => 'Zug',                    'class' => 'Switzerland\\Zug'],
                'FR' => ['label' => 'Freiburg',               'class' => 'Switzerland\\Fribourg'],
                'SO' => ['label' => 'Solothurn',              'class' => 'Switzerland\\Solothurn'],
                'BS' => ['label' => 'Basel-Stadt',            'class' => 'Switzerland\\BaselStadt'],
                'BL' => ['label' => 'Basel-Landschaft',       'class' => 'Switzerland\\BaselLandschaft'],
                'SH' => ['label' => 'Schaffhausen',           'class' => 'Switzerland\\Schaffhausen'],
                'AR' => ['label' => 'Appenzell Ausserrhoden', 'class' => 'Switzerland\\AppenzellAusserrhoden'],
                'AI' => ['label' => 'Appenzell Innerrhoden',  'class' => 'Switzerland\\AppenzellInnerrhoden'],
                'SG' => ['label' => 'St. Gallen',             'class' => 'Switzerland\\StGallen'],
                'GR' => ['label' => 'Graubünden',             'class' => 'Switzerland\\Grisons'],
                'AG' => ['label' => 'Aargau',                 'class' => 'Switzerland\\Aargau'],
                'TG' => ['label' => 'Thurgau',                'class' => 'Switzerland\\Thurgau'],
                'TI' => ['label' => 'Tessin',                 'class' => 'Switzerland\\Ticino'],
                'VD' => ['label' => 'Waadt',                  'class' => 'Switzerland\\Vaud'],
                'VS' => ['label' => 'Wallis',                 'class' => 'Switzerland\\Valais'],
                'NE' => ['label' => 'Neuenburg',              'class' => 'Switzerland\\Neuchatel'],
                'GE' => ['label' => 'Genf',                   'class' => 'Switzerland\\Geneva'],
                'JU' => ['label' => 'Jura',                   'class' => 'Switzerland\\Jura'],
            ],
        ],
        'GB' => [
            'label' => 'United Kingdom', 'group' => 'Popular', 'national' => 'UnitedKingdom',
            'locale' => 'en_GB', 'locales' => ['en_GB'],
            'regions' => [
                'ENG' => ['label' => 'England',          'class' => 'UnitedKingdom\\England'],
                'SCT' => ['label' => 'Scotland',         'class' => 'UnitedKingdom\\Scotland'],
                'WLS' => ['label' => 'Wales',            'class' => 'UnitedKingdom\\Wales'],
                'NIR' => ['label' => 'Northern Ireland', 'class' => 'UnitedKingdom\\NorthernIreland'],
            ],
        ],
        'FR' => [
            'label' => 'France', 'group' => 'Popular', 'national' => 'France',
            'locale' => 'fr_FR', 'locales' => ['fr_FR', 'en_US'], 'regions' => [],
        ],

        // ── Europe ────────────────────────────────────────────────────────
        'NL' => ['label' => 'Netherlands', 'group' => 'Europe', 'national' => 'Netherlands', 'locale' => 'nl_NL', 'locales' => ['nl_NL', 'en_US'], 'regions' => []],
        'BE' => ['label' => 'Belgium',     'group' => 'Europe', 'national' => 'Belgium',     'locale' => 'nl_BE', 'locales' => ['nl_BE', 'fr_BE', 'en_US'], 'regions' => []],
        'IT' => ['label' => 'Italy',       'group' => 'Europe', 'national' => 'Italy',       'locale' => 'it_IT', 'locales' => ['it_IT', 'en_US'], 'regions' => []],
        'ES' => ['label' => 'Spain',       'group' => 'Europe', 'national' => 'Spain',       'locale' => 'es_ES', 'locales' => ['es_ES', 'en_US'], 'regions' => []],
        'IE' => ['label' => 'Ireland',     'group' => 'Europe', 'national' => 'Ireland',     'locale' => 'en_IE', 'locales' => ['en_IE'], 'regions' => []],
        'DK' => ['label' => 'Denmark',     'group' => 'Europe', 'national' => 'Denmark',     'locale' => 'da_DK', 'locales' => ['da_DK', 'en_US'], 'regions' => []],
        'SE' => ['label' => 'Sweden',      'group' => 'Europe', 'national' => 'Sweden',      'locale' => 'sv_SE', 'locales' => ['sv_SE', 'en_US'], 'regions' => []],
        'NO' => ['label' => 'Norway',      'group' => 'Europe', 'national' => 'Norway',      'locale' => 'nb_NO', 'locales' => ['nb_NO', 'en_US'], 'regions' => []],
        'FI' => ['label' => 'Finland',     'group' => 'Europe', 'national' => 'Finland',     'locale' => 'fi_FI', 'locales' => ['fi_FI', 'en_US'], 'regions' => []],
        'PL' => ['label' => 'Poland',      'group' => 'Europe', 'national' => 'Poland',      'locale' => 'pl_PL', 'locales' => ['pl_PL', 'en_US'], 'regions' => []],
        'CZ' => ['label' => 'Czechia',     'group' => 'Europe', 'national' => 'Czechia',     'locale' => 'cs_CZ', 'locales' => ['cs_CZ', 'en_US'], 'regions' => []],

        // ── North America ───────────────────────────────────────────────────
        'US' => [
            'label' => 'United States', 'group' => 'North America', 'national' => 'USA',
            'locale' => 'en_US', 'locales' => ['en_US'], 'regions' => [],
        ],
        'CA' => [
            'label' => 'Canada', 'group' => 'North America', 'national' => 'Canada',
            'locale' => 'en_CA', 'locales' => ['en_CA', 'fr_CA', 'en_US'],
            'regions' => [
                'AB' => ['label' => 'Alberta',                   'class' => 'Canada\\Alberta'],
                'BC' => ['label' => 'British Columbia',          'class' => 'Canada\\BritishColumbia'],
                'MB' => ['label' => 'Manitoba',                  'class' => 'Canada\\Manitoba'],
                'NB' => ['label' => 'New Brunswick',             'class' => 'Canada\\NewBrunswick'],
                'NL' => ['label' => 'Newfoundland and Labrador', 'class' => 'Canada\\NewfoundlandAndLabrador'],
                'NT' => ['label' => 'Northwest Territories',     'class' => 'Canada\\NorthwestTerritories'],
                'NS' => ['label' => 'Nova Scotia',               'class' => 'Canada\\NovaScotia'],
                'NU' => ['label' => 'Nunavut',                   'class' => 'Canada\\Nunavut'],
                'ON' => ['label' => 'Ontario',                   'class' => 'Canada\\Ontario'],
                'PE' => ['label' => 'Prince Edward Island',      'class' => 'Canada\\PrinceEdwardIsland'],
                'QC' => ['label' => 'Quebec',                    'class' => 'Canada\\Quebec'],
                'SK' => ['label' => 'Saskatchewan',              'class' => 'Canada\\Saskatchewan'],
                'YT' => ['label' => 'Yukon',                     'class' => 'Canada\\Yukon'],
            ],
        ],

        // ── Other supported ─────────────────────────────────────────────────
        'AU' => [
            'label' => 'Australia', 'group' => 'Other supported', 'national' => 'Australia',
            'locale' => 'en_AU', 'locales' => ['en_AU', 'en_US'],
            'regions' => [
                'ACT' => ['label' => 'Australian Capital Territory', 'class' => 'Australia\\AustralianCapitalTerritory'],
                'NSW' => ['label' => 'New South Wales',              'class' => 'Australia\\NewSouthWales'],
                'NT'  => ['label' => 'Northern Territory',           'class' => 'Australia\\NorthernTerritory'],
                'QLD' => ['label' => 'Queensland',                   'class' => 'Australia\\Queensland'],
                'SA'  => ['label' => 'South Australia',              'class' => 'Australia\\SouthAustralia'],
                'TAS' => ['label' => 'Tasmania',                     'class' => 'Australia\\Tasmania'],
                'VIC' => ['label' => 'Victoria',                     'class' => 'Australia\\Victoria'],
                'WA'  => ['label' => 'Western Australia',            'class' => 'Australia\\WesternAustralia'],
            ],
        ],
    ];

    /**
     * Resolve a provider key into its descriptor, or null if not allowlisted.
     *
     * @return null|array{
     *   provider_key:string, country_code:string, region_code:?string,
     *   yasumi_class:string, country_label:string, region_label:?string,
     *   default_locale:string, supported_locales:array<string>, group:string, label:string
     * }
     */
    public static function resolve(string $providerKey): ?array
    {
        $providerKey = strtoupper(trim($providerKey));
        $parts       = explode('-', $providerKey, 2);
        $country     = $parts[0] ?? '';
        $region      = $parts[1] ?? null;

        $c = self::COUNTRIES[$country] ?? null;
        if ($c === null) {
            return null;
        }

        if ($region === null || $region === '') {
            return [
                'provider_key'      => $country,
                'country_code'      => $country,
                'region_code'       => null,
                'yasumi_class'      => $c['national'],
                'country_label'     => $c['label'],
                'region_label'      => null,
                'default_locale'    => $c['locale'],
                'supported_locales' => $c['locales'] ?? [$c['locale']],
                'types'             => $c['types'] ?? ['official', 'bank'],
                'group'             => $c['group'],
                'label'             => $c['label'],
            ];
        }

        $r = $c['regions'][$region] ?? null;
        if ($r === null) {
            return null;
        }

        return [
            'provider_key'      => $country . '-' . $region,
            'country_code'      => $country,
            'region_code'       => $region,
            'yasumi_class'      => $r['class'],
            'country_label'     => $c['label'],
            'region_label'      => $r['label'],
            'default_locale'    => $c['locale'],
            'supported_locales' => $c['locales'] ?? [$c['locale']],
            'types'             => $c['types'] ?? ['official', 'bank'],
            'group'             => $c['group'],
            'label'             => $c['label'] . ' — ' . $r['label'],
        ];
    }

    /** True if the key is a supported provider. */
    public static function isValid(string $providerKey): bool
    {
        return self::resolve($providerKey) !== null;
    }

    /**
     * Catalog for the UI picker: ordered group names plus the supported countries
     * (each tagged with its group) and their regions. Only allowlisted providers
     * are exposed.
     */
    public static function catalog(): array
    {
        $countries = [];
        foreach (self::COUNTRIES as $code => $c) {
            $regions = [];
            foreach ($c['regions'] as $rc => $r) {
                $regions[] = [
                    'region_code'  => $rc,
                    'provider_key' => $code . '-' . $rc,
                    'label'        => $r['label'],
                ];
            }
            $countries[] = [
                'country_code'          => $code,
                'label'                 => $c['label'],
                'group'                 => $c['group'],
                'default_locale'        => $c['locale'],
                'supported_locales'     => $c['locales'] ?? [$c['locale']],
                'has_regions'           => $regions !== [],
                'national_provider_key' => $code,
                'regions'               => $regions,
            ];
        }
        return ['groups' => self::GROUPS, 'countries' => $countries];
    }

    /** Calendar URI for a provider key, e.g. "DE-BW" => "holidays-de-bw". */
    public static function calendarUri(string $providerKey): string
    {
        return 'holidays-' . strtolower($providerKey);
    }

    /**
     * Reverse of calendarUri(): infer a provider key from a generated calendar
     * URI, or null if it does not look like a holiday calendar / is not in the
     * allowlist. Used to lazily adopt pre-existing holiday calendars.
     */
    public static function providerKeyFromUri(string $uri): ?string
    {
        if (!str_starts_with($uri, 'holidays-')) {
            return null;
        }
        $key = strtoupper(substr($uri, strlen('holidays-')));
        return self::isValid($key) ? self::resolve($key)['provider_key'] : null;
    }

    /** Human-readable default calendar name for a provider key. */
    public static function calendarName(string $providerKey): string
    {
        $d = self::resolve($providerKey);
        if ($d === null) {
            return 'Holidays';
        }
        return $d['region_label'] !== null
            ? 'Holidays ' . $d['country_label'] . ' (' . $d['region_label'] . ')'
            : 'Holidays ' . $d['country_label'];
    }
}
