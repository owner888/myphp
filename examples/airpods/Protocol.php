<?php
// AirPods BLE Proximity Pairing (0x004C) & AACP (L2CAP PSM 0x1001) protocol
// stack in pure PHP, following the LibrePods specification with first-class
// defaults for standard AirPods 4 (model A3053 / BLE 0x1920).
declare(strict_types=1);

namespace MyPHP\Examples\Airpods;

use InvalidArgumentException;

/**
 * Apple Continuity BLE Proximity Pairing broadcast (Manufacturer ID 0x004C,
 * type 0x07, 27 bytes). Used for the open-lid popup and initial battery/lid
 * state before or alongside the L2CAP 0x1001 control channel.
 */
final class AirPodsBleBroadcast
{
    public const APPLE_COMPANY_ID = 0x004C;
    public const PROXIMITY_PAIRING_TYPE = 0x07;

    public const MODEL_AIRPODS_1 = 0x0220;
    public const MODEL_AIRPODS_2 = 0x0F20;
    public const MODEL_AIRPODS_3 = 0x1320;
    public const MODEL_AIRPODS_4 = 0x1920;
    public const MODEL_AIRPODS_4_ANC = 0x1B20;
    public const MODEL_AIRPODS_PRO = 0x0E20;
    public const MODEL_AIRPODS_PRO_2_LIGHTNING = 0x1420;
    public const MODEL_AIRPODS_PRO_2_USBC = 0x2420;
    public const MODEL_AIRPODS_MAX = 0x0A20;

    public function __construct(
        public int $modelId,
        public string $modelName,
        public bool $hasAnc,
        public ?int $leftBattery,
        public ?int $rightBattery,
        public ?int $caseBattery,
        public bool $leftCharging,
        public bool $rightCharging,
        public bool $caseCharging,
        public bool $leftInEar,
        public bool $rightInEar,
        public bool $lidOpen,
        public int $lidCounter,
        public int $color = 0,
        public bool $pairingMode = false,
    ) {}

    /** @return array{name: string, anc: bool} */
    public static function modelInfo(int $modelId): array
    {
        return match ($modelId) {
            self::MODEL_AIRPODS_4 => ['name' => 'AirPods 4', 'anc' => false],
            self::MODEL_AIRPODS_4_ANC => ['name' => 'AirPods 4 (ANC)', 'anc' => true],
            self::MODEL_AIRPODS_PRO_2_LIGHTNING, self::MODEL_AIRPODS_PRO_2_USBC => ['name' => 'AirPods Pro 2', 'anc' => true],
            self::MODEL_AIRPODS_PRO => ['name' => 'AirPods Pro', 'anc' => true],
            self::MODEL_AIRPODS_MAX => ['name' => 'AirPods Max', 'anc' => true],
            self::MODEL_AIRPODS_3 => ['name' => 'AirPods 3', 'anc' => false],
            self::MODEL_AIRPODS_2 => ['name' => 'AirPods 2', 'anc' => false],
            self::MODEL_AIRPODS_1 => ['name' => 'AirPods 1', 'anc' => false],
            default => ['name' => sprintf('AirPods (0x%04X)', $modelId), 'anc' => false],
        };
    }

    /**
     * Decodes the 27-byte Apple Continuity payload (after stripping the 2-byte
     * 0x4C 0x00 company ID if present).
     */
    public static function decode(string $raw): self
    {
        if (strlen($raw) === 29 && ord($raw[0]) === 0x4C && ord($raw[1]) === 0x00) {
            $raw = substr($raw, 2);
        }
        if (strlen($raw) < 11 || ord($raw[0]) !== self::PROXIMITY_PAIRING_TYPE) {
            throw new InvalidArgumentException('Invalid Apple Proximity Pairing BLE payload');
        }
        $pairingMode = ord($raw[2]) === 0x00;
        $modelId = (ord($raw[3]) << 8) | ord($raw[4]);
        $info = self::modelInfo($modelId);
        if ($pairingMode) {
            return new self(
                modelId: $modelId,
                modelName: $info['name'],
                hasAnc: $info['anc'],
                leftBattery: null,
                rightBattery: null,
                caseBattery: null,
                leftCharging: false,
                rightCharging: false,
                caseCharging: false,
                leftInEar: false,
                rightInEar: false,
                lidOpen: true,
                lidCounter: 0,
                color: 0,
                pairingMode: true,
            );
        }
        $status = ord($raw[5]);
        $podsByte = ord($raw[6]);
        $flagsCaseByte = ord($raw[7]);
        $lidByte = ord($raw[8]);
        $color = ord($raw[9]);

        // Bit 5 (0x20) of status indicates which pod is primary (swaps left/right nibbles).
        $swapped = ($status & 0x20) === 0;
        $firstNibble = ($podsByte >> 4) & 0x0F;
        $secondNibble = $podsByte & 0x0F;
        $leftRaw = $swapped ? $secondNibble : $firstNibble;
        $rightRaw = $swapped ? $firstNibble : $secondNibble;
        $caseRaw = $flagsCaseByte & 0x0F;

        $chargeFlags = ($flagsCaseByte >> 4) & 0x0F;
        $leftCharging = ($chargeFlags & ($swapped ? 0x02 : 0x01)) !== 0;
        $rightCharging = ($chargeFlags & ($swapped ? 0x01 : 0x02)) !== 0;
        $caseCharging = ($chargeFlags & 0x04) !== 0;

        $primaryInEar = ($status & 0x02) !== 0;
        $secondaryInEar = ($status & 0x08) !== 0;
        $leftInEar = $swapped ? $secondaryInEar : $primaryInEar;
        $rightInEar = $swapped ? $primaryInEar : $secondaryInEar;

        $lidOpen = ($lidByte & 0x08) === 0;
        $lidCounter = $lidByte & 0x07;

        return new self(
            modelId: $modelId,
            modelName: $info['name'],
            hasAnc: $info['anc'],
            leftBattery: $leftRaw <= 10 ? min(100, $leftRaw * 10) : null,
            rightBattery: $rightRaw <= 10 ? min(100, $rightRaw * 10) : null,
            caseBattery: $caseRaw <= 10 ? min(100, $caseRaw * 10) : null,
            leftCharging: $leftCharging,
            rightCharging: $rightCharging,
            caseCharging: $caseCharging,
            leftInEar: $leftInEar,
            rightInEar: $rightInEar,
            lidOpen: $lidOpen,
            lidCounter: $lidCounter,
            color: $color,
            pairingMode: false,
        );
    }

    /** Encodes a 27-byte Apple Proximity Pairing BLE advertisement payload. */
    public static function encode(
        int $modelId = self::MODEL_AIRPODS_4,
        ?int $leftBattery = 90,
        ?int $rightBattery = 90,
        ?int $caseBattery = 80,
        bool $leftCharging = false,
        bool $rightCharging = false,
        bool $caseCharging = false,
        bool $leftInEar = true,
        bool $rightInEar = true,
        bool $lidOpen = false,
        int $lidCounter = 1,
        int $color = 0,
    ): string {
        $status = 0x20 | ($leftInEar ? 0x02 : 0x00) | ($rightInEar ? 0x08 : 0x00);
        $lNib = $leftBattery === null ? 0x0F : max(0, min(10, intdiv($leftBattery + 5, 10)));
        $rNib = $rightBattery === null ? 0x0F : max(0, min(10, intdiv($rightBattery + 5, 10)));
        $cNib = $caseBattery === null ? 0x0F : max(0, min(10, intdiv($caseBattery + 5, 10)));
        $podsByte = ($lNib << 4) | $rNib;
        $chargeFlags = ($leftCharging ? 0x01 : 0x00) | ($rightCharging ? 0x02 : 0x00) | ($caseCharging ? 0x04 : 0x00);
        $flagsCaseByte = ($chargeFlags << 4) | $cNib;
        $lidByte = ($lidOpen ? 0x00 : 0x08) | ($lidCounter & 0x07);

        return pack(
            'CCCCCCCCCCC',
            self::PROXIMITY_PAIRING_TYPE,
            0x19,
            0x01,
            ($modelId >> 8) & 0xFF,
            $modelId & 0xFF,
            $status,
            $podsByte,
            $flagsCaseByte,
            $lidByte,
            $color & 0xFF,
            0x00,
        ) . str_repeat("\x00", 16);
    }
}

/**
 * AACP (Apple Accessory Communication Protocol) over Bluetooth L2CAP PSM 0x1001.
 * Encodes outgoing commands and decodes incoming notifications from AirPods.
 */
final class AacpProtocol
{
    public const L2CAP_PSM = 0x1001; // 4097

    // Standard AACP 4-byte header: 04 00 04 00
    public const HEADER = "\x04\x00\x04\x00";

