<?php
// Tests for the AirPods 4 (A3053 / 0x1920) BLE Proximity Pairing, AACP L2CAP
// 0x1001 protocol, Xiaomi 14 Ultra L2CAP stack compatibility probe, and the
// native UI in examples/airpods.
declare(strict_types=1);

namespace MyPHP\Tests;

require_once __DIR__ . '/../examples/airpods/Protocol.php';

use MyPHP\Examples\Airpods\{
    AacpProtocol,
    AirPodsBleBroadcast,
    AirPodsCrypto,
    HeadGestureDetector,
    L2capProbeResult,
    SimulatedAirPods4Transport,
};

// 1. Xiaomi 14 Ultra L2CAP 0x1001 Stack Compatibility Probe (Android 16 QPR3+ vs Fluoride blocked)
$sim = new SimulatedAirPods4Transport();
$probeOk = L2capProbeResult::run($sim, 'AA:BB:CC:14:04:01');
same(L2capProbeResult::VERDICT_NATIVE_READY, $probeOk->verdict, 'Android 16 QPR3+ stack passes direct L2CAP 0x1001 probe');
check($probeOk->bleBeaconSeen && $probeOk->l2capSocketOpened && $probeOk->handshakeAcked, 'all 3 probe checkpoints pass');
same(6, count($probeOk->initialFrames), 'initial metadata, battery, ear detection, connected devices, audio source and proximity keys frames received');

$sim->simulateStackBlocked = true;
$probeBlocked = L2capProbeResult::run($sim, 'AA:BB:CC:14:04:01');
same(L2capProbeResult::VERDICT_STACK_BLOCKED, $probeBlocked->verdict, 'pre-QPR3 Fluoride stack block detected for Root L2CAP bypass');
check($probeBlocked->bleBeaconSeen && !$probeBlocked->l2capSocketOpened && !$probeBlocked->handshakeAcked, 'BLE works while L2CAP 0x1001 is blocked');

// 2. BLE Proximity Pairing (0x004C) for Standard AirPods 4 (0x1920)
$bleRaw = AirPodsBleBroadcast::encode(
    modelId: AirPodsBleBroadcast::MODEL_AIRPODS_4,
    leftBattery: 90,
    rightBattery: 80,
    caseBattery: 70,
    leftCharging: false,
    rightCharging: true,
    caseCharging: true,
    leftInEar: true,
    rightInEar: false,
    lidOpen: true,
    lidCounter: 3,
);
same(27, strlen($bleRaw), 'Proximity Pairing BLE payload is 27 bytes');
$ble = AirPodsBleBroadcast::decode("\x4c\x00" . $bleRaw);
same([AirPodsBleBroadcast::MODEL_AIRPODS_4, 'AirPods 4', false], [$ble->modelId, $ble->modelName, $ble->hasAnc], 'standard AirPods 4 identified without ANC');
same([90, 80, 70], [$ble->leftBattery, $ble->rightBattery, $ble->caseBattery], 'BLE 10% step battery levels');
same([false, true, true], [$ble->leftCharging, $ble->rightCharging, $ble->caseCharging], 'BLE charging flags');
same([true, false, true, 3], [$ble->leftInEar, $ble->rightInEar, $ble->lidOpen, $ble->lidCounter], 'BLE ear & lid flags');

// 3. AACP Packets & Exact LibrePods Wire Format Verification
same('00 00 04 00 01 00 02 00 00 00 00 00 00 00 00 00', AacpProtocol::toHex(AacpProtocol::handshakePacket()), 'AACP 16-byte handshake packet');
same('04 00 04 00 4D 00 FF 00 00 00 00 00 00 00', AacpProtocol::toHex(AacpProtocol::capabilitiesPacket()), 'AACP host capabilities packet');
same('04 00 04 00 0F 00 FF FF FE FF', AacpProtocol::toHex(AacpProtocol::requestNotificationsPacket()), 'AACP notification register packet');

