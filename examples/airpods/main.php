<?php
// AirPods 4 companion for Xiaomi 14 Ultra in MyPHP native UI:
// 1. Probes whether the Android Bluetooth stack has the Android 16 QPR3 / 17
//    L2CAP PSM 0x1001 fix (direct rootless connection) or blocks 0x1001 in
//    Fluoride (requiring the Root L2CAP bypass).
// 2. Decodes Apple BLE 0x004C Proximity Pairing broadcasts and drives the full
//    AACP binary protocol over L2CAP 0x1001 for standard AirPods 4 (A3053).
// Run: ./run.sh airpods
declare(strict_types=1);

if (!defined('STDIN')) { define('STDIN', fopen('php://stdin', 'r')); }
if (!defined('STDOUT')) { define('STDOUT', fopen('php://stdout', 'w')); }
if (!defined('STDERR')) { define('STDERR', fopen('php://stderr', 'w')); }

if (file_exists(__DIR__ . '/src/Native/LocalePolyfill.php')) {
    require_once __DIR__ . '/src/Native/LocalePolyfill.php';
}

use MyPHP\{App, Window, WindowOptions};
use MyPHP\Examples\Airpods\{
    AacpProtocol,
    AirPodsBleBroadcast,
    AirPodsCrypto,
    AirPodsTransport,
    AndroidBluetoothTransport,
    HeadGestureDetector,
    L2capProbeResult,
    SimulatedAirPods4Transport,
};
use MyPHP\UI\{Content, Context, Font, ListState, Node, ScrollState, TextState};

spl_autoload_register(function ($c) {
    if (str_starts_with($c, 'MyPHP\\') && !str_starts_with($c, 'MyPHP\\Examples\\')) {
        $rel = str_replace('\\', '/', substr($c, 6)) . '.php';
        $androidPath = __DIR__ . '/src/' . $rel;
        if (file_exists($androidPath)) {
            require $androidPath;
            return;
        }
        $desktopPath = __DIR__ . '/../../src/' . $rel;
        if (file_exists($desktopPath)) {
            require $desktopPath;
        }
    }
});
require_once __DIR__ . '/Protocol.php';

final class AirPodsApp
{
    public const LISTENING_MODES = ['Off', 'ANC', 'Transp.', 'Adaptive'];
    public const MIC_MODES = ['Auto', 'Right', 'Left'];
    public const CALL_END_MODES = ['Single', 'Double'];
    public const SPEED_MODES = ['Default', 'Slower', 'Slowest'];
    public const HOLD_MODES = ['Default', 'Shorter', 'Shortest'];

    public SimulatedAirPods4Transport $sim;
    public ?AndroidBluetoothTransport $androidBt = null;
    public AirPodsTransport $transport;
    public bool $useSimulator = true;
    public L2capProbeResult $probe;
    public HeadGestureDetector $gestures;

    public string $macAddress = 'AA:BB:CC:14:04:01';
    public string $deviceName = 'AirPods 4';
    public string $modelNumber = 'A3053';
    public string $firmware = '7B21';
    public string $serial = 'H0Y4AIRPODS4';

    public ?int $leftBattery = null;
    public ?int $rightBattery = null;
    public ?int $caseBattery = null;
    public int $leftStatus = AacpProtocol::BAT_UNKNOWN;
    public int $rightStatus = AacpProtocol::BAT_UNKNOWN;
    public int $caseStatus = AacpProtocol::BAT_UNKNOWN;

    public int $primaryEar = AacpProtocol::EAR_OUT_OF_EAR;
    public int $secondaryEar = AacpProtocol::EAR_OUT_OF_EAR;
    public bool $lidOpen = false;
    public bool $popupOpen = false;
    public bool $popupDismissed = false;
    public bool $isBonded = false;
    public int $bleRssi = -100;
    public string $pairState = 'idle';
    public bool $blePairingMode = false;

    // Multi-device TiPi / Smart Routing Handoff & 3-Device Rotation state
    public string $vendorId = '0x004C';
    public string $localMac = 'AA:BB:CC:14:04:01';
    public bool $ownsConnection = false;
    public bool $autoTakeoverEnabled = true;
    public bool $localAudioPlaying = false;
    private int $lastAudioSeq = 0;
    private int $lastAclSeq = 0;
    public string $audioSourceMac = '';
    public int $audioSourceType = AacpProtocol::AUDIO_NONE;
    /** @var list<array{mac: string, info1: int, info2: int, type: string}> */
    public array $connectedDevices = [];
    /** @var array<string, bool> */
    private array $announcedPeers = [];
    /** @var array<string, string> */
    private array $peerTypes = [];

    // Apple Magic Pairing Proximity Keys (Opcode 0x0030 / 0x0031: IRK + ENC_KEY)
    public ?string $irk = null;
    public ?string $encKey = null;
    public string $bleMac = '';
    public bool $rpaVerified = false;
    public string $bleConnState = '';

    // AirPods 4 configuration states (Opcode 0x0009)
    public int $listeningMode = 1; // 0=Off (0x01), 1=ANC (0x02), 2=Transparency (0x03), 3=Adaptive (0x04)
    public bool $conversationalAwareness = false;
    public bool $earDetectionEnabled = true;
    public bool $autoConnectEnabled = true;
    public bool $sleepDetectionEnabled = false;
    public int $micMode = 0;       // 0=Auto, 1=Right, 2=Left
    public int $endCallMode = 0;   // 0=Single (0x01), 1=Double (0x02)
    public int $pressSpeed = 0;    // 0..2
    public int $holdDuration = 0;  // 0..2
    public bool $headTrackingActive = false;
    public int $lastPitch = 0;
    public int $lastYaw = 0;

    public TextState $renameInput;
    public ListState $packetList;
    public ScrollState $pageScroll;
    /** @var list<array{dir: string, summary: string, hex: string}> */
    public array $packets = [];
    private ?string $lastBleRaw = null;

