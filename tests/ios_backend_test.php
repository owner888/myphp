<?php
// The iOS backend's contracts, Device form factors, and mobile UI fixes:
// - iOS bundle layout (Info.plist and resources at the bundle's root)
// - Device::type() (Desktop, Mobile, Pad, Tv) and Device::isMobile / isPad / isDesktop / isTv / isTouch
// - TextState::deleteBack() (used by iOS and Android deleteBackward)
// - Switching between ScrollView and TabView at the same tree position without state collision
// - Button with a custom label and frame(maxWidth: INF) stretching across the row
// - LazyVGrid with GridItem::flexible() and GridItem::fixed(), and clickable custom Buttons inside
// - TabView following ->tint() and hiding the floating tab bar on ->toolbarVisibility(Visibility::hidden, for: ToolbarPlacement::tabBar)
// - LinearGradient view, HStack(alignment: VerticalAlignment::firstTextBaseline), Toggle with ?bool null binding
// - Android Secrets AES-256-GCM encrypted store
// - What a phone does not have throwing Platform\Unsupported
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\{AboutPanelOptions, App, AppHandler, Device, DeviceType, Dock, Menu, MenuItem, OpenDialogOptions, PathName, SaveDialogOptions, SecretNotFound};
use MyPHP\Native\Bundle;
use MyPHP\Platform\Android\Secrets as AndroidSecrets;
use MyPHP\Platform\iOS\Backend;
use MyPHP\Platform\Unsupported;
use MyPHP\UI\{Content, TextState};

