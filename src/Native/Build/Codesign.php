<?php
declare(strict_types=1);

namespace MyPHP\Native\Build;

use RuntimeException;

/**
 * Signing an app bundle with codesign (mygo's bundle.go and resources.go):
 * the code among its resources from the inside out, then the bundle. Real
 * identities get the hardened runtime and a secure timestamp in
 * production, which notarization requires; "-" signs ad hoc, which runs
 * on the Mac that built the app.
 *
 * @internal
 */
final class Codesign
{
    /** The codesign arguments that sign $path. @return list<string> */
    public static function args(string $path, string $identity, string $entitlements, bool $production): array
    {
        $args = ['--force', '--deep', '--sign', $identity];
        if ($production && $identity !== '-') {
            array_push($args, '--options', 'runtime', '--timestamp');
        }
        if ($entitlements !== '') {
            array_push($args, '--entitlements', $entitlements);
        }
        $args[] = $path;
        return $args;
    }

    /**
     * The codesign arguments, before the path, that sign code among the
     * resources like the app. The code keeps the entitlements it is signed
     * with, such as the JIT of a JavaScript runtime, unless $entitlements
     * names a property list of others.
     *
     * @return list<string>
     */
    public static function nestedArgs(string $identity, string $entitlements, bool $production): array
    {
        $args = ['--force', '--sign', $identity];
        if ($production && $identity !== '-') {
            array_push($args, '--options', 'runtime', '--timestamp');
        }
        if ($entitlements !== '') {
            array_push($args, '--entitlements', $entitlements);
            return $args;
        }
        $args[] = '--preserve-metadata=entitlements';
        return $args;
    }

    /**
     * Signs the bundle $app, and the code among its resources first.
     * $entitlements is the property list of the app's (macos.entitlements),
     * $helpers those of code among its resources by its path there
     * (macos.helperEntitlements), both resolved.
     *
     * @param array<string, string> $helpers
     */
    public static function sign(string $app, string $identity, string $entitlements, array $helpers, bool $production, string $configName): void
    {
        self::signNested($app, $identity, $helpers, $production, $configName);
        [$code, $out] = Cmd::output(['codesign', ...self::args($app, $identity, $entitlements, $production)]);
        if ($code !== 0) {
            throw new RuntimeException('codesign ' . basename($app) . ": exit status $code\n$out");
        }
    }

    /**
     * Signs the code among the resources of a bundle, which codesign --deep
     * leaves alone: it only signs the bundle's code directories, and
     * notarization rejects unsigned code anywhere. Code is signed from the
     * inside out: Mach-O files, then the bundles holding them (apps,
     * frameworks, plug-ins), whose signatures seal what they contain. A real
     * identity signs all of it. Ad hoc signing only signs what has no valid
     * signature, so that copies keep their own: Mach-O files without one,
     * which Apple silicon does not run, bundles that are not sealed, and the
     * bundles around what it signed.
     *
     * @param array<string, string> $helpers
     */
    public static function signNested(string $app, string $identity, array $helpers, bool $production, string $configName): void
    {
        $dir = "$app/Contents/Resources";
        $files = []; $bundles = [];
        Resources::walk($dir, false, function (string $path, array $st) use ($dir, &$files, &$bundles): void {
            $n = ['path' => $path, 'name' => ltrim(substr($path, strlen($dir)), '/'), 'bundle' => ($st['mode'] & 0170000) === 0040000, 'signed' => false];
            if (($st['mode'] & 0170000) === 0100000) {
                [$ok, $n['signed']] = MachO::read($path);
                if ($ok) {
                    $files[] = $n;
                }
            } elseif ($n['bundle'] && self::isBundle($path)) {
                $bundles[] = $n;
            }
        });
        $inside = function (string $bundle, array $list): bool {
            foreach ($list as $n) {
                if (str_starts_with($n['name'], "$bundle/")) {
                    return true;
                }
            }
            return false;
        };
        $list = $files;
        foreach ($bundles as $b) {
            if ($inside($b['name'], $files)) { // bundles without code, such as localizations, are resources
                $list[] = $b;
            }
        }
        // What is deeper comes first, so a bundle comes after its contents.
        usort($list, fn(array $a, array $b): int => substr_count($b['name'], '/') - substr_count($a['name'], '/'));
        $unknown = [];
        $ents = [];
        foreach ($helpers as $name => $file) {
            $name = trim((string)$name, '/');
            $ents[$name] = $file;
            if (!in_array($name, array_column($list, 'name'), true)) {
                $unknown[] = $name;
            }
        }
        if ($unknown !== []) {
            sort($unknown);
            throw new RuntimeException("$configName: macos.helperEntitlements: the resources hold no executable, library or bundle at " . implode(', ', $unknown));
        }
        $signed = [];
        foreach ($list as $n) {
            $ent = $ents[$n['name']] ?? '';
            if ($identity === '-' && $ent === '') {
                if (!$n['bundle'] && $n['signed']) {
                    continue;
                }
                if ($n['bundle'] && !$inside($n['name'], $signed) && Cmd::output(['codesign', '--verify', $n['path']])[0] === 0) {
                    continue;
                }
            }
            [$code, $out] = Cmd::output(['codesign', ...self::nestedArgs($identity, $ent, $production), $n['path']]);
            if ($code !== 0) {
                throw new RuntimeException("codesign {$n['name']}: exit status $code\n$out");
            }
            $signed[] = $n;
        }
    }

    /** Whether $dir is a bundle that can hold code: an app, a framework or a plug-in, with an Info.plist where codesign looks for it. */
    public static function isBundle(string $dir): bool
    {
        if (!in_array(strtolower(pathinfo($dir, PATHINFO_EXTENSION)), ['app', 'appex', 'bundle', 'framework', 'plugin', 'xpc'], true)) {
            return false;
        }
        foreach (['Contents/Info.plist', 'Resources/Info.plist', 'Info.plist'] as $plist) {
            if (file_exists("$dir/$plist")) {
                return true;
            }
        }
        return false;
    }
}
