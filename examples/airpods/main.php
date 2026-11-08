<?php
// AirPods 4 companion for Xiaomi 14 Ultra in MyPHP native UI:
// 1. Probes whether the Android Bluetooth stack has the Android 16 QPR3 / 17
//    L2CAP PSM 0x1001 fix (direct rootless connection) or blocks 0x1001 in
//    Fluoride (requiring the Root L2CAP bypass).
// 2. Decodes Apple BLE 0x004C Proximity Pairing broadcasts and drives the full
//    AACP binary protocol over L2CAP 0x1001 for standard AirPods 4 (A3053).
// Run: myphp run -d macos examples/airpods
declare(strict_types=1);

if (!defined('STDIN')) { define('STDIN', fopen('php://stdin', 'r')); }
if (!defined('STDOUT')) { define('STDOUT', fopen('php://stdout', 'w')); }
if (!defined('STDERR')) { define('STDERR', fopen('php://stderr', 'w')); }

if (file_exists(__DIR__ . '/src/Native/LocalePolyfill.php')) {
    require_once __DIR__ . '/src/Native/LocalePolyfill.php';
}

use MyPHP\{App, Window, WindowOptions};
use MyPHP\Examples\Airpods\{AacpProtocol, AirPodsBleBroadcast, AirPodsCrypto, AirPodsTransport, AndroidBluetoothTransport, HeadGestureDetector, L2capProbeResult, SimulatedAirPods4Transport, };
use MyPHP\UI\Content;

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
require_once __DIR__ . '/strings/Strings.php'; // the catalogs, which myphp dev writes from strings/*.json (Strings, not Locales: the form example's class)

final class AirPodsApp
{
    public const LISTENING_MODES = ['Off', 'ANC', 'Transparency', 'Adaptive'];
    public const MIC_MODES = ['Auto', 'Right', 'Left'];
    public const CALL_END_MODES = ['Single', 'Double'];
    public const SPEED_MODES = ['Default', 'Slower', 'Slowest'];
    public const HOLD_MODES = ['Default', 'Shorter', 'Shortest'];
    /** The text sizes the Settings tab offers, by DynamicTypeSize's case: the system's, then iOS's, the first accessibility sizes after. */
    public const TEXT_SIZES = ['system' => 'System', 'xSmall' => 'Extra Small', 'small' => 'Small', 'medium' => 'Medium', 'large' => 'Default', 'xLarge' => 'Large',
        'xxLarge' => 'Extra Large', 'xxxLarge' => 'Extra Extra Large', 'accessibility1' => 'Larger Accessibility Size', 'accessibility3' => 'Largest Accessibility Size'];

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
    private int $lastTakeoverNs = 0;
    private int $lastAutoProbeNs = 0;
    private bool $wasConnected = false;
    private bool $wasConnecting = false;
    private bool $pendingTakeover = false;
    private bool $evictedToStandby = false;
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

