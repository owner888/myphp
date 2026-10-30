<?php
// The updater plugin (mygo's plugins/updater tests): the update window's
// flow without a window, its fibers run here (the open function records the
// sessions that would show one); the preferences; the schedule; its
// languages; its release notes; its page.
declare(strict_types=1);

namespace MyPHP\Tests;

use DateTimeImmutable;
use MyPHP\Native\Fibers;
use MyPHP\Plugins\Updater\{Checker, Markdown, Options, Release, Session, Strings, Text, Translations, UpdateWindow, Updater};

/** The updater without windows (mygo's harness). */
final class UpdHarness
{
    public Checker $u;
    public string $dir;
    /** @var list<Session> */
    public array $presented = [];
    public int $relaunches = 0;
    public int $checks = 0;
    /** What checks find. @var \Closure(\Closure): ?Release */
    public \Closure $result;
    public bool $enabled = true;

    public function __construct(Options $opts = new Options)
    {
        $this->dir = sys_get_temp_dir() . '/myphp-updater-' . getmypid() . '-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        $dir = $this->dir;
        register_shutdown_function(fn() => exec('rm -rf ' . escapeshellarg($dir)));
        $this->result = fn() => null;
        $u = $this->u = new Checker($opts);
        $u->enabled = fn(): bool => $this->enabled;
        $u->check = function (\Closure $canceled): ?Release {
            $this->checks++;
            return ($this->result)($canceled);
        };
        $u->relaunch = function (): void { $this->relaunches++; };
        $u->appVersion = fn(): string => '1.0.0';
        $u->locale = fn(): string => 'en-US';
        $u->stateDir = fn(): string => $this->dir;
        $u->go = fn(\Closure $fn) => Fibers::go($fn);
        $u->after = function (float $s, \Closure $fn): void {};
        $u->open = function (Session $s): void { $this->presented[] = $s; };
    }

    /** Starts a check; the object's done says when it ended. */
    public function begin(bool $user): \stdClass
    {
        $done = new \stdClass;
        $done->done = false;
        Fibers::go(function () use ($user, $done): void {
            $this->u->begin($user);
            $done->done = true;
        });
        return $done;
    }

    public function shown(): ?Session
    {
        Fibers::runUntil(fn(): bool => $this->presented !== []);
        $s = array_shift($this->presented);
        check($s !== null, 'a window was shown');
        return $s;
    }

    public function noWindow(): void
    {
        check($this->presented === [], 'no window: ' . json_encode(array_map(fn(Session $s) => $s->view['title'], $this->presented)));
    }

    /** @return array<string, mixed> */
    public function saved(): array
    {
        return json_decode((string)@file_get_contents("{$this->dir}/" . Checker::STATE_FILE), true) ?? [];
    }
}

/** Waits for $s to show a view whose title starts with $title. @return array<string, mixed> */
function updView(Session $s, string $title): array
{
    $ok = Fibers::runUntil(fn(): bool => str_starts_with($s->view['title'], $title));
    check($ok, "the view \"{$s->view['title']}\", want \"$title\"");
    return $s->view;
}

function updDone(\stdClass $done): void
{
    check(Fibers::runUntil(fn(): bool => $done->done), 'the check ended');
}

function updRelease(string $version, ?\Closure $install = null): Release
{
    return new Release($version, "### Fixed\n\n- A **bug**", $install ?? function (): void {});
}

(function () {
    // Up to date.
    $h = new UpdHarness;
    $done = $h->begin(true);
    $s = $h->shown();
    $v = updView($s, 'You’re up to date!');
    check(str_contains($v['message'], '1.0.0 is currently the newest version'), 'the message: ' . $v['message']);
    $s->respond($v['prompt'], 'ok', false);
    updDone($done);
    same(null, $h->u->session, 'the session cleared');
    check(abs((new DateTimeImmutable($h->saved()['lastCheck'] ?? '2000-01-01'))->getTimestamp() - time()) < 60, 'the last check saved');
})();