    public function __construct(?SimulatedAirPods4Transport $sim = null)
    {
        $this->sim = $sim ?? new SimulatedAirPods4Transport();
        if (getenv('MYPHP_PLATFORM') === 'android') {
            $this->androidBt = new AndroidBluetoothTransport();
            $this->transport = $this->androidBt;
            $this->useSimulator = false;
        } else {
            $this->transport = $this->sim;
            $this->useSimulator = true;
        }
        $this->probe = new L2capProbeResult();
        $this->gestures = new HeadGestureDetector();
        $this->renameInput = new TextState($this->deviceName);
        $this->packetList = new ListState();
        $this->pageScroll = new ScrollState();
        if ($this->useSimulator) {
            $this->runProbe();
        } elseif ($this->androidBt !== null) {
            $st = $this->androidBt->getStatus();
            $this->vendorId = $st['vendorId'] !== '' ? $st['vendorId'] : $this->vendorId;
            $this->localMac = $st['localMac'] !== '' ? $st['localMac'] : $this->localMac;
            $this->macAddress = $st['mac'] !== '' ? $st['mac'] : $this->macAddress;
            $this->deviceName = $st['name'] !== '' ? $st['name'] : $this->deviceName;
            $this->renameInput->text = $this->deviceName;
            $this->isBonded = $st['mac'] !== '';
            $this->probe = new L2capProbeResult(
                verdict: L2capProbeResult::VERDICT_NATIVE_READY,
                bleBeaconSeen: false,
                l2capSocketOpened: false,
                handshakeAcked: false,
                detail: sprintf('Android %s (SDK %d) Ready · Open lid or tap Probe L2CAP.', $st['release'], $st['sdk']),
                sdkInt: $st['sdk'],
                androidRelease: $st['release'],
                buildId: $st['build'],
                ctorSignature: $st['ctor'],
            );
            $this->logPacket('SYS', sprintf('SDK %d (%s) ctor=%s', $st['sdk'], $st['release'], $st['ctor']), $st['build']);
        }
    }

    public function setSimulatorMode(bool $simMode): void
    {
        $this->useSimulator = $simMode || $this->androidBt === null;
        $this->transport = $this->useSimulator ? $this->sim : $this->androidBt;
        $this->runProbe();
    }

    public function startPairing(): void
    {
        if ($this->transport instanceof AndroidBluetoothTransport) {
            $this->pairState = 'searching';
            $this->logPacket('SYS', 'Starting Bluetooth discovery & bond for AirPods 4', '');
            $this->transport->startPairing();
        } else {
            $this->isBonded = true;
            $this->pairState = 'bonded:' . $this->macAddress;
            $this->runProbe();
        }
    }

    /**
     * Step 1: Probes whether the phone's Bluetooth stack allows direct L2CAP
     * PSM 0x1001 connections (Android 16 QPR3 / 17 fix) or blocks them.
     */
    public function runProbe(): void
    {
        if ($this->transport instanceof AndroidBluetoothTransport) {
            $st = $this->transport->getStatus();
            $this->bleRssi = $st['rssi'];
            $this->pairState = $st['pair'];
            $this->isBonded = $st['mac'] !== '';
            if ($st['vendorId'] !== '') {
                $this->vendorId = $st['vendorId'];
            }
            if ($st['localMac'] !== '') {
                $this->localMac = $st['localMac'];
            }
            if ($st['mac'] !== '') {
                $this->macAddress = $st['mac'];
            }
            if ($st['name'] !== '') {
                $this->deviceName = $st['name'];
                $this->renameInput->text = $this->deviceName;
            }
        } else {
            $this->isBonded = true;
            $this->vendorId = '0x004C';
            $this->localMac = $this->sim->localMac;
        }

        $bleRaw = $this->transport->scanBle();
        if ($bleRaw !== null) {
            $ble = AirPodsBleBroadcast::decode($bleRaw);
            $this->logPacket('BLE', sprintf('%s (0x%04X) Lid:%s L:%s%% R:%s%% C:%s%%', $ble->modelName, $ble->modelId, $ble->lidOpen ? 'Open' : 'Closed', (string)($ble->leftBattery ?? '-'), (string)($ble->rightBattery ?? '-'), (string)($ble->caseBattery ?? '-')), AacpProtocol::toHex($bleRaw));
            $this->lidOpen = $ble->lidOpen;
            $this->blePairingMode = $ble->pairingMode;
            $this->leftBattery ??= $ble->leftBattery;
            $this->rightBattery ??= $ble->rightBattery;
            $this->caseBattery ??= $ble->caseBattery;
        }

        $this->announcedPeers = [];
        $this->probe = L2capProbeResult::run($this->transport, $this->macAddress);
        if ($this->probe->ctorSignature !== '') {
            $this->logPacket('SYS', sprintf('SDK %d (%s) ctor=%s', $this->probe->sdkInt, $this->probe->androidRelease ?: 'host', $this->probe->ctorSignature), $this->probe->buildId);
        }
        if ($this->probe->l2capSocketOpened) {
            $this->logPacket('TX', 'Handshake (0x0001)', AacpProtocol::toHex(AacpProtocol::handshakePacket()));
            $this->logPacket('TX', 'Host Capabilities (0x004D)', AacpProtocol::toHex(AacpProtocol::capabilitiesPacket()));
            $this->logPacket('TX', 'Notification Register (0x000F)', AacpProtocol::toHex(AacpProtocol::requestNotificationsPacket()));
            $this->logPacket('TX', 'Request Proximity Keys (0x0030)', AacpProtocol::toHex(AacpProtocol::requestProximityKeysPacket()));
        }
        foreach ($this->probe->initialFrames as $frame) {
            $this->handleIncomingFrame($frame);
        }
    }

    public function sendControl(int $identifier, int $d1, int $d2 = 0, string $label = 'Control'): void
    {
        if (!$this->transport->isConnected()) {
            return;
        }
        $pkt = AacpProtocol::controlPacket($identifier, $d1, $d2);
        $this->logPacket('TX', sprintf('%s (0x09 id=0x%02X d1=0x%02X)', $label, $identifier, $d1), AacpProtocol::toHex($pkt));
        $this->transport->send($pkt);
        $this->pollTransport();
    }

    public function rename(string $newName): void
    {
        $newName = trim($newName);
        if ($newName === '' || !$this->transport->isConnected()) {
            return;
        }
        $pkt = AacpProtocol::renamePacket($newName);
        $this->logPacket('TX', sprintf('Rename -> "%s" (0x001A)', $newName), AacpProtocol::toHex($pkt));
        $this->transport->send($pkt);
        $this->pollTransport();
    }

    public function setHeadTracking(bool $active): void
    {
        if (!$this->transport->isConnected()) {
            return;
        }
        $this->headTrackingActive = $active;
        $pkt = $active ? AacpProtocol::startHeadTrackingPacket() : AacpProtocol::stopHeadTrackingPacket();
        $this->logPacket('TX', $active ? 'Start Head Tracking (0x0017)' : 'Stop Head Tracking (0x0017)', AacpProtocol::toHex($pkt));
        $this->transport->send($pkt);
        if (!$active) {
            $this->gestures->reset();
        }
        $this->pollTransport();
    }

    public function triggerSimulatedGesture(string $gesture): void
    {
        if (!$this->transport->isConnected()) {
            return;
        }
        if (!$this->headTrackingActive) {
            $this->setHeadTracking(true);
        }
        $this->sim->simulateHeadGesture($gesture);
        $this->pollTransport();
    }

