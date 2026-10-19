<?php
// Routers (mygo's router_test.go): matching paths, the history, links and
// the keys and buttons going through it, pages keeping their state and the
// focus, sliding and fading in, nested routers and layouts.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{AccessNode, Animation, Context, Easing, Key, Node, Role, Route, RouteEntry, Router, ScrollState, TextState};

// Matching.
$route = fn(string $p) => new Route(new Router, new RouteEntry($p), segments: Router::pathParts($p));
foreach ([
    ['/', '/', true, []], ['/', '/notes', false, []], ['/notes', '/notes', true, []], ['/notes', '/notes/', true, []],
    ['/notes/42', '/notes', false, []], ['/notes/42', '/notes/{id}', true, ['id' => '42']], ['/notes/42/edit', '/notes/{id}', false, []],
    ['/notes/42/edit', '/notes/{id}/edit', true, ['id' => '42']], ['/files', '/files/{path...}', true, ['path' => '']],
    ['/files/docs/a.txt', '/files/{path...}', true, ['path' => 'docs/a.txt']],
    ['/users/ada/files/x', '/users/{name}/files/{rest...}', true, ['name' => 'ada', 'rest' => 'x']], ['/a b', '/a b', true, []],
] as [$path, $pattern, $match, $params]) {
    $r = $route($path);
    $ok = $r->match($pattern) === $match;
    foreach ($params as $k => $v) { $ok = $ok && $r->param($k) === $v; }
    check($ok, "$path matching $pattern");
}
$r = $route('/notes/42');
$r->match('/notes/{id}');
check(!$r->match('/notes/{id}/edit') && $r->param('id') === '42', 'a pattern that does not match leaves the parameters of the last that did');
try { $r->match('/{rest...}/edit'); check(false, '{rest...} before the end throws'); } catch (\LogicException) { check(true, '{rest...} before the end throws'); }

// The history.
$zero = new Router;
check($zero->path() === '/' && !$zero->canGoBack() && !$zero->canGoForward(), 'a new router shows /');
$r = new Router('/notes');
foreach ([
    [fn() => null, '/notes', false, false],
    [fn() => $r->push('/notes/42'), '/notes/42', true, false],
    [fn() => $r->push('edit'), '/notes/edit', true, false], // relative to the page, as links in web pages
    [fn() => $r->push('../settings/'), '/settings', true, false],
    [fn() => $r->push('?tab=fonts'), '/settings?tab=fonts', true, false],
    [fn() => $r->push('/settings?tab=fonts'), '/settings?tab=fonts', true, false], // the page shown
    [fn() => $r->back(), '/settings', true, true],
    [fn() => $r->go(-2), '/notes/42', true, true],
    [fn() => $r->forward(), '/notes/edit', true, true],
    [fn() => $r->push('/a%20b'), '/a%20b', true, false], // pushing forgets the pages after
    [fn() => $r->replace('/c'), '/c', true, false],
    [fn() => $r->back(), '/notes/edit', true, true],
    [fn() => $r->go(-10), '/notes', false, true],
    [fn() => $r->go(10), '/c', true, false],
] as $i => [$do, $loc, $back, $fwd]) {
    $do();
    check($r->location() === $loc && $r->canGoBack() === $back && $r->canGoForward() === $fwd, "step $i: at {$r->location()}");
}
$r->push('/search?q=go%20ui');
check($r->path() === '/search' && $r->query('q') === 'go ui', "the query: {$r->path()}, {$r->query('q')}");
$r->push('/a%20b');
same('/a b', $r->path(), 'an escaped path');
$r->push('/a b');
same('/a%20b', $r->location(), 'a path with a space is the escaped one');
$r->push('/n/0');
for ($i = 0; $i < 150; $i++) { $r->push("/n/$i"); }
$r->go(-1000);
same('/n/50', $r->location(), 'the history holds a hundred entries');