(function () {
    // Install and relaunch.
    $h = new UpdHarness;
    $proceed = false;
    $h->result = function () use (&$proceed) { return updRelease('2.0.0', function (?\Closure $progress) use (&$proceed): void {
        $progress(5_000_000, 20_000_000);
        Fibers::until(function () use (&$proceed): bool { return $proceed; });
        $progress(20_000_000, 20_000_000);
    }); };
    $done = $h->begin(true);
    $s = $h->shown();
    $v = updView($s, 'A new version of');
    check($v['release'] && $v['checkbox'] && !$v['checked'] && str_contains(Markdown::html($v['notes']), '<li>A <strong>bug</strong></li>'), 'the available view');
    check(str_contains($v['message'], '2.0.0 is now available—you have 1.0.0'), 'its message: ' . $v['message']);
    check(count($v['buttons']) === 3 && $v['buttons'][0]['action'] === 'skip' && !empty($v['buttons'][0]['aside']) && !empty($v['buttons'][2]['default']), 'its buttons');
    $s->respond($v['prompt'], 'install', true);
    updView($s, 'Downloading update…');
    Fibers::runUntil(fn(): bool => ($s->view['progress'] ?? 0) === 0.25);
    same(['5.0 MB of 20.0 MB', true, false], [$s->view['message'] ?? '', $s->view['bar'], $s->view['release']], 'the downloading view');
    $proceed = true;
    $v = updView($s, 'Ready to Relaunch');
    check(str_contains($v['message'], '2.0.0 is installed'), 'the ready view');
    $s->respond($v['prompt'], 'relaunch', false);
    updDone($done);
    same(1, $h->relaunches, 'relaunched');
    same(true, $h->saved()['automaticDownloads'] ?? false, 'the checkbox saved');
    // The update runs at the next launch: checks do not offer it again.
    $checks = $h->checks;
    updDone($h->begin(false));
    $h->noWindow();
    $done = $h->begin(true);
    $s = $h->shown();
    $v = updView($s, 'Ready to Relaunch');
    $s->respond($v['prompt'], 'later', false);
    updDone($done);
    same([$checks, 1], [$h->checks, $h->relaunches], 'not checked again, and Later does not relaunch');
})();

(function () {
    // Skip This Version.
    $h = new UpdHarness;
    $h->result = fn() => updRelease('2.0.0');
    $done = $h->begin(false);
    $s = $h->shown();
    check(!$s->user, 'a background check is not the user\'s');
    $v = updView($s, 'A new version of');
    $s->respond($v['prompt'], 'skip', false);
    updDone($done);
    same('2.0.0', $h->saved()['skippedVersion'] ?? '', 'skipped');
    updDone($h->begin(false));
    $h->noWindow();
    $done = $h->begin(true);
    $s = $h->shown();
    $v = updView($s, 'A new version of');
    $s->respond($v['prompt'], 'later', false);
    updDone($done);
    $h->result = fn() => updRelease('2.0.1');
    $done = $h->begin(false);
    $s = $h->shown();
    updView($s, 'A new version of');
    $s->close();
    updDone($done);
})();

