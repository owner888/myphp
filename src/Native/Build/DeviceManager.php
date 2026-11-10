<?php
declare(strict_types=1);

namespace MyPHP\Native\Build;

final class TargetDevice
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $platform, // 'darwin', 'android', 'ios', 'linux', 'windows'
        public readonly string $type,     // 'desktop', 'device', 'simulator'
        public readonly string $status,   // 'connected', 'booted', 'shutdown'
    ) {
    }
}

final class DeviceManager
{
    /** @return list<TargetDevice> */
    public static function listDevices(): array
    {
        $devices = [];

        // 1. Host Desktop Device
        if (PHP_OS_FAMILY === 'Darwin') {
            $arch = in_array(strtolower(php_uname('m')), ['arm64', 'aarch64'], true) ? 'arm64' : 'x86_64';
            $devices[] = new TargetDevice(
                id: 'macos',
                name: "macOS Desktop ($arch)",
                platform: 'darwin',
                type: 'desktop',
                status: 'connected',
            );
        } elseif (PHP_OS_FAMILY === 'Linux') {
            $arch = php_uname('m');
            $devices[] = new TargetDevice(
                id: 'linux',
                name: "Linux Desktop ($arch)",
                platform: 'linux',
                type: 'desktop',
                status: 'connected',
            );
        } elseif (PHP_OS_FAMILY === 'Windows') {
            $arch = in_array(strtolower((string)getenv('PROCESSOR_ARCHITECTURE')), ['arm64', 'aarch64'], true) ? 'arm64' : 'amd64';
            $devices[] = new TargetDevice(
                id: 'windows',
                name: "Windows Desktop ($arch)",
                platform: 'windows',
                type: 'desktop',
                status: 'connected',
            );
        }

        // 2. Android Devices via adb
        if (($adb = Emulators::tool('adb')) !== '') {
            [, $adbOut] = Cmd::output([$adb, 'devices', '-l']); // no shell: Windows's has no /dev/null
            foreach (explode("\n", $adbOut) as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, 'List of devices')) {
                    continue;
                }
                $parts = preg_split('/\s+/', $line);
                if (count($parts) >= 2 && $parts[1] === 'device') {
                    $serial = $parts[0];
                    $model = $serial;
                    if (preg_match('/model:([^\s]+)/', $line, $m)) {
                        $model = str_replace('_', ' ', $m[1]);
                    }
                    $isEmulator = str_starts_with($serial, 'emulator-');
                    if ($isEmulator) { // the AVD's name, as `myphp emulators` lists it, rather than the system image's model
                        [, $avd] = Cmd::output([$adb, '-s', $serial, 'emu', 'avd', 'name']);
                        $avd = trim(explode("\n", $avd)[0]);
                        if ($avd !== '' && $avd !== 'OK') { $model = $avd; }
                    }
                    $devices[] = new TargetDevice(
                        id: $serial,
                        name: "Android " . ($isEmulator ? "Emulator" : "Device") . " ($model)",
                        platform: 'android',
                        type: $isEmulator ? 'simulator' : 'device',
                        status: 'connected',
                    );
                }
            }
        }

        // 3. Physical iOS Devices via xcrun devicectl, then Simulators via xcrun simctl
        if (PHP_OS_FAMILY === 'Darwin' && Cmd::look('xcrun') !== '') {
            $devOut = (string)@shell_exec('xcrun devicectl list devices 2>/dev/null');
            foreach (explode("\n", $devOut) as $line) {
                if (preg_match('/^(.+?)\s{2,}.*?([A-F0-9]{8}-[A-F0-9-]{4,})\s+\(UDID\)\s+(connected|available \(paired\))\s+(.+?)\s+physical\b/i', trim($line), $m)) {
                    $name = trim($m[1]);
                    $udid = $m[2];
                    $model = trim($m[4]);
                    if (str_contains($model, 'iPhone') || str_contains($model, 'iPad') || str_contains($name, 'iPhone') || str_contains($name, 'iPad')) {
                        $devices[] = new TargetDevice(
                            id: $udid,
                            name: $name,
                            platform: 'ios',
                            type: 'device',
                            status: 'connected',
                        );
                    }
                }
            }
            $simOut = (string)@shell_exec('xcrun simctl list devices available 2>/dev/null');
            foreach (explode("\n", $simOut) as $line) {
                $line = trim($line);
                if (preg_match('/^(.+?)\s+\(([A-F0-9-]+)\)\s+\((Booted|Shutdown)\)/', $line, $m)) {
                    $name = $m[1];
                    $uuid = $m[2];
                    $status = strtolower($m[3]);
                    // Only list iPhone and iPad devices
                    if (str_contains($name, 'iPhone') || str_contains($name, 'iPad')) {
                        $devices[] = new TargetDevice(
                            id: $uuid,
                            name: "$name (Simulator)",
                            platform: 'ios',
                            type: 'simulator',
                            status: $status,
                        );
                    }
                }
            }
        }

        return $devices;
    }

    public static function findDevice(?string $query = null): ?TargetDevice
    {
        $all = self::listDevices();
        if ($all === []) {
            return null;
        }

        if ($query === null || $query === '') {
            // Intelligent priority selection:
            // 1. Any connected real mobile device (Android or iOS physical)
            foreach ($all as $d) {
                if ($d->type === 'device' && $d->status === 'connected') {
                    return $d;
                }
            }
            // 2. Any booted simulator
            foreach ($all as $d) {
                if ($d->status === 'booted') {
                    return $d;
                }
            }
            // 3. Host desktop
            foreach ($all as $d) {
                if ($d->type === 'desktop') {
                    return $d;
                }
            }
            return $all[0];
        }

        $q = strtolower($query);

        // Exact match by ID
        foreach ($all as $d) {
            if (strtolower($d->id) === $q) {
                return $d;
            }
        }

        // Match by platform keyword (android, ios, macos, linux, desktop)
        foreach ($all as $d) {
            if ($d->platform === $q) {
                // If query is 'ios', prefer booted one if available
                if ($d->status === 'booted') {
                    return $d;
                }
            }
        }
        foreach ($all as $d) {
            if ($d->platform === $q) {
                return $d;
            }
        }

        // Fuzzy match by name
        foreach ($all as $d) {
            if (str_contains(strtolower($d->name), $q)) {
                return $d;
            }
        }

        return null;
    }
}