(function (): void {
    $throwsUnsupported = function (\Closure $fn): bool {
        try {
            $fn();
            return false;
        } catch (Unsupported) {
            return true;
        }
    };

    // 1. Device form factor classification (Desktop, Mobile, Pad, Tv).
    same(DeviceType::Desktop, Device::type(), 'on desktop host Device::type() is Desktop');
    check(Device::isDesktop() && !Device::isMobile() && !Device::isPad() && !Device::isTv() && !Device::isTouch(), 'Desktop predicates');
    Device::setType(DeviceType::Mobile);
    check(Device::isMobile() && Device::isTouch() && !Device::isDesktop() && !Device::isPad(), 'Mobile predicates');
    Device::setType(DeviceType::Pad);
    check(Device::isPad() && Device::isTouch() && !Device::isMobile() && !Device::isDesktop(), 'Pad predicates');
    Device::setType(DeviceType::Tv);
    check(Device::isTv() && !Device::isTouch() && !Device::isDesktop(), 'Tv predicates');
    Device::setType(null);

    // 2. TextState::deleteBack() (called by iOS and Android backspace handlers).
    $ts = new TextState('你好a');
    $ts->deleteBack();
    same('你好', $ts->text, 'TextState::deleteBack deletes the character before the caret');
    $ts->deleteBack();
    same('你', $ts->text, 'TextState::deleteBack deletes a multi-byte UTF-8 character');

    // 3. Switching between a login ScrollView and a TabView at the same node position without crashing.
    $loggedIn = false;
    $tab = 'home';
    $h = new Headless(Content::view(function () use (&$loggedIn, &$tab) {
        if (!$loggedIn) {
            return ScrollView()(
                Text('Login Page'),
                Button('Sign In', action: function () use (&$loggedIn) { $loggedIn = true; }),
            );
        }
        return TabView(selection: $tab)(
            Tab('Home', systemImage: 'house', value: 'home')(Text('Welcome Home')),
            Tab('Me', systemImage: 'person', value: 'me')(Text('Profile')),
        );
    })->view, 390, 844);
    $h->rt->touch = true;
    $h->frame();
    check($h->byText('Login Page') !== null, 'login ScrollView shows first');
    $h->clickText('Sign In');
    $h->frame();
    check($loggedIn && $h->byText('Welcome Home') !== null, 'switching to TabView at the same position succeeds without state collision');

    // 4. Custom-label Button with frame(maxWidth: INF) stretches across the parent and its inner card fills it.
    $cardClicked = 0;
    $h = new Headless(Content::view(function () use (&$cardClicked) {
        return VStack()(
            Button(action: function () use (&$cardClicked) { $cardClicked++; }, label:
                VStack(alignment: \HorizontalAlignment::leading)(
                    Text('Full Width Card'),
                )->padding(12)->frame(maxWidth: INF, alignment: \Alignment::leading)->background(\Color::secondarySystemBackground)
            )->buttonStyle(\ButtonStyle::plain),
        )->padding(16);
    })->view, 390, 844);
    $h->frame();
    $btnNode = $h->root->kids[0]->kids[0];
    $cardNode = $btnNode->kids[0];
    $br = $h->rect($btnNode);
    same([358.0, 358.0], [$br[2], $h->rect($cardNode)[2]], 'both the Button and its inner card stretch across the 358 pt width');
    $h->click($br[0] + $br[2] - 10, $br[1] + $br[3] / 2); // click near the trailing edge of the card, far from the text
    same(1, $cardClicked, 'clicking the stretched trailing area of the custom Button triggers its action');

    // 5. LazyVGrid with GridItem::flexible() and GridItem::fixed(), and clickable custom Buttons.
    $gridClicks = [];
    $h = new Headless(Content::view(function () use (&$gridClicks) {
        return ScrollView()(
            LazyVGrid(columns: [\GridItem::fixed(100), \GridItem::flexible()], spacing: 10)(
                Each(['A', 'B', 'C', 'D'])(function (string $s) use (&$gridClicks) {
                    return Button(action: function () use (&$gridClicks, $s) { $gridClicks[] = $s; }, label:
                        VStack()(Text("Item $s"))->padding(8)->frame(maxWidth: INF)
                    )->buttonStyle(\ButtonStyle::plain);
                }),
            )->padding(10),
        );
    })->view, 390, 844);
    $h->frame();
    $gridNode = $h->root->kids[0]->kids[0];
    same([100.0, 260.0], [$h->rect($gridNode->kids[0])[2], $h->rect($gridNode->kids[1])[2]], 'GridItem::fixed(100) and GridItem::flexible() size columns to 100 and 260');
    $h->clickText('Item A');
    $h->clickText('Item D');
    same(['A', 'D'], $gridClicks, 'Buttons inside LazyVGrid fire their actions on click');

    // 6. TabView follows ->tint() and hides the floating tab bar when a pushed page sets toolbarVisibility(Visibility::hidden, for: ToolbarPlacement::tabBar).
    $activeTab = 'home';
    $navPath = new \NavigationPath();
    $h = new Headless(Content::view(function () use (&$activeTab, $navPath) {
        return TabView(selection: $activeTab)(
            Tab('Home', systemImage: 'house', value: 'home')(
                NavigationStack(path: $navPath)(
                    VStack()(
                        Text('Root Page'),
                        NavigationLink('Go Detail', value: '/detail'),
                    )->navigationTitle('Home'),
                )->navigationDestination(for: '/detail', destination: fn() =>
                    VStack()(Text('Detail Page'))
                        ->navigationTitle('Detail')
                        ->toolbarVisibility(\Visibility::hidden, for: \ToolbarPlacement::tabBar)
                ),
            ),
            Tab('Settings', systemImage: 'gearshape', value: 'settings')(Text('Settings Page')),
        )->tint(\Color::orange);
    })->view, 390, 844);
    $h->rt->touch = true;
    $h->frame();
    $tabHome = null;
    $findTab = static function (\MyPHP\UI\Node $n) use (&$findTab, &$tabHome): void {
        if (($n->kind === \MyPHP\UI\Node::RICH ? $n->plainText() : $n->text) === 'Home' && $n->fontSize == 10.0) { $tabHome = $n; }
        foreach ($n->kids as $k) { $findTab($k); }
    };
    $findTab($h->root);
    $findTab = null;
    same(\Color::orange->resolve($h->theme), $tabHome?->fg, 'TabView tab bar uses the ->tint() color for the selected tab');
    $h->clickText('Go Detail');
    $h->frame(2);
    check($h->byText('Detail Page') !== null && $h->byText('Settings') === null, 'pushed page with toolbarVisibility(hidden, tabBar) hides the floating tab bar');

    // 7. LinearGradient, VerticalAlignment::firstTextBaseline, and Toggle with ?bool null binding.
    $nullableOn = null;
    $h = new Headless(Content::view(function () use (&$nullableOn) {
        return VStack()(
            LinearGradient(colors: [\Color::blue, \Color::purple], startPoint: \UnitPoint::topLeading, endPoint: \UnitPoint::bottomTrailing)
                ->frame(height: 40),
            HStack(alignment: \VerticalAlignment::firstTextBaseline)(
                Text('Title')->font(\Font::title),
                Text('subtitle')->font(\Font::caption),
            ),
            Toggle('Allow', isOn: $nullableOn),
        );
    })->view, 390, 844);
    $h->frame();
    same(false, $nullableOn, 'Toggle initializes a null binding to false');
    check($h->root->kids[0]->kids[0]->gradient !== null || ($h->root->kids[0]->kids[0]->kids[0]->gradient ?? null) !== null, 'LinearGradient sets a linearGradient on its node');

    // 7b. SwiftUI Grid/GridRow, RadialGradient, AngularGradient, ContentUnavailableView, ShareLink, PhotosPicker, EmptyView, interactiveDismissDisabled, URLSession/URLRequest.
    $sheetOpen = false;
    $photoChosen = null;
    $h = new Headless(Content::view(function () use (&$sheetOpen, &$photoChosen) {
        return VStack()(
            Grid(horizontalSpacing: 10, verticalSpacing: 6)(
                GridRow()(Text('K1'), Text('V1')),
                GridRow()(Text('Span2')->gridCellColumns(2)),
            ),
            RadialGradient(colors: [\Color::red, \Color::blue])->frame(height: 20),
            AngularGradient(colors: [\Color::green, \Color::yellow])->frame(height: 20),
            ContentUnavailableView('No Items', systemImage: 'tray', description: 'Add an item to begin.')(
                Button('Create', action: function () use (&$sheetOpen) { $sheetOpen = true; }),
            ),
            ShareLink('https://myphp.ai', title: 'Share Site'),
            PhotosPicker('Pick Photo', selection: $photoChosen),
            EmptyView(),
        )->sheet(isPresented: $sheetOpen, content: fn() => Text('Locked Sheet')->interactiveDismissDisabled(true));
    })->view, 390, 844);
    $h->rt->touch = true;
    $h->frame();
    check($h->byText('K1') !== null && $h->byText('Span2') !== null, 'Grid and GridRow render cells');
    check($h->byText('No Items') !== null && $h->byText('Add an item to begin.') !== null, 'ContentUnavailableView renders title and description');
    $h->clickText('Create');
    $h->frame(2);
    check($sheetOpen && $h->byText('Locked Sheet') !== null, 'sheet opens');
    $h->escape();
    $h->frame();
    check($sheetOpen, 'interactiveDismissDisabled(true) prevents Escape / background tap dismissal');

    $req = (new \URLRequest('https://api.example.com/v1/items', 'POST', body: ['name' => 'demo']))->setBearerToken('tok123');
    same(['POST', 'Bearer tok123', 'application/json', '{"name":"demo"}'], [
        $req->httpMethod,
        $req->value('authorization'),
        $req->value('content-type'),
        $req->httpBody,
    ], 'URLRequest configures method, Bearer token and JSON body');
    $hydrated = \URLSession::hydrate(new class { public int $id = 0; public string $title = ''; }::class, ['id' => 7, 'title' => 'Book']);
    same([7, 'Book'], [$hydrated->id, $hydrated->title], 'URLSession::hydrate maps associative array to object properties');

    // 8. Android AES-256-GCM Secrets store.
    $sec = new AndroidSecrets();
    $sec->setSecret('myphp.test', 'token', 'secret-value-123');
    same('secret-value-123', $sec->secret('myphp.test', 'token'), 'Android Secrets encrypts and reads back the secret');
    $sec->deleteSecret('myphp.test', 'token');
    $notFound = false;
    try {
        $sec->secret('myphp.test', 'token');
    } catch (SecretNotFound) {
        $notFound = true;
    }
    check($notFound, 'deleted Android secret throws SecretNotFound');

    // 7c. TabViewStyle::page, VSplitView, FlowLayout, ViewThatFits, OutlineGroup, ControlGroup, redacted, TextField mobile modifiers.
    $pageIdx = 0;
    $tfVal = '';
    $treeData = [
        ['id' => 'root1', 'name' => 'Folder A', 'children' => [
            ['id' => 'leaf1', 'name' => 'File 1.txt', 'children' => []],
        ]],
    ];
    $h = new Headless(Content::view(function () use (&$pageIdx, &$tfVal, $treeData) {
        return VStack()(
            TabView($pageIdx)(
                Tab('First', value: 0)(Text('Page One')),
                Tab('Second', value: 1)(Text('Page Two')),
            )->tabViewStyle(\TabViewStyle::page(\PageIndexDisplayMode::always))->frame(height: 120),
            VSplitView()(
                Text('TopPane')->frame(idealHeight: 60),
                Text('BottomPane'),
            )->frame(height: 120),
            FlowLayout(spacing: 6)(Text('TagA'), Text('TagB')),
            ViewThatFits()(
                Text('WideCandidate')->frame(width: 900),
                Text('CompactFallback')->frame(width: 100),
            ),
            OutlineGroup($treeData, children: 'children', id: 'id')(fn(array $it) => Text($it['name'])),
            ControlGroup('Actions')(Button('Act1', action: fn() => null), Button('Act2', action: fn() => null)),
            Text('SecretPlaceholder')->redacted(\RedactionReasons::placeholder)->fixedSize(),
            TextField('Email', text: $tfVal)
                ->keyboardType(\KeyboardType::emailAddress)
                ->textContentType(\TextContentType::emailAddress)
                ->submitLabel(\SubmitLabel::done)
                ->textInputAutocapitalization(\TextInputAutocapitalization::never)
                ->autocorrectionDisabled(),
        );
    })->view, 390, 844);
    $h->rt->touch = true;
    $h->frame(2);
    check($h->byText('Page One') !== null, 'TabViewStyle::page renders active page');
    check($h->byText('TopPane') !== null && $h->byText('BottomPane') !== null, 'VSplitView renders top and bottom panes');
    check($h->byText('TagA') !== null && $h->byText('TagB') !== null, 'FlowLayout renders wrapping tags');
    check($h->byText('CompactFallback') !== null, 'ViewThatFits selects candidate that fits container width');
    check($h->byText('Folder A') !== null, 'OutlineGroup renders branch');
    check($h->byText('Act1') !== null, 'ControlGroup renders grouped buttons');

    // 8b. Build script, cold-start whenReady (#27), hardware keyboard (#10), log filter (#11) & DatePicker docs (#18).
    $buildIosSh = (string)file_get_contents(__DIR__ . '/../scripts/build-ios-php.sh');
    $mainM = (string)file_get_contents(__DIR__ . '/../src/Platform/iOS/launcher/main.m');
    $iosBackendSrc = (string)file_get_contents(__DIR__ . '/../src/Platform/iOS/Backend.php');
    $appSrc = (string)file_get_contents(__DIR__ . '/../src/App.php');
    $curlLoopSrc = (string)file_get_contents(__DIR__ . '/../src/Native/CurlLoop.php');
    $dateInputDoc = (string)file_get_contents(__DIR__ . '/../docs/ui/date-input.md');
    $binMyphpSrc = (string)file_get_contents(__DIR__ . '/../bin/myphp');
    check(str_contains($buildIosSh, '*darwin*'), 'build-ios-php.sh patches PHP configure for Darwin cross-compile MAP_ANON so OPcache builds from fresh tarball (#1)');
    check(strpos($appSrc, 'self::$initialized = true;') < strpos($appSrc, '$b->init('), 'App::run sets $initialized = true before $b->init() (#27)');
    check(str_contains($iosBackendSrc, 'if (!$this->readyCalled)') && !str_contains(explode('public function run()', $iosBackendSrc)[0], '$this->h->ready()'), 'iOS Backend calls ready() inside run() after event loop starts (#27)');
    check(str_contains($curlLoopSrc, '!App::isIOS()'), 'CurlLoop::driven and pump do not spin worker thread NSRunLoop on iOS (#27)');
    check(str_contains($mainM, 'k.characters') && str_contains($mainM, 'pressesEnded:'), 'main.m pressesBegan handles plain hardware keyboard characters directly without Simulator screenshot shortcut (#10)');
    check(str_contains($binMyphpSrc, 'UIEmojiSearchOperations'), 'bin/myphp filters UIEmojiSearchOperations log noise (#11)');
    check(str_contains($dateInputDoc, 'App::region()') && !str_contains($dateInputDoc, 'as en-US writes it'), 'docs/ui/date-input.md documents regional date formatting (#18)');

    // 9. iOS bundle layout and Unsupported guardrails.
    $tmp = sys_get_temp_dir() . '/myphp-ios-bundle-' . bin2hex(random_bytes(4)) . '.app';
    mkdir($tmp, 0755, true);
    file_put_contents("$tmp/Info.plist", \MyPHP\Native\Build\Bundle::xml([
        'CFBundleName' => 'PhoneDemo',
        'CFBundleIdentifier' => 'com.example.phonedemo',
        'CFBundleShortVersionString' => '2.4.0',
        'CFBundleVersion' => '2.4.0',
        'CFBundleExecutable' => 'PhoneDemo',
        'MyPHPProduction' => true,
    ]));

    $refLaunched = new \ReflectionProperty(Bundle::class, 'launched');
    $refInfo = new \ReflectionProperty(Bundle::class, 'info');
    $prevLaunched = $refLaunched->getValue();
    $prevInfo = $refInfo->getValue();
    $prevPlatform = getenv('MYPHP_PLATFORM');

    try {
        putenv('MYPHP_PLATFORM=ios');
        $refLaunched->setValue(null, $tmp);
        $refInfo->setValue(null, null);

        same(true, App::isIOS(), 'MYPHP_PLATFORM=ios marks the iOS runtime');
        same($tmp, Bundle::path(), 'the bundle path');
        same($tmp, Bundle::resources(), 'on iOS the resources sit in the bundle root');
        same($tmp, App::path(PathName::Resources), 'App::path(Resources) is the iOS bundle root');
        same('2.4.0', App::version(), 'App::version() reads <bundle>/Info.plist on iOS');
        same(["$tmp/PhoneDemo"], Bundle::command(), 'Bundle::command() points to <bundle>/<Executable> on iOS');
        same(false, App::isDev(), 'MyPHPProduction in <bundle>/Info.plist turns App::isDev() off');

        check($throwsUnsupported(fn() => App::setMenu(new Menu([new MenuItem('File')]))), 'App::setMenu throws Unsupported on iOS');
        same(null, Menu::default(), 'Menu::default() is null on iOS');
        check($throwsUnsupported(fn() => Dock::setMenu(new Menu())), 'Dock::setMenu throws Unsupported on iOS');
        check($throwsUnsupported(fn() => Dock::hide()), 'Dock::hide throws Unsupported on iOS');
        check($throwsUnsupported(fn() => Dock::show()), 'Dock::show throws Unsupported on iOS');

        $b = new Backend(new AppHandler());
        same('ios/metal', $b->name(), 'Backend::name()');
        check($throwsUnsupported(fn() => $b->popupMenu((new Menu())->snapshot(), null, null)), 'Backend::popupMenu throws Unsupported on iOS');
        check($throwsUnsupported(fn() => $b->newTray(new class implements \MyPHP\Platform\TrayHandler {
            public function clicked(): void {}
            public function rightClicked(): void {}
            public function appearanceChanged(): void {}
        })), 'Backend::newTray throws Unsupported on iOS');
        check($throwsUnsupported(fn() => $b->registerHotkey(1, 'CmdOrCtrl+K')), 'Backend::registerHotkey throws Unsupported on iOS');

        $app = $b->app();
        check($throwsUnsupported(fn() => $app->setBadge('1')), 'AppController::setBadge throws Unsupported on iOS');
        check($throwsUnsupported(fn() => $app->badge()), 'AppController::badge throws Unsupported on iOS');
        check($throwsUnsupported(fn() => $app->bounce(false)), 'AppController::bounce throws Unsupported on iOS');
        check($throwsUnsupported(fn() => $app->cancelBounce(0)), 'AppController::cancelBounce throws Unsupported on iOS');
        check($throwsUnsupported(fn() => $app->setDockIcon('')), 'AppController::setDockIcon throws Unsupported on iOS');
        check($throwsUnsupported(fn() => $app->setDockMenu(null)), 'AppController::setDockMenu throws Unsupported on iOS');
        check($throwsUnsupported(fn() => $app->showAboutPanel(new AboutPanelOptions())), 'AppController::showAboutPanel throws Unsupported on iOS');

        $dialogs = $b->dialogs();
        check($throwsUnsupported(fn() => $dialogs->prompt(null, 'Name', '')), 'Dialog::prompt throws Unsupported on iOS');
        check($throwsUnsupported(fn() => $dialogs->login(null, 'Sign in', '', '', '')), 'Dialog::login throws Unsupported on iOS');
        check($throwsUnsupported(fn() => $dialogs->save(new SaveDialogOptions())), 'Dialog::save throws Unsupported on iOS');
        check($throwsUnsupported(fn() => $dialogs->open(new OpenDialogOptions(directory: true))), 'Dialog::open(directory: true) throws Unsupported on iOS');
        if (PHP_OS_FAMILY === 'Darwin') {
            \MyPHP\Native\ObjC::boot(['Foundation']);
            $langs = $app->preferredLanguages();
            check($langs !== [] && is_string($langs[0]) && $langs[0] !== '', 'AppController::preferredLanguages reads NSLocale/AppleLanguages');
            $region = $app->region();
            check($region->locale !== '' && in_array($region->hourCycle, [12, 24], true), 'AppController::region reads NSLocale/NSCalendar');
        }
    } finally {
        $refLaunched->setValue(null, $prevLaunched);
        $refInfo->setValue(null, $prevInfo);
        putenv($prevPlatform === false ? 'MYPHP_PLATFORM' : "MYPHP_PLATFORM=$prevPlatform");
        @unlink("$tmp/Info.plist");
        @rmdir($tmp);
    }
})();