// Exact 22-byte battery packet from LibrePods AAP Definitions.md
$docBatteryBin = hex2bin('04000400040003020164020104016301010801110201');
$decBat = AacpProtocol::decode($docBatteryBin);
same('battery', $decBat['type'], 'decoded Opcode 0x0004 battery report');
same(100, $decBat['batteries'][AacpProtocol::COMP_RIGHT]['level'], 'Right pod 100%');
same(99, $decBat['batteries'][AacpProtocol::COMP_LEFT]['level'], 'Left pod 99%');
same(17, $decBat['batteries'][AacpProtocol::COMP_CASE]['level'], 'Case 17%');

// Control packets for AirPods 4 (Ear detection, Sleep detection, Mic mode, Rename)
$sleepPkt = AacpProtocol::controlPacket(AacpProtocol::CMD_SLEEP_DETECTION, 0x01);
same('04 00 04 00 09 00 35 01 00 00 00', AacpProtocol::toHex($sleepPkt), 'AirPods 4 sleep detection enable packet');
$decCtrl = AacpProtocol::decode($sleepPkt);
same(['control', AacpProtocol::CMD_SLEEP_DETECTION, [1, 0, 0, 0]], [$decCtrl['type'], $decCtrl['identifier'], $decCtrl['data']], 'control packet round-trip');

$renamePkt = AacpProtocol::renamePacket('My AirPods 4');
$decRename = AacpProtocol::decode($renamePkt);
same(['rename', 'My AirPods 4'], [$decRename['type'], $decRename['name']], 'AACP 0x001A rename round-trip');

// Head Gestures (Nod Yes / Shake No) over Opcode 0x0017 IMU packets
$det = new HeadGestureDetector();
same(HeadGestureDetector::NONE, $det->push(620, 10), 'first pitch peak');
same(HeadGestureDetector::NONE, $det->push(-640, -15), 'first pitch reversal');
same(HeadGestureDetector::NOD_YES, $det->push(610, 5), 'second pitch reversal triggers NOD_YES');
same(HeadGestureDetector::NONE, $det->push(15, 700), 'first yaw peak');
same(HeadGestureDetector::NONE, $det->push(-10, -710), 'first yaw reversal');
same(HeadGestureDetector::SHAKE_NO, $det->push(20, 680), 'second yaw reversal triggers SHAKE_NO');

// 4. Headless UI test of examples/airpods/main.php
[$view, $s] = Headless::load('airpods');
$t = new Headless($view, 980, 640);
$t->frame();

check($t->byText('Android 16 QPR3+ Ready (Rootless L2CAP OK)') !== null, 'Step 1 probe shows rootless L2CAP ready on startup');
check($t->byText('92%') !== null && $t->byText('88%') !== null && $t->byText('76%') !== null, 'AirPods 4 Left/Right/Case batteries rendered');

// Toggle pre-QPR3 Fluoride stack block simulation
$t->clickText('Simulate Pre-QPR3 Fluoride Block');
$t->frame();
same(L2capProbeResult::VERDICT_STACK_BLOCKED, $s->probe->verdict, 'UI switches to Fluoride blocked verdict');
check($t->byText('Fluoride Blocked 0x1001 (Root Patch Needed)') !== null, 'blocked verdict badge shown');

// Restore rootless L2CAP ready mode
$t->clickText('Simulate Pre-QPR3 Fluoride Block');
$t->frame();
same(L2capProbeResult::VERDICT_NATIVE_READY, $s->probe->verdict, 're-probed back to native ready');

// Toggle AirPods 4 Sleep Detection and Head Gestures from the UI
$t->clickText('Pause Media When Falling Asleep (0x35)');
$t->frame();
check($s->sleepDetectionEnabled, 'Sleep detection toggled on via AACP 0x0009');

$t->clickText('Simulate Nod (Yes)');
$t->frame();
same(HeadGestureDetector::NOD_YES, $s->gestures->lastGesture, 'Nod (Yes) head gesture recognized from 0x0017 stream');
check($t->byText('Last: Nod Yes (1)') !== null, 'UI displays Nod Yes count');

