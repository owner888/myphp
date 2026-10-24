<?php
// Code generated from mygo's i18n/tables.go (CLDR 48.2.0) by bin/cldr-tables; do not edit by hand.
declare(strict_types=1);

namespace MyPHP\Native;

use MyPHP\{Operands, Plural};

/**
 * The plural rules and the parent locales of CLDR 48.2.0, as mygo's
 * generated tables: the rule sets, the languages each is for, the
 * categories each has (a bit per Plural), and the locales whose parent
 * CLDR names.
 *
 * @internal
 */
final class CldrTables
{
    public const VERSION = '48.2.0';

    public const CARDINAL_LANGS = [
        'af', 'ak', 'am', 'an', 'ar', 'ars', 'as', 'asa', 'ast', 'az', 'bal', 'be',
        'bem', 'bez', 'bg', 'bho', 'blo', 'bm', 'bn', 'bo', 'br', 'brx', 'bs', 'ca',
        'ce', 'ceb', 'cgg', 'chr', 'ckb', 'cs', 'csw', 'cv', 'cy', 'da', 'de', 'doi',
        'dsb', 'dv', 'dz', 'ee', 'el', 'en', 'eo', 'es', 'et', 'eu', 'fa', 'ff',
        'fi', 'fil', 'fo', 'fr', 'fur', 'fy', 'ga', 'gd', 'gl', 'gsw', 'gu', 'guw',
        'gv', 'ha', 'haw', 'he', 'hi', 'hnj', 'hr', 'hsb', 'hu', 'hy', 'ia', 'id',
        'ie', 'ig', 'ii', 'io', 'is', 'it', 'iu', 'ja', 'jbo', 'jgo', 'jmc', 'jv',
        'jw', 'ka', 'kab', 'kaj', 'kcg', 'kde', 'kea', 'kk', 'kkj', 'kl', 'km', 'kn',
        'ko', 'kok', 'kok-Latn', 'ks', 'ksb', 'ksh', 'ku', 'kw', 'ky', 'lag', 'lb', 'lg',
        'lij', 'lkt', 'lld', 'ln', 'lo', 'lt', 'lv', 'mas', 'mg', 'mgo', 'mk', 'ml',
        'mn', 'mo', 'mr', 'ms', 'mt', 'my', 'nah', 'naq', 'nb', 'nd', 'ne', 'nl',
        'nn', 'nnh', 'no', 'nqo', 'nr', 'nso', 'ny', 'nyn', 'om', 'or', 'os', 'osa',
        'pa', 'pap', 'pcm', 'pl', 'prg', 'ps', 'pt', 'pt-PT', 'rm', 'ro', 'rof', 'ru',
        'rwk', 'sah', 'saq', 'sat', 'sc', 'scn', 'sd', 'sdh', 'se', 'seh', 'ses', 'sg',
        'sgs', 'sh', 'shi', 'si', 'sk', 'sl', 'sma', 'smi', 'smj', 'smn', 'sms', 'sn',
        'so', 'sq', 'sr', 'ss', 'ssy', 'st', 'su', 'sv', 'sw', 'syr', 'ta', 'te',
        'teo', 'th', 'ti', 'tig', 'tk', 'tl', 'tn', 'to', 'tpi', 'tr', 'ts', 'tzm',
        'ug', 'uk', 'und', 'ur', 'uz', 've', 'vec', 'vi', 'vo', 'vun', 'wa', 'wae',
        'wo', 'xh', 'xog', 'yi', 'yo', 'yue', 'zh', 'zu',
    ];

    public const CARDINAL_RULES = [
        1, 2, 3, 1, 4, 4, 3, 1, 5, 1, 1, 6,
        1, 1, 1, 2, 7, 0, 3, 0, 8, 1, 9, 10,
        1, 11, 1, 1, 1, 12, 2, 7, 13, 14, 5, 3,
        15, 1, 0, 1, 1, 5, 1, 16, 5, 1, 3, 17,
        5, 11, 1, 18, 1, 5, 19, 20, 5, 1, 3, 2,
        21, 1, 1, 22, 3, 0, 9, 15, 1, 17, 5, 0,
        5, 0, 0, 5, 23, 10, 24, 0, 0, 1, 1, 0,
        0, 1, 17, 1, 1, 0, 0, 1, 1, 1, 0, 3,
        0, 3, 3, 1, 1, 7, 1, 25, 1, 26, 1, 1,
        5, 0, 10, 2, 0, 27, 28, 1, 2, 1, 29, 1,
        1, 30, 1, 0, 31, 0, 1, 24, 1, 1, 1, 5,
        1, 1, 1, 0, 1, 2, 1, 1, 1, 1, 1, 0,
        2, 1, 3, 32, 28, 1, 33, 10, 1, 30, 1, 34,
        1, 0, 1, 24, 5, 10, 1, 1, 24, 1, 0, 0,
        35, 9, 36, 37, 12, 38, 24, 24, 24, 24, 24, 1,
        1, 1, 9, 1, 1, 1, 0, 5, 5, 1, 1, 1,
        1, 0, 2, 1, 1, 11, 1, 0, 0, 1, 1, 39,
        1, 34, 0, 5, 1, 1, 10, 0, 1, 1, 2, 1,
        0, 1, 1, 5, 0, 0, 0, 3,
    ];

    public const CARDINAL_CATEGORIES = [
        32, 34, 34, 34, 63, 34, 58, 35, 62, 42, 50, 34,
        58, 63, 34, 46, 50, 34, 50, 62, 46, 62, 38, 34,
        38, 63, 35, 58, 35, 34, 42, 62, 58, 50, 58, 62,
        42, 34, 46, 34,
    ];