(function () {
    // Automatic downloads, the cancel of a download and of a check, errors, unavailable.
    $h = new UpdHarness;
    $h->u->update(fn(array $s): array => ['automaticDownloads' => true] + $s);
    $installed = false;
    $h->result = function () use (&$installed) { return updRelease('2.0.0', function () use (&$installed): void { $installed = true; }); };
    updDone($h->begin(false));
    $h->noWindow();
    check($installed && $h->u->installed !== null, 'installed in the background');
    $done = $h->begin(true);
    $s = $h->shown();
    $v = updView($s, 'Ready to Relaunch');
    $s->respond($v['prompt'], 'relaunch', false);
    updDone($done);
    same([1, 1], [$h->relaunches, $h->checks], 'relaunched after one check');

    $h = new UpdHarness;
    $canceled = null;
    $h->result = function () use (&$canceled) { return updRelease('2.0.0', function (?\Closure $progress, \Closure $stop) use (&$canceled): void {
        $progress(1, 10);
        Fibers::until($stop);
        $canceled = true;
        throw new \RuntimeException('context canceled');
    }); };
    $done = $h->begin(true);
    $s = $h->shown();
    $v = updView($s, 'A new version of');
    $s->respond($v['prompt'], 'install', false);
    $v = updView($s, 'Downloading update…');
    $s->respond($v['prompt'], 'cancel', false);
    updDone($done);
    same([true, null], [$canceled, $h->u->installed], 'a canceled download');

    $h = new UpdHarness;
    $h->result = function (): ?Release { throw new \RuntimeException('offline'); };
    updDone($h->begin(false));
    $h->noWindow();
    check($h->u->failed !== null, 'the failure recorded');
    $done = $h->begin(true);
    $s = $h->shown();
    $v = updView($s, 'Update Error!');
    same('offline', $v['detail'], 'the error');
    $s->respond($v['prompt'], 'ok', false);
    updDone($done);
    $h->result = fn() => updRelease('2.0.0', function (): void { throw new \RuntimeException('bad signature'); });
    $done = $h->begin(true);
    $s = $h->shown();
    $v = updView($s, 'A new version of');
    $s->respond($v['prompt'], 'install', false);
    $v = updView($s, 'Update Error!');
    check($v['detail'] === 'bad signature' && str_contains($v['message'], 'installing'), 'an install error');
    $s->respond($v['prompt'], 'ok', false);
    updDone($done);

    $h = new UpdHarness;
    $h->result = function (\Closure $canceled): ?Release {
        Fibers::until($canceled);
        throw new \RuntimeException('context canceled');
    };
    $done = $h->begin(true);
    $s = $h->shown();
    $v = updView($s, 'Checking for updates…');
    $s->respond($v['prompt'], 'cancel', false);
    updDone($done);
    same(null, $h->u->failed, 'canceling is no failure');

    $h = new UpdHarness;
    $h->enabled = false;
    $done = $h->begin(true);
    $s = $h->shown();
    $v = updView($s, 'Updates Unavailable');
    $s->respond($v['prompt'], 'ok', false);
    updDone($done);
    same(0, $h->checks, 'not checked');
})();

(function () {
    // Responses: only the first, to the current prompt, with one of its buttons, counts; a background check promoted.
    $h = new UpdHarness;
    $h->result = fn() => updRelease('2.0.0');
    $done = $h->begin(true);
    $s = $h->shown();
    $v = updView($s, 'A new version of');
    $s->respond($v['prompt'] - 1, 'skip', false);
    $s->respond($v['prompt'], 'relaunch', false);
    $s->respond($v['prompt'], 'later', false);
    $s->respond($v['prompt'], 'skip', false);
    updDone($done);
    same('', $h->saved()['skippedVersion'] ?? '', 'not skipped');

    $h = new UpdHarness;
    $proceed = false;
    $h->result = function () use (&$proceed): ?Release { Fibers::until(function () use (&$proceed): bool { return $proceed; }); return null; };
    $done = $h->begin(false);
    Fibers::runUntil(fn(): bool => $h->checks > 0);
    $h->noWindow();
    updDone($h->begin(true)); // returns at once
    $s = $h->shown();
    updView($s, 'Checking for updates…');
    $proceed = true;
    $v = updView($s, 'You’re up to date!');
    $s->respond($v['prompt'], 'ok', false);
    updDone($done);
    same(1, $h->checks, 'one check');
})();