/** Cmd+[ and Cmd+], and a mouse's back and forward buttons. */
function back(Headless $t): void { $t->key(Key::BRACKET_LEFT, 'noop:', Key::COMMAND, '['); }
function forward(Headless $t): void { $t->key(Key::BRACKET_RIGHT, 'noop:', Key::COMMAND, ']'); }
function mouseBack(Headless $t): void { $t->input->push(['key', '', Key::BACK, 0]); $t->frame(); }
function mouseForward(Headless $t): void { $t->input->push(['key', '', Key::FORWARD, 0]); $t->frame(); }

// A view: links go to paths in the router and open URLs; the keys and the buttons go back and forward.
$r = new Router('/');
$r->transition = Router::NONE;
$note = ''; $opened = [];
$t = new Headless(function (Context $c) use ($r, &$note) {
    $c->column(0, 0, 1);
      $c->row();
        $c->backButton($r);
        $c->forwardButton($r);
      $c->end();
      $r->view($c, function (Route $rt) use ($c, &$note) {
          if ($rt->match('/')) {
              $rt->title('Home');
              $c->text('Welcome');
              $c->link('First note', '/notes/1');
              $c->link('Site', 'https://example.com');
          } elseif ($rt->match('/notes/{id}')) {
              $note = $rt->param('id');
              $rt->title("Note $note");
              $c->text("Note $note");
              $c->link('Next', (string)((int)$note + 1));
          } else {
              $c->text('Not found');
          }
      });
    $c->end();
}, 400, 300);
$t->rt->openURL = function (string $u) use (&$opened) { $opened[] = $u; };
$t->frame();
check(in_array('Welcome', $t->texts(), true) && $r->title() === 'Home', 'the first page, titled Home');
$t->clickText('Site');
$t->clickText('First note');
check(in_array('Note 1', $t->texts(), true) && !in_array('Welcome', $t->texts(), true) && $r->location() === '/notes/1', 'a link to a path goes there');
same(['https://example.com'], $opened, 'a link to a URL opens it');
$t->clickText('Next'); $t->clickText('Next');
same('3', $note, 'two relative links');
back($t);
same('2', $note, 'Cmd+[ goes back');
mouseBack($t);
same('1', $note, "a mouse's back button");
forward($t); mouseForward($t);
check($note === '3' && !$r->canGoForward(), 'forward twice');
$backButton = fn() => array_values(array_filter($t->root->kids[0]->kids[0]->kids, fn(Node $n) => $n->label === 'Back'))[0];
$t->clickOn($backButton());
same('2', $note, 'the Back button');
$t->clickOn(array_values(array_filter($t->root->kids[0]->kids[0]->kids, fn(Node $n) => $n->label === 'Forward'))[0]);
same('3', $note, 'the Forward button');
same(['Note 2', 'Note 1', 'Home'], $r->pagesToward(-1), "the Back button's menu");
$r->go(-3); $t->frame();
check(in_array('Welcome', $t->texts(), true) && !$r->canGoBack() && $backButton()->disabled, 'at the start, Back is disabled');
$r->push('/nowhere');
$t->frame();
check(in_array('Not found', $t->texts(), true), 'a push from outside the frame shows its page');

// Pages keep their state while in the history, ten of them.
$r = new Router('/list');
$r->transition = Router::NONE;
$scroll = null;
$t = new Headless(function (Context $c) use ($r, &$scroll) {
    $r->view($c, function (Route $rt) use ($c, &$scroll) {
        if ($rt->match('/list')) {
            $pg = $rt->page;
            $st = $c->runtime->local($pg->id, fn() => (object)['scroll' => new ScrollState, 'count' => 0]);
            $scroll = $st->scroll;
            if ($c->clicked($c->button("Clicked {$st->count}"))) { $st->count++; }
        } else {
            $c->text('Page ' . $rt->path());
        }
    });
}, 400, 300);
$t->frame();
$scroll->y = 300;
$t->clickText('Clicked 0');
$r->push('/other'); $t->frame();
$r->back(); $t->frame();
check($scroll->y == 300 && in_array('Clicked 1', $t->texts(), true), 'away and back, the page is as it was');
for ($i = 0; $i <= Router::KEPT_PAGES; $i++) { $r->push("/p/$i"); $t->frame(); }
$r->go(-(Router::KEPT_PAGES + 1)); $t->frame();
check($scroll->y == 0 && in_array('Clicked 0', $t->texts(), true), 'eleven pages away, it is too far to keep');
$t->clickText('Clicked 0');
$r->replace('/elsewhere'); $t->frame();
$r->replace('/list'); $t->frame();
check(in_array('Clicked 0', $t->texts(), true), 'a page replaced is gone');