    // Opcodes (16-bit little-endian after HEADER)
    public const OPCODE_HANDSHAKE = 0x0001;
    public const OPCODE_BATTERY = 0x0004;
    public const OPCODE_EAR_DETECTION = 0x0006;
    public const OPCODE_CONTROL = 0x0009;
    public const OPCODE_AUDIO_SOURCE_REQ = 0x000D;
    public const OPCODE_AUDIO_SOURCE_RESP = 0x000E;
    public const OPCODE_NOTIFICATION_REG = 0x000F;
    public const OPCODE_SMART_ROUTING = 0x0010;
    public const OPCODE_SMART_ROUTING_RESP = 0x0011;
    public const OPCODE_SEND_CONNECTED_MAC = 0x0014;
    public const OPCODE_HEAD_TRACKING = 0x0017;
    public const OPCODE_STEM_PRESS = 0x0019;
    public const OPCODE_RENAME = 0x001A;
    public const OPCODE_METADATA = 0x001D;
    public const OPCODE_CONNECTED_DEVICES = 0x002E;
    public const OPCODE_PROXIMITY_KEYS_REQ = 0x0030;
    public const OPCODE_PROXIMITY_KEYS_RSP = 0x0031;
    public const OPCODE_CONVERSATION = 0x004B;
    public const OPCODE_CAPABILITIES = 0x004D;

    // Proximity Key Types (Opcode 0x0030 / 0x0031)
    public const KEY_TYPE_IRK = 0x01;
    public const KEY_TYPE_ENC = 0x04;

    // Audio Source Types (Opcode 0x000E)
    public const AUDIO_NONE = 0x00;
    public const AUDIO_CALL = 0x01;
    public const AUDIO_MEDIA = 0x02;

    // Battery component identifiers (Opcode 0x0004)
    public const COMP_RIGHT = 0x02;
    public const COMP_LEFT = 0x04;
    public const COMP_CASE = 0x08;

    // Battery status values (Opcode 0x0004)
    public const BAT_UNKNOWN = 0x00;
    public const BAT_CHARGING = 0x01;
    public const BAT_DISCHARGING = 0x02;
    public const BAT_DISCONNECTED = 0x04;

    // Ear detection states (Opcode 0x0006)
    public const EAR_IN_EAR = 0x00;
    public const EAR_OUT_OF_EAR = 0x01;
    public const EAR_IN_CASE = 0x02;

    // Control Command Identifiers (Opcode 0x0009)
    public const CMD_MIC_MODE = 0x01;               // 0=Auto, 1=Always Right, 2=Always Left
    public const CMD_OWNS_CONNECTION = 0x06;        // 1=Own, 0=Release
    public const CMD_EAR_DETECTION = 0x0A;          // 1=Enabled, 2=Disabled
    public const CMD_LISTENING_MODE = 0x0D;         // 1=Off, 2=ANC, 3=Transparency, 4=Adaptive
    public const CMD_VOICE_TRIGGER_SIRI = 0x12;     // 1=Enabled, 2=Disabled
    public const CMD_CLICK_HOLD_MODE = 0x16;        // [right, left]: 1=Noise Control, 5=Siri
    public const CMD_DOUBLE_CLICK_INTERVAL = 0x17;  // 0=Default, 1=Slower, 2=Slowest
    public const CMD_CLICK_HOLD_INTERVAL = 0x18;    // 0=Default, 1=Shorter, 2=Shortest
    public const CMD_LISTENING_MODE_CONFIGS = 0x1A; // Bitmask: 1=Off, 2=ANC, 4=Trans, 8=Adaptive
    public const CMD_ONE_BUD_ANC = 0x1B;            // 1=Enabled, 2=Disabled
    public const CMD_CONNECT_AUTOMATICALLY = 0x20;  // 1=Enabled, 2=Disabled
    public const CMD_VOLUME_SWIPE_INTERVAL = 0x23;  // 0=Default, 1=Longer, 2=Longest
    public const CMD_CALL_MANAGEMENT = 0x24;        // End call: 1=Single press, 2=Double press
    public const CMD_VOLUME_SWIPE_MODE = 0x25;      // 1=Enabled, 2=Disabled
    public const CMD_ADAPTIVE_VOLUME = 0x26;        // 1=Enabled, 2=Disabled
    public const CMD_CONVERSATION_DETECT = 0x28;    // 1=Enabled, 2=Disabled
    public const CMD_AUTO_ANC_STRENGTH = 0x2E;      // 0..100
    public const CMD_IN_CASE_TONE = 0x31;           // 1=Enabled, 2=Disabled
    public const CMD_ALLOW_OFF_LISTENING = 0x34;    // 1=Enabled, 2=Disabled
    public const CMD_SLEEP_DETECTION = 0x35;        // 1=Enabled (pause media when asleep), 2=Disabled

    // Listening modes (CMD_LISTENING_MODE = 0x0D)
    public const MODE_OFF = 0x01;
    public const MODE_ANC = 0x02;
    public const MODE_TRANSPARENCY = 0x03;
    public const MODE_ADAPTIVE = 0x04;

    /** Handshake packet required before AirPods respond on L2CAP 0x1001. */
    public static function handshakePacket(): string
    {
        return "\x00\x00\x04\x00\x01\x00\x02\x00\x00\x00\x00\x00\x00\x00\x00\x00";
    }

    /** Host capabilities packet (enables H2 chip features on AirPods 4 / Pro 2). */
    public static function capabilitiesPacket(): string
    {
        return "\x04\x00\x04\x00\x4d\x00\xff\x00\x00\x00\x00\x00\x00\x00";
    }

    /** Registers for battery, ear detection, control and sensor notifications. */
    public static function requestNotificationsPacket(): string
    {
        return "\x04\x00\x04\x00\x0f\x00\xff\xff\xfe\xff";
    }

    /** Requests BLE Magic Pairing keys (IRK = 0x01, ENC_KEY = 0x04) over Opcode 0x0030. */
    public static function requestProximityKeysPacket(int $mask = self::KEY_TYPE_IRK | self::KEY_TYPE_ENC): string
    {
        return self::HEADER . pack('vCC', self::OPCODE_PROXIMITY_KEYS_REQ, $mask & 0xFF, 0x00);
    }

    /** Encodes an Opcode 0x0031 Proximity Keys response containing 16-byte IRK and ENC_KEY. */
    public static function encodeProximityKeysPacket(string $irk, string $encKey): string
    {
        return self::HEADER
            . pack('vC', self::OPCODE_PROXIMITY_KEYS_RSP, 2)
            . pack('CCCC', self::KEY_TYPE_IRK, 0x00, strlen($irk), 0x00) . $irk
            . pack('CCCC', self::KEY_TYPE_ENC, 0x00, strlen($encKey), 0x00) . $encKey;
    }

    /** Builds an 11-byte Opcode 0x0009 control command packet. */
    public static function controlPacket(int $identifier, int $d1, int $d2 = 0, int $d3 = 0, int $d4 = 0): string
    {
        return self::HEADER . pack('vCCCCC', self::OPCODE_CONTROL, $identifier & 0xFF, $d1 & 0xFF, $d2 & 0xFF, $d3 & 0xFF, $d4 & 0xFF);
    }

    /** Builds a packet to rename the AirPods in NVRAM. */
    public static function renamePacket(string $name): string
    {
        $len = strlen($name);
        if ($len === 0 || $len > 128) {
            throw new InvalidArgumentException('AirPods name must be 1..128 UTF-8 bytes');
        }
        return self::HEADER . pack('vCCC', self::OPCODE_RENAME, 0x01, $len, 0x00) . $name;
    }

    /** Starts the H2 head-tracking / head-gesture IMU stream (Opcode 0x0017). */
    public static function startHeadTrackingPacket(): string
    {
        return "\x04\x00\x04\x00\x17\x00\x00\x00\x10\x00\x10\x00\x08\xa1\x02\x42\x0b\x08\x0e\x10\x02\x1a\x05\x01\x40\x9c\x00\x00";
    }

    /** Stops the H2 head-tracking / head-gesture IMU stream (Opcode 0x0017). */
    public static function stopHeadTrackingPacket(): string
    {
        return "\x04\x00\x04\x00\x17\x00\x00\x00\x10\x00\x11\x00\x08\x7e\x10\x02\x42\x0b\x08\x4e\x10\x02\x1a\x05\x01\x00\x00\x00\x00";
    }

    /** Converts "AA:BB:CC:DD:EE:FF" to 6 reversed binary bytes (FF EE DD CC BB AA). */
    public static function macToReversedBytes(string $mac): string
    {
        $parts = explode(':', trim($mac));
        if (count($parts) !== 6) {
            throw new InvalidArgumentException('Invalid MAC address: ' . $mac);
        }
        $bytes = array_map(static fn(string $hex): int => (int)hexdec($hex), array_reverse($parts));
        return pack('CCCCCC', ...$bytes);
    }

    /** Converts 6 reversed binary bytes (FF EE DD CC BB AA) back to "AA:BB:CC:DD:EE:FF". */
    public static function reversedBytesToMac(string $sixBytes): string
    {
        $hex = [];
        for ($i = 5; $i >= 0; $i--) {
            $hex[] = sprintf('%02X', ord($sixBytes[$i]));
        }
        return implode(':', $hex);
    }

