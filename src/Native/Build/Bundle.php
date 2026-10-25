<?php
declare(strict_types=1);

namespace MyPHP\Native\Build;

/**
 * The property lists of an app bundle as `myphp build` writes them (mygo's
 * bundle.go): its Info.plist, and the XML property list format.
 *
 * @internal
 */
final class Bundle
{
    /** $kv as an XML property list. @param array<string, mixed> $kv */
    public static function xml(array $kv): string
    {
        return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<!DOCTYPE plist PUBLIC \"-//Apple//DTD PLIST 1.0//EN\" \"http://www.apple.com/DTDs/PropertyList-1.0.dtd\">\n<plist version=\"1.0\">\n" . self::value($kv, '') . "</plist>\n";
    }

    /** A value as XML property list, indented by $indent: lists are arrays, other arrays dicts, their keys sorted (mygo's writePlistValue). */
    private static function value(mixed $v, string $indent): string
    {
        $esc = fn(string $s): string => htmlspecialchars($s, ENT_XML1);
        if (is_array($v) && array_is_list($v) && $v !== []) {
            return "$indent<array>\n" . implode('', array_map(fn($e) => self::value($e, "$indent\t"), $v)) . "$indent</array>\n";
        }
        if (is_array($v)) {
            ksort($v, SORT_STRING);
            $x = "$indent<dict>\n";
            foreach ($v as $k => $e) {
                $x .= "$indent\t<key>" . $esc((string)$k) . "</key>\n" . self::value($e, "$indent\t");
            }
            return "$x$indent</dict>\n";
        }
        return $indent . match (true) {
            is_bool($v) => $v ? '<true/>' : '<false/>',
            is_int($v) => "<integer>$v</integer>",
            is_float($v) => "<real>$v</real>",
            $v === null => '<string></string>',
            default => '<string>' . $esc((string)$v) . '</string>',
        } . "\n";
    }

    /**
     * The bundle's Info.plist (mygo's infoPlist): what myphp.json says of the
     * app, its documents and URL schemes, its localizations, the update feed,
     * whether it is a production build (MyPHPProduction, which turns
     * App::isDev() off as mygo's production flag does), then
     * macos.infoPlist's keys, which win.
     *
     * @param array<string, mixed> $localizations
     * @param array<string, string> $updates
     * @return array<string, mixed>
     */
    public static function infoPlist(array $c, string $executable, bool $icon, bool $catalog, array $localizations, array $updates, bool $production): array
    {
        $d = [
            'CFBundleName' => $c['name'],
            'CFBundleDisplayName' => $c['name'],
            'CFBundleIdentifier' => $c['identifier'],
            'CFBundleVersion' => $c['version'],
            'CFBundleShortVersionString' => $c['version'],
            'CFBundleExecutable' => $executable,
            'CFBundlePackageType' => 'APPL',
            'CFBundleInfoDictionaryVersion' => '6.0',
            'LSMinimumSystemVersion' => $c['macos']['minimumSystemVersion'],
            'NSPrincipalClass' => 'NSApplication',
            'NSHighResolutionCapable' => true,
            'NSSupportsAutomaticGraphicsSwitching' => true,
        ] + $localizations;
        if ($icon) {
            $d['CFBundleIconFile'] = Resources::ICON;
        }
        if ($catalog) {
            $d['CFBundleIconName'] = Resources::ICON_NAME;
        }
        if (($c['copyright'] ?? '') !== '') {
            $d['NSHumanReadableCopyright'] = $c['copyright'];
        }
        $docs = [];
        foreach ($c['fileAssociations'] ?? [] as $fa) {
            $doc = ['CFBundleTypeName' => ($fa['name'] ?? '') !== '' ? $fa['name'] : strtoupper($fa['ext'][0]) . ' file', 'CFBundleTypeRole' => ($fa['role'] ?? '') !== '' ? $fa['role'] : 'Editor', 'LSHandlerRank' => 'Default', 'CFBundleTypeExtensions' => array_values($fa['ext'])];
            if (($fa['mimeType'] ?? '') !== '') {
                $doc['CFBundleTypeMIMETypes'] = [$fa['mimeType']];
            }
            $docs[] = $doc;
        }
        if ($docs !== []) {
            $d['CFBundleDocumentTypes'] = $docs;
        }
        if (($c['urlSchemes'] ?? []) !== []) {
            $d['CFBundleURLTypes'] = [['CFBundleURLName' => $c['identifier'], 'CFBundleURLSchemes' => array_values($c['urlSchemes'])]];
        }
        if ($production) {
            $d['MyPHPProduction'] = true;
        }
        return array_replace($d + $updates, $c['macos']['infoPlist']); // the app's own keys win
    }
}
