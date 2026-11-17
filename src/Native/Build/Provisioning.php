<?php
declare(strict_types=1);

namespace MyPHP\Native\Build;

use MyPHP\Native\Plist;
use RuntimeException;

/**
 * iOS provisioning profiles, as Xcode's automatic signing uses them: an app
 * runs on an iPhone signed by an Apple Development (or Distribution)
 * certificate, with a profile in it (embedded.mobileprovision) that names
 * the team, the app's identifier (or a wildcard of it), the devices it may
 * run on and the certificates that may sign it, and with the entitlements
 * the profile allows. `myphp run` and `myphp build` find the profile for
 * the app in the profiles Xcode keeps (or take ios.provisioningProfile),
 * sign with the identity in the keychain whose certificate the profile
 * names (or ios.signingIdentity), and give codesign the entitlements the
 * app needs of it. Not mygo's.
 *
 * @internal
 */
final class Provisioning
{
    /** Where Xcode keeps the profiles it downloads and makes: Xcode 16 and later, then before. */
    public const DIRS = ['Library/Developer/Xcode/UserData/Provisioning Profiles', 'Library/MobileDevice/Provisioning Profiles'];

    /**
     * What a profile ($bytes, a .mobileprovision: a property list in a CMS
     * signature) says, or null for no iOS profile: its name and UUID, its
     * team, the app identifier it is for (TEAM.com.example.app, or a
     * wildcard: TEAM.*, TEAM.com.example.*), when it expires, the devices
     * it lists (null: all, an enterprise profile's), the SHA-1 of the
     * certificates that may sign with it, its entitlements.
     *
     * @return array{name: string, uuid: string, team: string, appId: string, expires: int, devices: ?list<string>, certs: list<string>, entitlements: array<string, mixed>}|null
     */
    public static function read(string $bytes): ?array
    {
        $from = strpos($bytes, '<?xml');
        $to = $from === false ? false : strpos($bytes, '</plist>', $from);
        if ($to === false) {
            return null;
        }
        try {
            $p = Plist::parse(substr($bytes, $from, $to + 8 - $from));
        } catch (RuntimeException) {
            return null;
        }
        $e = $p['Entitlements'] ?? null;
        $platforms = $p['Platform'] ?? ['iOS'];
        if (!is_array($p) || !is_array($e) || !is_string($e['application-identifier'] ?? null) || !in_array('iOS', (array)$platforms, true)) {
            return null;
        }
        $expires = strtotime((string)($p['ExpirationDate'] ?? '')) ?: 0;
        $certs = array_map(fn(string $der) => strtoupper(sha1((string)base64_decode(preg_replace('/\s+/', '', $der) ?? '', true))), array_filter((array)($p['DeveloperCertificates'] ?? []), 'is_string'));
        return [
            'name' => (string)($p['Name'] ?? ''),
            'uuid' => (string)($p['UUID'] ?? ''),
            'team' => (string)(($p['TeamIdentifier'] ?? [])[0] ?? strtok($e['application-identifier'], '.')),
            'appId' => $e['application-identifier'],
            'expires' => $expires,
            'devices' => ($p['ProvisionsAllDevices'] ?? false) === true ? null : array_values(array_filter((array)($p['ProvisionedDevices'] ?? []), 'is_string')),
            'certs' => array_values($certs),
            'entitlements' => $e,
        ];
    }

    /**
     * How well profile $p fits the app $bundleId, of team $team ('' any) on
     * the device $udid ('' none: a build for any): 0 not at all (another
     * app, team or device, or expired at $now); else more for the app's own
     * identifier than for a wildcard, the longer the wildcard the more.
     *
     * @param array{team: string, appId: string, expires: int, devices: ?list<string>} $p
     */
    public static function fit(array $p, string $bundleId, string $team, string $udid, int $now): int
    {
        if ($p['expires'] <= $now || ($team !== '' && $p['team'] !== $team)) {
            return 0;
        }
        if ($udid !== '' && $p['devices'] !== null && !in_array(strtoupper($udid), array_map('strtoupper', $p['devices']), true)) {
            return 0;
        }
        $app = substr($p['appId'], strlen($p['team']) + 1); // after "TEAM."
        if ($app === $bundleId) {
            return 1000;
        }
        if (str_ends_with($app, '*') && str_starts_with($bundleId, substr($app, 0, -1))) {
            return 1 + strlen($app);
        }
        return 0;
    }

    /**
     * The profile among $profiles that fits best (fit()), the one that
     * expires last of those that fit as well; null for none.
     *
     * @param list<array{team: string, appId: string, expires: int, devices: ?list<string>}> $profiles
     */
    public static function choose(array $profiles, string $bundleId, string $team, string $udid, int $now): ?array
    {
        $best = null; $bestFit = 0;
        foreach ($profiles as $p) {
            $fit = self::fit($p, $bundleId, $team, $udid, $now);
            if ($fit > $bestFit || ($fit > 0 && $fit === $bestFit && $p['expires'] > $best['expires'])) {
                [$best, $bestFit] = [$p, $fit];
            }
        }
        return $best;
    }

    /**
     * The entitlements the app is signed with under profile $p, as Xcode's
     * for an app that asks for no capability: its identifier, its team,
     * whether a debugger may attach (a development profile's
     * get-task-allow, which `myphp run` needs to show the app's log), and
     * its keychain group where the profile has keychain groups (the
     * Keychain, Native\Keychain). What the profile allows beyond that (a
     * wildcard's "*" values among them) is not the app's to claim.
     *
     * @param array{team: string, entitlements: array<string, mixed>} $p
     * @return array<string, mixed>
     */
    public static function entitlements(array $p, string $bundleId): array
    {
        $id = "{$p['team']}.$bundleId";
        $e = ['application-identifier' => $id, 'com.apple.developer.team-identifier' => $p['team'], 'get-task-allow' => ($p['entitlements']['get-task-allow'] ?? false) === true];
        if (isset($p['entitlements']['keychain-access-groups'])) {
            $e['keychain-access-groups'] = [$id];
        }
        return $e;
    }

    /**
     * The signing identity in the keychain for profile $p, by the SHA-1 of
     * its certificate, as codesign takes it: the first valid identity of
     * $found (`security find-identity -v -p codesigning`'s output) whose
     * certificate the profile names; null for none.
     *
     * @param array{certs: list<string>} $p
     */
    public static function identity(array $p, string $found): ?string
    {
        preg_match_all('/^\s*\d+\)\s+([0-9A-F]{40})\s+"/m', $found, $m);
        foreach ($m[1] as $hash) {
            if (in_array($hash, $p['certs'], true)) {
                return $hash;
            }
        }
        return null;
    }
}