    /** Returns the MAC address of the active peer device (e.g. iPhone/Mac) connected simultaneously via TiPi, if any. */
    public function peerMac(): ?string
    {
        foreach ($this->connectedDevices as $dev) {
            if ($dev['info1'] > 0 && strcasecmp($dev['mac'], $this->localMac) !== 0) {
                return $dev['mac'];
            }
        }
        foreach ($this->connectedDevices as $dev) {
            if (strcasecmp($dev['mac'], $this->localMac) !== 0) {
                return $dev['mac'];
            }
        }
        return null;
    }

    /**
     * Claims audio ownership from the peer iPhone (Smart Routing / TiPi Hijackv2)
     * and shows the "Moved to Android [Move back to iPhone]" banner on the iPhone.
     */
    public function takeOverFromPeer(): void
    {
        if (!$this->transport->isConnected()) {
            return;
        }
        $this->ownsConnection = true;
        $ownPkt = AacpProtocol::ownsConnectionPacket(true);
        $this->logPacket('TX', 'Claim Ownership (0x09 id=0x06 val=0x01)', AacpProtocol::toHex($ownPkt));
        $this->transport->send($ownPkt);

        $peer = $this->peerMac();
        if ($peer !== null) {
            $mediaPkt = AacpProtocol::mediaInformationPacket($this->localMac, $peer, true);
            $uiPkt = AacpProtocol::smartRoutingShowUiPacket($peer);
            $hijackPkt = AacpProtocol::hijackRequestPacket($peer);
            $this->logPacket('TX', sprintf('TiPi MediaInfo -> %s (0x0010)', $peer), AacpProtocol::toHex($mediaPkt));
            $this->logPacket('TX', sprintf('TiPi ShowNearbyUI -> %s (0x0010)', $peer), AacpProtocol::toHex($uiPkt));
            $this->logPacket('TX', sprintf('TiPi Hijackv2 -> %s (0x0010)', $peer), AacpProtocol::toHex($hijackPkt));
            $this->transport->send($mediaPkt);
            $this->transport->send($uiPkt);
            $this->transport->send($hijackPkt);
        }
        if ($this->transport instanceof AndroidBluetoothTransport) {
            $this->transport->setAudioConnected($this->macAddress, true);
        }
        $this->pollTransport();
    }

    /**
     * Releases audio ownership and hands the AirPods stream back to the peer iPhone.
     */
    public function handBackToPeer(): void
    {
        if (!$this->transport->isConnected()) {
            return;
        }
        $this->ownsConnection = false;
        $ownPkt = AacpProtocol::ownsConnectionPacket(false);
        $this->logPacket('TX', 'Release Ownership (0x09 id=0x06 val=0x00)', AacpProtocol::toHex($ownPkt));
        $this->transport->send($ownPkt);

        $peer = $this->peerMac();
        if ($peer !== null) {
            $mediaPkt = AacpProtocol::mediaInformationPacket($this->localMac, $peer, false);
            $revPkt = AacpProtocol::hijackReversedPacket($peer);
            $this->logPacket('TX', sprintf('TiPi HandBack -> %s (0x0010)', $peer), AacpProtocol::toHex($revPkt));
            $this->transport->send($mediaPkt);
            $this->transport->send($revPkt);
        }
        if ($this->transport instanceof AndroidBluetoothTransport) {
            $this->transport->setAudioConnected($this->macAddress, false);
        }
        $this->pollTransport();
    }

    /**
     * Triggered automatically when any app on Xiaomi 14 Ultra starts playing
     * media or voice audio:
     * - If in a 2-device Multipoint session (already connected on L2CAP 0x1001),
     *   immediately sends TiPi Hijackv2 to take over audio from iPhone/Mac.
     * - If in a 3-device setup (Mac + iPhone held the 2 slots, so Xiaomi 14U was
     *   in standby), pulls Xiaomi 14U into an active Multipoint slot, reconnects
     *   L2CAP 0x1001, and takes over audio.
     */
    public function onLocalAudioStarted(): void
    {
        if (!$this->autoTakeoverEnabled) {
            return;
        }
        $this->localAudioPlaying = true;
        $this->logPacket('SYS', 'Local audio playback started -> Auto-Takeover', '');
        if (!$this->transport->isConnected() && $this->isBonded && $this->macAddress !== '') {
            if ($this->transport instanceof AndroidBluetoothTransport) {
                $this->transport->setAudioConnected($this->macAddress, true);
            }
            $this->runProbe();
        }
        if ($this->transport->isConnected()) {
            $this->takeOverFromPeer();
        }
    }

    public function pollTransport(): void
    {
        if ($this->transport instanceof AndroidBluetoothTransport) {
            $st = $this->transport->getStatus();
            $this->bleRssi = $st['rssi'];
            if ($st['bleMac'] !== '') {
                $this->bleMac = $st['bleMac'];
                if ($this->irk !== null) {
                    $this->rpaVerified = AirPodsCrypto::verifyRpa($this->bleMac, $this->irk);
                }
            }
            if ($st['vendorId'] !== '') {
                $this->vendorId = $st['vendorId'];
            }
            if ($st['localMac'] !== '') {
                $this->localMac = $st['localMac'];
            }
            $prevBonded = $this->isBonded;
            $this->isBonded = $st['mac'] !== '';
            if ($st['mac'] !== '') {
                $this->macAddress = $st['mac'];
            }
            if ($st['name'] !== '') {
                $this->deviceName = $st['name'];
            }
            if ($st['pair'] !== $this->pairState) {
                $this->pairState = $st['pair'];
                $this->logPacket('SYS', 'Pairing state: ' . $this->pairState, '');
                if (str_starts_with($this->pairState, 'bonded:') && !$this->transport->isConnected()) {
                    $this->runProbe();
                }
            } elseif (!$prevBonded && $this->isBonded && !$this->transport->isConnected()) {
                $this->runProbe();
            }

            if ($st['aclSeq'] > $this->lastAclSeq) {
                $this->lastAclSeq = $st['aclSeq'];
                if ($this->isBonded && !$this->transport->isConnected()) {
                    $this->runProbe();
                }
            }

            $wasPlaying = $this->localAudioPlaying;
            $this->localAudioPlaying = $st['audio'];
            if (($st['audioSeq'] > $this->lastAudioSeq) || ($st['audio'] && !$wasPlaying)) {
                $this->lastAudioSeq = $st['audioSeq'];
                $this->onLocalAudioStarted();
            }

            $bleRaw = $this->transport->scanBle();
            if ($bleRaw !== null && $bleRaw !== $this->lastBleRaw) {
                $this->lastBleRaw = $bleRaw;
                $ble = AirPodsBleBroadcast::decode($bleRaw);
                $this->probe->bleBeaconSeen = true;
                $wasClosed = !$this->lidOpen;
                $this->lidOpen = $ble->lidOpen;
                $this->blePairingMode = $ble->pairingMode;
                if (!$ble->lidOpen && !$this->transport->isConnected()) {
                    $this->popupDismissed = false;
                } elseif ($wasClosed && !$this->popupDismissed && $this->bleRssi >= -75) {
                    $this->popupOpen = true;
                    if ($this->isBonded && !$this->transport->isConnected()) {
                        $this->runProbe();
                    }
                }
                $decBat = ($this->encKey !== null && !$ble->pairingMode)
                    ? AirPodsCrypto::decryptBleBattery($bleRaw, $this->encKey)
                    : null;
                if ($decBat !== null) {
                    $this->bleConnState = $decBat['connState'];
                }
                if (!$this->transport->isConnected() && !$ble->pairingMode) {
                    $this->leftBattery = $decBat['leftBattery'] ?? $ble->leftBattery;
                    $this->rightBattery = $decBat['rightBattery'] ?? $ble->rightBattery;
                    $this->caseBattery = $decBat['caseBattery'] ?? $ble->caseBattery;
                    $lChg = $decBat['leftCharging'] ?? $ble->leftCharging;
                    $rChg = $decBat['rightCharging'] ?? $ble->rightCharging;
                    $cChg = $decBat['caseCharging'] ?? $ble->caseCharging;
                    $this->leftStatus = $lChg ? AacpProtocol::BAT_CHARGING : AacpProtocol::BAT_DISCHARGING;
                    $this->rightStatus = $rChg ? AacpProtocol::BAT_CHARGING : AacpProtocol::BAT_DISCHARGING;
                    $this->caseStatus = $cChg ? AacpProtocol::BAT_CHARGING : AacpProtocol::BAT_DISCHARGING;
                    $this->primaryEar = $ble->leftInEar ? AacpProtocol::EAR_IN_EAR : AacpProtocol::EAR_OUT_OF_EAR;
                    $this->secondaryEar = $ble->rightInEar ? AacpProtocol::EAR_IN_EAR : AacpProtocol::EAR_OUT_OF_EAR;
                }
                $this->logPacket('BLE', sprintf('%s (0x%04X)%s Lid:%s L:%s%% R:%s%% C:%s%%', $ble->modelName, $ble->modelId, $ble->pairingMode ? ' [PAIRING]' : '', $ble->lidOpen ? 'Open' : 'Closed', (string)($this->leftBattery ?? '-'), (string)($this->rightBattery ?? '-'), (string)($this->caseBattery ?? '-')), AacpProtocol::toHex($bleRaw));
            }
        }
        foreach ($this->transport->poll() as $frame) {
            $this->handleIncomingFrame($frame);
        }
    }