// The focus: into the new page from a link in the page; back where it was; outside the router, it stays.
$r = new Router('/');
$r->transition = Router::NONE;
$t = new Headless(function (Context $c) use ($r) {
    $c->row(0, 0, 1);
      $c->column();
        if ($c->clicked($c->button('Open settings'))) { $r->push('/settings'); }
      $c->end();
      $r->view($c, function (Route $rt) use ($c) {
          if ($rt->match('/')) { $rt->title('Home'); $c->button('First'); $c->link('Profile', '/profile'); }
          elseif ($rt->match('/profile')) { $rt->title('Your profile'); $c->button('Edit'); }
          elseif ($rt->match('/settings')) { $rt->title('Settings'); $c->button('Reset'); $c->link('Fonts', 'fonts'); }
          elseif ($rt->match('/fonts')) { $rt->title('Font settings'); $c->button('Bigger'); }
      });
    $c->end();
}, 500, 300);
$focused = fn() => Headless::label($t->focused()) . ($t->focused()?->label ?? '');
$t->frame();
$t->clickText('First');
$t->tab();
check(str_contains($focused(), 'Profile'), 'Tab reaches the link');
$t->enter();
check($r->path() === '/profile' && $t->focused()?->page && $t->focused()->label === 'Your profile', 'Enter on the link: the focus is on the new page');
mouseBack($t);
check(str_contains($focused(), 'Profile'), 'back, the focus is on the link it left from');
$told = count($t->told);
$t->input->push(['accessOn']); $t->frame();
$t->clickText('Open settings');
check(str_contains($focused(), 'Open settings') && in_array('Reset', $t->texts(), true), 'a button outside the router keeps the focus');
check(in_array('Settings', array_slice($t->told, $told), true), "screen readers hear the page's title");
$t->clickText('Reset');
back($t);
check(str_contains($focused(), 'Profile'), 'back to Home, the focus is on the link it left from');
forward($t);
check(str_contains($focused(), 'Reset'), 'forward, the focus is on Reset');
$t->tab(); $t->enter();
check($t->focused()?->page && $t->focused()->label === 'Font settings', 'to a page whose nodes do not take the focus, it goes to the page');
$t->tab();
check(str_contains($focused(), 'Bigger'), 'Tab from the page goes into it');
$t->tab(true);
check(str_contains($focused(), 'Open settings'), 'Shift+Tab goes past the page');
$tree = $t->rt->accessTree;
check(count(array_filter($tree->nodes, fn(AccessNode $n) => $n->role === Role::GROUP && $n->label === 'Font settings')) === 1, 'screen readers see the page, named by its title');