    public const ORDINAL_LANGS = [
        'af', 'am', 'an', 'ar', 'as', 'ast', 'az', 'bal', 'be', 'bg', 'blo', 'bn',
        'bs', 'ca', 'ce', 'cs', 'cv', 'cy', 'da', 'de', 'dsb', 'el', 'en', 'es',
        'et', 'eu', 'fa', 'fi', 'fil', 'fr', 'fy', 'ga', 'gd', 'gl', 'gsw', 'gu',
        'he', 'hi', 'hr', 'hsb', 'hu', 'hy', 'ia', 'id', 'ie', 'is', 'it', 'ja',
        'ka', 'kk', 'km', 'kn', 'ko', 'kok', 'kok-Latn', 'kw', 'ky', 'lij', 'lld', 'lo',
        'lt', 'lv', 'mk', 'ml', 'mn', 'mo', 'mr', 'ms', 'my', 'nb', 'ne', 'nl',
        'no', 'or', 'pa', 'pl', 'prg', 'ps', 'pt', 'ro', 'ru', 'sc', 'scn', 'sd',
        'sh', 'si', 'sk', 'sl', 'sq', 'sr', 'sv', 'sw', 'ta', 'te', 'th', 'tk',
        'tl', 'tpi', 'tr', 'uk', 'und', 'ur', 'uz', 'vec', 'vi', 'yue', 'zh', 'zu',
    ];

    public const ORDINAL_RULES = [
        0, 0, 0, 0, 1, 0, 2, 3, 4, 0, 5, 1,
        0, 6, 0, 0, 0, 7, 0, 0, 0, 0, 8, 0,
        0, 0, 0, 0, 3, 3, 0, 3, 9, 0, 0, 10,
        0, 10, 0, 0, 11, 3, 0, 0, 0, 0, 12, 0,
        13, 14, 0, 0, 0, 15, 15, 16, 0, 17, 12, 3,
        0, 0, 18, 0, 0, 3, 15, 3, 0, 0, 19, 0,
        0, 20, 0, 0, 0, 0, 0, 3, 0, 12, 17, 0,
        0, 0, 0, 0, 21, 0, 22, 0, 0, 0, 0, 23,
        3, 0, 0, 24, 0, 0, 0, 12, 3, 0, 0, 0,
    ];

    public const ORDINAL_CATEGORIES = [
        32, 62, 58, 34, 40, 43, 46, 63, 46, 46, 62, 34,
        48, 50, 48, 46, 50, 48, 54, 34, 62, 50, 34, 40,
        40,
    ];

    public const PARENT_CHILDREN = [
        'az-Arab', 'az-Cyrl', 'bal-Latn', 'blt-Latn', 'bm-Nkoo', 'bs-Cyrl', 'byn-Latn', 'cu-Glag', 'dje-Arab', 'dyo-Arab', 'en-150', 'en-AG',
        'en-AI', 'en-AT', 'en-AU', 'en-BB', 'en-BE', 'en-BM', 'en-BS', 'en-BW', 'en-BZ', 'en-CC', 'en-CH', 'en-CK',
        'en-CM', 'en-CX', 'en-CY', 'en-CZ', 'en-DE', 'en-DG', 'en-DK', 'en-DM', 'en-Dsrt', 'en-EE', 'en-ER', 'en-ES',
        'en-FI', 'en-FJ', 'en-FK', 'en-FM', 'en-FR', 'en-GB', 'en-GD', 'en-GE', 'en-GG', 'en-GH', 'en-GI', 'en-GM',
        'en-GS', 'en-GY', 'en-HK', 'en-HU', 'en-ID', 'en-IE', 'en-IL', 'en-IM', 'en-IN', 'en-IO', 'en-IT', 'en-JE',
        'en-JM', 'en-KE', 'en-KI', 'en-KN', 'en-KY', 'en-LC', 'en-LR', 'en-LS', 'en-LT', 'en-LV', 'en-MG', 'en-MO',
        'en-MS', 'en-MT', 'en-MU', 'en-MV', 'en-MW', 'en-MY', 'en-NA', 'en-NF', 'en-NG', 'en-NL', 'en-NO', 'en-NR',
        'en-NU', 'en-NZ', 'en-PG', 'en-PK', 'en-PL', 'en-PN', 'en-PT', 'en-PW', 'en-RO', 'en-RW', 'en-SB', 'en-SC',
        'en-SD', 'en-SE', 'en-SG', 'en-SH', 'en-SI', 'en-SK', 'en-SL', 'en-SS', 'en-SX', 'en-SZ', 'en-Shaw', 'en-TC',
        'en-TK', 'en-TO', 'en-TT', 'en-TV', 'en-TZ', 'en-UA', 'en-UG', 'en-VC', 'en-VG', 'en-VU', 'en-WS', 'en-ZA',
        'en-ZM', 'en-ZW', 'es-AR', 'es-BO', 'es-BR', 'es-BZ', 'es-CL', 'es-CO', 'es-CR', 'es-CU', 'es-DO', 'es-EC',
        'es-GT', 'es-HN', 'es-JP', 'es-MX', 'es-NI', 'es-PA', 'es-PE', 'es-PR', 'es-PY', 'es-SV', 'es-US', 'es-UY',
        'es-VE', 'ff-Adlm', 'ff-Arab', 'ha-Arab', 'hi-Latn', 'ht', 'iu-Latn', 'kaa-Latn', 'kk-Arab', 'kok-Latn', 'ks-Deva', 'ku-Arab',
        'kxv-Deva', 'kxv-Orya', 'kxv-Telu', 'ky-Arab', 'ky-Latn', 'ml-Arab', 'mn-Mong', 'mni-Mtei', 'ms-Arab', 'nb', 'nn', 'no-NO',
        'pa-Arab', 'pt-AO', 'pt-CH', 'pt-CV', 'pt-FR', 'pt-GQ', 'pt-GW', 'pt-LU', 'pt-MO', 'pt-MZ', 'pt-ST', 'pt-TL',
        'sat-Deva', 'sd-Deva', 'sd-Khoj', 'sd-Sind', 'shi-Latn', 'so-Arab', 'sr-Latn', 'suz-Sunu', 'sw-Arab', 'tg-Arab', 'ug-Cyrl', 'uz-Arab',
        'uz-Cyrl', 'vai-Latn', 'wo-Arab', 'yo-Arab', 'yue-Hans', 'zh-Hant', 'zh-Hant-MO',
    ];