    /** Claims (0x01) or releases (0x00) connection ownership (CMD_OWNS_CONNECTION = 0x06). */
    public static function ownsConnectionPacket(bool $owns): string
    {
        return self::controlPacket(self::CMD_OWNS_CONNECTION, $owns ? 0x01 : 0x00);
    }

    /**
     * Announces this phone's media state to a newly connected peer (e.g. iPhone)
     * over Opcode 0x0010 (Smart Routing, 116-byte payload).
     */
    public static function mediaInfoNewDevicePacket(string $selfMac, string $targetMac): string
    {
        $body = self::macToReversedBytes($targetMac)
            . "\x6c\x00\x01\xe5\x4a"
            . 'playingApp' . "\x42" . 'NA'
            . "\x52" . 'hostStreamingState' . "\x42" . 'NO'
            . "\x49" . 'btAddress' . "\x51" . strtoupper($selfMac)
            . "\x46" . 'btName' . "\x47" . 'Android'
            . "\x58" . 'otherDeviceAudioCategory' . "\x30\x64";
        return self::HEADER . pack('v', self::OPCODE_SMART_ROUTING) . str_pad($body, 116, "\x00");
    }

    /**
     * Registers this phone in the peer iPhone/Mac's TiPi multi-device table
     * over Opcode 0x0010 (90-byte payload).
     */
    public static function addTiPiDevicePacket(string $selfMac, string $targetMac): string
    {
        $body = self::macToReversedBytes($targetMac)
            . "\x52\x00\x01\xe5\x48"
            . 'idleTime' . "\x08\x47"
            . 'newTipi' . "\x01\x49"
            . 'btAddress' . "\x51" . strtoupper($selfMac)
            . "\x46" . 'btName' . "\x47" . 'Android'
            . "\x50" . 'nearbyAudioScore' . "\x0e";
        return self::HEADER . pack('v', self::OPCODE_SMART_ROUTING) . str_pad($body, 90, "\x00");
    }

    /**
     * Sends current streaming state ("YES" / "NO") to peer iPhone/Mac over
     * Opcode 0x0010 (138-byte payload).
     */
    public static function mediaInformationPacket(string $selfMac, string $targetMac, bool $streaming = true): string
    {
        $body = self::macToReversedBytes($targetMac)
            . "\x82\x00\x01\xe5\x4a"
            . 'PlayingApp' . "\x56" . 'com.google.ios.youtube'
            . "\x52" . 'HostStreamingState' . "\x42" . ($streaming ? 'YES' : 'NO')
            . "\x49" . 'btAddress' . "\x51" . strtoupper($selfMac)
            . 'btName' . "\x47" . 'Android'
            . "\x58" . 'otherDeviceAudioCategory' . "\x31\x2d\x01";
        return self::HEADER . pack('v', self::OPCODE_SMART_ROUTING) . str_pad($body, 138, "\x00");
    }

    /**
     * Triggers the native "Moved to Android [Move back to iPhone]" banner on
     * the peer iPhone over Opcode 0x0010 (134-byte payload).
     */
    public static function smartRoutingShowUiPacket(string $targetMac): string
    {
        $body = self::macToReversedBytes($targetMac)
            . "\x7e\x00\x01\xe6\x5b"
            . 'SmartRoutingKeyShowNearbyUI' . "\x01\x4a"
            . 'localscore' . "\x31\x2d\x01\x46"
            . 'reasonHhijackv2' . "\x51"
            . 'audioRoutingScore' . "\xa2\x5f"
            . 'audioRoutingSetOwnershipToFalse' . "\x01\x4b"
            . 'remotescore' . "\xa2";
        return self::HEADER . pack('v', self::OPCODE_SMART_ROUTING) . str_pad($body, 134, "\x00");
    }

    /**
     * Requests audio takeover (Hijackv2) from the peer iPhone over Opcode
     * 0x0010 (106-byte payload).
     */
    public static function hijackRequestPacket(string $targetMac): string
    {
        $body = self::macToReversedBytes($targetMac)
            . "\x62\x00\x01\xe5\x4a"
            . 'localscore' . "\x30\x64\x46"
            . 'reason' . "\x48" . 'Hijackv2' . "\x51"
            . 'audioRoutingScore' . "\x31\x2d\x01\x5f"
            . 'audioRoutingSetOwnershipToFalse' . "\x01\x4b"
            . 'remotescore' . "\xa5";
        return self::HEADER . pack('v', self::OPCODE_SMART_ROUTING) . str_pad($body, 106, "\x00");
    }

    /**
     * Hands audio back to the peer iPhone (ReverseBannerTapped) over Opcode
     * 0x0010 (97-byte payload).
     */
    public static function hijackReversedPacket(string $targetMac): string
    {
        $body = self::macToReversedBytes($targetMac)
            . "\x59\x00\x01\xe3\x5f"
            . 'audioRoutingSetOwnershipToFalse' . "\x01\x59"
            . 'audioRoutingShowReverseUI' . "\x01\x46"
            . 'reason' . "\x53" . 'ReverseBannerTapped';
        return self::HEADER . pack('v', self::OPCODE_SMART_ROUTING) . str_pad($body, 97, "\x00");
    }

    /**
     * Encodes an Opcode 0x002E connected devices notification packet.
     * @param list<array{mac: string, info1: int, info2: int}> $devices
     */
    public static function encodeConnectedDevicesPacket(array $devices): string
    {
        $out = self::HEADER . pack('vCCC', self::OPCODE_CONNECTED_DEVICES, 0x00, 0x00, count($devices));
        foreach ($devices as $d) {
            $parts = array_map(static fn(string $h): int => (int)hexdec($h), explode(':', $d['mac']));
            $out .= pack('CCCCCCCC', $parts[0], $parts[1], $parts[2], $parts[3], $parts[4], $parts[5], $d['info1'] & 0xFF, $d['info2'] & 0xFF);
        }
        return $out;
    }

    /** Encodes an Opcode 0x000E audio source notification packet. */
    public static function encodeAudioSourcePacket(string $mac, int $audioType): string
    {
        return self::HEADER . pack('v', self::OPCODE_AUDIO_SOURCE_RESP) . self::macToReversedBytes($mac) . pack('C', $audioType & 0xFF);
    }

    /**
     * Encodes an Opcode 0x0004 battery notification packet.
     * @param list<array{component: int, level: int, status: int}> $items
     */
    public static function encodeBatteryPacket(array $items): string
    {
        $out = self::HEADER . pack('vC', self::OPCODE_BATTERY, count($items));
        foreach ($items as $it) {
            $out .= pack('CCCCC', $it['component'], 0x01, max(0, min(100, $it['level'])), $it['status'], 0x01);
        }
        return $out;
    }

    /** Encodes an Opcode 0x0006 ear detection notification packet. */
    public static function encodeEarDetectionPacket(int $primary, int $secondary): string
    {
        return self::HEADER . pack('vCC', self::OPCODE_EAR_DETECTION, $primary & 0xFF, $secondary & 0xFF);
    }

    /** Encodes an Opcode 0x001D device metadata notification packet. */
    public static function encodeMetadataPacket(
        string $name,
        string $modelNumber = 'A3053',
        string $manufacturer = 'Apple Inc.',
        string $serial = 'H0Y4AIRPODS4',
        string $firmware = '7B21',
        string $hardwareRev = '1.0.0',
        string $leftSerial = 'H0Y4LEFT0004',
        string $rightSerial = 'H0Y4RGHT0004',
    ): string {
        $fields = [
            $name,
            $modelNumber,
            $manufacturer,
            $serial,
            $firmware,
            $firmware,
            $hardwareRev,
            'com.apple.accessory.updater.app.71',
            $leftSerial,
            $rightSerial,
        ];
        return self::HEADER . pack('v', self::OPCODE_METADATA) . "\x02\xd5\x00\x04\x00" . implode("\x00", $fields) . "\x00";
    }

    /** Encodes an Opcode 0x0017 head-tracking sensor packet (55 bytes). */
    public static function encodeHeadTrackingPacket(
        int $pitch,
        int $yaw,
        int $roll,
        int $accelX = 0,
        int $accelY = 0,
    ): string {
        $prefix = self::HEADER . pack('v', self::OPCODE_HEAD_TRACKING) . str_repeat("\x00", 37); // offsets 0..42
        return $prefix . pack('sssss', $pitch, $yaw, $roll, 0, $accelX) . pack('s', $accelY);
    }

