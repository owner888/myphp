<?php
declare(strict_types=1);

namespace MyPHP\UI;

use MyPHP\Plugins\Glass\ScrollEdge;


/**
 * The player's settings window, as IINA's (SettingsWindow, macOS 26's):
 * a sidebar of its pages, under a search field, the sections of the page
 * chosen listed under it, the one in view marked; the page right of it,
 * its title in the title bar, its sections a title over lists in cards,
 * a row a setting (SettingsItem: an icon, its label and description, its
 * control at the right, the settings under it, some shown at a click on
 * the row). The pages and their rows are VideoPlayerSettingsPages'; a
 * search finds a setting by any word of what its row says, and goes to
 * it, ringed for a moment.
 *
 * Built by Context::videoPlayerSettings, in a window of a hidden title bar
 * over the sidebar's material (examples/video's Settings…).
 *
 * @internal UI\Context, UI\VideoPlayerSettingsPages
 */
final class VideoPlayerSettings
{
    /** The sidebar's width, the title bar's height (IINA's toolbar), a row's height in the sidebar, a section's row in its index. */
    public const SIDEBAR = 200, BAR = 52, PAGE_ROW = 36, SECTION_ROW = 22;
    /** Where a row's label starts in its card; the indent of what a row has under it (SettingsSubList.indent). */
    public const LABEL = 36, INDENT = 28;
    /**
     * IINA's buttons of the control bar's toolbar (Preference.ToolBarButton),
     * by its numbers: their symbols (the first the system has), those shown
     * while on, the key of their names (osc_toolbar.*), in the order its
     * sheet lists them.
     */
    public const TOOLBAR = [
        0 => [['gearshape'], ['gearshape.fill'], 'settings'],
        1 => [['list.bullet.rectangle', 'list.bullet'], ['list.bullet.rectangle.fill', 'list.bullet'], 'playlist'],
        2 => [['pip.enter'], ['pip.exit'], 'pip'],
        3 => [['arrow.up.backward.and.arrow.down.forward.rectangle', 'arrow.up.left.and.arrow.down.right'], ['arrow.down.forward.and.arrow.up.backward.rectangle', 'arrow.down.right.and.arrow.up.left'], 'full_screen'],
        4 => [['microphone.dynamic.on.stand', 'music.microphone', 'music.mic'], [], 'music_mode'],
        5 => [['captions.bubble.fill'], [], 'sub_track'],
        6 => [['camera.shutter.button', 'camera.fill'], [], 'screenshot'],
        7 => [['puzzlepiece.extension', 'puzzlepiece'], ['puzzlepiece.extension.fill', 'puzzlepiece.fill'], 'plugins'],
        8 => [['document.viewfinder', 'doc.viewfinder', 'doc.text.viewfinder'], ['viewfinder.circle.fill'], 'live_text'],
    ];
    /** The default buttons of the toolbar, IINA's. */
    public const TOOLBAR_DEFAULT = [7, 2, 1, 0];

    /** What one window's settings view keeps between frames: the page, the search, the pages' scrolls, the section in view, the rows open, the fields' texts. */
    private static function local(Context $c, Node $root): object
    {
        $make = fn() => (object)['page' => 'general', 'search' => new TextState(), 'open' => false, 'scroll' => [], 'section' => '', 'ring' => null, 'jump' => null, 'pinned' => null, 'toolbarChosen' => null, 'audioDevices' => null, 'assSecondary' => false, 'thumbBytes' => null, 'kbSearch' => null, 'kbChosen' => null, 'expanded' => [], 'fields' => [], 'heights' => []];
        return $c->runtime?->local($root->id, $make) ?? $make();
    }

    public static function build(Context $c, VideoPlayerState $s): Node
    {
        $t = $c->theme;
        $vibrant = $c->vibrancy();
        if ($vibrant) { $c->root->color = null; } // the sidebar's material shows through
        $root = $c->row(0, 0, 1)->align(Node::STRETCH);
        $v = self::local($c, $root);
        $pages = (new VideoPlayerSettingsPages($c, $s, $v))->all();
        self::openFor($v, $pages);
        $page = $pages[$v->page] ?? reset($pages);
        $v->page = $page['id'];
        $c->runtime?->askWindow('title', $page['title']); // IINA's: the page's title
          $side = $c->column()->size(self::SIDEBAR, 0)->shrink(0);
          if (!$vibrant) { $side->background($t->surface); }
            self::sidebar($c, $v, $pages);
          $c->end();
          self::page($c, $v, $page);
        $c->end();
        return $root;
    }