    public const PARENT_LOCALES = [
        '', '', '', '', '', '', '', '', '', '', 'en-001', 'en-001',
        'en-001', 'en-150', 'en-001', 'en-001', 'en-150', 'en-001', 'en-001', 'en-001', 'en-001', 'en-001', 'en-150', 'en-001',
        'en-001', 'en-001', 'en-001', 'en-150', 'en-150', 'en-001', 'en-150', 'en-001', '', 'en-150', 'en-001', 'en-150',
        'en-150', 'en-001', 'en-001', 'en-001', 'en-150', 'en-001', 'en-001', 'en-150', 'en-001', 'en-001', 'en-001', 'en-001',
        'en-001', 'en-001', 'en-001', 'en-150', 'en-001', 'en-001', 'en-001', 'en-001', 'en-001', 'en-001', 'en-150', 'en-001',
        'en-001', 'en-001', 'en-001', 'en-001', 'en-001', 'en-001', 'en-001', 'en-001', 'en-150', 'en-150', 'en-001', 'en-001',
        'en-001', 'en-001', 'en-001', 'en-001', 'en-001', 'en-001', 'en-001', 'en-001', 'en-001', 'en-150', 'en-150', 'en-001',
        'en-001', 'en-001', 'en-001', 'en-001', 'en-150', 'en-001', 'en-150', 'en-001', 'en-150', 'en-001', 'en-001', 'en-001',
        'en-001', 'en-150', 'en-001', 'en-001', 'en-150', 'en-150', 'en-001', 'en-001', 'en-001', 'en-001', '', 'en-001',
        'en-001', 'en-001', 'en-001', 'en-001', 'en-001', 'en-150', 'en-001', 'en-001', 'en-001', 'en-001', 'en-001', 'en-001',
        'en-001', 'en-001', 'es-419', 'es-419', 'es-419', 'es-419', 'es-419', 'es-419', 'es-419', 'es-419', 'es-419', 'es-419',
        'es-419', 'es-419', 'es-419', 'es-419', 'es-419', 'es-419', 'es-419', 'es-419', 'es-419', 'es-419', 'es-419', 'es-419',
        'es-419', '', '', '', 'en-IN', 'fr-HT', '', '', '', '', '', '',
        '', '', '', '', '', '', '', '', '', 'no', 'no', 'no',
        '', 'pt-PT', 'pt-PT', 'pt-PT', 'pt-PT', 'pt-PT', 'pt-PT', 'pt-PT', 'pt-PT', 'pt-PT', 'pt-PT', 'pt-PT',
        '', '', '', '', '', '', '', '', '', '', '', '',
        '', '', '', '', '', '', 'zh-Hant-HK',
    ];