    private function handleIncomingFrame(string $frame): void
    {
        if (!$this->probe->handshakeAcked) {
            $this->probe->l2capSocketOpened = true;
            $this->probe->handshakeAcked = true;
            $this->probe->verdict = L2capProbeResult::VERDICT_NATIVE_READY;
            $this->probe->detail = 'Direct L2CAP 0x1001 + AACP handshake OK (Rootless)!';
        }
        $d = AacpProtocol::decode($frame);
        $hex = AacpProtocol::toHex($frame);
        switch ($d['type']) {
            case 'metadata':
                $this->deviceName = $d['name'] !== '' ? $d['name'] : $this->deviceName;
                $this->modelNumber = $d['modelNumber'] !== '' ? $d['modelNumber'] : $this->modelNumber;
                $this->firmware = $d['firmware'] !== '' ? $d['firmware'] : $this->firmware;
                $this->serial = $d['serial'] !== '' ? $d['serial'] : $this->serial;
                $this->renameInput->text = $this->deviceName;
                $this->logPacket('RX', sprintf('Device Info: %s (%s fw %s)', $this->deviceName, $this->modelNumber, $this->firmware), $hex);
                // Re-send notification & proximity key requests once 0x001D confirms handshake completion on real hardware
                if ($this->transport instanceof AndroidBluetoothTransport && $this->irk === null && $this->transport->isConnected()) {
                    $this->transport->send(AacpProtocol::capabilitiesPacket());
                    $this->transport->send(AacpProtocol::requestNotificationsPacket());
                    $this->transport->send(AacpProtocol::extCapabilitiesPacket());
                    $this->transport->send(AacpProtocol::requestProximityKeysPacket());
                }
                break;

            case 'battery':
                $bats = $d['batteries'] ?? [];
                if (isset($bats[AacpProtocol::COMP_LEFT])) {
                    $this->leftBattery = $bats[AacpProtocol::COMP_LEFT]['level'];
                    $this->leftStatus = $bats[AacpProtocol::COMP_LEFT]['status'];
                }
                if (isset($bats[AacpProtocol::COMP_RIGHT])) {
                    $this->rightBattery = $bats[AacpProtocol::COMP_RIGHT]['level'];
                    $this->rightStatus = $bats[AacpProtocol::COMP_RIGHT]['status'];
                }
                if (isset($bats[AacpProtocol::COMP_CASE])) {
                    $this->caseBattery = $bats[AacpProtocol::COMP_CASE]['level'];
                    $this->caseStatus = $bats[AacpProtocol::COMP_CASE]['status'];
                }
                $this->logPacket('RX', sprintf('Battery L:%d%% R:%d%% Case:%d%%', $this->leftBattery ?? 0, $this->rightBattery ?? 0, $this->caseBattery ?? 0), $hex);
                break;

            case 'ear_detection':
                $this->primaryEar = $d['primaryEar'] ?? AacpProtocol::EAR_OUT_OF_EAR;
                $this->secondaryEar = $d['secondaryEar'] ?? AacpProtocol::EAR_OUT_OF_EAR;
                $this->logPacket('RX', sprintf('Ear Detection P:%s S:%s', self::earLabel($this->primaryEar), self::earLabel($this->secondaryEar)), $hex);
                break;

            case 'control':
                $id = $d['identifier'] ?? 0;
                $d1 = $d['data'][0] ?? 0;
                switch ($id) {
                    case AacpProtocol::CMD_LISTENING_MODE:
                        if ($d1 >= 1 && $d1 <= 4) {
                            $this->listeningMode = $d1 - 1;
                        }
                        break;
                    case AacpProtocol::CMD_CONVERSATION_DETECT:
                        $this->conversationalAwareness = ($d1 === 0x01);
                        break;
                    case AacpProtocol::CMD_EAR_DETECTION:
                        $this->earDetectionEnabled = ($d1 === 0x01);
                        break;
                    case AacpProtocol::CMD_MIC_MODE:
                        if ($d1 >= 0 && $d1 <= 2) {
                            $this->micMode = $d1;
                        }
                        break;
                    case AacpProtocol::CMD_DOUBLE_CLICK_INTERVAL:
                        if ($d1 >= 0 && $d1 <= 2) {
                            $this->pressSpeed = $d1;
                        }
                        break;
                    case AacpProtocol::CMD_CLICK_HOLD_INTERVAL:
                        if ($d1 >= 0 && $d1 <= 2) {
                            $this->holdDuration = $d1;
                        }
                        break;
                    case AacpProtocol::CMD_OWNS_CONNECTION:
                        $this->ownsConnection = ($d1 === 0x01);
                        break;
                }
                $this->logPacket('RX', sprintf('Control ACK (id=0x%02X val=0x%02X)', $id, $d1), $hex);
                break;

            case 'connected_devices':
                $devs = $d['devices'] ?? [];
                // In 0x002E, the local host receiving the packet always has info2 === 0x00.
                foreach ($devs as $dev) {
                    if ($dev['info2'] === 0) {
                        $this->localMac = $dev['mac'];
                        break;
                    }
                }
                $newList = [];
                foreach ($devs as $dev) {
                    $mac = $dev['mac'];
                    $isLocal = ($dev['info2'] === 0) || strcasecmp($mac, $this->localMac) === 0;
                    $type = $isLocal ? 'Xiaomi 14U' : ($this->peerTypes[$mac] ?? 'Apple Peer');
                    $newList[] = [
                        'mac' => $mac,
                        'info1' => $dev['info1'],
                        'info2' => $dev['info2'],
                        'type' => $type,
                    ];
                    if (!$isLocal && $dev['info1'] > 0 && !isset($this->announcedPeers[$mac]) && $this->transport->isConnected()) {
                        $this->announcedPeers[$mac] = true;
                        $mPkt = AacpProtocol::mediaInfoNewDevicePacket($this->localMac, $mac);
                        $tPkt = AacpProtocol::addTiPiDevicePacket($this->localMac, $mac);
                        $this->logPacket('TX', sprintf('TiPi Announce -> %s (0x0010)', $mac), AacpProtocol::toHex($tPkt));
                        $this->transport->send($mPkt);
                        $this->transport->send($tPkt);
                        // When connecting as the 3rd device (count >= 3), claim slot within the 1.05s window before HCI 0x14 (LOW_RESOURCES)
                        if (count($devs) >= 3) {
                            $this->ownsConnection = true;
                            $this->transport->send(AacpProtocol::ownsConnectionPacket(true));
                            $this->transport->send(AacpProtocol::hijackRequestPacket($mac));
                            $this->logPacket('TX', sprintf('3-Device Slot Claim -> %s (0x0010)', $mac), '');
                        }
                    }
                }
                $this->connectedDevices = $newList;
                $this->logPacket('RX', sprintf('Connected Devices (%d): %s', count($newList), implode(', ', array_column($newList, 'mac'))), $hex);
                break;

            case 'audio_source':
                $this->audioSourceMac = $d['mac'] ?? '';
                $this->audioSourceType = $d['audioType'] ?? AacpProtocol::AUDIO_NONE;
                if ($this->audioSourceMac !== '' && strcasecmp($this->audioSourceMac, $this->localMac) !== 0 && $this->audioSourceType !== AacpProtocol::AUDIO_NONE) {
                    if ($this->ownsConnection) {
                        $this->ownsConnection = false;
                        if ($this->transport->isConnected()) {
                            $this->transport->send(AacpProtocol::ownsConnectionPacket(false));
                        }
                        if ($this->transport instanceof AndroidBluetoothTransport) {
                            $this->transport->setAudioConnected($this->macAddress, false);
                        }
                    }
                } elseif (strcasecmp($this->audioSourceMac, $this->localMac) === 0 && $this->audioSourceType !== AacpProtocol::AUDIO_NONE) {
                    $this->ownsConnection = true;
                }
                $typeName = match ($this->audioSourceType) {
                    AacpProtocol::AUDIO_CALL => 'CALL',
                    AacpProtocol::AUDIO_MEDIA => 'MEDIA',
                    default => 'NONE',
                };
                $this->logPacket('RX', sprintf('Audio Source: %s (%s)', $this->audioSourceMac, $typeName), $hex);
                break;

            case 'smart_routing_resp':
                $sender = $d['senderMac'] ?? '';
                $devType = $d['deviceType'] ?? 'Peer';
                if ($sender !== '') {
                    $this->peerTypes[$sender] = $devType;
                }
                foreach ($this->connectedDevices as &$cd) {
                    if (strcasecmp($cd['mac'], $sender) === 0) {
                        $cd['type'] = $devType;
                    }
                }
                unset($cd);
                if (!empty($d['setOwnershipFalse']) && $this->transport->isConnected()) {
                    $this->ownsConnection = false;
                    $this->transport->send(AacpProtocol::ownsConnectionPacket(false));
                    if ($this->transport instanceof AndroidBluetoothTransport) {
                        $this->transport->setAudioConnected($this->macAddress, false);
                    }
                }
                $this->logPacket('RX', sprintf('SmartRouting from %s (%s)%s', $sender, $devType, !empty($d['setOwnershipFalse']) ? ' [Hijacked]' : ''), $hex);
                break;

            case 'proximity_keys':
                if (isset($d['irk']) && strlen($d['irk']) === 16) {
                    $this->irk = $d['irk'];
                    if ($this->bleMac !== '') {
                        $this->rpaVerified = AirPodsCrypto::verifyRpa($this->bleMac, $this->irk);
                    }
                }
                if (isset($d['encKey']) && strlen($d['encKey']) === 16) {
                    $this->encKey = $d['encKey'];
                }
                $this->logPacket(
                    'RX',
                    sprintf('Proximity Keys (0x0031): IRK=%s ENC=%s', $this->irk !== null ? '16B' : '-', $this->encKey !== null ? '16B' : '-'),
                    $hex,
                );
                break;

            case 'head_tracking':
                $this->lastPitch = $d['pitch'] ?? 0;
                $this->lastYaw = $d['yaw'] ?? 0;
                $g = $this->gestures->push($this->lastPitch, $this->lastYaw);
                $tag = $g === HeadGestureDetector::NOD_YES ? ' -> NOD (YES)' : ($g === HeadGestureDetector::SHAKE_NO ? ' -> SHAKE (NO)' : '');
                $this->logPacket('RX', sprintf('IMU pitch=%d yaw=%d%s', $this->lastPitch, $this->lastYaw, $tag), $hex);
                break;
        }
    }