    /**
     * The settings $query finds, as IINA's search (SettingsSearch): each
     * of its words (apart at a space, a full-width one too) is in a word
     * of a row's label, description or what else it says (its options),
     * ignoring case; a row under another is found as itself. By page, in
     * their order; a page's by section, then label.
     * @param array<string, array<string, mixed>> $pages VideoPlayerSettingsPages::all()'s
     * @return list<array{page: string, id: string, section: string, label: string, parent: string}>
     */
    public static function search(array $pages, string $query): array
    {
        $words = preg_split('/[ \x{3000}]+/u', mb_strtolower(trim($query)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($words === []) {
            return [];
        }
        $found = [];
        foreach ($pages as $page) {
            $hits = [];
            $walk = function (array $item, string $section, string $parent) use (&$walk, &$hits, $words, $page): void {
                $own = preg_split('/\s+/u', mb_strtolower(implode(' ', [$item['label'], $item['desc'], ...$item['terms']])), -1, PREG_SPLIT_NO_EMPTY) ?: [];
                $all = true;
                foreach ($words as $w) {
                    $all = $all && array_filter($own, fn(string $o) => str_contains($o, $w)) !== [];
                }
                if ($all) { $hits[] = ['page' => $page['id'], 'id' => $item['id'], 'section' => $section, 'label' => $item['label'], 'parent' => $parent]; }
                foreach ($item['sub'] as $sub) { $walk($sub, $section, $item['label']); }
            };
            foreach ($page['sections'] as $section) {
                foreach ($section['lists'] as $list) {
                    foreach ($list as $item) { $walk($item, $section['title'], ''); }
                }
            }
            usort($hits, fn(array $a, array $b) => [$a['section'], $a['label']] <=> [$b['section'], $b['label']]);
            array_push($found, ...$hits);
        }
        return $found;
    }

    /** The sidebar: the search field, its results under it while it has some to show; the pages, the sections of the one chosen under it. */
    private static function sidebar(Context $c, object $v, array $pages): void
    {
        $t = $c->theme;
        $k = self::colors($t);
        $c->box(0, self::BAR, 0)->fillWidth()->shrink(0)->dragWindow()->color = null;
        $c->row()->height(30)->margin(0, 8)->round(15)->background($k['field'], $k['fieldAlpha'])->shrink(0); // IINA's large search field: a capsule
          $field = $c->input($v->search, $c->texts()->get('search'), 0, 13)->height(30)->round(15)->inset(28, 10);
          $field->bordered = false;
          $c->column()->absolute()->left(10)->top(7)->size(16, 16)->center()->passThrough();
            self::sf($c, 'magnifyingglass', 13, $t->textMuted);
          $c->end();
        $c->end();
        if ($c->changed($field)) { $v->open = trim($v->search->text) !== ''; }
        if ($v->open) { self::results($c, $v, $pages, $field); }
        $list = $c->scroll(0, 0, 1)->padding(8, 10, 8, 10);
          foreach ($pages as $id => $page) {
              $chosen = $id === $v->page;
              $row = $c->key("page:$id")->row(0, 6)->height(self::PAGE_ROW)->paddingX(8)->round(8)->align(Node::CENTER)->role(Role::TREE_ITEM)->checked($chosen)->label($page['title']);
              $key = $chosen && ($c->runtime?->windowFocus ?? true); // AppKit's: the page chosen in the accent while the window has the focus, gray else
              if ($chosen) { $key ? $row->background($t->accent) : $row->background($k['chosen'], $k['chosenAlpha']); }
              $fg = $key ? $t->accentText : $t->text;
                $c->column()->size(24, 24)->center();
                  self::sf($c, $page['symbol'], 18, $fg, 0.4);
                $c->end();
                $c->text($page['title'], 13, $fg, Font::BOLD)->singleLine();
              $c->end();
              if ($c->clicked($row) && !$chosen) { $v->page = $id; $v->section = ''; }
              if ($chosen && $page['subsections']) { self::sectionIndex($c, $v, $page); }
          }
        $c->end();
        $list->role(Role::TREE)->label($c->texts()->get('settingsPages'));
    }

    /** The sections of the page chosen, as IINA's sidebar lists them: a line, a dot on it at the one in view; a click scrolls to one. */
    private static function sectionIndex(Context $c, object $v, array $page): void
    {
        $t = $c->theme;
        $names = array_values(array_filter(array_column($page['sections'], 'title'), fn(string $n) => $n !== ''));
        if ($names === []) { $names = [$page['title']]; } // IINA's: a page of no titled section, its own title
        $at = max(0, (int)array_search($v->section, $names, true));
        $box = $c->column()->height(12 + self::SECTION_ROW * count($names))->fillWidth()->justify(Node::CENTER);
          $top = $c->animate($box, 'dot', 8 + self::SECTION_ROW * $at, 0.1);
          $c->column()->absolute()->left(10)->top(10)->size(16, self::SECTION_ROW * count($names) - 8)->passThrough()->draw(static function (DisplayList $l, Node $n) use ($t, $top): void { // IINA's: the line as high as the names, the dot 8 down on it at the first
              $l->rect($n->x + 7.5, $n->y, 1, $n->h, $t->dark ? 0xffffff : 0x000000, 0.4);
              $l->rect($n->x + 4, $n->y + $top - 4, 8, 8, 0x808080, 1.0, 4);
          });
          $c->end();
          $c->column()->paddingStart(36)->height(self::SECTION_ROW * count($names));
            foreach ($names as $name) {
                $b = $c->row()->height(self::SECTION_ROW)->align(Node::CENTER)->role(Role::BUTTON)->label($name);
                  $c->text($name, 11, $t->textMuted)->singleLine(); // as IINA shows them: the dot alone marks the one in view
                $c->end();
                if ($c->clicked($b)) { $v->jump = $name; }
            }
          $c->end();
        $c->end();
    }

    /** The search's results, in a popover under the field (IINA's): by page, its icon and title over its settings, each its section's name in a badge and its label (under its parent's, a row's under another); a click goes to it. */
    private static function results(Context $c, object $v, array $pages, Node $field): void
    {
        $t = $c->theme;
        $found = self::search($pages, $v->search->text);
        $open = $v->open;
        $panel = $c->popover($field, $open, function () use ($c, $t, $v, $pages, $found): void {
            $c->scroll()->width(340)->min(0, 120)->maxHeight(400)->padding(0, 0, 8, 0);
              if ($found === []) {
                  $c->column()->height(120)->center();
                    $c->text($c->texts()->get('settingsNoResult'), 13, $t->textMuted);
                  $c->end();
              }
              $shown = '';
              foreach ($found as $f) {
                  if ($f['page'] !== $shown) {
                      $shown = $f['page'];
                      $c->row(0, 8)->height(38)->align(Node::END)->padding(0, 8, 8, 8);
                        self::sf($c, $pages[$shown]['symbol'], 14, $t->textMuted, 0.4);
                        $c->text($pages[$shown]['title'], 13, $t->textMuted, Font::BOLD);
                      $c->end();
                  }
                  $hover = false;
                  $row = $c->key("found:{$f['id']}")->column(0, 4)->height($f['parent'] !== '' ? 46 : 25)->justify(Node::CENTER)->paddingX(8)->role(Role::BUTTON)->label($f['label']);
                  if ($hover = $c->hovered($row)) { $row->background($t->accent); }
                    $c->row(0, 4)->align(Node::CENTER);
                      $c->text($f['section'] !== '' ? $f['section'] : $pages[$f['page']]['title'], 11, $t->dark ? 0x1e1e1e : 0xffffff)->singleLine()->paddingX(2)->round(3)->background($t->textMuted);
                      $c->text($f['parent'] !== '' ? $f['parent'] : $f['label'], $f['parent'] !== '' ? 11 : 13, $hover ? $t->accentText : ($f['parent'] !== '' ? $t->textMuted : $t->text))->singleLine()->ellipsis('…');
                    $c->end();
                    if ($f['parent'] !== '') {
                        $c->row(0, 4)->align(Node::CENTER)->paddingStart(4);
                          self::sf($c, 'arrow.turn.down.right', 9, $hover ? $t->accentText : $t->text);
                          $c->text($f['label'], 13, $hover ? $t->accentText : $t->text)->singleLine()->ellipsis('…');
                        $c->end();
                    }
                  $c->end();
                  if ($c->clicked($row)) { [$v->page, $v->ring, $v->open] = [$f['page'], [$f['id'], microtime(true)], false]; }
              }
            $c->end();
        });
        $panel?->systemMaterial('popover')->round(10)->padding(0);
        if (!$open) { $v->open = false; }
    }

    /** The page chosen: its title in the title bar, over the material once the page scrolls under it, the page's help (IINA's ⓘ) at its right; its sections. */
    private static function page(Context $c, object $v, array $page): void
    {
        $t = $c->theme;
        $k = self::colors($t);
        $box = $c->column(0, 0, 1)->min(0, 0)->background($k['page']);
          $sc = $v->scroll[$page['id']] ??= new ScrollState();
          $c->key('page:' . $page['id'])->column(0, 16, 1)->scroll($sc)->padding(self::BAR, 4, 8, 4);
            $titles = [];
            foreach ($page['sections'] as $section) {
                $sec = $c->column(0, 12)->fillWidth();
                  if ($section['title'] !== '') { $titles[$section['title']] = [$sec, $c->text($section['title'], 14, $t->textMuted, Font::BOLD)->margin(0, 8, 0, 16)]; }
                  $c->column(0, 16)->fillWidth();
                    foreach ($section['lists'] as $list) { self::card($c, $v, $list); }
                  $c->end();
                $c->end();
            }
            if ($titles === []) { $titles[$page['title']] = [$box, $box]; }
          $c->end();
          $bar = $c->row()->absolute()->left(0)->right(0)->top(0)->height(self::BAR)->align(Node::CENTER)->paddingX(20)->dragWindow();
          if ($sc->y > 0) { $bar->material(new ScrollEdge(hard: true, background: $k['page'])); } // IINA's: the title bar shows once the page is under it, frosting it (macOS 26's hard scroll edge: our own content, which a system material would hide)
            $c->text($page['title'], 15, $t->text, Font::BOLD)->singleLine()->grow(1);
            if ($page['help'] !== '') { self::infoButton($c, $page['help']); }
          $c->end();
        $c->end();
        self::follow($c, $v, $sc, $titles, $box);
    }

    /** IINA's ⓘ of a page in the title bar: its help in a popover, 300 wide. */
    private static function infoButton(Context $c, string $help): void
    {
        $t = $c->theme;
        $b = $c->key('info')->column()->size(36, 36)->round(18)->center()->role(Role::BUTTON)->label($c->texts()->get('settingsMoreInfo'))->systemMaterial('glass');
          self::sf($c, 'info.circle', 16, $t->text);
        $c->end();
        $open = $c->runtime?->local($b->id, fn() => (object)['open' => false]) ?? (object)['open' => false];
        if ($c->clicked($b)) { $open->open = !$open->open; }
        $panel = $c->popover($b, $open->open, function () use ($c, $t, $help): void {
            $c->column()->width(300)->padding(16);
              $c->text($help, 13, $t->text);
            $c->end();
        });
        $panel?->systemMaterial('popover')->round(10)->padding(0);
    }

    /**
     * The section in view, as IINA's index marks it: the first while the
     * page is near its top, the last near its end, else the one a third of
     * the way down; a section asked for (a click in the index) scrolled to.
     * @param array<string, array{0: Node, 1: Node}> $titles the sections and their titles, by title
     */
    private static function follow(Context $c, object $v, ScrollState $sc, array $titles, Node $box): void
    {
        $names = array_keys($titles);
        [, $boxY, , $h] = $c->layoutBounds($box);
        if ($v->jump !== null && isset($titles[$v->jump])) {
            [, $y] = $c->layoutBounds($titles[$v->jump][1]);
            $to = max(0.0, min($sc->maxY, $sc->y + $y - $boxY - ($h * 0.33 + 40)));
            $sc->scrollTo($to);
            $v->pinned = [$v->jump, $to]; // the section clicked stays marked where it is scrolled to, though the page ends before it reaches the third of the way down
            $v->jump = null;
        }
        if ($v->pinned !== null && ($sc->moving() || abs($sc->y - $v->pinned[1]) < 1)) {
            $v->section = $v->pinned[0];
            return;
        }
        $v->pinned = null; // scrolled since: the section in view
        if ($sc->y <= 40) {
            $v->section = $names[0] ?? '';
        } elseif ($sc->y >= $sc->maxY - 40) {
            $v->section = $names[count($names) - 1] ?? '';
        } else {
            $mid = $boxY + $h * 0.33;
            foreach ($titles as $name => [$sec]) { // the last section begun by the band a third of the way down, 40 high: where a click in the index scrolls its title
                [, $y] = $c->layoutBounds($sec);
                if ($y > $mid + 40.5) { break; }
                $v->section = $name;
            }
        }
    }

    /** A list of rows in a card, as IINA's SettingsList: rounded, bordered, a line between rows from where their labels start. */
    private static function card(Context $c, object $v, array $list): void
    {
        $k = self::colors($c->theme);
        $c->column()->margin(0, 8)->round(12)->borderWidth(1)->borderColor($k['line'])->background($k['card'], $k['cardAlpha'])->clip();
          $last = count($list) - 1;
          foreach ($list as $i => $item) {
              if ($i > 0) { $c->box(0, 1, $k['line'])->fillWidth()->margin(0, 0, 0, self::LABEL); }
              self::row($c, $v, $item, $i === 0, $i === $last, false, true);
          }
        $c->end();
    }

    /**
     * A setting's row, as IINA's SettingsItem.General: its icon, label (a
     * help button after it) and description, its control centered on its
     * first line at the right, the settings under it (SettingsSubList: a
     * size smaller, a line above each), shown at once, as its switch is
     * on, or as the row is clicked open (its disclosure at the right);
     * dimmed and held while not $enabled, as what is under a switch that is
     * off. Ringed for a moment when a search went to it.
     * @param array<string, mixed> $item VideoPlayerSettingsPages' row
     */
    private static function row(Context $c, object $v, array $item, bool $first, bool $last, bool $small, bool $enabled): void
    {
        $t = $c->theme;
        $k = self::colors($t);
        $enabled = $enabled && (($item['holds'] ?? null) === null || ($item['holds'])()); // a row of its own condition (the advanced settings' switch)
        $pad = $small ? 8 : 12; // SettingsItem's vertical padding of its control size
        $expand = $item['expand'];
        $open = $expand ? ($v->expanded[$item['id']] ?? false) : ($item['shows'] === null || ($item['shows'])());
        $box = $c->key('item:' . $item['id'])->column()->fillWidth();
          $head = $c->row()->align(Node::START)->paddingStart(6)->paddingEnd(8)->fillWidth();
          if (!$enabled) { $head->disabled(); }
          if ($expand) {
              $head->role(Role::DISCLOSURE)->label($item['label'])->checked($open);
              $round = [$first ? 12 : 0, $first ? 12 : 0, $last && !$open ? 12 : 0, $last && !$open ? 12 : 0];
              if ($c->hovered($head)) { $head->background($k['hover'], $k['hoverAlpha'])->radius(...$round); } // IINA's ClickableView under the pointer
              if ($c->clicked($head)) { $v->expanded[$item['id']] = !$open; }
          }
            $c->column()->size(24, 20)->margin($small ? 7 : 11, 6, 0, 0)->center();
              if ($item['symbol'] !== null) { self::glyph($c, $item['symbol'], $t->text); }
            $c->end();
            $c->column(0, 6)->grow(1)->paddingY($pad)->justify(Node::CENTER);
              $c->row(0, 6)->align(Node::CENTER);
                $c->text($item['label'], $small ? 12 : 13, $t->text);
                if ($item['help'] !== '') { self::helpButton($c, $item['help']); }
              $c->end();
              if ($item['desc'] !== '') { $c->text($item['desc'], 11, $t->textMuted); }
            $c->end();
            if ($item['value'] !== null) {
                $c->row(0, 6)->height($pad * 2 + 16)->align(Node::CENTER);
                  ($item['value'])($enabled);
                $c->end();
            }
            if ($expand) {
                $c->column()->height($pad * 2 + 16)->width(16)->center()->marginStart(4);
                  self::sf($c, $open ? 'chevron.down' : 'chevron.right', 11, $t->textMuted, 0.4);
                $c->end();
            }
          $c->end();
          $inner = $enabled && ($item['enables'] === null || ($item['enables'])());
          if ($item['detail'] !== null) {
              $d = $c->column()->paddingStart(8)->fillWidth();
              if (!$inner) { $d->disabled(); }
                ($item['detail'])($inner);
              $c->end();
          }
          if ($item['sub'] !== [] && $open) {
              $sub = $c->column()->paddingStart(8)->fillWidth();
              $shown = $c->animate($sub, 'open', 1.0, $expand ? 0.25 : 0.0); // IINA's: what opens fades in
              $sub->opacity($shown);
                foreach ($item['sub'] as $i => $s) {
                    $c->box(0, 1, $k['line'])->fillWidth()->margin(0, 0, 0, self::LABEL);
                    self::row($c, $v, $s, false, $last && $i === count($item['sub']) - 1, true, $inner);
                }
              $c->end();
          }
        $c->end();
        if (($v->ring[0] ?? null) === $item['id']) { self::ring($c, $v, $box); }
    }

    /** IINA's ring around a setting a search went to: white under the accent, a second, the last fifth fading. */
    private static function ring(Context $c, object $v, Node $box): void
    {
        $t = $c->theme;
        $age = microtime(true) - $v->ring[1];
        if ($age >= 1.0) { $v->ring = null; return; }
        if ($age < 0.05) { $box->scrollIntoView(); }
        $alpha = $age < 0.8 ? 1.0 : (1.0 - $age) / 0.2;
        $c->within($box, function () use ($c, $t, $alpha): void {
            $c->column()->absolute()->left(-4)->right(-4)->top(-4)->bottom(-4)->passThrough()->draw(static function (DisplayList $l, Node $n) use ($t, $alpha): void {
                $l->ring($n->x, $n->y, $n->w, $n->h, 0xffffff, $alpha, 8, 4);
                $l->ring($n->x + 0.5, $n->y + 0.5, $n->w - 1, $n->h - 1, $t->accent, $alpha, 8, 3);
            });
            $c->end();
        });
        $c->animationFrame();
    }

    /** A search went to the setting $id under a row that opens at a click: it opens. */
    public static function openFor(object $v, array $pages): void
    {
        if ($v->ring === null) { return; }
        foreach ($pages as $page) {
            foreach ($page['sections'] as $section) {
                foreach ($section['lists'] as $list) {
                    foreach ($list as $item) {
                        foreach ($item['sub'] as $sub) {
                            if ($sub['id'] === $v->ring[0] && $item['expand']) { $v->expanded[$item['id']] = true; }
                        }
                    }
                }
            }
        }
    }

    /**
     * IINA's choice among options in boxes (SettingsAccessory.Selection),
     * under a row: each its title over its description, the one chosen
     * bordered in the accent; returns the one clicked, else null.
     * @param array<int, array{0: string, 1: string}> $options title and description by value
     */
    public static function selection(Context $c, array $options, int $chosen): ?int
    {
        $t = $c->theme;
        $clicked = null;
        $c->column(0, 4)->padding(-4, 8, 8, self::INDENT)->fillWidth();
          foreach ($options as $value => [$title, $desc]) {
              $on = $value === $chosen;
              $line = $on ? $t->accent : self::colors($t)['line'];
              $b = $c->key("option:$value")->column(0, 2)->padding(6, 12)->round(6)->fillWidth()->role(Role::RADIO)->checked($on)->label($title)->background($on ? $t->accent : 0x808080, 0.1)
                  ->draw(static function (DisplayList $l, Node $n) use ($line, $on): void { // the border over the face, 2 wide on the one chosen, the content staying where it is (NSBox's)
                      $l->ring($n->x, $n->y, $n->w, $n->h, $line, 1.0, 6, $on ? 2 : 1);
                  });
                $c->text($title, 13, $t->text);
                if ($desc !== '') { $c->text($desc, 11, $t->textMuted); }
              $c->end();
              if ($c->clicked($b) && !$on) { $clicked = $value; }
          }
        $c->end();
        return $clicked;
    }

    /**
     * IINA's radio buttons under a row (its radioGroup): each a circle and
     * its title, 24 apart, the one chosen in the accent (gray while the
     * window is not the key one) with its dot; returns the one clicked.
     * @param array<int, string> $options titles by value
     */
    public static function radios(Context $c, array $options, int $chosen): ?int
    {
        $t = $c->theme;
        $clicked = null;
        $c->column(0, 6)->padding(0, 8, 12, self::INDENT - 8)->fillWidth()->role(Role::RADIO_GROUP);
          foreach ($options as $value => $title) {
              $b = $c->key("radio:$value")->row(0, 6)->height(18)->align(Node::CENTER)->role(Role::RADIO)->checked($value === $chosen)->label($title);
                self::radio($c, $value === $chosen);
                $c->text($title, 13, $t->text);
              $c->end();
              if ($c->clicked($b) && $value !== $chosen) { $clicked = $value; }
          }
        $c->end();
        return $clicked;
    }

    /** A radio button's circle, 14 points, as macOS 26 draws it: the accent with a white dot when $on in the key window, gray with one in another; gray, empty, when off. */
    private static function radio(Context $c, bool $on): void
    {
        $key = $c->runtime?->windowFocus ?? true;
        $c->column()->size(14, 14)->draw(static function (DisplayList $l, Node $n, Theme $t) use ($on, $key): void {
            $accent = $on && $key;
            $l->rect($n->x, $n->y, 14, 14, $accent ? $t->accent : ($t->dark ? 0xffffff : 0x000000), $accent ? 1.0 : ($t->dark ? 0.12 : 0.06), 7);
            if (!$accent && !$t->dark) { $l->ring($n->x, $n->y, 14, 14, 0x000000, 0.2, 7, 0.5); }
            if ($on) { $l->rect($n->x + 4.5, $n->y + 4.5, 5, 5, $accent ? 0xffffff : ($t->dark ? 0xdfdfdf : 0x000000), $accent ? 1.0 : 0.85, 2.5); }
        });
        $c->end();
    }

    /**
     * IINA's choice of where the control bar is (its OSCLayoutView): three
     * small windows showing the floating bar, the bar at the top and at the
     * foot, 105 by 59, ringed, a radio button under each; the one clicked.
     */
    public static function oscLayout(Context $c, int $chosen): ?int
    {
        $t = $c->theme;
        $clicked = null;
        $c->row(0, 8)->paddingStart(self::INDENT)->paddingEnd(8)->paddingY(4)->fillWidth()->justify(Node::CENTER);
          foreach ([0, 1, 2] as $at) {
              $title = self::tr($c, "oscPosition.items.$at");
              $box = $c->key("osc:$at")->column(0, 8)->align(Node::CENTER)->padding(0, 0, 8, 0)->role(Role::RADIO)->checked($at === $chosen)->label($title);
                $c->column()->size(105.6, 59.4)->draw(static function (DisplayList $l, Node $n, Theme $t) use ($at): void { self::oscFigure($l, $n->x, $n->y, $n->w, $n->h, $at, $t); });
                $c->end();
                $c->row(0, 6)->align(Node::CENTER);
                  self::radio($c, $at === $chosen);
                  $c->text($title, 13, $t->text);
                $c->end();
              $c->end();
              if ($c->clicked($box) && $at !== $chosen) { $clicked = $at; }
          }
        $c->end();
        return $clicked;
    }

    /** A small window of a landscape (a sky, a sun, two hills) with the control bar where $at puts it (0 floating, 1 at the top, 2 at the foot): myphp's own drawing of what IINA's figures show. */
    private static function oscFigure(DisplayList $l, float $x, float $y, float $w, float $h, int $at, Theme $t): void
    {
        $l->rect($x, $y, $w, $h, 0x8bc9f8, 1.0, 6); // the sky
        $l->rect($x + $w - 22, $y + 10, 14, 14, 0xdad97f, 1.0, 7); // the sun
        foreach ([[0.4, 0.42, 0.45], [0.75, 0.5, 0.38]] as [$px, $py, $half]) { // two hills: their peaks, how far they spread, in slices
            for ($i = 0; $i < 24; $i++) {
                $k = ($i + 0.5) / 12 - 1; // -1 to 1 across the hill
                $top = $y + $h * ($py + (1 - $py) * abs($k) * 0.8);
                $x0 = max($x + 1, $x + $w * ($px + $k * $half) - $w * $half / 24); $x1 = min($x + $w - 1, $x + $w * ($px + $k * $half) + $w * $half / 24 + 0.5);
                if ($x1 > $x0) { $l->rect($x0, $top, $x1 - $x0, $y + $h - 1 - $top, 0x4a8f5c, 1.0); }
            }
        }
        $l->rect($x, $y, $w, 9, 0x506372, 1.0, 6); // the title bar, its lights
        $l->rect($x, $y + 5, $w, 4, 0x506372, 1.0);
        foreach ([0xfe5f57, 0xfebc2e, 0x28c840] as $i => $rgb) { $l->rect($x + 5 + $i * 7, $y + 2.5, 4.5, 4.5, $rgb, 1.0, 2.25); }
        [$bx, $by, $bw, $bh] = match ($at) { 0 => [$x + $w * 0.25, $y + $h - 17, $w * 0.5, 12], 1 => [$x, $y + 9, $w, 11], default => [$x, $y + $h - 11, $w, 11] };
        $l->rect($bx, $by, $bw, $bh, 0x2b3a30, 0.82, $at === 0 ? 2 : 0); // the bar, its buttons and its time
        $cy = $by + $bh / 2;
        $l->rect($bx + ($at === 0 ? $bw / 2 - 9 : 5), $cy - 1.5, 18, 3, 0xffffff, 0.85, 1.5);
        if ($at !== 0) { $l->rect($bx + 27, $cy - 0.5, $bw - 40, 1, 0xffffff, 0.75); $l->rect($bx + $bw - 11, $cy - 1.5, 6, 3, 0xffffff, 0.85, 1.5); }
        else { $l->rect($bx + 6, $by + $bh - 3, $bw - 12, 1, 0xffffff, 0.75); }
        $l->ring($x, $y, $w, $h, $t->dark ? 0xffffff : 0x000000, 0.15, 6, 2); // NSColor.separatorColor, 2 wide
    }

    /**
     * IINA's toolbar row's view (OSCToolbarView): the buttons the control
     * bar has, in a box, and Customize… which opens the sheet to choose
     * them; the buttons chosen there, else null.
     * @param list<int> $buttons
     */
    public static function toolbar(Context $c, object $v, array $buttons): ?array
    {
        $t = $c->theme;
        $c->row(0, 8)->padding(0, 8, 8, self::INDENT)->fillWidth()->justify(Node::CENTER)->align(Node::CENTER);
          $c->row(0, 0)->size(130, 26)->round(13)->justify(Node::END)->align(Node::CENTER)->paddingEnd(4)->background($t->dark ? 0xffffff : 0x000000, 0.05);
            foreach ($buttons as $b) {
                $c->column()->size(24, 24)->center();
                  self::glyph($c, self::TOOLBAR[$b][0] ?? ['questionmark'], $t->text);
                $c->end();
            }
          $c->end();
          $customize = self::pushButton($c, self::tr($c, '$Customize'), false);
        $c->end();
        if ($customize) { VideoPlayerToolbarSheet::open($c, $buttons, function (array $chosen) use ($v): void { $v->toolbarChosen = $chosen; }); }
        if (($v->toolbarChosen ?? null) !== null) {
            $chosen = $v->toolbarChosen;
            $v->toolbarChosen = null;
            return $chosen;
        }
        return null;
    }

    /** A switch as macOS 26 draws one (NSSwitch, mini), $label's to assistive technology; whether it was clicked. */
    public static function toggle(Context $c, string $label, bool $on): bool
    {
        $t = $c->theme;
        $sw = $c->row()->size(38, 18)->role(Role::SWITCH)->label($label)->checked($on)->draw(static function (DisplayList $l, Node $n, Theme $t) use ($on): void {
            $l->rect($n->x, $n->y, $n->w, $n->h, $on ? $t->accent : ($t->dark ? 0xffffff : 0x000000), $on ? 1.0 : ($t->dark ? 0.2 : 0.09), 9);
            if (!$t->dark) { $l->shadow($on ? $n->x + $n->w - 24 : $n->x + 2, $n->y + 2, 22, 14, 0x000000, 0.25, 7, 2, 0, 0.5); }
            $l->rect($on ? $n->x + $n->w - 24 : $n->x + 2, $n->y + 2, 22, 14, 0xffffff, 1.0, 7);
        });
        $c->end();
        return $c->clicked($sw);
    }

    /**
     * A pop-up button as macOS 26 draws one: the option chosen and the
     * arrows at the right, as wide as the widest option ($fill: as the row
     * is), its menu the options, the one chosen checked, a line where an
     * option is null; returns the value chosen from it, else null.
     * @param array<int|string, ?string> $options titles by value
     */
    public static function popUp(Context $c, array $options, int|string $chosen, bool $fill = false, bool $small = false): int|string|null
    {
        $t = $c->theme;
        $picked = null;
        $k = self::colors($t);
        $size = $small ? 12 : 13;
        $title = $options[$chosen] ?? '';
        $b = $c->row(0, 8)->height($small ? 22 : 24)->paddingStart($small ? 10 : 12)->paddingEnd($small ? 8 : 10)->round($small ? 7 : 8)->align(Node::CENTER)->role(Role::POP_UP_BUTTON)->label($title)
            ->background($k['control'], $k['controlAlpha']);
        if (!$t->dark) { $b->shadow(0, 0.5, 1.5, 0, 0x000000, 0.25); }
        if ($fill) { $b->fillWidth(); }
          $label = $c->text($title, $size, $t->text)->singleLine();
          if ($fill) { $label->grow(1); } else { $label->min(max(array_map(fn(?string $o) => $o === null ? 0.0 : $c->metrics()->measure($o, $size)[0], $options)), 0); }
          self::sf($c, 'chevron.up.chevron.down', $small ? 9 : 10, $t->text, 0.4);
        $c->end();
        $b->menu(function (Menu $m) use ($options, $chosen, &$picked): void {
            foreach ($options as $value => $o) {
                if ($o === null) { $m->separator(); continue; }
                if ($m->item($o)->checked($value === $chosen)->chosen()) { $picked = $value; }
            }
        });
        return $picked === $chosen ? null : $picked;
    }

    /** A push button as macOS 26 draws one; whether it was clicked. */
    public static function pushButton(Context $c, string $label, bool $small = true): bool
    {
        $t = $c->theme;
        $k = self::colors($t);
        $b = $c->row()->height($small ? 22 : 24)->paddingX($small ? 10 : 12)->round($small ? 7 : 8)->align(Node::CENTER)->role(Role::BUTTON)->label($label)->background($k['control'], $k['controlAlpha']);
        if (!$t->dark) { $b->shadow(0, 0.5, 1.5, 0, 0x000000, 0.25); }
          $c->text($label, $small ? 12 : 13, $t->text);
        $c->end();
        return $c->clicked($b);
    }

    /** A button of only symbol $name, as IINA's folder and font buttons (SButton): bordered faintly, $on in the accent; whether it was clicked. */
    public static function symbolButton(Context $c, string $name, string $label, bool $on = false, float $width = 32): bool
    {
        $t = $c->theme;
        $b = $c->row()->size($width, 25)->round(6)->center()->role(Role::BUTTON)->label($label)->borderWidth(1)->borderColor($t->dark ? 0x4a4a4a : 0xd0d0d0);
          self::sf($c, $name, 13, $on ? $t->accent : $t->text);
        $c->end();
        return $c->clicked($b);
    }

    /**
     * A field of a number as IINA's (NSTextField, 64 wide, rounded) with
     * its stepper (NSStepper) and its unit after it: what is typed taken
     * once it is a number in [$min, $max] (of no fraction unless $floats),
     * a step up or down from it; the new value, else null.
     */
    public static function number(Context $c, object $v, string $id, float $value, float $min, float $max, float $step, bool $floats, string $unit = '', bool $small = false): ?float
    {
        $t = $c->theme;
        $k = self::colors($t);
        $shown = $floats ? rtrim(rtrim(sprintf('%.2f', $value), '0'), '.') : (string)(int)round($value);
        $state = $v->fields[$id] ??= new TextState($shown);
        $new = null;
        $c->row(0, 2)->align(Node::CENTER);
          $c->row()->size(64, $small ? 22 : 26)->round(6)->background($k['field'], $k['fieldAlpha'] / 2)->borderWidth(1)->borderColor($k['line']);
            $f = $c->key("field:$id")->input($state, '', 64, $small ? 12 : 13)->height($small ? 22 : 26)->round(6);
            $f->bordered = false;
          $c->end();
          if (!$c->focused($f) && $state->text !== $shown && !$c->changed($f)) { $state->text = $shown; } // the value, while it is not being typed
          if ($c->changed($f) && is_numeric(trim($state->text))) {
              $n = (float)trim($state->text);
              if ($n >= $min && $n <= $max && ($floats || $n == floor($n))) { $new = $n; }
          }
          if ($step > 0) {
              $c->column()->size(13, $small ? 22 : 26)->role(Role::STEPPER);
                $up = $c->column()->grow(1)->center()->role(Role::BUTTON)->label('+');
                  self::sf($c, 'chevron.up', 8, $t->text, 0.4);
                $c->end();
                $down = $c->column()->grow(1)->center()->role(Role::BUTTON)->label('−');
                  self::sf($c, 'chevron.down', 8, $t->text, 0.4);
                $c->end();
              $c->end();
              if ($c->clicked($up)) { $new = min($max, $value + $step); }
              if ($c->clicked($down)) { $new = max($min, $value - $step); }
          }
          if ($unit !== '') { $c->text($unit, $small ? 12 : 13, $t->text)->marginStart(4); }
        $c->end();
        if ($new !== null && $new != $value) {
            if (!$c->changed($f)) { $state->text = $floats ? rtrim(rtrim(sprintf('%.2f', $new), '0'), '.') : (string)(int)$new; }
            return $new;
        }
        return null;
    }

    /** A field of text as IINA's long inputs (NSTextField, the row's width): the text once changed, else null. */
    public static function textField(Context $c, object $v, string $id, string $value, bool $small = true, string $placeholder = ''): ?string
    {
        $k = self::colors($c->theme);
        $state = $v->fields[$id] ??= new TextState($value);
        $c->row()->height($small ? 22 : 26)->fillWidth()->round(6)->background($k['field'], $k['fieldAlpha'] / 2)->borderWidth(1)->borderColor($k['line']);
          $f = $c->key("field:$id")->input($state, $placeholder, 0, $small ? 12 : 13)->height($small ? 22 : 26)->round(6);
          $f->bordered = false;
        $c->end();
        if (!$c->focused($f) && $state->text !== $value && !$c->changed($f)) { $state->text = $value; }
        return $c->changed($f) && $state->text !== $value ? $state->text : null;
    }

    /** IINA's help button after a label (NSButton, helpButton, mini): opens $url. */
    private static function helpButton(Context $c, string $url): void
    {
        $t = $c->theme;
        $b = $c->row()->size(16, 16)->round(8)->center()->role(Role::BUTTON)->label($c->texts()->get('help'))->background($t->dark ? 0xffffff : 0x000000, $t->dark ? 0.15 : 0.08);
          $c->text('?', 10, $t->text, Font::BOLD);
        $c->end();
        if ($c->clicked($b)) { $c->openURL($url); }
    }

    /**
     * The colors of macOS 26's settings, as IINA's window shows them: the
     * page's, a card's fill and its border (the lines between rows too), a
     * control's face, a field's, the page chosen in the sidebar, a row
     * under the pointer.
     * @return array<string, int|float>
     */
    public static function colors(Theme $t): array
    {
        return $t->dark
            ? ['page' => 0x212121, 'card' => 0x181818, 'cardAlpha' => 1.0, 'line' => 0x2e2e2e, 'control' => 0xffffff, 'controlAlpha' => 0.07, 'field' => 0xffffff, 'fieldAlpha' => 0.18, 'chosen' => 0xffffff, 'chosenAlpha' => 0.06, 'hover' => 0xffffff, 'hoverAlpha' => 0.1]
            : ['page' => 0xf5f5f5, 'card' => 0x000000, 'cardAlpha' => 0.02, 'line' => 0xe6e6e6, 'control' => 0xffffff, 'controlAlpha' => 1.0, 'field' => 0x000000, 'fieldAlpha' => 0.05, 'chosen' => 0x000000, 'chosenAlpha' => 0.08, 'hover' => 0x000000, 'hoverAlpha' => 0.08];
    }

    /**
     * A row's icon: an SF Symbol, the first of a list the system has (as
     * IINA's NSImage.sf), or one of IINA's own (its custom symbols, a
     * symbol and a badge) as a symbol with a smaller one at its bottom
     * right, "base+badge", the badge's ground cleared in the card's color.
     * @param string|list<string> $symbol
     */
    public static function glyph(Context $c, string|array $symbol, int $rgb): void
    {
        $names = is_array($symbol) ? $symbol : [$symbol];
        foreach ($names as $name) {
            [$base, $badge] = str_contains($name, '+') ? explode('+', $name, 2) : [$name, null];
            if (!self::has($base) || ($badge !== null && !self::has($badge))) { continue; }
            if ($badge === null) {
                self::sf($c, $base, 13, $rgb);
                return;
            }
            $c->column()->size(20, 18)->center();
              self::sf($c, $base, 13, $rgb);
              $k = self::colors($c->theme);
              $c->column()->absolute()->right(-1)->bottom(-1)->size(11, 11)->round(5.5)->center()->background($c->theme->dark ? $k['card'] : 0xfafafa);
                self::sf($c, $badge, 8, $rgb, 0.4);
              $c->end();
            $c->end();
            return;
        }
        throw new \RuntimeException('VideoPlayerSettings: none of the symbols ' . implode(', ', $names));
    }

    /** Whether the system has SF Symbol $name. */
    private static function has(string $name): bool
    {
        static $known = [];
        return $known[$name] ??= \MyPHP\Native\Symbols::exists($name);
    }

    /** SF Symbol $name at $size points in $rgb, of $weight: drawn once a window's scale. */
    public static function sf(Context $c, string $name, float $size, int $rgb, float $weight = 0.0): Node
    {
        static $drawn = [];
        $img = $drawn["$name/$size/$weight/$rgb/{$c->scale}"] ??= Image::symbol($name, $size, $rgb, $weight, $c->scale);
        return $c->image($img, $img->w / $c->scale, $img->h / $c->scale);
    }

    /** IINA's string $key of its settings (its "settings." strings): settingsPauseWhenOpenLabel for "pauseWhenOpen.label", settingsBehavior for "$Behavior". */
    public static function tr(Context $c, string $key, array $args = []): string
    {
        return $c->texts()->get(self::key($key), $args);
    }

    /** IINA's string $key outside its settings (its Localizable.strings: "history_window.col.filename" is iinaHistoryWindowColFilename). */
    public static function iina(Context $c, string $key, array $args = []): string
    {
        return $c->texts()->get('iina' . implode('', array_map('ucfirst', preg_split('/[._$]+/', $key, -1, PREG_SPLIT_NO_EMPTY))), $args);
    }

    /** The key of IINA's settings string $key in MyPHP's strings. */
    public static function key(string $key): string
    {
        return 'settings' . implode('', array_map('ucfirst', preg_split('/[._$]+/', $key, -1, PREG_SPLIT_NO_EMPTY)));
    }

}