    public static function cardinal(int $r, Operands $o): Plural
    {
        switch ($r) {
            case 1:
                // n = 1
                if ($o->f == 0 && $o->i == 1) {
                    return Plural::One;
                }
                break;
            case 2:
                // n = 0..1
                if ($o->f == 0 && $o->i <= 1) {
                    return Plural::One;
                }
                break;
            case 3:
                // i = 0 or n = 1
                if ($o->i == 0 || ($o->f == 0 && $o->i == 1)) {
                    return Plural::One;
                }
                break;
            case 4:
                // n = 0
                if ($o->f == 0 && $o->i == 0) {
                    return Plural::Zero;
                }
                // n = 1
                if ($o->f == 0 && $o->i == 1) {
                    return Plural::One;
                }
                // n = 2
                if ($o->f == 0 && $o->i == 2) {
                    return Plural::Two;
                }
                // n % 100 = 3..10
                if ($o->f == 0 && ($o->i%100 >= 3 && $o->i%100 <= 10)) {
                    return Plural::Few;
                }
                // n % 100 = 11..99
                if ($o->f == 0 && ($o->i%100 >= 11 && $o->i%100 <= 99)) {
                    return Plural::Many;
                }
                break;
            case 5:
                // i = 1 and v = 0
                if ($o->i == 1 && $o->v == 0) {
                    return Plural::One;
                }
                break;
            case 6:
                // n % 10 = 1 and n % 100 != 11
                if ($o->f == 0 && $o->i%10 == 1 && !($o->i%100 == 11)) {
                    return Plural::One;
                }
                // n % 10 = 2..4 and n % 100 != 12..14
                if ($o->f == 0 && ($o->i%10 >= 2 && $o->i%10 <= 4) && !($o->i%100 >= 12 && $o->i%100 <= 14)) {
                    return Plural::Few;
                }
                // n % 10 = 0 or n % 10 = 5..9 or n % 100 = 11..14
                if (($o->f == 0 && $o->i%10 == 0) || ($o->f == 0 && ($o->i%10 >= 5 && $o->i%10 <= 9)) || ($o->f == 0 && ($o->i%100 >= 11 && $o->i%100 <= 14))) {
                    return Plural::Many;
                }
                break;
            case 7:
                // n = 0
                if ($o->f == 0 && $o->i == 0) {
                    return Plural::Zero;
                }
                // n = 1
                if ($o->f == 0 && $o->i == 1) {
                    return Plural::One;
                }
                break;
            case 8:
                // n % 10 = 1 and n % 100 != 11,71,91
                if ($o->f == 0 && $o->i%10 == 1 && !($o->i%100 == 11 || $o->i%100 == 71 || $o->i%100 == 91)) {
                    return Plural::One;
                }
                // n % 10 = 2 and n % 100 != 12,72,92
                if ($o->f == 0 && $o->i%10 == 2 && !($o->i%100 == 12 || $o->i%100 == 72 || $o->i%100 == 92)) {
                    return Plural::Two;
                }
                // n % 10 = 3..4,9 and n % 100 != 10..19,70..79,90..99
                if ($o->f == 0 && ($o->i%10 >= 3 && $o->i%10 <= 4 || $o->i%10 == 9) && !($o->i%100 >= 10 && $o->i%100 <= 19 || $o->i%100 >= 70 && $o->i%100 <= 79 || $o->i%100 >= 90 && $o->i%100 <= 99)) {
                    return Plural::Few;
                }
                // n != 0 and n % 1000000 = 0
                if ($o->f == 0 && !($o->i == 0) && $o->i%1000000 == 0) {
                    return Plural::Many;
                }
                break;
            case 9:
                // v = 0 and i % 10 = 1 and i % 100 != 11 or f % 10 = 1 and f % 100 != 11
                if (($o->v == 0 && $o->i%10 == 1 && !($o->i%100 == 11)) || ($o->f%10 == 1 && !($o->f%100 == 11))) {
                    return Plural::One;
                }
                // v = 0 and i % 10 = 2..4 and i % 100 != 12..14 or f % 10 = 2..4 and f % 100 != 12..14
                if (($o->v == 0 && ($o->i%10 >= 2 && $o->i%10 <= 4) && !($o->i%100 >= 12 && $o->i%100 <= 14)) || (($o->f%10 >= 2 && $o->f%10 <= 4) && !($o->f%100 >= 12 && $o->f%100 <= 14))) {
                    return Plural::Few;
                }
                break;
            case 10:
                // i = 1 and v = 0
                if ($o->i == 1 && $o->v == 0) {
                    return Plural::One;
                }
                // e = 0 and i != 0 and i % 1000000 = 0 and v = 0 or e != 0..5
                if (($o->c == 0 && !($o->i == 0) && $o->i%1000000 == 0 && $o->v == 0) || !($o->c <= 5)) {
                    return Plural::Many;
                }
                break;
            case 11:
                // v = 0 and i = 1,2,3 or v = 0 and i % 10 != 4,6,9 or v != 0 and f % 10 != 4,6,9
                if (($o->v == 0 && ($o->i == 1 || $o->i == 2 || $o->i == 3)) || ($o->v == 0 && !($o->i%10 == 4 || $o->i%10 == 6 || $o->i%10 == 9)) || (!($o->v == 0) && !($o->f%10 == 4 || $o->f%10 == 6 || $o->f%10 == 9))) {
                    return Plural::One;
                }
                break;
            case 12:
                // i = 1 and v = 0
                if ($o->i == 1 && $o->v == 0) {
                    return Plural::One;
                }
                // i = 2..4 and v = 0
                if (($o->i >= 2 && $o->i <= 4) && $o->v == 0) {
                    return Plural::Few;
                }
                // v != 0
                if (!($o->v == 0)) {
                    return Plural::Many;
                }
                break;
            case 13:
                // n = 0
                if ($o->f == 0 && $o->i == 0) {
                    return Plural::Zero;
                }
                // n = 1
                if ($o->f == 0 && $o->i == 1) {
                    return Plural::One;
                }
                // n = 2
                if ($o->f == 0 && $o->i == 2) {
                    return Plural::Two;
                }
                // n = 3
                if ($o->f == 0 && $o->i == 3) {
                    return Plural::Few;
                }
                // n = 6
                if ($o->f == 0 && $o->i == 6) {
                    return Plural::Many;
                }
                break;
            case 14:
                // n = 1 or t != 0 and i = 0,1
                if (($o->f == 0 && $o->i == 1) || (!($o->t == 0) && ($o->i == 0 || $o->i == 1))) {
                    return Plural::One;
                }
                break;
            case 15:
                // v = 0 and i % 100 = 1 or f % 100 = 1
                if (($o->v == 0 && $o->i%100 == 1) || $o->f%100 == 1) {
                    return Plural::One;
                }
                // v = 0 and i % 100 = 2 or f % 100 = 2
                if (($o->v == 0 && $o->i%100 == 2) || $o->f%100 == 2) {
                    return Plural::Two;
                }
                // v = 0 and i % 100 = 3..4 or f % 100 = 3..4
                if (($o->v == 0 && ($o->i%100 >= 3 && $o->i%100 <= 4)) || ($o->f%100 >= 3 && $o->f%100 <= 4)) {
                    return Plural::Few;
                }
                break;
            case 16:
                // n = 1
                if ($o->f == 0 && $o->i == 1) {
                    return Plural::One;
                }
                // e = 0 and i != 0 and i % 1000000 = 0 and v = 0 or e != 0..5
                if (($o->c == 0 && !($o->i == 0) && $o->i%1000000 == 0 && $o->v == 0) || !($o->c <= 5)) {
                    return Plural::Many;
                }
                break;
            case 17:
                // i = 0,1
                if ($o->i == 0 || $o->i == 1) {
                    return Plural::One;
                }
                break;
            case 18:
                // i = 0,1
                if ($o->i == 0 || $o->i == 1) {
                    return Plural::One;
                }
                // e = 0 and i != 0 and i % 1000000 = 0 and v = 0 or e != 0..5
                if (($o->c == 0 && !($o->i == 0) && $o->i%1000000 == 0 && $o->v == 0) || !($o->c <= 5)) {
                    return Plural::Many;
                }
                break;
            case 19:
                // n = 1
                if ($o->f == 0 && $o->i == 1) {
                    return Plural::One;
                }
                // n = 2
                if ($o->f == 0 && $o->i == 2) {
                    return Plural::Two;
                }
                // n = 3..6
                if ($o->f == 0 && ($o->i >= 3 && $o->i <= 6)) {
                    return Plural::Few;
                }
                // n = 7..10
                if ($o->f == 0 && ($o->i >= 7 && $o->i <= 10)) {
                    return Plural::Many;
                }
                break;
            case 20:
                // n = 1,11
                if ($o->f == 0 && ($o->i == 1 || $o->i == 11)) {
                    return Plural::One;
                }
                // n = 2,12
                if ($o->f == 0 && ($o->i == 2 || $o->i == 12)) {
                    return Plural::Two;
                }
                // n = 3..10,13..19
                if ($o->f == 0 && ($o->i >= 3 && $o->i <= 10 || $o->i >= 13 && $o->i <= 19)) {
                    return Plural::Few;
                }
                break;
            case 21:
                // v = 0 and i % 10 = 1
                if ($o->v == 0 && $o->i%10 == 1) {
                    return Plural::One;
                }
                // v = 0 and i % 10 = 2
                if ($o->v == 0 && $o->i%10 == 2) {
                    return Plural::Two;
                }
                // v = 0 and i % 100 = 0,20,40,60,80
                if ($o->v == 0 && ($o->i%100 == 0 || $o->i%100 == 20 || $o->i%100 == 40 || $o->i%100 == 60 || $o->i%100 == 80)) {
                    return Plural::Few;
                }
                // v != 0
                if (!($o->v == 0)) {
                    return Plural::Many;
                }
                break;
            case 22:
                // i = 1 and v = 0 or i = 0 and v != 0
                if (($o->i == 1 && $o->v == 0) || ($o->i == 0 && !($o->v == 0))) {
                    return Plural::One;
                }
                // i = 2 and v = 0
                if ($o->i == 2 && $o->v == 0) {
                    return Plural::Two;
                }
                break;
            case 23:
                // t = 0 and i % 10 = 1 and i % 100 != 11 or t % 10 = 1 and t % 100 != 11
                if (($o->t == 0 && $o->i%10 == 1 && !($o->i%100 == 11)) || ($o->t%10 == 1 && !($o->t%100 == 11))) {
                    return Plural::One;
                }
                break;
            case 24:
                // n = 1
                if ($o->f == 0 && $o->i == 1) {
                    return Plural::One;
                }
                // n = 2
                if ($o->f == 0 && $o->i == 2) {
                    return Plural::Two;
                }
                break;
            case 25:
                // n = 0
                if ($o->f == 0 && $o->i == 0) {
                    return Plural::Zero;
                }
                // n = 1
                if ($o->f == 0 && $o->i == 1) {
                    return Plural::One;
                }
                // n % 100 = 2,22,42,62,82 or n % 1000 = 0 and n % 100000 = 1000..20000,40000,60000,80000 or n != 0 and n % 1000000 = 100000
                if (($o->f == 0 && ($o->i%100 == 2 || $o->i%100 == 22 || $o->i%100 == 42 || $o->i%100 == 62 || $o->i%100 == 82)) || ($o->f == 0 && $o->i%1000 == 0 && ($o->i%100000 >= 1000 && $o->i%100000 <= 20000 || $o->i%100000 == 40000 || $o->i%100000 == 60000 || $o->i%100000 == 80000)) || ($o->f == 0 && !($o->i == 0) && $o->i%1000000 == 100000)) {
                    return Plural::Two;
                }
                // n % 100 = 3,23,43,63,83
                if ($o->f == 0 && ($o->i%100 == 3 || $o->i%100 == 23 || $o->i%100 == 43 || $o->i%100 == 63 || $o->i%100 == 83)) {
                    return Plural::Few;
                }
                // n != 1 and n % 100 = 1,21,41,61,81
                if ($o->f == 0 && !($o->i == 1) && ($o->i%100 == 1 || $o->i%100 == 21 || $o->i%100 == 41 || $o->i%100 == 61 || $o->i%100 == 81)) {
                    return Plural::Many;
                }
                break;
            case 26:
                // n = 0
                if ($o->f == 0 && $o->i == 0) {
                    return Plural::Zero;
                }
                // i = 0,1 and n != 0
                if (($o->i == 0 || $o->i == 1) && !($o->f == 0 && ($o->i == 0))) {
                    return Plural::One;
                }
                break;
            case 27:
                // n % 10 = 1 and n % 100 != 11..19
                if ($o->f == 0 && $o->i%10 == 1 && !($o->i%100 >= 11 && $o->i%100 <= 19)) {
                    return Plural::One;
                }
                // n % 10 = 2..9 and n % 100 != 11..19
                if ($o->f == 0 && ($o->i%10 >= 2 && $o->i%10 <= 9) && !($o->i%100 >= 11 && $o->i%100 <= 19)) {
                    return Plural::Few;
                }
                // f != 0
                if (!($o->f == 0)) {
                    return Plural::Many;
                }
                break;
            case 28:
                // n % 10 = 0 or n % 100 = 11..19 or v = 2 and f % 100 = 11..19
                if (($o->f == 0 && $o->i%10 == 0) || ($o->f == 0 && ($o->i%100 >= 11 && $o->i%100 <= 19)) || ($o->v == 2 && ($o->f%100 >= 11 && $o->f%100 <= 19))) {
                    return Plural::Zero;
                }
                // n % 10 = 1 and n % 100 != 11 or v = 2 and f % 10 = 1 and f % 100 != 11 or v != 2 and f % 10 = 1
                if (($o->f == 0 && $o->i%10 == 1 && !($o->i%100 == 11)) || ($o->v == 2 && $o->f%10 == 1 && !($o->f%100 == 11)) || (!($o->v == 2) && $o->f%10 == 1)) {
                    return Plural::One;
                }
                break;
            case 29:
                // v = 0 and i % 10 = 1 and i % 100 != 11 or f % 10 = 1 and f % 100 != 11
                if (($o->v == 0 && $o->i%10 == 1 && !($o->i%100 == 11)) || ($o->f%10 == 1 && !($o->f%100 == 11))) {
                    return Plural::One;
                }
                break;
            case 30:
                // i = 1 and v = 0
                if ($o->i == 1 && $o->v == 0) {
                    return Plural::One;
                }
                // v != 0 or n = 0 or n != 1 and n % 100 = 1..19
                if (!($o->v == 0) || ($o->f == 0 && $o->i == 0) || ($o->f == 0 && !($o->i == 1) && ($o->i%100 >= 1 && $o->i%100 <= 19))) {
                    return Plural::Few;
                }
                break;
            case 31:
                // n = 1
                if ($o->f == 0 && $o->i == 1) {
                    return Plural::One;
                }
                // n = 2
                if ($o->f == 0 && $o->i == 2) {
                    return Plural::Two;
                }
                // n = 0 or n % 100 = 3..10
                if (($o->f == 0 && $o->i == 0) || ($o->f == 0 && ($o->i%100 >= 3 && $o->i%100 <= 10))) {
                    return Plural::Few;
                }
                // n % 100 = 11..19
                if ($o->f == 0 && ($o->i%100 >= 11 && $o->i%100 <= 19)) {
                    return Plural::Many;
                }
                break;
            case 32:
                // i = 1 and v = 0
                if ($o->i == 1 && $o->v == 0) {
                    return Plural::One;
                }
                // v = 0 and i % 10 = 2..4 and i % 100 != 12..14
                if ($o->v == 0 && ($o->i%10 >= 2 && $o->i%10 <= 4) && !($o->i%100 >= 12 && $o->i%100 <= 14)) {
                    return Plural::Few;
                }
                // v = 0 and i != 1 and i % 10 = 0..1 or v = 0 and i % 10 = 5..9 or v = 0 and i % 100 = 12..14
                if (($o->v == 0 && !($o->i == 1) && $o->i%10 <= 1) || ($o->v == 0 && ($o->i%10 >= 5 && $o->i%10 <= 9)) || ($o->v == 0 && ($o->i%100 >= 12 && $o->i%100 <= 14))) {
                    return Plural::Many;
                }
                break;
            case 33:
                // i = 0..1
                if ($o->i <= 1) {
                    return Plural::One;
                }
                // e = 0 and i != 0 and i % 1000000 = 0 and v = 0 or e != 0..5
                if (($o->c == 0 && !($o->i == 0) && $o->i%1000000 == 0 && $o->v == 0) || !($o->c <= 5)) {
                    return Plural::Many;
                }
                break;
            case 34:
                // v = 0 and i % 10 = 1 and i % 100 != 11
                if ($o->v == 0 && $o->i%10 == 1 && !($o->i%100 == 11)) {
                    return Plural::One;
                }
                // v = 0 and i % 10 = 2..4 and i % 100 != 12..14
                if ($o->v == 0 && ($o->i%10 >= 2 && $o->i%10 <= 4) && !($o->i%100 >= 12 && $o->i%100 <= 14)) {
                    return Plural::Few;
                }
                // v = 0 and i % 10 = 0 or v = 0 and i % 10 = 5..9 or v = 0 and i % 100 = 11..14
                if (($o->v == 0 && $o->i%10 == 0) || ($o->v == 0 && ($o->i%10 >= 5 && $o->i%10 <= 9)) || ($o->v == 0 && ($o->i%100 >= 11 && $o->i%100 <= 14))) {
                    return Plural::Many;
                }
                break;
            case 35:
                // n % 10 = 1 and n % 100 != 11
                if ($o->f == 0 && $o->i%10 == 1 && !($o->i%100 == 11)) {
                    return Plural::One;
                }
                // n = 2
                if ($o->f == 0 && $o->i == 2) {
                    return Plural::Two;
                }
                // n != 2 and n % 10 = 2..9 and n % 100 != 11..19
                if ($o->f == 0 && !($o->i == 2) && ($o->i%10 >= 2 && $o->i%10 <= 9) && !($o->i%100 >= 11 && $o->i%100 <= 19)) {
                    return Plural::Few;
                }
                // f != 0
                if (!($o->f == 0)) {
                    return Plural::Many;
                }
                break;
            case 36:
                // i = 0 or n = 1
                if ($o->i == 0 || ($o->f == 0 && $o->i == 1)) {
                    return Plural::One;
                }
                // n = 2..10
                if ($o->f == 0 && ($o->i >= 2 && $o->i <= 10)) {
                    return Plural::Few;
                }
                break;
            case 37:
                // n = 0,1 or i = 0 and f = 1
                if (($o->f == 0 && ($o->i == 0 || $o->i == 1)) || ($o->i == 0 && $o->f == 1)) {
                    return Plural::One;
                }
                break;
            case 38:
                // v = 0 and i % 100 = 1
                if ($o->v == 0 && $o->i%100 == 1) {
                    return Plural::One;
                }
                // v = 0 and i % 100 = 2
                if ($o->v == 0 && $o->i%100 == 2) {
                    return Plural::Two;
                }
                // v = 0 and i % 100 = 3..4 or v != 0
                if (($o->v == 0 && ($o->i%100 >= 3 && $o->i%100 <= 4)) || !($o->v == 0)) {
                    return Plural::Few;
                }
                break;
            case 39:
                // n = 0..1 or n = 11..99
                if (($o->f == 0 && $o->i <= 1) || ($o->f == 0 && ($o->i >= 11 && $o->i <= 99))) {
                    return Plural::One;
                }
                break;
        }
        return Plural::Other;
    }