    public static function earLabel(int $state): string
    {
        return match ($state) {
            AacpProtocol::EAR_IN_EAR => 'In Ear',
            AacpProtocol::EAR_IN_CASE => 'In Case',
            default => 'Out of Ear',
        };
    }

    public static function statusLabel(int $status): string
    {
        return match ($status) {
            AacpProtocol::BAT_CHARGING => 'Charging',
            AacpProtocol::BAT_DISCHARGING => 'Discharging',
            AacpProtocol::BAT_DISCONNECTED => 'Disconnected',
            default => '--',
        };
    }

    private function logPacket(string $dir, string $summary, string $hex): void
    {
        if (strlen($hex) > 96) {
            $hex = substr($hex, 0, 96) . ' …';
        }
        array_unshift($this->packets, ['dir' => $dir, 'summary' => $summary, 'hex' => $hex]);
        if (count($this->packets) > 60) {
            array_pop($this->packets);
        }
    }
}

function view(Context $c, AirPodsApp $s): void
{
    $s->pollTransport();
    $t = $c->theme;
    $connected = $s->transport->isConnected();
    $compact = $c->width < 680;

    $root = $c->column(12, 10, 1, $t->background);
    if ($compact) {
        $root->scroll($s->pageScroll);
    }

      // 1. Top Header + Xiaomi 14 Ultra L2CAP 0x1001 Stack Probe Card
      $c->column(12, 8, 0, $t->surface)->radius(8)->border(1, $t->border);
        $c->text('Step 1 · Xiaomi 14 Ultra L2CAP 0x1001 Stack Probe', 14, style: Font::BOLD);
        $verdictText = match ($s->probe->verdict) {
            L2capProbeResult::VERDICT_NATIVE_READY => 'Android 16 QPR3+ Ready (Rootless L2CAP OK)',
            L2capProbeResult::VERDICT_STACK_BLOCKED => 'Fluoride Blocked 0x1001 (Root Patch Needed)',
            L2capProbeResult::VERDICT_BLE_ONLY => 'BLE 0x004C Only',
            default => 'Untested',
        };
        $verdictColor = match ($s->probe->verdict) {
            L2capProbeResult::VERDICT_NATIVE_READY => $t->success,
            L2capProbeResult::VERDICT_STACK_BLOCKED => $t->warning,
            default => $t->textMuted,
        };
        $c->text($verdictText, 12, $verdictColor, Font::BOLD);

        $c->text($s->probe->detail, 12, $t->textMuted);
        if ($s->probe->ctorSignature !== '') {
            $ctorText = $compact && strlen($s->probe->ctorSignature) > 36
                ? substr($s->probe->ctorSignature, 0, 36) . '…'
                : $s->probe->ctorSignature;
            $c->text('JNI Ctor: ' . $ctorText, 11, $t->accent, Font::MONO);
        }

        $c->row(0, 10);
          $c->text('1. BLE 0x004C: ' . ($s->probe->bleBeaconSeen ? 'OK' : 'No'), 11, $s->probe->bleBeaconSeen ? $t->success : $t->textMuted, Font::MONO);
          $c->text('2. L2CAP 0x1001: ' . ($s->probe->l2capSocketOpened ? 'OK' : 'Blocked'), 11, $s->probe->l2capSocketOpened ? $t->success : $t->danger, Font::MONO);
          $c->text('3. Handshake: ' . ($s->probe->handshakeAcked ? 'OK' : 'No ACK'), 11, $s->probe->handshakeAcked ? $t->success : $t->danger, Font::MONO);
        $c->end();

        $c->row(0, 8);
          if ($c->clicked($c->key('run-probe')->primaryButton('Probe L2CAP 0x1001'))) {
              $s->runProbe();
          }
          if ($c->clicked($c->key('open-pair-sheet')->button('Pair AirPods 4'))) {
              $s->popupOpen = true;
              $s->popupDismissed = false;
          }
        $c->end();
        if ($s->androidBt !== null) {
            $simOn = $s->useSimulator;
            if ($c->changed($c->key('use-sim')->checkbox('Use AirPods 4 Simulator', $simOn))) {
                $s->setSimulatorMode($simOn);
            }
        }
        if ($s->useSimulator) {
            $blockSim = $s->sim->simulateStackBlocked;
            if ($c->changed($c->key('sim-block')->checkbox('Simulate Pre-QPR3 Fluoride Block', $blockSim))) {
                $s->sim->simulateStackBlocked = $blockSim;
                $s->runProbe();
            }
        }
      $c->end();

      // 1b. Apple VendorID (0x004C) & iPhone Seamless Handoff (TiPi / Smart Routing) Card
      $c->column(12, 8, 0, $t->surface)->radius(8)->border(1, $t->border);
        $c->text('Step 2 · iPhone Seamless Handoff (TiPi · 0x004C)', 14, style: Font::BOLD);
        $isAppleDid = strcasecmp($s->vendorId, '0x004C') === 0 || $s->vendorId === '76';
        $c->row(0, 12);
          $c->text($isAppleDid ? 'DID: 0x004C (Apple)' : 'DID: ' . ($s->vendorId ?: 'Default'), 12, $isAppleDid ? $t->success : $t->warning, Font::BOLD | Font::MONO);
          $audioOwnerLabel = $s->ownsConnection
              ? 'Audio Owner: Xiaomi 14U'
              : ($s->audioSourceMac !== '' ? 'Audio Owner: Peer (' . $s->audioSourceMac . ')' : 'Audio Owner: Idle');
          $c->text($audioOwnerLabel, 12, $s->ownsConnection ? $t->success : $t->accent, Font::MONO);
        $c->end();

        $activeSlots = [];
        $evictedSlots = [];
        foreach ($s->connectedDevices as $dev) {
            $tag = $dev['type'] . ' (' . $dev['mac'] . ')';
            if ($dev['info1'] > 0) {
                $activeSlots[] = $tag;
            } else {
                $evictedSlots[] = $tag;
            }
        }
        $c->text(
            $activeSlots !== []
                ? sprintf('Active Multipoint (%d/2): %s', count($activeSlots), implode(' + ', $activeSlots))
                : 'Active Multipoint (1/2): Xiaomi 14U',
            11,
            $t->textMuted,
        );
        if ($evictedSlots !== []) {
            $c->text('Standby Slot #3 (Auto-rotates on play): ' . implode(', ', $evictedSlots), 11, $t->warning);
        }

        $keysText = sprintf(
            'Magic Keys (0x0031): IRK=%s · ENC_KEY=%s%s',
            $s->irk !== null ? 'OK (16B)' : '--',
            $s->encKey !== null ? 'OK (16B)' : '--',
            $s->rpaVerified ? ' · RPA Verified' : ($s->bleConnState !== '' ? ' · BLE:' . $s->bleConnState : ''),
        );
        $c->text($keysText, 11, $s->irk !== null ? $t->success : $t->textMuted, Font::MONO);

        $autoTake = $s->autoTakeoverEnabled;
        if ($c->changed($c->key('ctrl-autotake')->switch('Auto-Takeover on Phone Play (2 & 3-Device)', $autoTake))) {
            $s->autoTakeoverEnabled = $autoTake;
        }

        $c->row(0, 8);
          if ($c->clicked($c->key('btn-takeover')->primaryButton('Take Over from iPhone'))) {
              $s->onLocalAudioStarted();
          }
          if ($c->clicked($c->key('btn-handback')->button('Hand Back to iPhone')->disabled(!$connected))) {
              $s->handBackToPeer();
          }
        $c->end();
      $c->end();

      // 2. Device Overview + Battery & Ear Detection Cards
      $c->column(12, 8, 0, $t->surface)->radius(8)->border(1, $t->border);
        $c->row(0, 8);
          $c->text($s->deviceName, 16, style: Font::BOLD);
          $c->spacer();
          $c->text($s->lidOpen ? 'Case Lid Open' : 'Case Lid Closed', 12, $s->lidOpen ? $t->accent : $t->textMuted);
        $c->end();
        $c->muted($s->modelNumber . ' · FW ' . $s->firmware, 12);
        $c->row(0, 8);
          $c->input($s->renameInput, 'AirPods Name', 160);
          if ($c->clicked($c->key('btn-rename')->button('Rename')->disabled(!$connected))) {
              $s->rename($s->renameInput->text);
          }
          if ($c->clicked($c->key('btn-lid')->button($s->lidOpen ? 'Close Lid' : 'Open Lid'))) {
              $s->sim->lidOpen = !$s->sim->lidOpen;
              $s->lidOpen = $s->sim->lidOpen;
              $s->popupOpen = $s->lidOpen;
              $s->popupDismissed = !$s->lidOpen;
          }
        $c->end();
      $c->end();

      $c->row(0, 8)->alignItems(Node::STRETCH);
        podCard($c, 'Left Pod', $s->leftBattery, AirPodsApp::statusLabel($s->leftStatus), AirPodsApp::earLabel($s->primaryEar));
        podCard($c, 'Right Pod', $s->rightBattery, AirPodsApp::statusLabel($s->rightStatus), AirPodsApp::earLabel($s->secondaryEar));
        podCard($c, 'Case', $s->caseBattery, AirPodsApp::statusLabel($s->caseStatus), $s->lidOpen ? 'Lid Open' : 'Lid Closed');
      $c->end();

      // 3. AirPods 4 Controls (AACP Opcode 0x0009 & 0x0017) + Live Packet Inspector
      if ($compact) {
          controlsCard($c, $s, $connected);
          packetLogCard($c, $s);
      } else {
          $c->row(0, 12, 1)->alignItems(Node::STRETCH);
            controlsCard($c, $s, $connected);
            packetLogCard($c, $s);
          $c->end();
      }

      if ($s->popupOpen) {
          openLidModal($c, $s, $connected);
      }

    $c->end();
}

