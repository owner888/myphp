<?php
// The window's view of the state main.php makes, $this in it (Content::file): saved, it hot reloads.
declare(strict_types=1);

use MyPHP\Examples\Airpods\{AacpProtocol, HeadGestureDetector, L2capProbeResult};

// AirPods 4 as the Mac's System Settings shows them: tabs of the AirPods' settings, the connection to them and the
// packets they send and receive — a sidebar on the desktop and a tablet on its side, tabs across the top of a tablet held
// upright, the tab bar of a phone; the sheet an iPhone shows as the case's lid opens. Its text in English or Chinese
// (strings/, as SwiftUI's String Catalog), the language, the appearance and the text size chosen in its Settings tab.

/** The language the app shows: the one chosen in Settings, else the system's. */
$locale = fn() => $this->lang !== '' ? $this->lang : \MyPHP\I18n::locale();
/** $key said in that language, for what shows a string as it is (a LabeledContent's value), as SwiftUI's String(localized:). */
$tr = fn(string $key, array $args = []) => \MyPHP\Catalog::say($locale(), $key, $args) ?? ($args === [] ? $key : \MyPHP\Message::parse($key)->format($locale(), $args));

/** A setting sent to the AirPods as the user changes it: what the app reads back from them sets it without sending it again. */
$sent = fn(string $field, int $id, Closure $data, string $label) => new Binding(
    get: fn() => $this->$field,
    set: function ($v) use ($field, $id, $data, $label) { $this->$field = $v; $this->sendControl($id, $data($v), 0, $label); },
);

/** A choice among $names as a row of the form: its label, a pop-up button. */
$choice = fn(string $label, array $names, Binding &$selection) => Picker($label, selection: $selection)(Each($names)(fn(string $n, int $i) => Text($n)->tag($i)));

/** A row stating how a check went: its name, and a check mark or a cross. */
$check = fn(string $name, bool $ok, string $no = 'No') => HStack()( // $tr, captured by the arrow function
    Text($name),
    Spacer(),
    Text(verbatim: $ok ? '✓ ' . $tr('OK') : '✕ ' . $tr($no))->foregroundStyle($ok ? Color::green : Color::red),
);

/** A pod's or the case's battery: its charge as a bar, where it is and whether it charges. */
$battery = fn(string $name, ?int $level, string $status, string $placement) => VStack(alignment: HorizontalAlignment::leading, spacing: 6)(
    Text($name)->font(Font::caption)->foregroundStyle(Color::secondary),
    Text(verbatim: $level === null ? '--' : $level . '%')->font(Font::system(size: 22, weight: Weight::semibold)),
    ProgressView(value: ($level ?? 0) / 100)->tint($level !== null && $level <= 20 ? Color::red : Color::green),
    Text(verbatim: $tr($placement) . ' · ' . $tr($status))->font(Font::caption)->foregroundStyle(Color::secondary)->lineLimit(1),
)->padding(12)->frame(maxWidth: INF, alignment: Alignment::leading)->background(Color::secondarySystemBackground)->cornerRadius(10);

$batteries = fn() => HStack(spacing: 10)(
    $battery('Left', $this->leftBattery, AirPodsApp::statusLabel($this->leftStatus), AirPodsApp::earLabel($this->primaryEar)),
    $battery('Right', $this->rightBattery, AirPodsApp::statusLabel($this->rightStatus), AirPodsApp::earLabel($this->secondaryEar)),
    $battery('Case', $this->caseBattery, AirPodsApp::statusLabel($this->caseStatus), $this->lidOpen ? 'Lid open' : 'Lid closed'),
);

/** Connected, or not: a dot and a word in a capsule. */
$status = fn(bool $connected) => HStack(spacing: 6)(
    Circle()->fill($connected ? Color::green : Color::secondary)->frame(width: 8, height: 8),
    Text($connected ? 'Connected' : 'Not connected')->font(Font::caption),
)->padding(Edge::vertical, 4)->padding(Edge::horizontal, 10)->background(Color::secondarySystemBackground)->clipShape(Capsule());