    public static function ordinal(int $r, Operands $o): Plural
    {
        switch ($r) {
            case 1:
                // n = 1,5,7,8,9,10
                if ($o->f == 0 && ($o->i == 1 || $o->i == 5 || $o->i == 7 || $o->i == 8 || $o->i == 9 || $o->i == 10)) {
                    return Plural::One;
                }
                // n = 2,3
                if ($o->f == 0 && ($o->i == 2 || $o->i == 3)) {
                    return Plural::Two;
                }
                // n = 4
                if ($o->f == 0 && $o->i == 4) {
                    return Plural::Few;
                }
                // n = 6
                if ($o->f == 0 && $o->i == 6) {
                    return Plural::Many;
                }
                break;
            case 2:
                // i % 10 = 1,2,5,7,8 or i % 100 = 20,50,70,80
                if (($o->i%10 == 1 || $o->i%10 == 2 || $o->i%10 == 5 || $o->i%10 == 7 || $o->i%10 == 8) || ($o->i%100 == 20 || $o->i%100 == 50 || $o->i%100 == 70 || $o->i%100 == 80)) {
                    return Plural::One;
                }
                // i % 10 = 3,4 or i % 1000 = 100,200,300,400,500,600,700,800,900
                if (($o->i%10 == 3 || $o->i%10 == 4) || ($o->i%1000 == 100 || $o->i%1000 == 200 || $o->i%1000 == 300 || $o->i%1000 == 400 || $o->i%1000 == 500 || $o->i%1000 == 600 || $o->i%1000 == 700 || $o->i%1000 == 800 || $o->i%1000 == 900)) {
                    return Plural::Few;
                }
                // i = 0 or i % 10 = 6 or i % 100 = 40,60,90
                if ($o->i == 0 || $o->i%10 == 6 || ($o->i%100 == 40 || $o->i%100 == 60 || $o->i%100 == 90)) {
                    return Plural::Many;
                }
                break;
            case 3:
                // n = 1
                if ($o->f == 0 && $o->i == 1) {
                    return Plural::One;
                }
                break;
            case 4:
                // n % 10 = 2,3 and n % 100 != 12,13
                if ($o->f == 0 && ($o->i%10 == 2 || $o->i%10 == 3) && !($o->i%100 == 12 || $o->i%100 == 13)) {
                    return Plural::Few;
                }
                break;
            case 5:
                // i = 0
                if ($o->i == 0) {
                    return Plural::Zero;
                }
                // i = 1
                if ($o->i == 1) {
                    return Plural::One;
                }
                // i = 2,3,4,5,6
                if ($o->i == 2 || $o->i == 3 || $o->i == 4 || $o->i == 5 || $o->i == 6) {
                    return Plural::Few;
                }
                break;
            case 6:
                // n = 1,3
                if ($o->f == 0 && ($o->i == 1 || $o->i == 3)) {
                    return Plural::One;
                }
                // n = 2
                if ($o->f == 0 && $o->i == 2) {
                    return Plural::Two;
                }
                // n = 4
                if ($o->f == 0 && $o->i == 4) {
                    return Plural::Few;
                }
                break;
            case 7:
                // n = 0,7,8,9
                if ($o->f == 0 && ($o->i == 0 || $o->i == 7 || $o->i == 8 || $o->i == 9)) {
                    return Plural::Zero;
                }
                // n = 1
                if ($o->f == 0 && $o->i == 1) {
                    return Plural::One;
                }
                // n = 2
                if ($o->f == 0 && $o->i == 2) {
                    return Plural::Two;
                }
                // n = 3,4
                if ($o->f == 0 && ($o->i == 3 || $o->i == 4)) {
                    return Plural::Few;
                }
                // n = 5,6
                if ($o->f == 0 && ($o->i == 5 || $o->i == 6)) {
                    return Plural::Many;
                }
                break;
            case 8:
                // n % 10 = 1 and n % 100 != 11
                if ($o->f == 0 && $o->i%10 == 1 && !($o->i%100 == 11)) {
                    return Plural::One;
                }
                // n % 10 = 2 and n % 100 != 12
                if ($o->f == 0 && $o->i%10 == 2 && !($o->i%100 == 12)) {
                    return Plural::Two;
                }
                // n % 10 = 3 and n % 100 != 13
                if ($o->f == 0 && $o->i%10 == 3 && !($o->i%100 == 13)) {
                    return Plural::Few;
                }
                break;
            case 9:
                // n = 1,11
                if ($o->f == 0 && ($o->i == 1 || $o->i == 11)) {
                    return Plural::One;
                }
                // n = 2,12
                if ($o->f == 0 && ($o->i == 2 || $o->i == 12)) {
                    return Plural::Two;
                }
                // n = 3,13
                if ($o->f == 0 && ($o->i == 3 || $o->i == 13)) {
                    return Plural::Few;
                }
                break;
            case 10:
                // n = 1
                if ($o->f == 0 && $o->i == 1) {
                    return Plural::One;
                }
                // n = 2,3
                if ($o->f == 0 && ($o->i == 2 || $o->i == 3)) {
                    return Plural::Two;
                }
                // n = 4
                if ($o->f == 0 && $o->i == 4) {
                    return Plural::Few;
                }
                // n = 6
                if ($o->f == 0 && $o->i == 6) {
                    return Plural::Many;
                }
                break;
            case 11:
                // n = 1,5
                if ($o->f == 0 && ($o->i == 1 || $o->i == 5)) {
                    return Plural::One;
                }
                break;
            case 12:
                // n = 11,8,80,800
                if ($o->f == 0 && ($o->i == 11 || $o->i == 8 || $o->i == 80 || $o->i == 800)) {
                    return Plural::Many;
                }
                break;
            case 13:
                // i = 1
                if ($o->i == 1) {
                    return Plural::One;
                }
                // i = 0 or i % 100 = 2..20,40,60,80
                if ($o->i == 0 || ($o->i%100 >= 2 && $o->i%100 <= 20 || $o->i%100 == 40 || $o->i%100 == 60 || $o->i%100 == 80)) {
                    return Plural::Many;
                }
                break;
            case 14:
                // n % 10 = 6 or n % 10 = 9 or n % 10 = 0 and n != 0
                if (($o->f == 0 && $o->i%10 == 6) || ($o->f == 0 && $o->i%10 == 9) || ($o->f == 0 && $o->i%10 == 0 && !($o->i == 0))) {
                    return Plural::Many;
                }
                break;
            case 15:
                // n = 1
                if ($o->f == 0 && $o->i == 1) {
                    return Plural::One;
                }
                // n = 2,3
                if ($o->f == 0 && ($o->i == 2 || $o->i == 3)) {
                    return Plural::Two;
                }
                // n = 4
                if ($o->f == 0 && $o->i == 4) {
                    return Plural::Few;
                }
                break;
            case 16:
                // n = 1..4 or n % 100 = 1..4,21..24,41..44,61..64,81..84
                if (($o->f == 0 && ($o->i >= 1 && $o->i <= 4)) || ($o->f == 0 && ($o->i%100 >= 1 && $o->i%100 <= 4 || $o->i%100 >= 21 && $o->i%100 <= 24 || $o->i%100 >= 41 && $o->i%100 <= 44 || $o->i%100 >= 61 && $o->i%100 <= 64 || $o->i%100 >= 81 && $o->i%100 <= 84))) {
                    return Plural::One;
                }
                // n = 5 or n % 100 = 5
                if (($o->f == 0 && $o->i == 5) || ($o->f == 0 && $o->i%100 == 5)) {
                    return Plural::Many;
                }
                break;
            case 17:
                // n = 11,8,80..89,800..899
                if ($o->f == 0 && ($o->i == 11 || $o->i == 8 || $o->i >= 80 && $o->i <= 89 || $o->i >= 800 && $o->i <= 899)) {
                    return Plural::Many;
                }
                break;
            case 18:
                // i % 10 = 1 and i % 100 != 11
                if ($o->i%10 == 1 && !($o->i%100 == 11)) {
                    return Plural::One;
                }
                // i % 10 = 2 and i % 100 != 12
                if ($o->i%10 == 2 && !($o->i%100 == 12)) {
                    return Plural::Two;
                }
                // i % 10 = 7,8 and i % 100 != 17,18
                if (($o->i%10 == 7 || $o->i%10 == 8) && !($o->i%100 == 17 || $o->i%100 == 18)) {
                    return Plural::Many;
                }
                break;
            case 19:
                // n = 1..4
                if ($o->f == 0 && ($o->i >= 1 && $o->i <= 4)) {
                    return Plural::One;
                }
                break;
            case 20:
                // n = 1,5,7..9
                if ($o->f == 0 && ($o->i == 1 || $o->i == 5 || $o->i >= 7 && $o->i <= 9)) {
                    return Plural::One;
                }
                // n = 2,3
                if ($o->f == 0 && ($o->i == 2 || $o->i == 3)) {
                    return Plural::Two;
                }
                // n = 4
                if ($o->f == 0 && $o->i == 4) {
                    return Plural::Few;
                }
                // n = 6
                if ($o->f == 0 && $o->i == 6) {
                    return Plural::Many;
                }
                break;
            case 21:
                // n = 1
                if ($o->f == 0 && $o->i == 1) {
                    return Plural::One;
                }
                // n % 10 = 4 and n % 100 != 14
                if ($o->f == 0 && $o->i%10 == 4 && !($o->i%100 == 14)) {
                    return Plural::Many;
                }
                break;
            case 22:
                // n % 10 = 1,2 and n % 100 != 11,12
                if ($o->f == 0 && ($o->i%10 == 1 || $o->i%10 == 2) && !($o->i%100 == 11 || $o->i%100 == 12)) {
                    return Plural::One;
                }
                break;
            case 23:
                // n % 10 = 6,9 or n = 10
                if (($o->f == 0 && ($o->i%10 == 6 || $o->i%10 == 9)) || ($o->f == 0 && $o->i == 10)) {
                    return Plural::Few;
                }
                break;
            case 24:
                // n % 10 = 3 and n % 100 != 13
                if ($o->f == 0 && $o->i%10 == 3 && !($o->i%100 == 13)) {
                    return Plural::Few;
                }
                break;
        }
        return Plural::Other;
    }
}
