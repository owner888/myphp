<?php
declare(strict_types=1);

namespace MyPHP\Native;

use RuntimeException;

/**
 * XML property lists, as Info.plist has them: dict, array, string, true,
 * false, integer, real, as PHP arrays and scalars (dicts by key).
 *
 * @internal
 */
final class Plist
{
    public static function parse(string $xml): mixed
    {
        if (preg_match_all('~<(/?)(dict|array|key|string|integer|real|true|false|date|data)(\s*/)?>|([^<]+)~', $xml, $tokens, PREG_SET_ORDER) === false) {
            throw new RuntimeException('not a property list');
        }
        $i = 0;
        // Skip the prolog to the plist's value.
        while ($i < count($tokens) && ($tokens[$i][2] ?? '') === '') {
            $i++;
        }
        return self::value($tokens, $i);
    }

    /** @param list<array<int, string>> $t */
    private static function value(array $t, int &$i): mixed
    {
        $tag = $t[$i][2] ?? throw new RuntimeException('a property list ends early');
        $self = ($t[$i][3] ?? '') !== '';
        $i++;
        switch ($tag) {
            case 'true': return true;
            case 'false': return false;
            case 'string': case 'integer': case 'real': case 'date': case 'data': case 'key':
                if ($self) {
                    return $tag === 'string' ? '' : throw new RuntimeException("<$tag/> has no value");
                }
                $text = '';
                if (($t[$i][4] ?? '') !== '') {
                    $text = html_entity_decode($t[$i][4], ENT_XML1 | ENT_QUOTES);
                    $i++;
                }
                $i++; // the closing tag
                return match ($tag) { 'integer' => (int)$text, 'real' => (float)$text, default => $text };
            case 'array':
                $out = [];
                if ($self) { return $out; }
                while (self::skip($t, $i) && $t[$i][1] !== '/') {
                    $out[] = self::value($t, $i);
                }
                $i++;
                return $out;
            case 'dict':
                $out = [];
                if ($self) { return $out; }
                while (self::skip($t, $i) && $t[$i][1] !== '/') {
                    $k = self::value($t, $i);
                    self::skip($t, $i);
                    $out[$k] = self::value($t, $i);
                }
                $i++;
                return $out;
        }
        throw new RuntimeException("<$tag> in a property list");
    }

    /** Steps over whitespace between elements; false at the end. @param list<array<int, string>> $t */
    private static function skip(array $t, int &$i): bool
    {
        while ($i < count($t) && ($t[$i][2] ?? '') === '') {
            $i++;
        }
        return $i < count($t);
    }
}