/** The AirPods: their name and batteries, then their settings, each sent as it changes (AACP 0x0009 and 0x0017). */
$settings = function (bool $connected) use ($sent, $choice, $batteries, $status, $tr) {
    $onOff = fn(bool $on) => $on ? 0x01 : 0x02;
    $same = fn(int $v) => $v;
    $b = [
        'listening' => $sent('listeningMode', AacpProtocol::CMD_LISTENING_MODE, fn(int $v) => $v + 1, 'Listening Mode'),
        'conversation' => $sent('conversationalAwareness', AacpProtocol::CMD_CONVERSATION_DETECT, $onOff, 'Conversational Awareness'),
        'ear' => $sent('earDetectionEnabled', AacpProtocol::CMD_EAR_DETECTION, $onOff, 'Ear Detection'),
        'connect' => $sent('autoConnectEnabled', AacpProtocol::CMD_CONNECT_AUTOMATICALLY, $onOff, 'Connect Automatically'),
        'sleep' => $sent('sleepDetectionEnabled', AacpProtocol::CMD_SLEEP_DETECTION, $onOff, 'Sleep Detection'),
        'mic' => $sent('micMode', AacpProtocol::CMD_MIC_MODE, $same, 'Mic Mode'),
        'call' => $sent('endCallMode', AacpProtocol::CMD_CALL_MANAGEMENT, fn(int $v) => $v === 0 ? 0x01 : 0x02, 'Call Management'),
        'speed' => $sent('pressSpeed', AacpProtocol::CMD_DOUBLE_CLICK_INTERVAL, $same, 'Press Speed'),
        'hold' => $sent('holdDuration', AacpProtocol::CMD_CLICK_HOLD_INTERVAL, $same, 'Hold Duration'),
    ];
    $last = $this->gestures->lastGesture;
    $off = !$connected; // the settings, not the AirPods' name and batteries, wait for the connection
    return Form()(
        Section()(
            HStack(alignment: VerticalAlignment::top)(
                VStack(alignment: HorizontalAlignment::leading, spacing: 4)(
                    Text(verbatim: $this->deviceName)->font(Font::title2)->bold(),
                    Text('{model} · Firmware {firmware}', ['model' => $this->modelNumber, 'firmware' => $this->firmware])->font(Font::subheadline)->foregroundStyle(Color::secondary),
                ),
                Spacer(),
                $status($connected),
            ),
            $batteries(),
        ),
        Section(header: 'Noise Control', footer: 'AACP 0x0009, setting 0x0D.')(
            Picker('Noise Control', selection: $b['listening'])(Each(AirPodsApp::LISTENING_MODES)(fn(string $n, int $i) => Text($n)->tag($i)))
                ->pickerStyle(PickerStyle::segmented)->labelsHidden(),
        )->disabled($off),
        Section(header: 'General', footer: 'Settings 0x28, 0x0A, 0x20 and 0x35.')(
            Toggle('Conversational Awareness', isOn: $b['conversation'])->toggleStyle(ToggleStyle::switch),
            Toggle('Automatic Ear Detection', isOn: $b['ear'])->toggleStyle(ToggleStyle::switch),
            Toggle('Connect Automatically', isOn: $b['connect'])->toggleStyle(ToggleStyle::switch),
            Toggle('Pause Media When Falling Asleep', isOn: $b['sleep'])->toggleStyle(ToggleStyle::switch),
        )->disabled($off),
        Section(header: 'Press and Hold', footer: 'Settings 0x17 and 0x18.')(
            $choice('Press Speed', AirPodsApp::SPEED_MODES, $b['speed']),
            $choice('Press and Hold Duration', AirPodsApp::HOLD_MODES, $b['hold']),
        )->disabled($off),
        Section(header: 'Calls and Microphone', footer: 'Settings 0x24 and 0x01.')(
            $choice('End Call', AirPodsApp::CALL_END_MODES, $b['call']),
            $choice('Microphone', AirPodsApp::MIC_MODES, $b['mic']),
        )->disabled($off),
        Section(header: 'Head Gestures', footer: 'Nod to answer, shake to decline: the head-tracking stream, AACP 0x0017.')(
            LabeledContent('Last gesture', match ($last) {
                HeadGestureDetector::NOD_YES => $tr('Nod Yes ({count})', ['count' => $this->gestures->nodCount]),
                HeadGestureDetector::SHAKE_NO => $tr('Shake No ({count})', ['count' => $this->gestures->shakeCount]),
                default => $tr('None'),
            }),
            Button($this->headTrackingActive ? 'Stop Head Tracking' : 'Start Head Tracking', action: fn() => $this->setHeadTracking(!$this->headTrackingActive)),
            Button('Simulate Nod', action: fn() => $this->triggerSimulatedGesture(HeadGestureDetector::NOD_YES)),
            Button('Simulate Shake', action: fn() => $this->triggerSimulatedGesture(HeadGestureDetector::SHAKE_NO)),
        )->disabled($off),
        Section(header: 'Name')(
            HStack(spacing: 8)(
                TextField('Name', text: $this->renameText)->labelsHidden(),
                Button('Rename', action: fn() => $this->rename($this->renameText))->disabled(trim($this->renameText) === '' || $this->renameText === $this->deviceName),
            ),
        )->disabled($off),
    )->navigationTitle('AirPods 4');
};