    /**
     * Decodes a raw AACP packet from L2CAP PSM 0x1001.
     * @return array{
     *   type: string,
     *   opcode: int,
     *   batteries?: array<int, array{level: int, status: int}>,
     *   primaryEar?: int,
     *   secondaryEar?: int,
     *   identifier?: int,
     *   data?: list<int>,
     *   name?: string,
     *   modelNumber?: string,
     *   manufacturer?: string,
     *   serial?: string,
     *   firmware?: string,
     *   hardwareRev?: string,
     *   leftSerial?: string,
     *   rightSerial?: string,
     *   speakingLevel?: int,
     *   pitch?: int,
     *   yaw?: int,
     *   roll?: int,
     *   accelX?: int,
     *   accelY?: int
     * }
     */
    public static function decode(string $raw): array
    {
        $len = strlen($raw);
        if ($len < 6) {
            throw new InvalidArgumentException('AACP packet too short: ' . $len . ' bytes');
        }
        if ( str_starts_with($raw, "\x00\x00\x04\x00\x01\x00")) {
            return ['type' => 'handshake', 'opcode' => self::OPCODE_HANDSHAKE];
        }
        $opcode = unpack('v', substr($raw, 4, 2))[1];
        switch ($opcode) {
            case self::OPCODE_BATTERY:
                if ($len < 7) {
                    throw new InvalidArgumentException('Truncated AACP battery packet');
                }
                $count = ord($raw[6]);
                $batteries = [];
                $offset = 7;
                for ($i = 0; $i < $count && $offset + 4 < $len; $i++, $offset += 5) {
                    $comp = ord($raw[$offset]);
                    $level = ord($raw[$offset + 2]);
                    $status = ord($raw[$offset + 3]);
                    $batteries[$comp] = ['level' => $level, 'status' => $status];
                }
                return ['type' => 'battery', 'opcode' => $opcode, 'batteries' => $batteries];

            case self::OPCODE_EAR_DETECTION:
                if ($len < 8) {
                    throw new InvalidArgumentException('Truncated AACP ear detection packet');
                }
                return [
                    'type' => 'ear_detection',
                    'opcode' => $opcode,
                    'primaryEar' => ord($raw[6]),
                    'secondaryEar' => ord($raw[7]),
                ];

            case self::OPCODE_CONTROL:
                if ($len < 11) {
                    throw new InvalidArgumentException('Truncated AACP control packet');
                }
                return [
                    'type' => 'control',
                    'opcode' => $opcode,
                    'identifier' => ord($raw[6]),
                    'data' => [ord($raw[7]), ord($raw[8]), ord($raw[9]), ord($raw[10])],
                ];

            case self::OPCODE_NOTIFICATION_REG:
                return ['type' => 'notification_register', 'opcode' => $opcode];

            case self::OPCODE_CAPABILITIES:
                return ['type' => 'capabilities', 'opcode' => $opcode];

            case self::OPCODE_RENAME:
                if ($len < 9) {
                    throw new InvalidArgumentException('Truncated AACP rename packet');
                }
                $nameLen = ord($raw[7]);
                return [
                    'type' => 'rename',
                    'opcode' => $opcode,
                    'name' => substr($raw, 9, $nameLen),
                ];

            case self::OPCODE_CONVERSATION:
                if ($len < 10) {
                    throw new InvalidArgumentException('Truncated AACP conversational awareness packet');
                }
                return [
                    'type' => 'conversational_awareness',
                    'opcode' => $opcode,
                    'speakingLevel' => ord($raw[9]),
                ];

            case self::OPCODE_METADATA:
                // Header (4) + opcode (2) + 5-byte preamble (02 xx 00 04 00) = offset 11
                $payloadOffset = ($len > 11 && $raw[11] === "\x00") ? 12 : min(11, $len);
                $parts = explode("\x00", substr($raw, $payloadOffset));
                return [
                    'type' => 'metadata',
                    'opcode' => $opcode,
                    'name' => $parts[0] ?? '',
                    'modelNumber' => $parts[1] ?? '',
                    'manufacturer' => $parts[2] ?? '',
                    'serial' => $parts[3] ?? '',
                    'firmware' => $parts[4] ?? '',
                    'hardwareRev' => $parts[6] ?? '',
                    'leftSerial' => $parts[8] ?? '',
                    'rightSerial' => $parts[9] ?? '',
                ];

            case self::OPCODE_HEAD_TRACKING:
                if ($len >= 55 && $len <= 100) {
                    $u = unpack('spitch/syaw/sroll/sreserved/saccelX/saccelY', substr($raw, 43, 12));
                    return [
                        'type' => 'head_tracking',
                        'opcode' => $opcode,
                        'pitch' => $u['pitch'],
                        'yaw' => $u['yaw'],
                        'roll' => $u['roll'],
                        'accelX' => $u['accelX'],
                        'accelY' => $u['accelY'],
                    ];
                }
                return ['type' => 'head_tracking_control', 'opcode' => $opcode];

            case self::OPCODE_AUDIO_SOURCE_RESP:
                if ($len < 13) {
                    throw new InvalidArgumentException('Truncated AACP audio source packet');
                }
                return [
                    'type' => 'audio_source',
                    'opcode' => $opcode,
                    'mac' => self::reversedBytesToMac(substr($raw, 6, 6)),
                    'audioType' => ord($raw[12]),
                ];

            case self::OPCODE_CONNECTED_DEVICES:
                if ($len < 9) {
                    throw new InvalidArgumentException('Truncated AACP connected devices packet');
                }
                $devCount = ord($raw[8]);
                $devices = [];
                $off = 9;
                for ($i = 0; $i < $devCount && $off + 7 < $len; $i++, $off += 8) {
                    $macHex = [];
                    for ($b = 0; $b < 6; $b++) {
                        $macHex[] = sprintf('%02X', ord($raw[$off + $b]));
                    }
                    $devices[] = [
                        'mac' => implode(':', $macHex),
                        'info1' => ord($raw[$off + 6]),
                        'info2' => ord($raw[$off + 7]),
                    ];
                }
                return [
                    'type' => 'connected_devices',
                    'opcode' => $opcode,
                    'devices' => $devices,
                ];

            case self::OPCODE_SMART_ROUTING:
                if ($len < 12) {
                    return ['type' => 'smart_routing', 'opcode' => $opcode];
                }
                return [
                    'type' => 'smart_routing',
                    'opcode' => $opcode,
                    'targetMac' => self::reversedBytesToMac(substr($raw, 6, 6)),
                ];

            case self::OPCODE_SMART_ROUTING_RESP:
                if ($len < 12) {
                    throw new InvalidArgumentException('Truncated AACP smart routing response');
                }
                $senderMac = self::reversedBytesToMac(substr($raw, 6, 6));
                $devType = 'Peer';
                foreach (['iPhone', 'iPad', 'Mac', 'Android', 'Linux'] as $candidate) {
                    if (str_contains($raw, $candidate)) {
                        $devType = $candidate;
                        break;
                    }
                }
                return [
                    'type' => 'smart_routing_resp',
                    'opcode' => $opcode,
                    'senderMac' => $senderMac,
                    'deviceType' => $devType,
                    'setOwnershipFalse' => str_contains($raw, 'SetOwnershipToFalse'),
                    'reverseBannerTapped' => str_contains($raw, 'ReverseBannerTapped'),
                    'showNearbyUi' => str_contains($raw, 'ShowNearbyUI'),
                ];

            case self::OPCODE_PROXIMITY_KEYS_REQ:
                return [
                    'type' => 'proximity_keys_req',
                    'opcode' => $opcode,
                    'mask' => $len > 6 ? ord($raw[6]) : 0,
                ];

            case self::OPCODE_PROXIMITY_KEYS_RSP:
                if ($len < 7) {
                    throw new InvalidArgumentException('Truncated AACP proximity keys response');
                }
                $keyCount = ord($raw[6]);
                $keys = [];
                $off = 7;
                for ($i = 0; $i < $keyCount && $off + 3 < $len; $i++) {
                    $kType = ord($raw[$off]);
                    $kLen = ord($raw[$off + 2]);
                    $off += 4;
                    if ($off + $kLen <= $len) {
                        $keys[$kType] = substr($raw, $off, $kLen);
                    }
                    $off += $kLen;
                }
                return [
                    'type' => 'proximity_keys',
                    'opcode' => $opcode,
                    'irk' => $keys[self::KEY_TYPE_IRK] ?? null,
                    'encKey' => $keys[self::KEY_TYPE_ENC] ?? null,
                ];

            default:
                return ['type' => 'unknown', 'opcode' => $opcode];
        }
    }

    /** Formats a binary packet as uppercase hex bytes ("04 00 04 00 ..."). */
    public static function toHex(string $raw): string
    {
        return strtoupper(implode(' ', str_split(bin2hex($raw), 2)));
    }
}

/**
 * Pure-PHP AES-128-ECB & Bluetooth Core Spec Cryptography (IRK RPA resolution +
 * Apple Proximity Pairing 16-byte BLE payload decryption). Works identically on
 * Android (without ext-openssl) and desktop.
 */