function openLidModal(Context $c, AirPodsApp $s, bool $connected): void
{
    $t = $c->theme;
    $open = $s->popupOpen;
    if ($c->changed($c->modal($open, function () use ($c, $s, $t, $connected) {
        $c->column(20, 14, 0, $t->surface)->size(min(360.0, $c->width - 32.0), 0)->radius(16)->border(1, $t->border);
          $c->column(0, 4)->alignItems(Node::CENTER);
            $c->text($s->deviceName, 20, style: Font::BOLD);
            $sub = $s->modelNumber . ($s->bleRssi > -100 ? sprintf(' · RSSI %d dBm', $s->bleRssi) : '');
            $c->muted($sub, 12);
          $c->end();

          $c->row(0, 8)->alignItems(Node::STRETCH);
            podCard($c, 'Left', $s->leftBattery, AirPodsApp::statusLabel($s->leftStatus), AirPodsApp::earLabel($s->primaryEar));
            podCard($c, 'Right', $s->rightBattery, AirPodsApp::statusLabel($s->rightStatus), AirPodsApp::earLabel($s->secondaryEar));
            podCard($c, 'Case', $s->caseBattery, AirPodsApp::statusLabel($s->caseStatus), $s->lidOpen ? 'Open' : 'Closed');
          $c->end();

          if (!$s->isBonded && !$s->useSimulator) {
              $c->column(8, 4, 0, $t->background)->radius(8);
                $c->text('First-Time Pairing (iOS Proximity Flow)', 12, $t->accent, Font::BOLD);
                $c->text('1. Open your AirPods 4 case lid near Xiaomi 14 Ultra.', 11, $t->textMuted);
                $c->text('2. Double-tap the front of the case until the white LED flashes.', 11, $t->textMuted);
                $c->text('3. Tap "Connect & Pair" below to bond & open L2CAP 0x1001.', 11, $t->textMuted);
                if ($s->pairState !== 'idle') {
                    $c->text('Status: ' . $s->pairState, 11, $t->warning, Font::MONO);
                }
              $c->end();
          } else {
              $statusLine = $connected
                  ? 'Connected over L2CAP 0x1001 (AACP Active)'
                  : 'Bonded (' . $s->macAddress . ') · Ready for L2CAP 0x1001';
              $c->text($statusLine, 12, $connected ? $t->success : $t->accent, Font::BOLD);
          }

          $c->row(0, 10);
            if (!$s->isBonded && !$s->useSimulator) {
                $pairLabel = str_starts_with($s->pairState, 'searching') ? 'Searching AirPods 4…' : 'Connect & Pair';
                if ($c->clicked($c->key('modal-pair')->primaryButton($pairLabel))) {
                    $s->startPairing();
                }
            } elseif (!$connected) {
                if ($c->clicked($c->key('modal-conn')->primaryButton('Connect L2CAP 0x1001'))) {
                    $s->runProbe();
                }
            }
            $c->spacer();
            if ($c->clicked($c->key('modal-done')->button('Done'))) {
                $s->popupOpen = false;
                $s->popupDismissed = true;
            }
          $c->end();
        $c->end();
    }))) {
        $s->popupOpen = $open;
        if (!$open) {
            $s->popupDismissed = true;
        }
    }
}