/** How the phone reaches the AirPods: its stack opening L2CAP 0x1001, pairing, and handing the audio over to and from an iPhone. */
$connection = function (bool $connected) use ($check, $tr) {
    $p = $this->probe;
    $simulated = new Binding(get: fn() => $this->useSimulator, set: fn(bool $on) => $this->setSimulatorMode($on));
    $blocked = new Binding(get: fn() => $this->sim->simulateStackBlocked, set: function (bool $on) { $this->sim->simulateStackBlocked = $on; $this->runProbe(); });
    $apple = strcasecmp($this->vendorId, '0x004C') === 0 || $this->vendorId === '76';
    [$active, $standby] = [[], []];
    foreach ($this->connectedDevices as $dev) { $dev['info1'] > 0 ? $active[] = "{$dev['type']} ({$dev['mac']})" : $standby[] = "{$dev['type']} ({$dev['mac']})"; }
    return Form()(
        Section(header: 'L2CAP Stack Probe', footer: $p->detail)(
            Text(match ($p->verdict) {
                L2capProbeResult::VERDICT_NATIVE_READY => 'Android 16 QPR3+ Ready (Rootless L2CAP OK)',
                L2capProbeResult::VERDICT_STACK_BLOCKED => 'Fluoride Blocked 0x1001 (Root Patch Needed)',
                L2capProbeResult::VERDICT_BLE_ONLY => 'BLE 0x004C Only',
                default => 'Untested',
                })->font(Font::headline)->foregroundStyle(match ($p->verdict) {
                L2capProbeResult::VERDICT_NATIVE_READY => Color::green,
                L2capProbeResult::VERDICT_STACK_BLOCKED => Color::orange,
                default => Color::secondary,
                }),
            $check('BLE beacon 0x004C', $p->bleBeaconSeen),
            $check('L2CAP socket 0x1001', $p->l2capSocketOpened, 'Blocked'),
            $check('AACP handshake', $p->handshakeAcked, 'No ACK'),
            $p->ctorSignature !== '' ? LabeledContent('JNI constructor', $p->ctorSignature) : null,
            Button('Probe Again', action: fn() => $this->runProbe()),
        ),
        Section(header: 'Pairing')(
            LabeledContent('Address', $this->macAddress !== '' ? $this->macAddress : '--'),
            Button('Pair AirPods 4', action: function () { $this->popupOpen = true; $this->popupDismissed = false; }),
            Button($this->lidOpen ? 'Close Case Lid' : 'Open Case Lid', action: function () {
                $this->sim->lidOpen = !$this->sim->lidOpen;
                $this->lidOpen = $this->sim->lidOpen;
                $this->popupOpen = $this->lidOpen;
                $this->popupDismissed = !$this->lidOpen;
            }),
            $this->androidBt !== null ? Toggle('Use the AirPods 4 Simulator', isOn: $simulated)->toggleStyle(ToggleStyle::switch) : null,
            $this->useSimulator ? Toggle('Simulate Pre-QPR3 Fluoride Block', isOn: $blocked)->toggleStyle(ToggleStyle::switch) : null,
        ),
        Section(header: 'iPhone Handoff', footer: 'Apple\'s vendor ID 0x004C; the keys of opcode 0x0031 tell the AirPods\' private addresses and decrypt their batteries.')(
            LabeledContent('Vendor ID', $apple ? $tr('0x004C (Apple)') : ($this->vendorId ?: $tr('Default'))),
            LabeledContent('Audio owner', $this->ownsConnection ? $tr('This phone') : ($this->audioSourceMac !== '' ? $tr('iPhone ({address})', ['address' => $this->audioSourceMac]) : $tr('Idle'))),
            LabeledContent('Multipoint', $active !== [] ? $tr('{count} of 2: {devices}', ['count' => count($active), 'devices' => implode(', ', $active)]) : $tr('1 of 2: this phone')),
            $standby !== [] ? LabeledContent('Standby', implode(', ', $standby)) : null,
            LabeledContent('Identity key', $this->irk !== null ? $tr($this->rpaVerified ? 'Received · address verified' : 'Received') : '--'),
            LabeledContent('Encryption key', $this->encKey !== null ? $tr('Received') : '--'),
            Toggle('Take Over When This Phone Plays', isOn: $this->autoTakeoverEnabled)->toggleStyle(ToggleStyle::switch),
            Button('Take Over from iPhone', action: fn() => $this->onLocalAudioStarted()),
            Button('Hand Back to iPhone', action: fn() => $this->handBackToPeer())->disabled(!$connected),
        ),
    )->navigationTitle('Connection');
};