// Transitions: deeper, the page slides in over the one going away, which takes no shortcuts; back up from the left; across it fades; with less motion, all fade.
$r = new Router('/notes');
$shortcuts = [];
$t = poseTester(function (Context $c) use ($r, &$shortcuts) {
    $r->view($c, function (Route $rt) use ($c, &$shortcuts) {
        $c->text('Page ' . $rt->path());
        if ($c->shortcut('s', Key::COMMAND)) { $shortcuts[] = $rt->path(); }
    });
}, $now, 400, 300);
$at = function (string $s) use ($t): float { $n = $t->byText($s) ?? throw new \RuntimeException("no $s"); return $t->rect($n)[0]; };
$pages = fn() => $t->root->kids[0]->kids;
$r->push('/notes/1'); $t->frame(1);
$now += 60_000_000; $t->frame(1);
$x = $at('Page /notes/1');
check($x > 0 && $x < 400, "sliding in, at $x");
same(['Page /notes/1'], $t->texts(), 'the page going away has no text to find');
same(2, count($pages()), 'two pages built');
$t->shortcut('s', Key::COMMAND);
same(['/notes/1'], $shortcuts, "Cmd+S runs the shortcut of the page coming, not the one going away's");
$now += 300_000_000; $t->frame(1);
same(0.0, $at('Page /notes/1'), 'in, at 0');
$r->back(); $t->frame(1);
$now += 60_000_000; $t->frame(1);
check($at('Page /notes') < 0, 'coming back, from the left');
$now += 300_000_000; $t->frame(1);
$r->push('/settings'); $t->frame(1);
$now += 60_000_000; $t->frame(1);
$p = $pages();
check($at('Page /settings') == 0 && count($p) === 1 && $p[0]->opacity > 0 && $p[0]->opacity < 1, 'across, it fades in in place');
$now += 300_000_000; $t->frame(1);
check(count($pages()) === 1 && $pages()[0]->opacity == 1.0, 'the page ends opaque');
$t->rt->reduceMotion = true;
$r->push('/settings/fonts'); $t->frame(1);
$now += 60_000_000; $t->frame(1);
check($at('Page /settings/fonts') == 0 && $pages()[0]->opacity < 1, 'with less motion, deeper pages fade in');
$now += 300_000_000; $t->frame(1);
$r->transition = Router::NONE;
$r->back(); $t->frame(1);
same(1, count($pages()), 'without a transition, one page');

// An animation of its own: halfway along its curve, and a spring.
$r = new Router('/notes');
$r->animation = new Animation(0.1, Easing::linear(...));
$t = poseTester(function (Context $c) use ($r) { $r->view($c, function (Route $rt) use ($c) { $c->text('Page ' . $rt->path()); }); }, $now, 400, 300);
$r->push('/notes/1'); $t->frame(1);
$now += 50_000_000; $t->frame(1);
$x = $t->rect($t->byText('Page /notes/1'))[0];
check($x >= 195 && $x <= 205, "halfway along its curve, at $x");
$now += 60_000_000; $t->frame(1);
check($t->rect($t->byText('Page /notes/1'))[0] == 0 && count($t->root->kids[0]->kids) === 1, 'once in, at 0');
$r->animation = Animation::snappy();
$r->push('/notes/1/2'); $t->frame(1);
$now += 150_000_000; $t->frame(1);
$x = $t->rect($t->byText('Page /notes/1/2'))[0];
check($x > 0 && $x < 200, "sprung partway, at $x");
$now += 2_000_000_000; $t->frame(1);
check($t->rect($t->byText('Page /notes/1/2'))[0] == 0 && !$t->rt->needsFrame, 'sprung in');

// Nested routers: the focus in the inner one, its keys go back in it; away from the outer page and back, the inner is as it was.
$outer = new Router('/settings'); $inner = new Router('/general');
$outer->transition = $inner->transition = Router::NONE;
$t = new Headless(function (Context $c) use ($outer, $inner) {
    $outer->view($c, function (Route $r) use ($c, $inner) {
        if ($r->match('/settings')) {
            $c->text('Settings');
            $inner->view($c, function (Route $r) use ($c) { $c->text('Section ' . $r->path()); $c->button('Inside ' . $r->path()); });
        } else {
            $c->text('Elsewhere');
        }
    });
}, 400, 300);
$t->frame();
$inner->push('/fonts'); $t->frame();
$t->clickText('Inside /fonts');
back($t);
check($inner->path() === '/general' && $outer->path() === '/settings', "back in the inner router: {$outer->path()}, {$inner->path()}");
$inner->forward(); $t->frame();
$t->clickText('Inside /fonts');
$f = $t->rt->focus;
$outer->push('/away'); $t->frame();
back($t);
check(in_array('Section /fonts', $t->texts(), true) && $t->rt->focus === $f, 'back in the outer router, the inner page and the focus are as they were');