function controlsCard(Context $c, AirPodsApp $s, bool $connected): void
{
    $t = $c->theme;
    $c->column(12, 10, 1, $t->surface)->radius(8)->border(1, $t->border);
      $c->text('AirPods 4 Controls (AACP 0x0009 / 0x0017)', 14, style: Font::BOLD);

      $c->column(0, 4);
        $c->muted('Noise Control (0x0D)', 12);
        $nc = $s->listeningMode;
        if ($c->changed($c->key('ctrl-nc')->segmented(AirPodsApp::LISTENING_MODES, $nc)->disabled(!$connected))) {
            $s->listeningMode = $nc;
            $s->sendControl(AacpProtocol::CMD_LISTENING_MODE, $nc + 1, 0, 'Listening Mode');
        }
      $c->end();

      $conv = $s->conversationalAwareness;
      if ($c->changed($c->key('ctrl-conv')->switch('Conversational Awareness (0x28)', $conv)->disabled(!$connected))) {
          $s->conversationalAwareness = $conv;
          $s->sendControl(AacpProtocol::CMD_CONVERSATION_DETECT, $conv ? 0x01 : 0x02, 0, 'Conversational Awareness');
      }

      $earDet = $s->earDetectionEnabled;
      if ($c->changed($c->key('ctrl-ear')->switch('Automatic Ear Detection (0x0A)', $earDet)->disabled(!$connected))) {
          $s->earDetectionEnabled = $earDet;
          $s->sendControl(AacpProtocol::CMD_EAR_DETECTION, $earDet ? 0x01 : 0x02, 0, 'Ear Detection');
      }

      $autoConn = $s->autoConnectEnabled;
      if ($c->changed($c->key('ctrl-autoconn')->switch('Connect Automatically (0x20)', $autoConn)->disabled(!$connected))) {
          $s->autoConnectEnabled = $autoConn;
          $s->sendControl(AacpProtocol::CMD_CONNECT_AUTOMATICALLY, $autoConn ? 0x01 : 0x02, 0, 'Connect Automatically');
      }

      $sleepDet = $s->sleepDetectionEnabled;
      if ($c->changed($c->key('ctrl-sleep')->switch('Pause Media When Falling Asleep (0x35)', $sleepDet)->disabled(!$connected))) {
          $s->sleepDetectionEnabled = $sleepDet;
          $s->sendControl(AacpProtocol::CMD_SLEEP_DETECTION, $sleepDet ? 0x01 : 0x02, 0, 'Sleep Detection');
      }

      $c->row(0, 8);
        $c->muted('Microphone (0x01)', 12);
        $c->spacer();
        $mic = $s->micMode;
        if ($c->changed($c->key('ctrl-mic')->segmented(AirPodsApp::MIC_MODES, $mic)->disabled(!$connected))) {
            $s->micMode = $mic;
            $s->sendControl(AacpProtocol::CMD_MIC_MODE, $mic, 0, 'Mic Mode');
        }
      $c->end();

      $c->row(0, 8);
        $c->muted('End Call (0x24)', 12);
        $c->spacer();
        $callEnd = $s->endCallMode;
        if ($c->changed($c->key('ctrl-call')->segmented(AirPodsApp::CALL_END_MODES, $callEnd)->disabled(!$connected))) {
            $s->endCallMode = $callEnd;
            $s->sendControl(AacpProtocol::CMD_CALL_MANAGEMENT, $callEnd === 0 ? 0x01 : 0x02, 0, 'Call Management');
        }
      $c->end();

      $c->row(0, 8);
        $c->muted('Press Speed (0x17)', 12);
        $c->spacer();
        $speed = $s->pressSpeed;
        if ($c->changed($c->key('ctrl-speed')->segmented(AirPodsApp::SPEED_MODES, $speed)->disabled(!$connected))) {
            $s->pressSpeed = $speed;
            $s->sendControl(AacpProtocol::CMD_DOUBLE_CLICK_INTERVAL, $speed, 0, 'Press Speed');
        }
      $c->end();

      $c->row(0, 8);
        $c->muted('Hold Duration (0x18)', 12);
        $c->spacer();
        $hold = $s->holdDuration;
        if ($c->changed($c->key('ctrl-hold')->segmented(AirPodsApp::HOLD_MODES, $hold)->disabled(!$connected))) {
            $s->holdDuration = $hold;
            $s->sendControl(AacpProtocol::CMD_CLICK_HOLD_INTERVAL, $hold, 0, 'Hold Duration');
        }
      $c->end();

      $c->divider();

      // H2 Head Gestures section
      $c->row(0, 8);
        $c->text('H2 Head Gestures (0x0017)', 13, style: Font::BOLD);
        $c->spacer();
        $gestureLabel = match ($s->gestures->lastGesture) {
            HeadGestureDetector::NOD_YES => sprintf('Last: Nod Yes (%d)', $s->gestures->nodCount),
            HeadGestureDetector::SHAKE_NO => sprintf('Last: Shake No (%d)', $s->gestures->shakeCount),
            default => 'Last: --',
        };
        $c->text($gestureLabel, 12, $s->gestures->lastGesture !== '' ? $t->accent : $t->textMuted, Font::BOLD);
      $c->end();

      $c->row(0, 8);
        if ($c->clicked($c->key('btn-imu')->button($s->headTrackingActive ? 'Stop IMU' : 'Start IMU')->disabled(!$connected))) {
            $s->setHeadTracking(!$s->headTrackingActive);
        }
        if ($c->clicked($c->key('btn-nod')->button('Simulate Nod (Yes)')->disabled(!$connected))) {
            $s->triggerSimulatedGesture(HeadGestureDetector::NOD_YES);
        }
      $c->end();
      $c->row(0, 8);
        if ($c->clicked($c->key('btn-shake')->button('Simulate Shake (No)')->disabled(!$connected))) {
            $s->triggerSimulatedGesture(HeadGestureDetector::SHAKE_NO);
        }
      $c->end();
    $c->end();
}