/** The frames sent and received, newest last, only those in view built. */
$packets = fn() => ListView(count($this->packets))(function (int $i) {
        $p = $this->packets[$i];
        return HStack(alignment: VerticalAlignment::top, spacing: 10)(
            Text(verbatim: $p['dir'])->font(Font::caption)->bold()->monospaced()
                ->foregroundStyle($p['dir'] === 'TX' ? Color::accentColor : ($p['dir'] === 'RX' ? Color::green : Color::orange))->frame(width: 28, alignment: Alignment::leading),
            VStack(alignment: HorizontalAlignment::leading, spacing: 2)(
                Text(verbatim: $p['summary'])->lineLimit(1),
                Text(verbatim: $p['hex'])->font(Font::caption)->monospaced()->foregroundStyle(Color::secondary)->lineLimit(1),
            ),
        );
    })->listStyle(ListStyle::plain)
    ->navigationTitle('Packet Log')
    ->toolbar(Text('{count, plural, one {# frame} other {# frames}}', ['count' => count($this->packets)])->foregroundStyle(Color::secondary));

/** The sheet the case's lid opening brings up, as an iPhone's: the batteries, how to pair, and connecting. */
$lidSheet = function (bool $connected) use ($batteries, $tr) {
    $pairing = !$this->isBonded && !$this->useSimulator;
    return VStack(spacing: 16)(
        VStack(spacing: 4)(
            Text(verbatim: $this->deviceName)->font(Font::title2)->bold(),
            Text(verbatim: $this->modelNumber . ($this->bleRssi > -100 ? sprintf(' · %d dBm', $this->bleRssi) : ''))->font(Font::subheadline)->foregroundStyle(Color::secondary),
        ),
        $batteries(),
        $pairing
            ? VStack(alignment: HorizontalAlignment::leading, spacing: 4)(
                Text('First-time pairing')->font(Font::headline),
                Each(['Open the case near this phone.', 'Hold the case\'s button until its light flashes white.', 'Click Connect & Pair.'])(
                    fn(string $step, int $i) => Text(verbatim: ($i + 1) . '. ' . $tr($step))->foregroundStyle(Color::secondary)),
                $this->pairState !== 'idle' ? Text('Status: {state}', ['state' => $this->pairState])->font(Font::caption)->monospaced()->foregroundStyle(Color::orange) : null,
            )->frame(maxWidth: INF, alignment: Alignment::leading)
            : Text($connected ? 'Connected over L2CAP 0x1001' : 'Paired · ready to connect')->foregroundStyle($connected ? Color::green : Color::secondary),
        HStack(spacing: 10)(
            Spacer(),
            Button('Done', action: function () { $this->popupOpen = false; $this->popupDismissed = true; }),
            $pairing ? Button(str_starts_with($this->pairState, 'searching') ? 'Searching…' : 'Connect & Pair', action: fn() => $this->startPairing())->buttonStyle(ButtonStyle::borderedProminent) : null,
            !$pairing && !$connected ? Button('Connect', action: fn() => $this->runProbe())->buttonStyle(ButtonStyle::borderedProminent) : null,
        ),
    )->padding(24)->frame(width: 420);
};