(function () {
    // Preferences and onChange.
    $h = new UpdHarness(new Options(disableAutomaticChecks: true));
    \Closure::bind(fn() => self::$active = $h->u, null, Updater::class)();
    try {
        check(!Updater::automaticChecks() && !Updater::automaticDownloads() && Updater::lastCheck() === null, 'the defaults');
        $changes = 0;
        $off = Updater::onChange(function () use (&$changes) { $changes++; });
        Updater::setAutomaticChecks(true);
        Updater::setAutomaticDownloads(true);
        Updater::setAutomaticDownloads(true);
        check(Updater::automaticChecks() && Updater::automaticDownloads(), 'set');
        same(2, $changes, 'told of the changes, not of the same value');
        $u = new Checker(new Options(disableAutomaticChecks: true));
        $u->stateDir = fn(): string => $h->dir;
        $u->load();
        check($u->automaticChecks() && $u->state['automaticDownloads'], 'another run reads them back');
        same(86400.0, (new Checker(new Options))->opts->interval, 'a day by default');
        $off();
        Updater::setAutomaticDownloads(false);
        same(2, $changes, 'a function removed');
        $h->u->running = true;
        $h->u->schedule();
        check($h->u->timer !== null, 'a check scheduled');
        Updater::setAutomaticChecks(false);
        same(null, $h->u->timer, 'none with automatic checks off');
    } finally {
        \Closure::bind(fn() => self::$active = null, null, Updater::class)();
    }
    try { Updater::checkForUpdates(); check(false, 'checked'); } catch (\RuntimeException $e) { check(str_contains($e->getMessage(), 'not used'), 'without the plugin'); }
})();

(function () {
    // A window keeps the language it opened in; after the app's language changes, the next window and the menu item take the new one (mygo's TestLanguageChange).
    $h = new UpdHarness;
    $proceed = false;
    $h->result = function () use (&$proceed) { return updRelease('2.0.0', function () use (&$proceed): void { Fibers::until(function () use (&$proceed): bool { return $proceed; }); }); };
    $done = $h->begin(true);
    $s = $h->shown();
    $v = updView($s, 'A new version of');
    $s->respond($v['prompt'], 'install', false);
    updView($s, 'Downloading update…');
    $h->u->locale = fn(): string => 'zh-Hans';
    $proceed = true;
    $v = updView($s, 'Ready to Relaunch');
    same('Software Update', $s->t->s->title, 'the texts of the open window');
    $s->respond($v['prompt'], 'later', false);
    updDone($done);
    $done = $h->begin(true);
    $s = $h->shown();
    $v = updView($s, '可以重新启动了');
    same('软件更新', $s->t->s->title, 'the texts of the next window');
    $s->respond($v['prompt'], 'later', false);
    updDone($done);
    same('检查更新…', $h->u->text()->s->menuItem, 'the menu item');
})();

(function () {
    // Soon after every launch, however recent the last check of an earlier launch, then an interval after it (mygo's TestCheckAtLaunch).
    $h = new UpdHarness();
    $waits = [];
    $h->u->after = function (float $s, \Closure $fn) use (&$waits): void { $waits[] = $s; };
    $h->u->load();
    $h->u->state['lastCheck'] = ($h->u->now)()->modify('-1 hour');
    $h->u->running = true;
    $h->u->schedule();
    same([10.0], $waits, 'the last launch checked an hour ago: soon after this one');
    $d = $h->begin(false);
    Fibers::runUntil(fn(): bool => $d->done);
    $waits = [];
    $h->u->schedule();
    check(count($waits) === 1 && $waits[0] > 3600, 'then an interval after it, not at once again: ' . json_encode($waits));
})();

(function () {
    // When the next check is due.
    $now = new DateTimeImmutable('2026-09-30T12:00:00Z');
    $at = fn(string $rel) => $now->modify($rel);
    $day = 86400.0;
    $s = fn(DateTimeImmutable $t) => Checker::seconds($t);
    foreach ([
        ['never checked', null, null, $day, Checker::ZERO_TIME + $day],
        ['checked an hour ago', $at('-1 hour'), null, $day, $s($at('+23 hours'))],
        ['due check failed', $at('-2 days'), $at('-1 minute'), $day, $s($at('+59 minutes'))],
        ['a check of the user failed', $at('-1 hour'), $at('-1 minute'), $day, $s($at('+23 hours'))],
        ['short interval', $at('-1 hour'), $now, 600.0, $s($at('+10 minutes'))],
        ['clock set back', $at('+1 hour'), null, $day, $s($at('+1 day'))],
    ] as [$name, $last, $failed, $interval, $want]) {
        same($want, Checker::nextCheck($last, $failed, $interval, $now), $name);
    }
})();