// A text field leaves the history's keys.
$r = new Router('/a');
$r->transition = Router::NONE;
$text = new TextState('');
$t = new Headless(function (Context $c) use ($r, $text) { $r->view($c, function (Route $rt) use ($c, $text) { $c->input($text); }); }, 300, 200);
$t->frame();
$r->push('/b'); $t->frame();
$t->tab(); $t->type('hi');
back($t);
same('/a', $r->path(), 'from a text field, Cmd+[ goes back');
mouseForward($t);
same('/b', $r->path(), "from a text field, a mouse's forward button goes forward");

// Layouts: the layout stays, with its state, as the pages inside change; they fade or slide inside it.
$r = new Router('/settings/general');
$outerBox = $innerBox = null; $section = $what = '';
$t = poseTester(function (Context $c) use ($r, &$outerBox, &$innerBox, &$section, &$what) {
    $outerBox = $r->view($c, function (Route $rt) use ($c, $r, &$innerBox, &$section, &$what) {
        if ($rt->match('/settings/{section...}')) {
            $rt->title('Settings');
            $c->row(0, 0, 1);
              $side = $c->column()->size(140, 0);
                $l = $c->runtime->local($side->id, fn() => (object)['clicks' => 0]);
                if ($c->clicked($c->button("Layout {$l->clicks}"))) { $l->clicks++; }
                foreach (['general', 'fonts', 'fonts/size'] as $s) {
                    if ($c->clicked($c->button("Go $s"))) { $r->push("/settings/$s"); }
                }
              $c->end();
              $innerBox = $rt->view($c, function (Route $rt) use ($c, &$section, &$what) {
                  $section = $rt->param('section');
                  if ($rt->match('/general')) { $rt->title('General'); $c->text('General page'); }
                  elseif ($rt->match('/fonts')) {
                      $rt->title('Fonts');
                      $n = $c->runtime->local($rt->page->id, fn() => (object)['n' => 0]);
                      if ($c->clicked($c->button("Fonts {$n->n}"))) { $n->n++; }
                  } elseif ($rt->match('/fonts/{what}')) { $what = $rt->param('what'); $rt->title('Size'); $c->text('Size page'); }
              });
            $c->end();
        } else {
            $rt->title('Notes');
            $c->text('Notes page');
        }
    });
}, $now, 500, 300);
$settle = function () use ($t, &$now) { $now += 300_000_000; $t->frame(1); $t->frame(1); };
$has = fn(string $s) => in_array($s, $t->texts(), true);
$t->clickText('Layout 0');
check($has('General page') && $has('Layout 1') && $section === 'general', "the layout and its page, section $section");
$told = count($t->told);
$t->input->push(['accessOn']); $t->frame(1);
$t->clickText('Go fonts');
check($has('Fonts 0') && $has('Layout 1') && str_contains(Headless::label($t->focused()), 'Go fonts'), 'a page inside: the layout stays, with its state, and the focus on its button');
check(in_array('Fonts', array_slice($t->told, $told), true), "the inner page's title is announced");
check(count($outerBox->kids) === 1 && count($innerBox->kids) === 1 && $innerBox->kids[0]->opacity < 1, 'only the page inside fades in');
$settle();
$t->clickText('Fonts 0');
$t->clickText('Go fonts/size');
check(count($outerBox->kids) === 1 && count($innerBox->kids) === 2, 'deeper inside: the page inside slides, the layout stays');
$settle();
check($what === 'size' && $section === 'fonts/size', "the size page's parameters: $what, $section");
$r->push('/notes'); $t->frame(1);
check(count($outerBox->kids) === 1 && $has('Notes page'), 'away from the layout');
$settle();
back($t); $settle();
check($has('Size page') && $has('Layout 1'), 'back: the layout and the page inside as they were');
back($t); $settle();
check($has('Fonts 1') && $has('Layout 1'), 'back again');
$r->push('?size=12'); $t->frame(1);
check(count($innerBox->kids) === 1 && $innerBox->kids[0]->opacity == 1.0 && $has('Fonts 1'), 'another query of a page inside is the same page');
try {
    (new Route($r, new RouteEntry('/x')))->view(new Context($t->metrics, $t->rt), fn() => null);
    check(false, 'a layout needs a rest');
} catch (\LogicException) {
    check(true, 'a layout needs a rest');
}
