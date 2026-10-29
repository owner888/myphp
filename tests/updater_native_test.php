<?php
// The update window in native UI (mygo's plugins/updater/native tests):
// what it shows of a session's view, its answers, the size it asks for,
// its layout from right to left.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\Plugins\Updater\{Checker, Markdown, Options, Session, Strings, Text};
use MyPHP\Plugins\Updater\Native\{Notes, UpdateWindow};
use MyPHP\UI\{Context, Image, Node};

/** A window over a session whose view the test sets, recording its answers and the sizes it asks for (mygo's fake session). */
final class UpdNative
{
    public Session $s;
    public UpdateWindow $w;
    public Headless $h;
    /** @var list<array{0: int, 1: string, 2: bool}> */
    public array $answers = [];
    /** @var list<array{0: int, 1: int}> */
    public array $fits = [];
    /** @var list<string> */
    public array $opened = [];

    /** @param array<string, mixed> $view */
    public function __construct(array $view, float $width, float $height, string $lang = 'en', ?Image $icon = null)
    {
        $text = Text::for($lang, [$lang => new Strings]);
        $this->s = new Session(new Checker(new Options), $text, true);
        $this->s->view = $view;
        $this->w = new UpdateWindow($this->s, $text, $icon);
        $this->w->respond = function (int $prompt, string $action, bool $automatic): void { $this->answers[] = [$prompt, $action, $automatic]; };
        $this->w->fit = function (int $w, int $h): void { $this->fits[] = [$w, $h]; };
        $this->h = new Headless(fn(Context $c) => $this->w->view($c), $width, $height);
        $this->h->rt->openURL = function (string $url): void { $this->opened[] = $url; };
        $this->h->frame(4);
    }

    /** @param array<string, mixed> $view */
    public function set(array $view): void
    {
        $this->s->view = $view;
        $this->h->frame(4);
    }

    /** The last size the window asked for. @return array{0: int, 1: int} */
    public function fit(): array
    {
        check($this->fits !== [], 'the window asked for a size');
        $last = end($this->fits);
        $this->fits = [];
        return $last;
    }

    /** @return array{0: float, 1: float, 2: float, 3: float} */
    public function find(string $text): array
    {
        $n = $this->h->byText($text);
        check($n !== null, "a node showing \"$text\" in " . json_encode($this->h->texts(), JSON_UNESCAPED_UNICODE));
        return $n === null ? [0.0, 0.0, 0.0, 0.0] : $this->h->rect($n);
    }
}

const UPD_AUTO = 'Automatically download and install updates in the future';

/** @return array<string, mixed> */
function updAvailable(int $prompt, string $notes): array
{
    return [
        'prompt' => $prompt, 'release' => true,
        'title' => 'A new version of App is available!',
        'message' => 'App 2.0 is now available—you have 1.0. Would you like to install it now?',
        'notes' => Markdown::parse($notes), 'checkbox' => true,
        'buttons' => [
            ['action' => 'skip', 'label' => 'Skip This Version', 'aside' => true],
            ['action' => 'later', 'label' => 'Remind Me Later', 'cancel' => true],
            ['action' => 'install', 'label' => 'Install Update', 'default' => true],
        ],
    ];
}

/** @return array<string, mixed> */
function updStatus(int $prompt, string $title, string $message): array
{
    return ['prompt' => $prompt, 'title' => $title, 'message' => $message, 'buttons' => [['action' => 'ok', 'label' => 'OK', 'default' => true, 'cancel' => true]]];
}

same(UPD_AUTO, Text::for('en', [])->s->automaticDownloads, 'the label of the checkbox in English');