final class AirPodsCrypto
{
    private const SBOX = [
        0x63,0x7c,0x77,0x7b,0xf2,0x6b,0x6f,0xc5,0x30,0x01,0x67,0x2b,0xfe,0xd7,0xab,0x76,
        0xca,0x82,0xc9,0x7d,0xfa,0x59,0x47,0xf0,0xad,0xd4,0xa2,0xaf,0x9c,0xa4,0x72,0xc0,
        0xb7,0xfd,0x93,0x26,0x36,0x3f,0xf7,0xcc,0x34,0xa5,0xe5,0xf1,0x71,0xd8,0x31,0x15,
        0x04,0xc7,0x23,0xc3,0x18,0x96,0x05,0x9a,0x07,0x12,0x80,0xe2,0xeb,0x27,0xb2,0x75,
        0x09,0x83,0x2c,0x1a,0x1b,0x6e,0x5a,0xa0,0x52,0x3b,0xd6,0xb3,0x29,0xe3,0x2f,0x84,
        0x53,0xd1,0x00,0xed,0x20,0xfc,0xb1,0x5b,0x6a,0xcb,0xbe,0x39,0x4a,0x4c,0x58,0xcf,
        0xd0,0xef,0xaa,0xfb,0x43,0x4d,0x33,0x85,0x45,0xf9,0x02,0x7f,0x50,0x3c,0x9f,0xa8,
        0x51,0xa3,0x40,0x8f,0x92,0x9d,0x38,0xf5,0xbc,0xb6,0xda,0x21,0x10,0xff,0xf3,0xd2,
        0xcd,0x0c,0x13,0xec,0x5f,0x97,0x44,0x17,0xc4,0xa7,0x7e,0x3d,0x64,0x5d,0x19,0x73,
        0x60,0x81,0x4f,0xdc,0x22,0x2a,0x90,0x88,0x46,0xee,0xb8,0x14,0xde,0x5e,0x0b,0xdb,
        0xe0,0x32,0x3a,0x0a,0x49,0x06,0x24,0x5c,0xc2,0xd3,0xac,0x62,0x91,0x95,0xe4,0x79,
        0xe7,0xc8,0x37,0x6d,0x8d,0xd5,0x4e,0xa9,0x6c,0x56,0xf4,0xea,0x65,0x7a,0xae,0x08,
        0xba,0x78,0x25,0x2e,0x1c,0xa6,0xb4,0xc6,0xe8,0xdd,0x74,0x1f,0x4b,0xbd,0x8b,0x8a,
        0x70,0x3e,0xb5,0x66,0x48,0x03,0xf6,0x0e,0x61,0x35,0x57,0xb9,0x86,0xc1,0x1d,0x9e,
        0xe1,0xf8,0x98,0x11,0x69,0xd9,0x8e,0x94,0x9b,0x1e,0x87,0xe9,0xce,0x55,0x28,0xdf,
        0x8c,0xa1,0x89,0x0d,0xbf,0xe6,0x42,0x68,0x41,0x99,0x2d,0x0f,0xb0,0x54,0xbb,0x16,
    ];
    private const INV_SBOX = [
        0x52,0x09,0x6a,0xd5,0x30,0x36,0xa5,0x38,0xbf,0x40,0xa3,0x9e,0x81,0xf3,0xd7,0xfb,
        0x7c,0xe3,0x39,0x82,0x9b,0x2f,0xff,0x87,0x34,0x8e,0x43,0x44,0xc4,0xde,0xe9,0xcb,
        0x54,0x7b,0x94,0x32,0xa6,0xc2,0x23,0x3d,0xee,0x4c,0x95,0x0b,0x42,0xfa,0xc3,0x4e,
        0x08,0x2e,0xa1,0x66,0x28,0xd9,0x24,0xb2,0x76,0x5b,0xa2,0x49,0x6d,0x8b,0xd1,0x25,
        0x72,0xf8,0xf6,0x64,0x86,0x68,0x98,0x16,0xd4,0xa4,0x5c,0xcc,0x5d,0x65,0xb6,0x92,
        0x6c,0x70,0x48,0x50,0xfd,0xed,0xb9,0xda,0x5e,0x15,0x46,0x57,0xa7,0x8d,0x9d,0x84,
        0x90,0xd8,0xab,0x00,0x8c,0xbc,0xd3,0x0a,0xf7,0xe4,0x58,0x05,0xb8,0xb3,0x45,0x06,
        0xd0,0x2c,0x1e,0x8f,0xca,0x3f,0x0f,0x02,0xc1,0xaf,0xbd,0x03,0x01,0x13,0x8a,0x6b,
        0x3a,0x91,0x11,0x41,0x4f,0x67,0xdc,0xea,0x97,0xf2,0xcf,0xce,0xf0,0xb4,0xe6,0x73,
        0x96,0xac,0x74,0x22,0xe7,0xad,0x35,0x85,0xe2,0xf9,0x37,0xe8,0x1c,0x75,0xdf,0x6e,
        0x47,0xf1,0x1a,0x71,0x1d,0x29,0xc5,0x89,0x6f,0xb7,0x62,0x0e,0xaa,0x18,0xbe,0x1b,
        0xfc,0x56,0x3e,0x4b,0xc6,0xd2,0x79,0x20,0x9a,0xdb,0xc0,0xfe,0x78,0xcd,0x5a,0xf4,
        0x1f,0xdd,0xa8,0x33,0x88,0x07,0xc7,0x31,0xb1,0x12,0x10,0x59,0x27,0x80,0xec,0x5f,
        0x60,0x51,0x7f,0xa9,0x19,0xb5,0x4a,0x0d,0x2d,0xe5,0x7a,0x9f,0x93,0xc9,0x9c,0xef,
        0xa0,0xe0,0x3b,0x4d,0xae,0x2a,0xf5,0xb0,0xc8,0xeb,0xbb,0x3c,0x83,0x53,0x99,0x61,
        0x17,0x2b,0x04,0x7e,0xba,0x77,0xd6,0x26,0xe1,0x69,0x14,0x63,0x55,0x21,0x0c,0x7d,
    ];
    private const RCON = [0x01,0x02,0x04,0x08,0x10,0x20,0x40,0x80,0x1b,0x36];

    private static function xtime(int $x): int
    {
        return (($x << 1) ^ (($x & 0x80) !== 0 ? 0x1b : 0)) & 0xff;
    }

    private static function gmul(int $a, int $b): int
    {
        $p = 0;
        for ($i = 0; $i < 8; $i++) {
            if (($b & 1) !== 0) {
                $p ^= $a;
            }
            $a = self::xtime($a);
            $b >>= 1;
        }
        return $p;
    }

    /** @return list<int> */
    private static function expandKey(string $key): array
    {
        $w = array_values(unpack('C16', $key));
        for ($i = 16; $i < 176; $i += 4) {
            $t = [$w[$i - 4], $w[$i - 3], $w[$i - 2], $w[$i - 1]];
            if ($i % 16 === 0) {
                $t = [
                    self::SBOX[$t[1]] ^ self::RCON[intdiv($i, 16) - 1],
                    self::SBOX[$t[2]],
                    self::SBOX[$t[3]],
                    self::SBOX[$t[0]],
                ];
            }
            for ($j = 0; $j < 4; $j++) {
                $w[$i + $j] = $w[$i - 16 + $j] ^ $t[$j];
            }
        }
        return $w;
    }

    public static function encryptBlock(string $block, string $key): string
    {
        $w = self::expandKey($key);
        $s = array_values(unpack('C16', $block));
        for ($i = 0; $i < 16; $i++) {
            $s[$i] ^= $w[$i];
        }
        for ($round = 1; $round <= 10; $round++) {
            for ($i = 0; $i < 16; $i++) {
                $s[$i] = self::SBOX[$s[$i]];
            }
            $s = [$s[0],$s[5],$s[10],$s[15], $s[4],$s[9],$s[14],$s[3], $s[8],$s[13],$s[2],$s[7], $s[12],$s[1],$s[6],$s[11]];
            if ($round < 10) {
                for ($c = 0; $c < 16; $c += 4) {
                    [$a0, $a1, $a2, $a3] = [$s[$c], $s[$c + 1], $s[$c + 2], $s[$c + 3]];
                    $s[$c] = self::gmul($a0, 2) ^ self::gmul($a1, 3) ^ $a2 ^ $a3;
                    $s[$c + 1] = $a0 ^ self::gmul($a1, 2) ^ self::gmul($a2, 3) ^ $a3;
                    $s[$c + 2] = $a0 ^ $a1 ^ self::gmul($a2, 2) ^ self::gmul($a3, 3);
                    $s[$c + 3] = self::gmul($a0, 3) ^ $a1 ^ $a2 ^ self::gmul($a3, 2);
                }
            }
            $base = $round * 16;
            for ($i = 0; $i < 16; $i++) {
                $s[$i] ^= $w[$base + $i];
            }
        }
        return pack('C16', ...$s);
    }