$t->clickText('Simulate Shake (No)');
$t->frame();
same(HeadGestureDetector::SHAKE_NO, $s->gestures->lastGesture, 'Shake (No) head gesture recognized from 0x0017 stream');
check($t->byText('Last: Shake No (1)') !== null, 'UI displays Shake No count');

// Open-lid / Pairing Modal Popup test
$t->clickText('Pair AirPods 4');
$t->frame();
check($s->popupOpen && $t->byText('Connected over L2CAP 0x1001 (AACP Active)') !== null, 'Open-lid pairing modal popup opens');
$t->clickText('Done');
$t->frame();
check(!$s->popupOpen && $s->popupDismissed, 'Done closes the open-lid modal');

// Smart Routing / TiPi Multi-Device Handoff with iPhone test
same(2, count($s->connectedDevices), '2 simultaneous Multipoint devices (Xiaomi 14U + iPhone) decoded from Opcode 0x002E');
same('F0:D1:A9:11:22:33', $s->peerMac(), 'peer iPhone MAC detected');
check(!$s->ownsConnection && $s->audioSourceMac === 'F0:D1:A9:11:22:33', 'iPhone initially owns audio source');

$t->clickText('Take Over from iPhone');
$t->frame();
check($s->ownsConnection && $s->audioSourceMac === 'AA:BB:CC:14:04:01', 'Take Over from iPhone claims ownership and switches audio source to Xiaomi 14U');

$t->clickText('Hand Back to iPhone');
$t->frame();
check(!$s->ownsConnection && $s->audioSourceMac === 'F0:D1:A9:11:22:33', 'Hand Back to iPhone releases ownership back to iPhone');

// 5. Opcode 0x0031 Proximity Keys (IRK + ENC_KEY) & 3-Device Rotation Auto-Takeover
check($s->irk !== null && strlen($s->irk) === 16 && $s->encKey !== null && strlen($s->encKey) === 16, 'IRK and ENC_KEY received from Opcode 0x0031');
$rpa = AirPodsCrypto::generateRpa($s->irk, "\x11\x22\x45");
check(AirPodsCrypto::verifyRpa($rpa, $s->irk), 'RPA generated with AirPods IRK verifies via Bluetooth ah()');
check(!AirPodsCrypto::verifyRpa('12:34:56:78:9A:BC', $s->irk), 'random non-matching MAC rejected by verifyRpa');

// Encrypt a 16-byte BLE tail with ENC_KEY: left=93% discharging, right=87% charging (0x80|87), case=64% charging (0x80|64)
$plainTail = "\x00" . chr(93) . chr(0x80 | 87) . chr(0x80 | 64) . str_repeat("\x00", 12);
$encTail = AirPodsCrypto::encryptBlock($plainTail, $s->encKey);
$bleHeader11 = substr(AirPodsBleBroadcast::encode(), 0, 10) . "\x05"; // byte 10 = 0x05 (Music)
$decBle = AirPodsCrypto::decryptBleBattery($bleHeader11 . $encTail, $s->encKey);
same([93, 87, 64, false, true, true, 'Music'], [
    $decBle['leftBattery'],
    $decBle['rightBattery'],
    $decBle['caseBattery'],
    $decBle['leftCharging'],
    $decBle['rightCharging'],
    $decBle['caseCharging'],
    $decBle['connState'],
], 'ENC_KEY decrypts exact 1% BLE battery and Music state');

// Simulate 3-device rotation: Xiaomi 14U evicted to standby (disconnected), then local audio starts playing
$s->transport->disconnect();
check(!$s->transport->isConnected(), 'Xiaomi 14U in 3rd standby slot (disconnected)');
$s->onLocalAudioStarted();
$t->frame();
check($s->transport->isConnected() && $s->ownsConnection && $s->audioSourceMac === 'AA:BB:CC:14:04:01', '3-device rotation: local audio playback auto-reconnects L2CAP 0x1001 and takes over audio');