// Available: the texts, the notes, the link, the keyboard, the answers.
$u = new UpdNative(updAvailable(1, "### Fixed\n\n- [The editor](https://example.com/editor) lost a **bug**\n  - nested\n\n```\ncode\n```"), Checker::RELEASE_WIDTH, Checker::RELEASE_HEIGHT);
$texts = $u->h->texts();
foreach (['A new version of App is available!', 'Release Notes:', 'Fixed', 'The editor lost a bug', 'nested', 'code', '•', '◦', UPD_AUTO] as $want) {
    check(in_array($want, $texts, true), "the window shows \"$want\": " . json_encode($texts, JSON_UNESCAPED_UNICODE));
}
// The link of the notes opens in the browser.
$r = $u->find('The editor lost a bug');
$u->h->click($r[0] + 10, $r[1] + $r[3] / 2);
same(['https://example.com/editor'], $u->opened, 'a click on the link opens it');
// The keyboard reaches it, and Enter opens it rather than installing.
$u->h->tab();
same('CB(' . UPD_AUTO . ')', Headless::label($u->h->focused()), 'Tab goes from the link to the checkbox');
$u->h->tab(true);
same('T(The editor)', Headless::label($u->h->focused()), 'Shift+Tab goes back to the link of the notes');
$u->h->enter();
same(2, count($u->opened), 'Enter on the link opens it');
same([], $u->answers, 'and answers nothing');
$u->h->clickText(UPD_AUTO);
$u->h->clickText('Install Update');
// The answer disables the buttons until the next prompt.
$u->h->clickText('Remind Me Later');
$u->h->escape();
same([[1, 'install', true]], $u->answers, 'one answer to a prompt, with the checkbox');
// A new prompt resets the checkbox, and takes an answer again.
$u->set(updAvailable(2, 'Notes'));
$u->h->escape();
same([2, 'later', false], $u->answers[1] ?? null, 'Escape answers the next prompt');

// Keys: Enter installs, Escape reminds later, disabled buttons do not answer.
$u = new UpdNative(updAvailable(1, ''), Checker::STATUS_WIDTH, 200);
$u->h->enter();
same([[1, 'install', false]], $u->answers, 'Enter answers with the default button');
$u->set(updAvailable(2, ''));
$u->h->escape();
same([2, 'later', false], $u->answers[1] ?? null, 'Escape answers with the cancel button');
$v = updStatus(3, 'Installing update…', '');
$v['buttons'] = [['action' => 'cancel', 'label' => 'Cancel', 'cancel' => true, 'disabled' => true]];
$u->set($v);
$u->h->escape();
$u->h->clickText('Cancel');
same(2, count($u->answers), 'a disabled button does not answer');

// Progress.
$u = new UpdNative(['prompt' => 1, 'title' => 'Downloading update…', 'message' => '5.0 MB of 20.0 MB', 'bar' => true, 'progress' => 0.25,
    'buttons' => [['action' => 'cancel', 'label' => 'Cancel', 'cancel' => true]]], Checker::STATUS_WIDTH, Checker::STATUS_HEIGHT);
check(in_array('5.0 MB of 20.0 MB', $u->h->texts(), true), 'the progress shows');
$bar = null;
$walk = function (Node $n) use (&$walk, &$bar): void { if ($n->kind === Node::PROGRESS) { $bar = $n; } foreach ($n->kids as $k) { $walk($k); } };
$walk($u->h->root);
check($bar !== null && $bar->fraction === 0.25 && $bar->w > 300, 'the bar is a quarter full across the column');
$u->h->clickText('Cancel');
same([[1, 'cancel', false]], $u->answers, 'Cancel answers');

// Fit: the height of a status, the width of the buttons.
$u = new UpdNative(updStatus(1, 'You’re up to date!', 'App 1.0 is currently the newest version available.'), Checker::STATUS_WIDTH, Checker::STATUS_HEIGHT);
$short = $u->fit();
check($short[0] <= Checker::STATUS_WIDTH && $short[1] >= 60 && $short[1] <= Checker::STATUS_HEIGHT + 40, 'a short status asks for ' . json_encode($short));
// A longer text asks for more height, at the window's width.
$v = updStatus(2, 'Update Error!', 'An error occurred while checking for updates. Please try again later.');
$v['detail'] = str_repeat('dial tcp: lookup updates.example.com: no such host ', 4);
$u->set($v);
$long = $u->fit();
check($long[1] > $short[1], 'an error asks for ' . json_encode($long) . ', a short status for ' . json_encode($short));
$v['detail'] = str_repeat('dial tcp: lookup updates.example.com: no such host ', 12); // all of a long error shows: the window grows for it (mygo's 002a1ea)
$u->set($v);
$longer = $u->fit();
check($longer[1] > $long[1], 'a longer error asks for more yet, not cut to two lines: ' . json_encode($longer));
$v['detail'] = str_repeat('dial tcp: lookup updates.example.com: no such host ', 4);
$u->set($v); $u->fit();
// Once it has the size it asked for, it asks for no other.
$u->h->w = max($long[0], Checker::STATUS_WIDTH);
$u->h->h = $long[1];
$u->h->frame(4);
same([], $u->fits, 'no size asked once the window has it');
// Long labels widen the window.
$v = updAvailable(3, 'Notes');
foreach ($v['buttons'] as $i => $b) {
    $v['buttons'][$i]['label'] = str_repeat($b['label'] . ' ', 3);
}
$u->h->w = Checker::RELEASE_WIDTH;
$u->h->h = Checker::RELEASE_HEIGHT;
$u->set($v);
$wide = $u->fit();
check($wide[0] > Checker::RELEASE_WIDTH, 'long labels ask for ' . json_encode($wide));