function packetLogCard(Context $c, AirPodsApp $s): void
{
    $t = $c->theme;
    $c->column(12, 8, 1, $t->surface)->minHeight(220)->radius(8)->border(1, $t->border);
      $c->row(0, 8);
        $c->text('AACP / BLE Packet Log', 14, style: Font::BOLD);
        $c->spacer();
        $c->muted(count($s->packets) . ' frames', 12);
      $c->end();

      $c->list($s->packetList, count($s->packets), function (int $i) use ($c, $t, $s) {
          $p = $s->packets[$i];
          $dirColor = $p['dir'] === 'TX' ? $t->accent : ($p['dir'] === 'RX' ? $t->success : $t->warning);
          $c->column(4, 2);
            $c->row(0, 6);
              $c->text($p['dir'], 11, $dirColor, Font::BOLD | Font::MONO);
              $c->text($p['summary'], 12, $t->text)->singleLine();
            $c->end();
            $c->text($p['hex'], 10, $t->textMuted, Font::MONO)->singleLine();
          $c->end();
      });
    $c->end();
}

function podCard(Context $c, string $title, ?int $battery, string $status, string $placement): void
{
    $t = $c->theme;
    $c->column(10, 4, 1, $t->surface)->basis(0)->radius(8)->border(1, $t->border);
      $c->muted($title, 11);
      $c->text($battery === null ? '--' : $battery . '%', 20, style: Font::BOLD);
      $c->text($placement . ' · ' . $status, 10, $t->textMuted)->singleLine();
    $c->end();
}

$s = new AirPodsApp();
App::setName('AirPods 4');
App::whenReady(function () use ($s) {
    new Window(new WindowOptions(
        title: 'AirPods 4 · Xiaomi 14 Ultra',
        width: 980,
        height: 640,
        content: Content::view(fn(Context $c) => view($c, $s)),
    ));
    if (getenv('MYPHP_PLATFORM') === 'android') {
        $bgPoll = null;
        $bgPoll = function () use ($s, &$bgPoll): void {
            $s->pollTransport();
            App::after(0.2, $bgPoll);
        };
        App::after(0.2, $bgPoll);
    }
});
App::run();
