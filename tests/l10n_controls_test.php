<?php
// The controls say myphp's own strings in the language the app shows,
// assistive technology's included (mygo's l10n_test.go
// TestControlsSayTheLanguage; its count of allocations is Go's).
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\{App, L10n};
use MyPHP\UI\{AccessNode, Context, Role, TextState};

$open = true; $query = new TextState('x'); $current = 0;
$h = new Headless(function (Context $c) use (&$open, $query, &$current): void {
    $c->column();
      $c->findBar($open, $query, 0, $current);
      $c->searchField(new TextState(''));
    $c->end();
}, 600, 200);
$was = App::locale();
App::setLanguages('en', 'en-GB', 'ja', 'zh-Hans', 'zh-Hant', 'fa', 'de', 'fr-CA'); // as datetime_test's, and Japanese
App::setLocale('en'); $h->frame();
check($h->byText('No matches') !== null && $h->byText('Done') !== null, 'in English');
$h->input->push(['accessOn']);
foreach (['ja', 'zh-Hans', 'zh-Hant'] as $lang) {
    App::setLocale($lang); $h->frame();
    $tx = L10n::in($lang);
    check($h->byText($tx->get('noMatches')) !== null && $h->byText($tx->get('done')) !== null && $h->byText('Done') === null, "in $lang: " . json_encode($h->texts(), JSON_UNESCAPED_UNICODE));
    $named = fn(int $role, string $label) => array_filter($h->rt->accessTree->nodes, fn(AccessNode $n) => $n->role === $role && $n->label === $label) !== [];
    check($named(Role::TOOLBAR, $tx->get('find')) && $named(Role::BUTTON, $tx->get('previous')), "in $lang, to assistive technology");
}

// Desktop Arc UpdateCapsule speaks the app's language in collapsed and expanded states
$tab = 'home'; $auto = false;
$hu = new Headless(function (Context $c) use (&$tab, &$auto): void {
    $c->disableAnimations();
    \MyPHP\UI\Content::render(fn() => \TabView(selection: $tab)(
        \Tab('Home', systemImage: 'house', value: 'home')(\Text('Main')),
    )->tabViewStyle(\TabViewStyle::sidebarAdaptable)->bottomAccessory(fn() => \UpdateCapsule(
        version: '2.0.0',
        automaticUpdates: $auto,
        onAction: fn() => null,
    )), $c);
}, 900, 600);
App::setLocale('zh-Hans'); $hu->frame(2);
$zhPill = $hu->byLabel(L10n::in('zh-Hans')->get('updateAvailable'));
check($zhPill !== null, 'desktop UpdateCapsule pill localized in zh-Hans');
[$ux, $uy] = $hu->middle($zhPill);
$hu->hover($ux, $uy);
check(in_array(L10n::in('zh-Hans')->get('updateAutomatic'), $hu->texts(), true) && in_array(L10n::in('zh-Hans')->get('updateRestart'), $hu->texts(), true),
    'desktop UpdateCapsule expanded card localized in zh-Hans');

App::setLocale($was); $h->frame();