/** The app's own settings, as an iPhone app's Settings tab: its appearance, its text size and its language. */
$preferences = fn() => Form()(
    Section(header: 'Appearance')(
        Picker('Appearance', selection: $this->appearance)(Text('System')->tag('system'), Text('Light')->tag('light'), Text('Dark')->tag('dark'))
            ->pickerStyle(PickerStyle::segmented)->labelsHidden(),
    ),
    Section(header: 'Text Size', footer: 'The size of the app\'s text, as the system\'s Text Size sets it for every app.')(
        Picker('Text Size', selection: $this->textSize)(Each(AirPodsApp::TEXT_SIZES)(fn(string $label, string $size) => Text($label)->tag($size))),
    ),
    Section(header: 'Language')(
        Picker('Language', selection: $this->lang)(Text('System')->tag(''), Text(verbatim: 'English')->tag('en'), Text(verbatim: '简体中文')->tag('zh-Hans')), // each language in its own words
    ),
)->navigationTitle('Settings');

return function () use ($settings, $connection, $packets, $lidSheet, $preferences) {
    $this->pollTransport();
    $connected = $this->transport->isConnected();
    return TabView(selection: $this->page)(
        Tab('AirPods 4', systemImage: 'airpods', value: 'AirPods')($settings($connected)),
        Tab('Connection', systemImage: 'antenna.radiowaves.left.and.right', value: 'Connection')($connection($connected)),
        Tab('Packet Log', systemImage: 'list.bullet.rectangle', value: 'Packets')($packets())->badge(count($this->packets)),
        Tab('Settings', systemImage: 'gearshape', value: 'Settings')($preferences()),
    )->tabViewStyle(TabViewStyle::sidebarAdaptable)->sheet(isPresented: $this->popupOpen, content: fn() => $lidSheet($connected))
        ->onChange(of: $this->popupOpen, action: function ($old, $open) { if (!$open) { $this->popupDismissed = true; } })
        ->preferredColorScheme(match ($this->appearance) { 'light' => ColorScheme::light, 'dark' => ColorScheme::dark, default => null })
        ->dynamicTypeSize($this->textSize === 'system' ? null : constant(DynamicTypeSize::class . '::' . $this->textSize))
        ->environment(locale: $this->lang !== '' ? $this->lang : null);
};