    /** The name typed in the rename field. */
    public string $renameText = '';
    /** The tab shown: the AirPods' settings, the connection, or the packets. */
    public string $page = 'AirPods';
    /** The app's appearance (system, light, dark), its text size (a key of TEXT_SIZES) and its language ('' for the system's), as its Settings tab chooses them. */
    public string $appearance = 'system', $textSize = 'system', $lang = '';
    /** The frame of the log its sheet shows, and whether it shows. */
    public int $packetShown = 0;
    public bool $packetOpen = false;
    /** Whether the log's Clear asks to confirm. */
    public bool $clearAsked = false;
    /** The Search tab's query and scope (all, RX, TX, BLE). */
    public string $query = '';
    public string $scope = 'all';
    /** @var list<array{dir: string, summary: string, hex: string}> */
    public array $packets = [];
    private ?string $lastBleRaw = null;
    private int $lastStatusPollNs = 0;

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
        $this->renameText = $this->deviceName;
        if ($this->useSimulator) {
            $this->runProbe();
        } elseif ($this->androidBt !== null) {
            $st = $this->androidBt->getStatus();
            $this->vendorId = $st['vendorId'] !== '' ? $st['vendorId'] : $this->vendorId;
            $this->localMac = $st['localMac'] !== '' ? $st['localMac'] : $this->localMac;
            $this->macAddress = $st['mac'] !== '' ? $st['mac'] : $this->macAddress;
            $this->deviceName = $st['name'] !== '' ? $st['name'] : $this->deviceName;
            $this->renameText = $this->deviceName;
            $this->isBonded = $st['mac'] !== '';
            $this->probe = new L2capProbeResult(
                verdict: L2capProbeResult::VERDICT_NATIVE_READY,
                bleBeaconSeen: false,
                l2capSocketOpened: false,
                handshakeAcked: false,
                detail: sprintf('Android %s (SDK %d) Ready · Auto-connecting L2CAP 0x1001…', $st['release'], $st['sdk']),
                sdkInt: $st['sdk'],
                androidRelease: $st['release'],
                buildId: $st['build'],
                ctorSignature: $st['ctor'],
            );
            $this->logPacket('SYS', sprintf('SDK %d (%s) ctor=%s', $st['sdk'], $st['release'], $st['ctor']), $st['build']);
            if ($this->isBonded && $this->macAddress !== '' && (($st['acl'] ?? false) || ($st['audio'] ?? false))) {
                $this->runProbe();
            }
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
                $this->renameText = $this->deviceName;
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

        $this->lastAutoProbeNs = hrtime(true);
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

    /**
     * Returns all active peer MAC addresses (e.g. both macOS and iPhone in a 3-device setup),
     * prioritizing the device currently streaming audio ($this->audioSourceMac).
     * @return list<string>
     */
    public function peerMacs(): array
    {
        $peers = [];
        if ($this->audioSourceMac !== '' && strcasecmp($this->audioSourceMac, $this->localMac) !== 0) {
            $peers[strtoupper($this->audioSourceMac)] = $this->audioSourceMac;
        }
        foreach ($this->connectedDevices as $dev) {
            if ($dev['info1'] > 0 && strcasecmp($dev['mac'], $this->localMac) !== 0) {
                $peers[strtoupper($dev['mac'])] = $dev['mac'];
            }
        }
        foreach ($this->connectedDevices as $dev) {
            if (strcasecmp($dev['mac'], $this->localMac) !== 0) {
                $peers[strtoupper($dev['mac'])] = $dev['mac'];
            }
        }
        return array_values($peers);
    }

    /** Returns the primary active peer MAC address (e.g. macOS/iPhone), if any. */
    public function peerMac(): ?string
    {
        $peers = $this->peerMacs();
        return $peers[0] ?? null;
    }

    /**
     * Claims audio ownership from all connected peers (iPhone + macOS via Smart Routing / TiPi Hijackv2)
     * and shows the "Moved to Android" banner on the peer.
     */
    public function takeOverFromPeer(): void
    {
        if (!$this->transport->isConnected()) {
            return;
        }
        $this->lastTakeoverNs = hrtime(true);
        $this->ownsConnection = true;
        $ownPkt = AacpProtocol::ownsConnectionPacket(true);
        $this->logPacket('TX', 'Claim Ownership (0x09 id=0x06 val=0x01)', AacpProtocol::toHex($ownPkt));
        $this->transport->send($ownPkt);

        foreach ($this->peerMacs() as $peer) {
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
     * Releases audio ownership and hands the AirPods stream back to the peer iPhone/Mac.
     */
    public function handBackToPeer(): void
    {
        if (!$this->transport->isConnected()) {
            return;
        }
        $this->pendingTakeover = false;
        $this->lastTakeoverNs = 0;
        $this->ownsConnection = false;
        $ownPkt = AacpProtocol::ownsConnectionPacket(false);
        $this->logPacket('TX', 'Release Ownership (0x09 id=0x06 val=0x00)', AacpProtocol::toHex($ownPkt));
        $this->transport->send($ownPkt);

        foreach ($this->peerMacs() as $peer) {
            $mediaPkt = AacpProtocol::mediaInformationPacket($this->localMac, $peer, false);
            $revPkt = AacpProtocol::hijackReversedPacket($peer);
            $this->logPacket('TX', sprintf('TiPi HandBack -> %s (0x0010)', $peer), AacpProtocol::toHex($revPkt));
            $this->transport->send($mediaPkt);
            $this->transport->send($revPkt);
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
     *   L2CAP 0x1001, and takes over audio as soon as 0x002E arrives.
     */
    public function onLocalAudioStarted(): void
    {
        if (!$this->autoTakeoverEnabled) {
            return;
        }
        $this->localAudioPlaying = true;
        $this->pendingTakeover = true;
        $this->evictedToStandby = false;
        $this->ownsConnection = true;
        $this->logPacket('SYS', 'Local audio playback started -> Auto-Takeover', '');
        if (!$this->transport->isConnected() && $this->isBonded && $this->macAddress !== '') {
            $this->runProbe();
        }
        if ($this->transport->isConnected()) {
            $this->takeOverFromPeer();
        }
    }

    public function onLocalAudioStopped(): void
    {
        $this->localAudioPlaying = false;
        $this->pendingTakeover = false;
        $this->ownsConnection = false;
        $this->logPacket('SYS', 'Local audio playback stopped -> Releasing ownership', '');
        if ($this->transport->isConnected()) {
            $this->transport->send(AacpProtocol::ownsConnectionPacket(false));
            foreach ($this->peerMacs() as $peer) {
                $mediaPkt = AacpProtocol::mediaInformationPacket($this->localMac, $peer, false);
                $this->transport->send($mediaPkt);
            }
        }
    }

    public function pollTransport(): void
    {
        if ($this->transport instanceof AndroidBluetoothTransport && (hrtime(true) - $this->lastStatusPollNs) >= 250_000_000) {
            $this->lastStatusPollNs = hrtime(true);
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

            if ($st['err'] !== '' && !$this->transport->isConnected()) {
                $this->probe->l2capSocketOpened = false;
                $this->probe->handshakeAcked = false;
                $this->probe->detail = $st['err'];
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
            } elseif ($wasPlaying && !$this->localAudioPlaying) {
                $this->onLocalAudioStopped();
            }

            $nowConnecting = $st['connecting'] ?? false;
            if ($this->wasConnecting && !$nowConnecting) {
                $this->lastAutoProbeNs = hrtime(true);
            }
            $this->wasConnecting = $nowConnecting;

            $nowConnected = $this->transport->isConnected();
            if ($this->wasConnected && !$nowConnected) {
                $this->lastAutoProbeNs = hrtime(true);
                $this->probe->l2capSocketOpened = false;
                $this->probe->handshakeAcked = false;
                if (count($this->connectedDevices) >= 3 && !$this->localAudioPlaying && !$this->pendingTakeover) {
                    $this->evictedToStandby = true;
                    $this->probe->detail = '3-Device BLE Standby (auto-connects when local audio plays)';
                    $this->logPacket('SYS', 'L2CAP closed -> 3-Device BLE Standby', '');
                } else {
                    $this->probe->detail = 'L2CAP disconnected · Auto-reconnecting…';
                    $this->logPacket('SYS', 'L2CAP disconnected (auto-reconnect armed)', '');
                }
            } elseif (!$this->wasConnected && $nowConnected) {
                $this->evictedToStandby = false;
                $this->probe->l2capSocketOpened = true;
                $this->logPacket('SYS', 'L2CAP 0x1001 connected automatically', '');
                if ($this->pendingTakeover || $this->localAudioPlaying) {
                    $this->takeOverFromPeer();
                }
            }
            $this->wasConnected = $nowConnected;

            $bleRaw = $this->transport->scanBle();
            if ($bleRaw !== null && $bleRaw !== $this->lastBleRaw) {
                $this->lastBleRaw = $bleRaw;
                $ble = AirPodsBleBroadcast::decode($bleRaw);
                $this->probe->bleBeaconSeen = true;
                $wasClosed = !$this->lidOpen;
                $this->lidOpen = $ble->lidOpen;
                $this->blePairingMode = $ble->pairingMode;
                if (!$ble->lidOpen && !$nowConnected) {
                    $this->popupDismissed = false;
                } elseif ($wasClosed && !$this->popupDismissed && $this->bleRssi >= -75) {
                    $this->popupOpen = true;
                    if ($this->isBonded && !$nowConnected && !$nowConnecting) {
                        $this->runProbe();
                    }
                }
                $decBat = ($this->encKey !== null && !$ble->pairingMode)
                    ? AirPodsCrypto::decryptBleBattery($bleRaw, $this->encKey)
                    : null;
                if ($decBat !== null) {
                    $this->bleConnState = $decBat['connState'];
                }
                if (!$nowConnected && !$ble->pairingMode) {
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

            if ($this->isBonded && $this->macAddress !== '' && !$nowConnected && !$nowConnecting) {
                $elapsedNs = hrtime(true) - $this->lastAutoProbeNs;
                $budsAwake = ($st['acl'] ?? false)
                    || ($this->bleRssi >= -82 && ($this->lidOpen || $this->primaryEar === AacpProtocol::EAR_IN_EAR || $this->secondaryEar === AacpProtocol::EAR_IN_EAR));
                if (($this->pendingTakeover || $this->localAudioPlaying) && $elapsedNs >= 2_500_000_000) {
                    $this->runProbe();
                } elseif ((($st['acl'] ?? false) || (!$this->evictedToStandby && $budsAwake)) && $elapsedNs >= 4_000_000_000) {
                    $this->runProbe();
                }
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
                $this->renameText = $this->deviceName;
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
                if ($this->pendingTakeover || $this->localAudioPlaying) {
                    $this->pendingTakeover = false;
                    $this->takeOverFromPeer();
                }
                break;

            case 'audio_source':
                $this->audioSourceMac = $d['mac'] ?? '';
                $this->audioSourceType = $d['audioType'] ?? AacpProtocol::AUDIO_NONE;
                $inTakeoverGrace = $this->lastTakeoverNs > 0 && (hrtime(true) - $this->lastTakeoverNs) < 3_000_000_000;
                if ($this->audioSourceMac !== '' && $this->localMac !== '' && strcasecmp($this->audioSourceMac, $this->localMac) !== 0 && $this->audioSourceType !== AacpProtocol::AUDIO_NONE && !$inTakeoverGrace && !$this->localAudioPlaying) {
                    if ($this->ownsConnection) {
                        $this->ownsConnection = false;
                        if ($this->transport->isConnected()) {
                            $this->transport->send(AacpProtocol::ownsConnectionPacket(false));
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
                $inTakeoverGrace = $this->lastTakeoverNs > 0 && (hrtime(true) - $this->lastTakeoverNs) < 3_000_000_000;
                $shouldRelease = (!empty($d['setOwnershipFalse']) || !empty($d['reverseBannerTapped'])) && !$inTakeoverGrace;
                if ($shouldRelease && $this->transport->isConnected()) {
                    $this->ownsConnection = false;
                    $this->transport->send(AacpProtocol::ownsConnectionPacket(false));
                }
                $this->logPacket('RX', sprintf('SmartRouting from %s (%s)%s', $sender, $devType, $shouldRelease ? ' [Released]' : ''), $hex);
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

$s = new AirPodsApp();
App::setName('AirPods 4');
App::whenReady(function () use ($s) {
    new Window(new WindowOptions(
        title: 'AirPods 4 · Xiaomi 14 Ultra',
        width: 980,
        height: 640,
        minWidth: 560,
        titleBarStyle: 'hiddenInset', // the window controls over the sidebar, whose material shows through it, as the Mac's settings
        vibrancy: 'sidebar',
        content: Content::file(__DIR__ . '/view.php', $s),
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