    public static function decryptBlock(string $block, string $key): string
    {
        $w = self::expandKey($key);
        $s = array_values(unpack('C16', $block));
        for ($i = 0; $i < 16; $i++) {
            $s[$i] ^= $w[160 + $i];
        }
        for ($round = 9; $round >= 0; $round--) {
            $s = [$s[0],$s[13],$s[10],$s[7], $s[4],$s[1],$s[14],$s[11], $s[8],$s[5],$s[2],$s[15], $s[12],$s[9],$s[6],$s[3]];
            for ($i = 0; $i < 16; $i++) {
                $s[$i] = self::INV_SBOX[$s[$i]];
            }
            $base = $round * 16;
            for ($i = 0; $i < 16; $i++) {
                $s[$i] ^= $w[$base + $i];
            }
            if ($round > 0) {
                for ($c = 0; $c < 16; $c += 4) {
                    [$a0, $a1, $a2, $a3] = [$s[$c], $s[$c + 1], $s[$c + 2], $s[$c + 3]];
                    $s[$c] = self::gmul($a0, 14) ^ self::gmul($a1, 11) ^ self::gmul($a2, 13) ^ self::gmul($a3, 9);
                    $s[$c + 1] = self::gmul($a0, 9) ^ self::gmul($a1, 14) ^ self::gmul($a2, 11) ^ self::gmul($a3, 13);
                    $s[$c + 2] = self::gmul($a0, 13) ^ self::gmul($a1, 9) ^ self::gmul($a2, 14) ^ self::gmul($a3, 11);
                    $s[$c + 3] = self::gmul($a0, 11) ^ self::gmul($a1, 13) ^ self::gmul($a2, 9) ^ self::gmul($a3, 14);
                }
            }
        }
        return pack('C16', ...$s);
    }

    /**
     * Bluetooth Core Spec `ah(k, r)` RPA hash function: verifies if a rotating
     * BLE Resolvable Private Address matches the 16-byte Identity Resolving Key.
     */
    public static function verifyRpa(string $bleMac, string $irk): bool
    {
        if (strlen($irk) !== 16 || strlen($bleMac) !== 17) {
            return false;
        }
        $rpaBytes = AacpProtocol::macToReversedBytes($bleMac);
        $hash = substr($rpaBytes, 0, 3);
        $prand = substr($rpaBytes, 3, 3);
        $rPadded = $prand . str_repeat("\x00", 13);
        // Bluetooth e(key, data) reverses both key and 16-byte input block, then reverses output
        $enc = strrev(self::encryptBlock(strrev($rPadded), strrev($irk)));
        return substr($enc, 0, 3) === $hash;
    }

    /**
     * Generates a valid BLE RPA address for $irk and 3-byte $prand (top 2 bits of prand[2] = 0b01).
     */
    public static function generateRpa(string $irk, string $prand3 = "\x11\x22\x45"): string
    {
        $rPadded = substr($prand3, 0, 3) . str_repeat("\x00", 13);
        $hash = substr(strrev(self::encryptBlock(strrev($rPadded), strrev($irk))), 0, 3);
        return AacpProtocol::reversedBytesToMac($hash . substr($prand3, 0, 3));
    }

    /**
     * Decrypts the last 16 bytes of a 27-byte Apple 0x004C BLE packet using the
     * 16-byte ENC_KEY (Opcode 0x0031) to extract exact 1% battery levels.
     * @return ?array{leftBattery: int, rightBattery: int, caseBattery: ?int, leftCharging: bool, rightCharging: bool, caseCharging: bool, connState: string}
     */
    public static function decryptBleBattery(string $raw27, string $encKey): ?array
    {
        if (strlen($raw27) < 27 || strlen($encKey) !== 16) {
            return null;
        }
        $dec = self::decryptBlock(substr($raw27, strlen($raw27) - 16, 16), $encKey);
        $status = ord($raw27[5]);
        $connByte = ord($raw27[10]);
        $primaryLeft = (($status >> 5) & 0x01) === 1;
        $leftIdx = $primaryLeft ? 1 : 2;
        $rightIdx = $primaryLeft ? 2 : 1;

        $lByte = ord($dec[$leftIdx]);
        $rByte = ord($dec[$rightIdx]);
        $cByte = ord($dec[3]);

        $connState = match ($connByte) {
            0x00 => 'Disconnected',
            0x04 => 'Idle',
            0x05 => 'Music',
            0x06 => 'Call',
            0x07 => 'Ringing',
            default => sprintf('0x%02X', $connByte),
        };

        return [
            'leftBattery' => $lByte & 0x7F,
            'rightBattery' => $rByte & 0x7F,
            'caseBattery' => ($cByte === 0xFF || $cByte === 0x7F) ? null : ($cByte & 0x7F),
            'leftCharging' => ($lByte & 0x80) !== 0,
            'rightCharging' => ($rByte & 0x80) !== 0,
            'caseCharging' => ($cByte & 0x80) !== 0,
            'connState' => $connState,
        ];
    }
}

/**
 * Detects Head Gestures (Nod = Yes, Shake = No) from AirPods 4 H2 IMU streams
 * (Opcode 0x0017). Tracks alternating sign reversals in pitch (nodding) vs yaw
 * (shaking) within a sliding window.
 */
final class HeadGestureDetector
{
    public const NONE = '';
    public const NOD_YES = 'nod_yes';
    public const SHAKE_NO = 'shake_no';

    public const THRESHOLD = 450;

    /** @var list<array{pitch: int, yaw: int}> */
    private array $samples = [];
    public string $lastGesture = self::NONE;
    public int $nodCount = 0;
    public int $shakeCount = 0;

    public function reset(): void
    {
        $this->samples = [];
        $this->lastGesture = self::NONE;
    }

    /** Feeds a sample and returns self::NOD_YES, self::SHAKE_NO, or self::NONE. */
    public function push(int $pitch, int $yaw): string
    {
        $this->samples[] = ['pitch' => $pitch, 'yaw' => $yaw];
        if (count($this->samples) > 12) {
            array_shift($this->samples);
        }
        $pitchPeaks = $this->countAlternatingPeaks(array_column($this->samples, 'pitch'));
        $yawPeaks = $this->countAlternatingPeaks(array_column($this->samples, 'yaw'));

        if ($pitchPeaks >= 2 && $pitchPeaks > $yawPeaks) {
            $this->samples = [];
            $this->nodCount++;
            return $this->lastGesture = self::NOD_YES;
        }
        if ($yawPeaks >= 2 && $yawPeaks > $pitchPeaks) {
            $this->samples = [];
            $this->shakeCount++;
            return $this->lastGesture = self::SHAKE_NO;
        }
        return self::NONE;
    }

    /** @param list<int> $values */
    private function countAlternatingPeaks(array $values): int
    {
        $lastSign = 0;
        $flips = 0;
        foreach ($values as $v) {
            if (abs($v) < self::THRESHOLD) {
                continue;
            }
            $sign = $v > 0 ? 1 : -1;
            if ($lastSign !== 0 && $sign !== $lastSign) {
                $flips++;
            }
            $lastSign = $sign;
        }
        return $flips;
    }
}

/**
 * Result of probing whether the device's Bluetooth stack (e.g. Xiaomi 14 Ultra
 * HyperOS / Android Bluetooth APEX) allows direct unpatched L2CAP PSM 0x1001
 * connections (fixed in Android 16 QPR3 / Android 17) or blocks them in
 * Fluoride, requiring the Root L2CAP bypass.
 */
final class L2capProbeResult
{
    public const VERDICT_UNTESTED = 'untested';
    public const VERDICT_NATIVE_READY = 'native_ready';
    public const VERDICT_STACK_BLOCKED = 'stack_blocked';
    public const VERDICT_BLE_ONLY = 'ble_only';

    /**
     * @param list<string> $initialFrames
     */
    public function __construct(
        public string $verdict = self::VERDICT_UNTESTED,
        public bool $bleBeaconSeen = false,
        public bool $l2capSocketOpened = false,
        public bool $handshakeAcked = false,
        public string $detail = 'Run L2CAP 0x1001 probe to check if Root bypass is needed.',
        public array $initialFrames = [],
        public int $sdkInt = 0,
        public string $androidRelease = '',
        public string $buildId = '',
        public string $ctorSignature = '',
    ) {}