// Layout, left to right and right to left; notes take the direction of their language.
check(!Notes::rightToLeft(Markdown::parse('- An English item')) && Notes::rightToLeft(Markdown::parse('- פריט בעברית')) && Notes::rightToLeft(Markdown::parse('1. *مرحبا* world')), 'the direction of notes is their first letter\'s');
$icon = Image::fromPixels(128, 128, str_repeat("\0", 128 * 128 * 4));
$column = UpdateWindow::PAD_SIDE + UpdateWindow::ICON_SIZE + UpdateWindow::ICON_GAP;
foreach (['en' => false, 'he' => true] as $lang => $rtl) {
    $u = new UpdNative(updAvailable(1, '- An English item'), Checker::RELEASE_WIDTH, Checker::RELEASE_HEIGHT, $lang, $icon);
    $body = $u->find('A new version of App is available!');
    [$skip, $later, $install] = [$u->find('Skip This Version'), $u->find('Remind Me Later'), $u->find('Install Update')];
    $box = $u->find(UPD_AUTO);
    if (!$rtl) {
        check($body[0] >= $column && $skip[0] < $later[0] && $later[0] < $install[0], "left to right: texts at {$body[0]}, Skip at {$skip[0]}, Later at {$later[0]}, Install at {$install[0]}");
        check(abs($box[0] - $column) < 1, "left to right: the checkbox starts the column, at {$box[0]}");
    } else {
        check($body[0] + $body[2] <= Checker::RELEASE_WIDTH - $column + 1 && $skip[0] > $later[0] && $later[0] > $install[0], "right to left: texts at {$body[0]}+{$body[2]}, Skip at {$skip[0]}, Later at {$later[0]}, Install at {$install[0]}");
        check(abs($box[0] + $box[2] - (Checker::RELEASE_WIDTH - $column)) < 1, 'right to left: the checkbox ends the column, at ' . ($box[0] + $box[2]));
        $n = $u->h->byText(UPD_AUTO);
        check($n !== null && $n->reverse, 'right to left: the box after the label');
    }
    [$bullet, $item] = [$u->find('•'), $u->find('An English item')];
    check($bullet[0] < $item[0], "$lang: the bullet of English notes at {$bullet[0]}, the item at {$item[0]}");
    $u->set(updAvailable(2, '- פריט בעברית'));
    [$bullet, $item] = [$u->find('•'), $u->find('פריט בעברית')];
    check($bullet[0] > $item[0], "$lang: the bullet of Hebrew notes at {$bullet[0]}, the item at {$item[0]}");
}

// The window's look, the web page's.
$t = UpdateWindow::theme(\MyPHP\UI\Theme::light());
same([0xececec, 0xffffff, 0x007aff], [$t->background, $t->surface, $t->accent], 'light: the gray of dialogs, white buttons, the system blue');
$t = UpdateWindow::theme(\MyPHP\UI\Theme::dark());
same([0x1e1e1e, 0x5b5b5f, 0x0a84ff], [$t->background, $t->surface, $t->accent], 'dark');

// Reverse (mygo's): a row from the right, a column from the bottom.
$h = new Headless(function (Context $c): void {
    $c->row()->reverse(); $c->box(10, 10, 0); $c->box(20, 10, 0); $c->end();
    $c->column(0, 0, 0)->reverse()->size(50, 100); $c->box(10, 10, 0); $c->box(10, 30, 0); $c->end();
}, 100, 200);
$root = $h->frame();
[$row, $col] = $root->kids;
same([90.0, 70.0], [$row->kids[0]->x, $row->kids[1]->x], 'a reversed row lays out from the right');
same([$col->y + 90, $col->y + 60], [$col->kids[0]->y, $col->kids[1]->y], 'a reversed column from the bottom');