(function () {
    // Languages.
    $available = ['de', 'en', 'pt-BR', 'sv', 'zh-Hans', 'zh-Hant'];
    foreach (['de-DE' => 'de', 'de_AT.UTF-8' => 'de', 'en-GB' => 'en', 'pt-BR' => 'pt-BR', 'pt-PT' => 'pt-BR', 'zh-Hans-CN' => 'zh-Hans', 'zh-CN' => 'zh-Hans',
        'zh_SG' => 'zh-Hans', 'zh' => 'zh-Hans', 'zh-Hant-HK' => 'zh-Hant', 'zh-TW' => 'zh-Hant', 'zh_HK.UTF-8' => 'zh-Hant', 'zh-MO' => 'zh-Hant',
        'SV_se' => 'sv', 'es-419' => 'en', 'sr-Latn-RS' => 'en', '' => 'en', 'C' => 'en'] as $locale => $want) {
        same($want, Text::match((string)$locale, $available), "the language of \"$locale\"");
    }
    same('en', Text::match('zh-TW', ['en', 'zh-Hans']), 'one script of Chinese is no fallback for the other');
    // Every translation has every text, and its formats use every argument.
    foreach (['en' => Translations::ENGLISH] + Translations::LANGUAGES as $lang => $texts) {
        same(null, (new Strings(...$texts))->check(), "$lang: its formats");
        foreach ($texts as $name => $text) {
            $n = Strings::FORMATS[$name] ?? null;
            if ($n === null) {
                check($text !== '' && !str_contains($text, '%'), "$lang: $name is a text");
                continue;
            }
            $args = array_map(fn($j) => "<$j>", range(0, $n - 1));
            $out = sprintf($text, ...$args);
            check(array_filter($args, fn($a) => !str_contains($out, $a)) === [], "$lang: $name uses its arguments");
        }
    }
    $de = Text::for('de-AT', []);
    same(['de', 'Update installieren', '12,3 MB'], [$de->lang, $de->s->install, $de->megabytes(12_345_678)], 'German');
    same("1,5\u{a0}Mo", Text::for('fr-CA', [])->megabytes(1_500_000), 'French');
    same('1.5 MB', Text::for('ja-JP', [])->megabytes(1_500_000), 'Japanese');
    $extra = ['EN' => new Strings(install: 'Update Now'), 'ar' => new Strings(title: 'تحديث البرامج'), 'sv' => new Strings(install: 'Installera uppdatering')];
    $en = Text::for('en-US', $extra);
    same(['Update Now', 'Skip This Version'], [$en->s->install, $en->s->skip], 'the app\'s English');
    $sv = Text::for('sv-SE', $extra);
    same(['sv', 'Installera uppdatering', 'Skip This Version'], [$sv->lang, $sv->s->install, $sv->s->skip], 'a language the app adds');
    $ar = Text::for('ar-EG', $extra);
    same([true, 'تحديث البرامج'], [$ar->rtl, $ar->s->title], 'right to left');
    same('Update installieren', Text::for('de', $extra)->s->install, 'no other language\'s strings');
    $p = Updater::new(new Options(strings: ['en' => new Strings(available: 'A new version of %d')]));
    try { ($p->setup)(); check(false, 'set up'); } catch (\RuntimeException $e) { check(str_contains($e->getMessage(), 'available'), 'invalid strings: ' . $e->getMessage()); }
})();