    /** Runs the 3-step L2CAP PSM 0x1001 probe against $transport. */
    public static function run(AirPodsTransport $transport, string $macAddress): self
    {
        $sdkInt = 0;
        $release = '';
        $buildId = '';
        $ctorSig = 'Simulated(L2CAP_0x1001)';

        if ($transport instanceof AndroidBluetoothTransport) {
            $st = $transport->getStatus();
            $sdkInt = $st['sdk'];
            $release = $st['release'];
            $buildId = $st['build'];
            $ctorSig = $st['ctor'];
            if ($st['mac'] !== '') {
                $macAddress = $st['mac'];
            }
            $hasA16Qpr3Fix = $sdkInt >= 37 || str_starts_with($ctorSig, 'A16QPR3_A17');
            if (!$st['perm']) {
                $transport->requestPermissions();
                return new self(
                    verdict: $hasA16Qpr3Fix ? self::VERDICT_NATIVE_READY : self::VERDICT_UNTESTED,
                    bleBeaconSeen: false,
                    l2capSocketOpened: false,
                    handshakeAcked: false,
                    detail: sprintf('Android %s (SDK %d · %s) detected [%s]. Grant Bluetooth permission & turn on Bluetooth to connect AirPods 4.', $release, $sdkInt, $buildId, $ctorSig),
                    sdkInt: $sdkInt,
                    androidRelease: $release,
                    buildId: $buildId,
                    ctorSignature: $ctorSig,
                );
            }
            if (!$st['btOn']) {
                return new self(
                    verdict: $hasA16Qpr3Fix ? self::VERDICT_NATIVE_READY : self::VERDICT_UNTESTED,
                    bleBeaconSeen: false,
                    l2capSocketOpened: false,
                    handshakeAcked: false,
                    detail: sprintf('Android %s (SDK %d · %s) L2CAP ctor [%s] is Rootless-Ready! Turn ON Bluetooth & open AirPods 4 lid to connect.', $release, $sdkInt, $buildId, $ctorSig),
                    sdkInt: $sdkInt,
                    androidRelease: $release,
                    buildId: $buildId,
                    ctorSignature: $ctorSig,
                );
            }
            if ($st['mac'] === '') {
                $ble = $transport->scanBle() !== null;
                return new self(
                    verdict: $hasA16Qpr3Fix ? self::VERDICT_NATIVE_READY : self::VERDICT_BLE_ONLY,
                    bleBeaconSeen: $ble,
                    l2capSocketOpened: false,
                    handshakeAcked: false,
                    detail: sprintf('Android %s (SDK %d) L2CAP ctor [%s] is Rootless-Ready! Pair AirPods 4 in system Bluetooth first.', $release, $sdkInt, $ctorSig),
                    sdkInt: $sdkInt,
                    androidRelease: $release,
                    buildId: $buildId,
                    ctorSignature: $ctorSig,
                );
            }
        }

        $ble = $transport->scanBle() !== null;
        if (!($transport instanceof AndroidBluetoothTransport && $transport->isConnected())) {
            try {
                $transport->connect($macAddress);
            } catch (\Throwable $e) {
                $reconnected = false;
                if ($transport instanceof AndroidBluetoothTransport) {
                    $transport->setAudioConnected($macAddress, true);
                    usleep(600000);
                    try {
                        $transport->connect($macAddress);
                        $reconnected = true;
                    } catch (\Throwable $e2) {
                        $e = $e2;
                    }
                }
                if (!$reconnected) {
                    return new self(
                        verdict: $ble ? self::VERDICT_STACK_BLOCKED : self::VERDICT_BLE_ONLY,
                        bleBeaconSeen: $ble,
                        l2capSocketOpened: false,
                        handshakeAcked: false,
                        detail: 'L2CAP PSM 0x1001 connect rejected by Bluetooth stack: ' . $e->getMessage(),
                        sdkInt: $sdkInt,
                        androidRelease: $release,
                        buildId: $buildId,
                        ctorSignature: $ctorSig,
                    );
                }
            }
        }

        try {
            $transport->send(AacpProtocol::handshakePacket());
            $transport->send(AacpProtocol::capabilitiesPacket());
            $transport->send(AacpProtocol::requestNotificationsPacket());
            $transport->send(AacpProtocol::requestProximityKeysPacket());
            $frames = $transport->poll();
            if ($frames === [] && $transport instanceof AndroidBluetoothTransport) {
                for ($i = 0; $i < 10 && $frames === []; $i++) {
                    usleep(40000);
                    $frames = $transport->poll();
                }
            }
            $acked = $frames !== [];
        } catch (\Throwable $e) {
            return new self(
                verdict: self::VERDICT_STACK_BLOCKED,
                bleBeaconSeen: $ble,
                l2capSocketOpened: true,
                handshakeAcked: false,
                detail: 'L2CAP socket opened, but AACP handshake failed: ' . $e->getMessage(),
                sdkInt: $sdkInt,
                androidRelease: $release,
                buildId: $buildId,
                ctorSignature: $ctorSig,
            );
        }

        if (!$acked) {
            return new self(
                verdict: self::VERDICT_STACK_BLOCKED,
                bleBeaconSeen: $ble,
                l2capSocketOpened: true,
                handshakeAcked: false,
                detail: 'L2CAP 0x1001 connected, but AirPods sent 0 AACP frames (blocked by Fluoride stack). Enable Root L2CAP patch.',
                sdkInt: $sdkInt,
                androidRelease: $release,
                buildId: $buildId,
                ctorSignature: $ctorSig,
            );
        }

        return new self(
            verdict: self::VERDICT_NATIVE_READY,
            bleBeaconSeen: $ble,
            l2capSocketOpened: true,
            handshakeAcked: true,
            detail: 'Direct L2CAP PSM 0x1001 + AACP handshake succeeded without Root patch!',
            initialFrames: $frames,
            sdkInt: $sdkInt,
            androidRelease: $release,
            buildId: $buildId,
            ctorSignature: $ctorSig,
        );
    }
}

/**
 * Platform-agnostic Bluetooth transport contract.
 * On Android (Xiaomi 14 Ultra), implemented via FFI -> JNI BluetoothLeScanner
 * and BluetoothDevice.createInsecureL2capChannel(0x1001).
 */
interface AirPodsTransport
{
    public function connect(string $macAddress): void;
    public function disconnect(): void;
    public function isConnected(): bool;
    public function send(string $packet): void;
    /** @return list<string> Raw binary AACP packets received since last poll. */
    public function poll(): array;
    /** Latest 27-byte BLE 0x004C Proximity Pairing broadcast payload, if any. */
    public function scanBle(): ?string;
}

/**
 * Simulated AirPods 4 (Model A3053, Firmware 7B21, H2 chip) responder.
 * Speaks real binary AACP and BLE 0x004C packets so the full protocol stack
 * and UI run end-to-end before and after Android L2CAP transport hookup.
 */
final class SimulatedAirPods4Transport implements AirPodsTransport
{
    private bool $connected = false;
    private bool $handshakeDone = false;
    private bool $notificationsEnabled = false;
    /** @var list<string> */
    private array $rxQueue = [];

    public string $deviceName = 'AirPods 4';
    public string $modelNumber = 'A3053';
    public string $firmware = '7B21';
    public int $leftBattery = 92;
    public int $rightBattery = 88;
    public int $caseBattery = 76;
    public int $leftStatus = AacpProtocol::BAT_DISCHARGING;
    public int $rightStatus = AacpProtocol::BAT_DISCHARGING;
    public int $caseStatus = AacpProtocol::BAT_CHARGING;
    public int $primaryEar = AacpProtocol::EAR_IN_EAR;
    public int $secondaryEar = AacpProtocol::EAR_IN_EAR;
    public bool $lidOpen = true;
    public int $lidCounter = 1;
    public string $localMac = 'AA:BB:CC:14:04:01';
    public string $peerIphoneMac = 'F0:D1:A9:11:22:33';
    public string $irk = "\x10\x21\x32\x43\x54\x65\x76\x87\x98\xa9\xba\xcb\xdc\xed\xfe\x0f";
    public string $encKey = "\x2b\x7e\x15\x16\x28\xae\xd2\xa6\xab\xf7\x15\x88\x09\xcf\x4f\x3c";
    /** When true, simulates a pre-Android 16 QPR3 Fluoride stack rejecting L2CAP PSM 0x1001 without Root patch. */
    public bool $simulateStackBlocked = false;

    public function connect(string $macAddress): void
    {
        if ($this->simulateStackBlocked) {
            $this->connected = false;
            throw new \RuntimeException('BluetoothSocket: L2CAP PSM 0x1001 rejected by Fluoride stack (pre-Android 16 QPR3)');
        }
        $this->connected = true;
        $this->handshakeDone = false;
        $this->notificationsEnabled = false;
        $this->rxQueue = [];
    }

    public function disconnect(): void
    {
        $this->connected = false;
        $this->handshakeDone = false;
        $this->notificationsEnabled = false;
        $this->rxQueue = [];
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }

    public function send(string $packet): void
    {
        if (!$this->connected) {
            throw new InvalidArgumentException('Cannot send AACP packet while disconnected');
        }
        $decoded = AacpProtocol::decode($packet);
        switch ($decoded['type']) {
            case 'handshake':
                $this->handshakeDone = true;
                break;

            case 'notification_register':
                if ($this->handshakeDone) {
                    $this->notificationsEnabled = true;
                    // AirPods immediately emit Device Metadata, Battery, Ear Detection, and Connected Devices upon registration.
                    $this->rxQueue[] = AacpProtocol::encodeMetadataPacket(
                        name: $this->deviceName,
                        modelNumber: $this->modelNumber,
                        firmware: $this->firmware,
                    );
                    $this->pushBatteryReport();
                    $this->pushEarReport();
                    $this->rxQueue[] = AacpProtocol::encodeConnectedDevicesPacket([
                        ['mac' => $this->localMac, 'info1' => 1, 'info2' => 0],
                        ['mac' => $this->peerIphoneMac, 'info1' => 2, 'info2' => 6],
                    ]);
                    $this->rxQueue[] = AacpProtocol::encodeAudioSourcePacket($this->peerIphoneMac, AacpProtocol::AUDIO_MEDIA);
                }
                break;

            case 'control':
                // AirPods echo back the 0x0009 control packet once applied.
                if ($this->handshakeDone) {
                    $this->rxQueue[] = $packet;
                    if (($decoded['identifier'] ?? 0) === AacpProtocol::CMD_OWNS_CONNECTION && ($decoded['data'][0] ?? 0) === 0x01) {
                        $this->rxQueue[] = AacpProtocol::encodeAudioSourcePacket($this->localMac, AacpProtocol::AUDIO_MEDIA);
                    }
                }
                break;

            case 'smart_routing':
                if ($this->handshakeDone && str_contains($packet, 'ReverseBannerTapped')) {
                    $this->rxQueue[] = AacpProtocol::encodeAudioSourcePacket($this->peerIphoneMac, AacpProtocol::AUDIO_MEDIA);
                }
                break;

            case 'proximity_keys_req':
                if ($this->handshakeDone) {
                    $this->rxQueue[] = AacpProtocol::encodeProximityKeysPacket($this->irk, $this->encKey);
                }
                break;

            case 'rename':
                if ($this->handshakeDone && isset($decoded['name']) && $decoded['name'] !== '') {
                    $this->deviceName = $decoded['name'];
                    $this->rxQueue[] = AacpProtocol::encodeMetadataPacket(
                        name: $this->deviceName,
                        modelNumber: $this->modelNumber,
                        firmware: $this->firmware,
                    );
                }
                break;
        }
    }

    public function poll(): array
    {
        $out = $this->rxQueue;
        $this->rxQueue = [];
        return $out;
    }

    public function scanBle(): ?string
    {
        return AirPodsBleBroadcast::encode(
            modelId: AirPodsBleBroadcast::MODEL_AIRPODS_4,
            leftBattery: $this->leftBattery,
            rightBattery: $this->rightBattery,
            caseBattery: $this->caseBattery,
            leftCharging: $this->leftStatus === AacpProtocol::BAT_CHARGING,
            rightCharging: $this->rightStatus === AacpProtocol::BAT_CHARGING,
            caseCharging: $this->caseStatus === AacpProtocol::BAT_CHARGING,
            leftInEar: $this->primaryEar === AacpProtocol::EAR_IN_EAR,
            rightInEar: $this->secondaryEar === AacpProtocol::EAR_IN_EAR,
            lidOpen: $this->lidOpen,
            lidCounter: $this->lidCounter,
        );
    }

    public function pushBatteryReport(): void
    {
        if (!$this->connected || !$this->notificationsEnabled) {
            return;
        }
        $this->rxQueue[] = AacpProtocol::encodeBatteryPacket([
            ['component' => AacpProtocol::COMP_LEFT, 'level' => $this->leftBattery, 'status' => $this->leftStatus],
            ['component' => AacpProtocol::COMP_RIGHT, 'level' => $this->rightBattery, 'status' => $this->rightStatus],
            ['component' => AacpProtocol::COMP_CASE, 'level' => $this->caseBattery, 'status' => $this->caseStatus],
        ]);
    }

    public function pushEarReport(): void
    {
        if (!$this->connected || !$this->notificationsEnabled) {
            return;
        }
        $this->rxQueue[] = AacpProtocol::encodeEarDetectionPacket($this->primaryEar, $this->secondaryEar);
    }

    /** Injects a simulated head gesture (Nod Yes or Shake No) as raw 0x0017 IMU packets. */
    public function simulateHeadGesture(string $gesture): void
    {
        if (!$this->connected) {
            return;
        }
        $sequence = $gesture === HeadGestureDetector::NOD_YES
            ? [[650, 20], [-680, -15], [640, 10]]
            : [[20, 660], [-15, -690], [10, 650]];
        foreach ($sequence as [$pitch, $yaw]) {
            $this->rxQueue[] = AacpProtocol::encodeHeadTrackingPacket($pitch, $yaw, 0);
        }
    }
}

/**
 * Real Android Bluetooth transport over MyPHP\Native\Android FFI -> JNI:
 * - Probes BluetoothSocket L2CAP constructor signature in ART (Android 16 QPR3 / 17 vs older)
 * - Scans Apple Continuity BLE 0x004C Proximity Pairing broadcasts
 * - Connects Classic BR/EDR L2CAP socket on PSM 0x1001 (4097) with UUID
 *   74ec2172-0bad-4d01-8f77-997b2be0722a for AirPods 4 AACP control.
 */
final class AndroidBluetoothTransport implements AirPodsTransport
{
    public function requestPermissions(): void
    {
        \MyPHP\Native\Android::ffi()->myphp_bt_request_permissions();
    }

    public function startPairing(): void
    {
        \MyPHP\Native\Android::ffi()->myphp_bt_start_pairing();
    }

    public function setAudioConnected(string $macAddress, bool $connect): void
    {
        \MyPHP\Native\Android::ffi()->myphp_bt_set_audio_connected($macAddress, $connect ? 1 : 0);
    }

    /**
     * @return array{sdk: int, release: string, build: string, perm: bool, btOn: bool, ctor: string, vendorId: string, localMac: string, mac: string, name: string, bleMac: string, rssi: int, audio: bool, audioSeq: int, pair: string, err: string}
     */
    public function getStatus(): array
    {
        $f = \MyPHP\Native\Android::ffi();
        $buf = $f->new('char[1024]');
        $len = $f->myphp_bt_get_status(\FFI::addr($buf[0]), 1024);
        $raw = $len > 0 ? \FFI::string(\FFI::addr($buf[0]), $len) : '';
        $kv = [];
        foreach (explode('|', $raw) as $part) {
            if (($eq = strpos($part, '=')) !== false) {
                $kv[substr($part, 0, $eq)] = substr($part, $eq + 1);
            }
        }
        return [
            'sdk' => (int)($kv['sdk'] ?? 0),
            'release' => $kv['release'] ?? '',
            'build' => $kv['build'] ?? '',
            'perm' => ($kv['perm'] ?? '0') === '1',
            'btOn' => ($kv['btOn'] ?? '0') === '1',
            'ctor' => $kv['ctor'] ?? '',
            'vendorId' => $kv['vendorId'] ?? '',
            'localMac' => $kv['localMac'] ?? '',
            'mac' => $kv['mac'] ?? '',
            'name' => $kv['name'] ?? '',
            'bleMac' => $kv['bleMac'] ?? '',
            'rssi' => (int)($kv['rssi'] ?? -100),
            'audio' => ($kv['audio'] ?? '0') === '1',
            'audioSeq' => (int)($kv['audioSeq'] ?? 0),
            'aclSeq' => (int)($kv['aclSeq'] ?? 0),
            'pair' => $kv['pair'] ?? 'idle',
            'err' => $kv['err'] ?? '',
        ];
    }

    public function connect(string $macAddress): void
    {
        $f = \MyPHP\Native\Android::ffi();
        $errBuf = $f->new('char[512]');
        $ok = $f->myphp_bt_connect_l2cap($macAddress, AacpProtocol::L2CAP_PSM, \FFI::addr($errBuf[0]), 512);
        if ($ok === 0) {
            $err = \FFI::string(\FFI::addr($errBuf[0]));
            throw new \RuntimeException($err !== '' ? $err : 'Failed to connect L2CAP PSM 0x1001');
        }
    }

    public function disconnect(): void
    {
        \MyPHP\Native\Android::ffi()->myphp_bt_disconnect();
    }

    public function isConnected(): bool
    {
        return \MyPHP\Native\Android::ffi()->myphp_bt_is_connected() !== 0;
    }

    public function send(string $packet): void
    {
        $len = strlen($packet);
        if ($len === 0) {
            return;
        }
        $f = \MyPHP\Native\Android::ffi();
        $buf = $f->new("uint8_t[$len]");
        \FFI::memcpy(\FFI::addr($buf[0]), $packet, $len);
        $ok = $f->myphp_bt_send(\FFI::addr($buf[0]), $len);
        if ($ok === 0) {
            throw new \RuntimeException('L2CAP send failed');
        }
    }

    public function poll(): array
    {
        $f = \MyPHP\Native\Android::ffi();
        $buf = $f->new('uint8_t[4096]');
        $frames = [];
        while (($n = $f->myphp_bt_poll(\FFI::addr($buf[0]), 4096)) > 0) {
            $frames[] = \FFI::string(\FFI::addr($buf[0]), $n);
        }
        return $frames;
    }

    public function scanBle(): ?string
    {
        $f = \MyPHP\Native\Android::ffi();
        $buf = $f->new('uint8_t[64]');
        $n = $f->myphp_bt_scan_ble(\FFI::addr($buf[0]), 64);
        if ($n < 27) {
            return null;
        }
        return \FFI::string(\FFI::addr($buf[0]), 27);
    }
}

