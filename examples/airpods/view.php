<?php
// The window's view of the state main.php makes, $this in it (Content::file): saved, it hot reloads.
declare(strict_types=1);

use MyPHP\Examples\Airpods\{AacpProtocol, HeadGestureDetector, L2capProbeResult, };
use MyPHP\UI\{Context, Font, Node};

$openLidModal = function (Context $c, AirPodsApp $s, bool $connected) use (&$podCard): void {
    $t = $c->theme;
    $open = $s->popupOpen;
    if ($c->changed($c->modal($open, function () use ($c, $s, $t, $connected, $podCard) {
        $c->column(20, 14, 0, $t->surface)->size(min(360.0, $c->width - 32.0), 0)->radius(16)->border(1, $t->border);
          $c->column(0, 4)->alignItems(Node::CENTER);
            $c->text($s->deviceName, 20, style: Font::BOLD);
            $sub = $s->modelNumber . ($s->bleRssi > -100 ? sprintf(' · RSSI %d dBm', $s->bleRssi) : '');
            $c->muted($sub, 12);
          $c->end();

          $c->row(0, 8)->alignItems(Node::STRETCH);
            $podCard($c, 'Left', $s->leftBattery, AirPodsApp::statusLabel($s->leftStatus), AirPodsApp::earLabel($s->primaryEar));
            $podCard($c, 'Right', $s->rightBattery, AirPodsApp::statusLabel($s->rightStatus), AirPodsApp::earLabel($s->secondaryEar));
            $podCard($c, 'Case', $s->caseBattery, AirPodsApp::statusLabel($s->caseStatus), $s->lidOpen ? 'Open' : 'Closed');
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
};

$controlsCard = function (Context $c, AirPodsApp $s, bool $connected): void {
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
};

$packetLogCard = function (Context $c, AirPodsApp $s): void {
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
};

$podCard = function (Context $c, string $title, ?int $battery, string $status, string $placement): void {
    $t = $c->theme;
    $c->column(10, 4, 1, $t->surface)->basis(0)->radius(8)->border(1, $t->border);
      $c->muted($title, 11);
      $c->text($battery === null ? '--' : $battery . '%', 20, style: Font::BOLD);
      $c->text($placement . ' · ' . $status, 10, $t->textMuted)->singleLine();
    $c->end();
};

return function (Context $c) use (&$podCard, &$controlsCard, &$packetLogCard, &$openLidModal): void {
    $this->pollTransport();
    $t = $c->theme;
    $connected = $this->transport->isConnected();
    $compact = $c->width < 680;

    $root = $c->column(12, 10, 1, $t->background);
    if ($compact) {
        $root->scroll($this->pageScroll);
    }

      // 1. Top Header + Xiaomi 14 Ultra L2CAP 0x1001 Stack Probe Card
      $c->column(12, 8, 0, $t->surface)->radius(8)->border(1, $t->border);
        $c->text('Step 1 · Xiaomi 14 Ultra L2CAP 0x1001 Stack Probe', 14, style: Font::BOLD);
        $verdictText = match ($this->probe->verdict) {
            L2capProbeResult::VERDICT_NATIVE_READY => 'Android 16 QPR3+ Ready (Rootless L2CAP OK)',
            L2capProbeResult::VERDICT_STACK_BLOCKED => 'Fluoride Blocked 0x1001 (Root Patch Needed)',
            L2capProbeResult::VERDICT_BLE_ONLY => 'BLE 0x004C Only',
            default => 'Untested',
        };
        $verdictColor = match ($this->probe->verdict) {
            L2capProbeResult::VERDICT_NATIVE_READY => $t->success,
            L2capProbeResult::VERDICT_STACK_BLOCKED => $t->warning,
            default => $t->textMuted,
        };
        $c->text($verdictText, 12, $verdictColor, Font::BOLD);

        $c->text($this->probe->detail, 12, $t->textMuted);
        if ($this->probe->ctorSignature !== '') {
            $ctorText = $compact && strlen($this->probe->ctorSignature) > 36
                ? substr($this->probe->ctorSignature, 0, 36) . '…'
                : $this->probe->ctorSignature;
            $c->text('JNI Ctor: ' . $ctorText, 11, $t->accent, Font::MONO);
        }

        $c->row(0, 10);
          $c->text('1. BLE 0x004C: ' . ($this->probe->bleBeaconSeen ? 'OK' : 'No'), 11, $this->probe->bleBeaconSeen ? $t->success : $t->textMuted, Font::MONO);
          $c->text('2. L2CAP 0x1001: ' . ($this->probe->l2capSocketOpened ? 'OK' : 'Blocked'), 11, $this->probe->l2capSocketOpened ? $t->success : $t->danger, Font::MONO);
          $c->text('3. Handshake: ' . ($this->probe->handshakeAcked ? 'OK' : 'No ACK'), 11, $this->probe->handshakeAcked ? $t->success : $t->danger, Font::MONO);
        $c->end();

        $c->row(0, 8);
          if ($c->clicked($c->key('run-probe')->primaryButton('Probe L2CAP 0x1001'))) {
              $this->runProbe();
          }
          if ($c->clicked($c->key('open-pair-sheet')->button('Pair AirPods 4'))) {
              $this->popupOpen = true;
              $this->popupDismissed = false;
          }
        $c->end();
        if ($this->androidBt !== null) {
            $simOn = $this->useSimulator;
            if ($c->changed($c->key('use-sim')->checkbox('Use AirPods 4 Simulator', $simOn))) {
                $this->setSimulatorMode($simOn);
            }
        }
        if ($this->useSimulator) {
            $blockSim = $this->sim->simulateStackBlocked;
            if ($c->changed($c->key('sim-block')->checkbox('Simulate Pre-QPR3 Fluoride Block', $blockSim))) {
                $this->sim->simulateStackBlocked = $blockSim;
                $this->runProbe();
            }
        }
      $c->end();

      // 1b. Apple VendorID (0x004C) & iPhone Seamless Handoff (TiPi / Smart Routing) Card
      $c->column(12, 8, 0, $t->surface)->radius(8)->border(1, $t->border);
        $c->text('Step 2 · iPhone Seamless Handoff (TiPi · 0x004C)', 14, style: Font::BOLD);
        $isAppleDid = strcasecmp($this->vendorId, '0x004C') === 0 || $this->vendorId === '76';
        $c->row(0, 12);
          $c->text($isAppleDid ? 'DID: 0x004C (Apple)' : 'DID: ' . ($this->vendorId ?: 'Default'), 12, $isAppleDid ? $t->success : $t->warning, Font::BOLD | Font::MONO);
          $audioOwnerLabel = $this->ownsConnection
              ? 'Audio Owner: Xiaomi 14U'
              : ($this->audioSourceMac !== '' ? 'Audio Owner: Peer (' . $this->audioSourceMac . ')' : 'Audio Owner: Idle');
          $c->text($audioOwnerLabel, 12, $this->ownsConnection ? $t->success : $t->accent, Font::MONO);
        $c->end();

        $activeSlots = [];
        $evictedSlots = [];
        foreach ($this->connectedDevices as $dev) {
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
            $this->irk !== null ? 'OK (16B)' : '--',
            $this->encKey !== null ? 'OK (16B)' : '--',
            $this->rpaVerified ? ' · RPA Verified' : ($this->bleConnState !== '' ? ' · BLE:' . $this->bleConnState : ''),
        );
        $c->text($keysText, 11, $this->irk !== null ? $t->success : $t->textMuted, Font::MONO);

        $autoTake = $this->autoTakeoverEnabled;
        if ($c->changed($c->key('ctrl-autotake')->switch('Auto-Takeover on Phone Play (2 & 3-Device)', $autoTake))) {
            $this->autoTakeoverEnabled = $autoTake;
        }

        $c->row(0, 8);
          if ($c->clicked($c->key('btn-takeover')->primaryButton('Take Over from iPhone'))) {
              $this->onLocalAudioStarted();
          }
          if ($c->clicked($c->key('btn-handback')->button('Hand Back to iPhone')->disabled(!$connected))) {
              $this->handBackToPeer();
          }
        $c->end();
      $c->end();

      // 2. Device Overview + Battery & Ear Detection Cards
      $c->column(12, 8, 0, $t->surface)->radius(8)->border(1, $t->border);
        $c->row(0, 8);
          $c->text($this->deviceName, 16, style: Font::BOLD);
          $c->spacer();
          $c->text($this->lidOpen ? 'Case Lid Open' : 'Case Lid Closed', 12, $this->lidOpen ? $t->accent : $t->textMuted);
        $c->end();
        $c->muted($this->modelNumber . ' · FW ' . $this->firmware, 12);
        $c->row(0, 8);
          $c->input($this->renameInput, 'AirPods Name', 160);
          if ($c->clicked($c->key('btn-rename')->button('Rename')->disabled(!$connected))) {
              $this->rename($this->renameInput->text);
          }
          if ($c->clicked($c->key('btn-lid')->button($this->lidOpen ? 'Close Lid' : 'Open Lid'))) {
              $this->sim->lidOpen = !$this->sim->lidOpen;
              $this->lidOpen = $this->sim->lidOpen;
              $this->popupOpen = $this->lidOpen;
              $this->popupDismissed = !$this->lidOpen;
          }
        $c->end();
      $c->end();

      $c->row(0, 8)->alignItems(Node::STRETCH);
        $podCard($c, 'Left Pod', $this->leftBattery, AirPodsApp::statusLabel($this->leftStatus), AirPodsApp::earLabel($this->primaryEar));
        $podCard($c, 'Right Pod', $this->rightBattery, AirPodsApp::statusLabel($this->rightStatus), AirPodsApp::earLabel($this->secondaryEar));
        $podCard($c, 'Case', $this->caseBattery, AirPodsApp::statusLabel($this->caseStatus), $this->lidOpen ? 'Lid Open' : 'Lid Closed');
      $c->end();

      // 3. AirPods 4 Controls (AACP Opcode 0x0009 & 0x0017) + Live Packet Inspector
      if ($compact) {
          $controlsCard($c, $this, $connected);
          $packetLogCard($c, $this);
      } else {
          $c->row(0, 12, 1)->alignItems(Node::STRETCH);
            $controlsCard($c, $this, $connected);
            $packetLogCard($c, $this);
          $c->end();
      }

      if ($this->popupOpen) {
          $openLidModal($c, $this, $connected);
      }

    $c->end();
};