(function () {
    // Release notes.
    foreach ([
        ['', ''], ["Hello\nworld", '<p>Hello world</p>'], ["One\n\nTwo", '<p>One</p><p>Two</p>'],
        ["### Added\n\n- One\n- Two", '<h3>Added</h3><ul><li>One</li><li>Two</li></ul>'], ['## 1.2.0 ##', '<h2>1.2.0</h2>'], ['#hashtag', '<p>#hashtag</p>'],
        ["Fixes:\n- one\n- two", '<p>Fixes:</p><ul><li>one</li><li>two</li></ul>'], ["1. one\n2. two", '<ol><li>one</li><li>two</li></ol>'],
        ["We shipped\n2. things", '<p>We shipped 2. things</p>'], ["- a\n  - b\n  - c\n- d", '<ul><li>a<ul><li>b</li><li>c</li></ul></li><li>d</li></ul>'],
        ["1. a\n  - b", '<ol><li>a<ul><li>b</li></ul></li></ol>'], ["- a long\nitem\n- b", '<ul><li>a long item</li><li>b</li></ul>'],
        ["- a\n\n  more\n- b", '<ul><li>a<br>more</li><li>b</li></ul>'], ["- a\n\nAfter", '<ul><li>a</li></ul><p>After</p>'],
        ["- a\n* b", '<ul><li>a</li><li>b</li></ul>'], ["- a\n1. b", '<ul><li>a</li></ul><ol><li>b</li></ol>'],
        ["> quoted\n> text", '<blockquote><p>quoted text</p></blockquote>'], ['---', '<hr>'], ['- - -', '<hr>'],
        ["```\n<b>code</b>\n\n  x\n```\nafter", "<pre><code>&lt;b&gt;code&lt;/b&gt;\n\n  x</code></pre><p>after</p>"],
        ['**bold** and *em* and _em_ and __bold__', '<p><strong>bold</strong> and <em>em</em> and <em>em</em> and <strong>bold</strong></p>'],
        ['snake_case_name and 2 * 3 * 4', '<p>snake_case_name and 2 * 3 * 4</p>'], ['**bold *em* bold**', '<p><strong>bold <em>em</em> bold</strong></p>'],
        ['`a *b* <c>` and ``x ` y``', '<p><code>a *b* &lt;c&gt;</code> and <code>x ` y</code></p>'], ['\*not em\*', '<p>*not em*</p>'],
        ['[docs](https://example.com/a?b=1&c=2 "Title")', '<p><a href="https://example.com/a?b=1&amp;c=2">docs</a></p>'],
        ['[**bold** link](https://example.com)', '<p><a href="https://example.com"><strong>bold</strong> link</a></p>'],
        ['[bad](javascript:alert(1))', '<p>bad)</p>'], ['[rel](/path)', '<p>rel</p>'],
        ['![logo](https://example.com/logo.png)', '<p><a href="https://example.com/logo.png">logo</a></p>'],
        ['<https://example.com> <b>hi</b>', '<p><a href="https://example.com">https://example.com</a> &lt;b&gt;hi&lt;/b&gt;</p>'],
        ['See https://example.com/x_(y). Or (https://example.com).', '<p>See <a href="https://example.com/x_(y)">https://example.com/x_(y)</a>. Or (<a href="https://example.com">https://example.com</a>).</p>'],
        ['<script>alert(1)</script>', '<p>&lt;script&gt;alert(1)&lt;/script&gt;</p>'], ['<img src=x onerror="alert(1)">', '<p>&lt;img src=x onerror=&#34;alert(1)&#34;&gt;</p>'],
        ['[x](https://a"onmouseover="alert(1))', '<p><a href="https://a&#34;onmouseover=&#34;alert(1">x</a>)</p>'],
        ['Café — ünïcode', '<p>Café — ünïcode</p>'], ["a\r\nb", '<p>a b</p>'], ['**unclosed', '<p>**unclosed</p>'],
        ['[unclosed](https://x', '<p>[unclosed](<a href="https://x">https://x</a></p>'],
    ] as [$in, $want]) {
        same($want, Markdown::html(Markdown::parse($in)), 'notes ' . json_encode($in));
    }
    same([], Markdown::parse(" \n\n"), 'blank notes');
})();

(function () {
    // The page: escaped, with a nonce, the icon and the texts of its language.
    $fr = Text::for('fr-FR', ['fr' => new Strings(releaseNotes: '<b>Notes</b>')]);
    $v = ['prompt' => 1, 'title' => '</script><script>alert(1)</script>', 'notes' => Markdown::parse('A **bug**'), 'buttons' => [['action' => 'ok', 'label' => 'OK', 'default' => true, 'cancel' => true]]];
    $p = UpdateWindow::page($fr, 'data:image/png;base64,AAAA', $v);
    check(!str_contains($p, '{{'), 'no placeholder left');
    check(!str_contains($p, '</script><script>alert'), 'the view escaped');
    $i = strpos($p, "'nonce-");
    $nonce = substr($p, $i + 7, strpos($p, "'", $i + 7) - $i - 7);
    check(strlen($nonce) >= 20 && str_contains($p, "<script nonce=\"$nonce\">"), 'the nonce');
    check(str_contains($p, 'src="data:image/png;base64,AAAA"'), 'the icon');
    foreach (['<html lang="fr" dir="ltr">', '<title>Mise à jour de logiciels</title>', '&lt;b&gt;Notes&lt;/b&gt;', '"notes":"' . str_replace(['<', '>'], ['\\u003C', '\\u003E'], '<p>A <strong>bug</strong></p>') . '"'] as $want) {
        check(str_contains($p, $want), "the page has $want");
    }
})();

(function () {
    // Session::present (mygo's frontend_test): the update window of a background check, as Sparkle shows it.
    $win = fn() => new class {
        public bool $visible = false, $focused = false;
        /** @var list<\Closure> */
        public array $closed = [];
        public function show(): void { $this->visible = true; }
        public function focus(): void { $this->focused = true; }
        public function isVisible(): bool { return $this->visible; }
        public function on(string $event, \Closure $fn): \Closure
        {
            same('closed', $event, 'present watches the window closing');
            $this->closed[] = $fn;
            return function (): void { $this->closed = []; };
        }
    };
    $app = new class {
        public bool $active = false, $focused = false;
        public \MyPHP\ActivationPolicy $policy = \MyPHP\ActivationPolicy::Regular;
        /** @var list<\Closure> */
        public array $listeners = [];
        public function activate(): void { $this->active = true; foreach ($this->listeners as $fn) { $fn(); } }
    };
    Session::$app = [
        'active' => fn() => $app->active,
        'policy' => fn() => $app->policy,
        'focus' => function () use ($app): void { $app->focused = true; },
        'onActive' => function (\Closure $fn) use ($app): \Closure { $app->listeners[] = $fn; return function () use ($app): void { $app->listeners = []; }; },
    ];
    try {
        $app->active = true;
        $w = $win();
        Session::present($w, false);
        same([true, true], [$w->visible, $w->focused], 'a background check while the app is active: the window in front');

        $app->active = false;
        $w = $win();
        Session::present($w, true);
        same([true, true], [$w->visible, $w->focused], 'a check the user asked for: in front');

        $w = $win();
        Session::present($w, false);
        same(false, $w->visible, 'a background check while another app is in front waits');
        $app->activate();
        same([true, true, [], []], [$w->visible, $w->focused, $app->listeners, $w->closed], 'then shows in front once the app is active, and stops watching');

        $app->active = false;
        $w = $win();
        Session::present($w, false);
        foreach ($w->closed as $fn) { $fn(); }
        $app->activate();
        same(false, $w->visible, 'a window closed while it waits never shows');

        $app->active = false;
        $app->policy = \MyPHP\ActivationPolicy::Accessory;
        $w = $win();
        Session::present($w, false);
        same([true, true, true], [$app->focused, $w->visible, $w->focused], 'an app without a Dock icon comes to the front with the window');
    } finally {
        Session::$app = null;
    }
})();
