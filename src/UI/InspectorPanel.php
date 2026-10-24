<?php
declare(strict_types=1);

namespace MyPHP\UI;

/**
 * Builds the inspector's panel (mygo's inspector_panel.go) with the
 * view's nodes, at the root after them: its toolbar (the picker, the tabs,
 * the right-to-left toggle, the issues and Close), the Elements tab (the
 * tree of nodes as markup with its find bar and breadcrumbs, and the
 * sidebar of the node chosen: Styles, Computed, Properties), Performance
 * and Issues.
 */
final class InspectorPanel
{
    /** The panel's colors, Chrome's developer tools', in the window's appearance. @return array<string, int> */
    public static function palette(bool $dark): array
    {
        return $dark ? [
            'bg' => 0x202124, 'toolbar' => 0x292a2d, 'border' => 0x494c50, 'text' => 0xe8eaed, 'muted' => 0x9aa0a6,
            'accent' => 0x8ab4f8, 'selected' => 0x0f3a66, 'unfocused' => 0x3c4043, 'hover' => 0x2f3237,
            'tag' => 0x5db0d7, 'attr' => 0x9bbbdc, 'value' => 0xf29766, 'prop' => 0x5cd5fb, 'str' => 0xf28b54, 'lit' => 0x9980ff, 'warn' => 0xfdd663,
        ] : [
            'bg' => 0xffffff, 'toolbar' => 0xf1f3f4, 'border' => 0xcbcdd1, 'text' => 0x202124, 'muted' => 0x5f6368,
            'accent' => 0x1a73e8, 'selected' => 0xcfe8fc, 'unfocused' => 0xe3e3e3, 'hover' => 0xebf1fb,
            'tag' => 0x881280, 'attr' => 0x994500, 'value' => 0x1a1aa6, 'prop' => 0xc80000, 'str' => 0xc41a16, 'lit' => 0x1a1aa6, 'warn' => 0xe37400,
        ];
    }

    /** The colors of the Performance tab: building as Chrome's scripting, laying out as rendering, painting as painting. */
    private const BUILD = 0xf2b630, LAYOUT = 0x9a7fd1, PAINT = 0x71b362;

    /** @var ?array{pick: SVG, close: SVG, warn: SVG, arrow: SVG} the panel's icons, parsed once a window opens it */
    private static ?array $icons = null;

    /** @return array{pick: SVG, close: SVG, warn: SVG, arrow: SVG} */
    private static function icons(): array
    {
        $stroked = fn(string $shapes) => SVG::parse('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . $shapes . '</svg>');
        return self::$icons ??= [
            'pick' => $stroked('<path d="M21 11V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h6"/><path d="m12 12 4.2 10 1.6-4.4L22 16z"/>'),
            'close' => $stroked('<path d="M18 6 6 18M6 6l12 12"/>'),
            'warn' => $stroked('<path d="m21.7 18-8-14a2 2 0 0 0-3.4 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.7-3z"/><path d="M12 9v4M12 17h.01"/>'),
            'arrow' => SVG::parse('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><path d="M3 1.5 7.5 5 3 8.5z" fill="currentColor"/></svg>'),
        ];
    }

    /** What labels the picker, which tests click. */
    public const PICK = 'Select an element in the window to inspect it';

    /** Builds the panel at the right of the content, which the inspector left $in->appW of the window $w × $h. */
    public static function build(Context $c, Runtime $rt, Inspector $in, float $w, float $h): void
    {
        $in->shown = $in->sum; $in->changed = false;
        $in->base = $c->theme;
        $pal = $in->pal = self::palette($c->theme->dark);
        // The panel looks as Chrome's tools do, whatever theme the view set; rows chosen span the tree, square.
        $t = clone $c->theme;
        $t->background = $pal['bg']; $t->surface = $pal['toolbar']; $t->surfaceHover = $pal['hover']; $t->border = $pal['border'];
        $t->text = $pal['text']; $t->textMuted = $pal['muted']; $t->fontSize = 12; $t->radius = 0;
        $t->selection = $in->listId !== null && $rt->focused($in->listId) ? $pal['selected'] : $pal['unfocused']; // the tree's row chosen, as the tree has the focus or not
        $t->selectionAlpha = 1.0;
        $in->theme = $t;
        $appW = $in->appW;
        $c->root->padding(0, $w - $appW, 0, 0); // the content keeps clear of the panel
        $saved = $c->theme;
        $c->theme = $t;
        $c->overlay(function () use ($c, $rt, $in, $pal, $appW, $w, $h): void {
            // The panel reads left to right whatever the window, as browsers' developer tools do.
            $panel = $c->key(Inspector::KEY)->column(0, 0, 0, $pal['bg'])->at($appW, 0)->size($w - $appW, $h)->direction(Direction::LeftToRight)
                ->borderWidth(0, 0, 0, 1)->borderColor($pal['border'])->align(Node::STRETCH)->fontSize(12)->textColor($pal['text'])->label('Developer tools');
            $panel->theme = $in->theme;
            $in->panel = $panel->id;
              self::resizer($c, $rt, $in, $h);
              self::toolbar($c, $rt, $in);
              match ($in->tab) {
                  Inspector::PERFORMANCE => self::performance($c, $in, $w - $appW),
                  Inspector::ISSUES => self::issues($c, $rt, $in),
                  default => self::elements($c, $rt, $in, $h),
              };
            $c->end();
            if ($rt->focusWithin($panel->id) && $c->shortcut('f', Key::COMMAND)) {
                $in->finding = true; $in->tab = Inspector::ELEMENTS;
            }
            if ($in->picking) { // the node under the pointer in the content
                $x = $rt->input->mouseX; $y = $rt->input->mouseY;
                $in->hovered = $rt->input->mouseInside && $x < $appW ? $in->hitAt($rt, $x, $y) : null;
            }
        });
        $c->theme = $saved;
    }

    /** The panel's left edge, which the pointer drags to resize it. */
    private static function resizer(Context $c, Runtime $rt, Inspector $in, float $h): void
    {
        $edge = $c->box(6, $h, 0)->at(-4, 0)->cursor('resizeEW')->label('Resize developer tools');
        $edge->color = null;
        if (($d = $rt->dragged($edge->id)) !== null && $d[0] != 0) {
            $in->width = max($in->width - $d[0], 260);
        }
    }

    /** The bar at the top of the panel: the picker, the tabs, the right-to-left toggle, the issues and Close. */
    private static function toolbar(Context $c, Runtime $rt, Inspector $in): void
    {
        $pal = $in->pal; $icons = self::icons();
        $c->row(0, 0, 0, $pal['toolbar'])->size(0, 30)->shrink(0)->paddingX(4)->align(Node::CENTER)->borderWidth(0, 0, 1, 0)->borderColor($pal['border']);
          if (self::iconButton($c, $in, $icons['pick'], $in->picking, self::PICK)) {
              $in->picking = !$in->picking; $in->hovered = null; $in->tab = Inspector::ELEMENTS;
          }
          $c->box(1, 16, $pal['border'])->margin(0, 4);
          $n = count($rt->warnings);
          foreach (['Elements', 'Performance', $n > 0 ? "Issues $n" : 'Issues'] as $i => $label) {
              if (self::tab($c, $in, $label, $in->tab === $i, 30)) { $in->tab = $i; }
          }
          $c->spacer();
          // Lays the window out right to left, or back, to try it without translations, as GTK's inspector can.
          $rtl = $rt->direction();
          $dir = self::tabNode($c, $in, 'RTL', $rtl, 30)->role(Role::TOGGLE_BUTTON)->checked($rtl)->tooltip('Lay the window out right to left');
          if ($c->clicked($dir)) {
              $rt->dirOverride = $rtl ? 1 : 2;
              $rt->changed();
          }
          if ($n > 0) {
              $badge = $c->row(0, 3)->paddingX(6)->align(Node::CENTER)->cursor('pointer')->label("$n issues");
                $c->icon($icons['warn'], 13, $pal['warn']);
                $c->text((string)$n, 11, $pal['muted']);
              $c->end();
              if ($c->clicked($badge)) { $in->tab = Inspector::ISSUES; }
          }
          if (self::iconButton($c, $in, $icons['close'], false, 'Close')) {
              $in->toggle($rt);
          }
        $c->end();
    }

    /** A button of the toolbar showing $icon, in the accent color while on; whether it was clicked. It takes no focus, so as not to take it from the content. */
    private static function iconButton(Context $c, Inspector $in, SVG $icon, bool $on, string $label): bool
    {
        $pal = $in->pal;
        $b = $c->column()->size(26, 24)->round(4)->center()->label($label)->role(Role::BUTTON)->tooltip($label);
        $color = $pal['muted'];
        if ($on) {
            $color = $pal['accent'];
        } elseif ($c->hovered($b)) {
            $color = $pal['text'];
            $b->background($pal['hover']);
        }
          $c->icon($icon, 16, $color);
        $c->end();
        return $c->clicked($b);
    }

    /** A tab of a bar $height high, underlined in the accent color while selected; whether it was clicked. */
    private static function tab(Context $c, Inspector $in, string $label, bool $selected, float $height): bool
    {
        return $c->clicked(self::tabNode($c, $in, $label, $selected, $height));
    }

    private static function tabNode(Context $c, Inspector $in, string $label, bool $selected, float $height): Node
    {
        $pal = $in->pal;
        $t = $c->column()->size(0, $height)->paddingX(10)->center()->label($label)->role(Role::TAB)->checked($selected);
        $color = $pal['muted'];
        if ($selected) {
            $color = $pal['text'];
            $t->borderWidth(0, 0, 2, 0)->borderColor($pal['accent']);
        } elseif ($c->hovered($t)) {
            $color = $pal['text'];
            $t->background($pal['hover']);
        }
          $c->text($label, 12, $color)->singleLine();
        $c->end();
        return $t;
    }

    /** The Elements tab: the tree above, the sidebar of the node chosen below. */
    private static function elements(Context $c, Runtime $rt, Inspector $in, float $h): void
    {
        if ($in->split == 0) { $in->split = max($h * 0.55, 120); }
        $c->split($in->split, function () use ($c, $rt, $in): void {
            $c->column(0, 0, 1)->align(Node::STRETCH);
              self::find($c, $rt, $in);
              self::tree($c, $rt, $in);
              self::breadcrumbs($c, $in);
            $c->end();
        }, fn() => self::sidebar($c, $in), vertical: true)->grow(1)->min(0, 0);
    }

    /** Lists the rows the tree shows: the nodes inside no node closed, and the closing tags of those open. */
    private static function treeRows(Inspector $in): void
    {
        $in->rows = [];
        $open = []; // the nodes open around the node, innermost last
        $hide = -1;
        foreach ($in->nodes as $i => $n) {
            while ($open !== [] && $in->nodes[$open[count($open) - 1]]['depth'] >= $n['depth']) {
                $in->rows[] = [array_pop($open), true];
            }
            if ($hide >= 0 && $n['depth'] > $hide) { continue; }
            $hide = -1;
            $in->rows[] = [$i, false];
            if ($n['kids']) {
                if ($in->isOpen($i)) { $open[] = $i; } else { $hide = $n['depth']; }
            }
        }
        while ($open !== []) { $in->rows[] = [array_pop($open), true]; }
    }

    /** The row of the opening tag of node $id, -1 for none. */
    private static function rowOf(Inspector $in, ?int $id): int
    {
        foreach ($in->rows as $r => [$i, $close]) {
            if (!$close && $in->nodes[$i]['id'] === $id) { return $r; }
        }
        return -1;
    }

    /** The tree of nodes as markup, Chrome's Elements panel. */
    private static function tree(Context $c, Runtime $rt, Inspector $in): void
    {
        $pick = function () use ($in): void { // the row the keys or a click chose: its node
            $r = $in->list->selected ?? -1;
            if ($r >= 0 && isset($in->rows[$r])) { $in->choose($in->nodes[$in->rows[$r][0]]['id']); }
        };
        self::treeRows($in);
        if ($in->list->changed()) { $pick(); } // Up and Down, as the events came
        if ($in->reveal && $in->selected !== null) {
            $in->reveal = false;
            if ($in->expandTo($in->selected)) { self::treeRows($in); }
            if (($r = self::rowOf($in, $in->selected)) >= 0) { $in->list->scrollIntoView($r); }
        }
        $in->list->selected = self::rowOf($in, $in->selected);
        $in->list->key = fn(int $r) => $in->rows[$r][1] ? ~$in->nodes[$in->rows[$r][0]]['key'] : $in->nodes[$in->rows[$r][0]]['key'];
        $hovered = null;
        $list = $c->list($in->list, count($in->rows), function (int $r) use ($c, $in, &$hovered): void {
            [$i, $close] = $in->rows[$r];
            $n = $in->nodes[$i];
            $row = $c->row()->size(0, 20)->padding(0, 6, 0, $n['depth'] * 14 + 4)->align(Node::CENTER)->label(trim($n['name'] . ' ' . Inspector::clip($n['text'], 40)));
            if ($c->hovered($row)) { $hovered = $n['id']; }
            if ($n['leaving']) { $row->opacity(0.5); }
              self::treeRow($c, $in, $i, $close);
            $c->end();
        })->grow(1)->min(0, 0)->paddingY(2)->label('Elements');
        $in->listId = $list->id;
        if ($in->list->changed()) { $pick(); } // a click on a row
        // Left closes the node chosen, else goes to the node around it; Right opens it, else goes into it.
        if ($rt->focused($list->id) && ($i = $in->nodeOf($in->selected)) >= 0) {
            $n = $in->nodes[$i];
            $left = $c->shortcut(Key::LEFT); $right = !$left && $c->shortcut(Key::RIGHT);
            if ($left && $n['kids'] && $in->isOpen($i)) {
                $in->setOpen($i, false, false);
            } elseif ($left && $n['parent'] >= 0) {
                $in->choose($in->nodes[$n['parent']]['id']); $in->reveal = true;
            } elseif ($right && $n['kids'] && !$in->isOpen($i)) {
                $in->setOpen($i, true, false);
            } elseif ($right && $n['kids']) {
                $in->choose($in->nodes[$i + 1]['id']); $in->reveal = true;
            }
        }
        if (!$in->picking) { $in->hovered = $hovered; }
    }

    /** The markup of a row: the opening tag of node $i, or its closing tag. */
    private static function treeRow(Context $c, Inspector $in, int $i, bool $close): void
    {
        $pal = $in->pal; $n = $in->nodes[$i];
        if (!$close && $n['kids']) {
            $open = $in->isOpen($i);
            $arrow = $c->icon(self::icons()['arrow'], 10, $pal['muted'])->margin(0, 2, 0, 0)->label($open ? 'Collapse' : 'Expand');
            if ($open) { $arrow->rotate(90); }
            if ($c->clicked($arrow)) {
                $in->setOpen($i, !$open, ($c->runtime->clickMods & Key::OPTION) !== 0); // Option opens or closes everything inside, as Alt in Chrome
            }
        } else {
            $c->box(12, 1, 0)->shrink(0)->color = null;
        }
        $tag = fn(string $s) => new Span($s, color: $pal['tag']);
        if ($close) {
            $spans = [$tag('</' . $n['name'] . '>')];
        } else {
            $spans = [$tag('<' . $n['name'])];
            $attr = function (string $name, string $value) use (&$spans, $tag, $pal): void {
                array_push($spans, new Span(" $name", color: $pal['attr']), $tag('='), new Span('"' . Inspector::clip($value, 40) . '"', color: $pal['value']));
            };
            if ($n['keyText'] !== '') { $attr('key', $n['keyText']); }
            if ($n['label'] !== '' && $n['label'] !== $n['text']) { $attr('label', $n['label']); }
            if ($n['kids'] && $in->isOpen($i)) {
                $spans[] = $tag('>');
            } elseif ($n['kids']) {
                array_push($spans, $tag('>'), new Span('…', color: $pal['muted']), $tag('</' . $n['name'] . '>'));
            } elseif ($n['text'] !== '') {
                array_push($spans, $tag('>'), new Span(Inspector::clip($n['text'], 80), color: $pal['text']), $tag('</' . $n['name'] . '>'));
            } else {
                $spans[] = $tag('></' . $n['name'] . '>');
            }
        }
        $c->richText($spans, 11.5)->font('monospace')->noWrap()->ellipsis('…')->shrink(1)->min(0, 0);
        if (!$close && $n['trans']) {
            $c->text('transition', 10, $pal['muted'])->font('monospace')->padding(0, 4)->margin(0, 0, 0, 6)->round(3)->borderWidth(1)->borderColor($pal['border'])->shrink(0);
        }
    }

    /** The find bar of the tree (Cmd+F), which finds nodes by their tag, text, label or key. */
    private static function find(Context $c, Runtime $rt, Inspector $in): void
    {
        self::findMatches($in);
        $bar = $c->findBar($in->finding, $in->query, count($in->matches), $in->match);
        $typed = $in->query->text !== $in->typed; // typed since the last frame: the field takes the keys before the build
        $in->typed = $in->query->text;
        if ($bar === null) { return; }
        if ($typed) { // the first match of the new query
            self::findMatches($in);
            $in->match = 0;
            $rt->changed();
        }
        if (($bar->changed || $typed) && $in->matches !== []) {
            $in->match = max(0, min($in->match, count($in->matches) - 1));
            $in->choose($in->nodes[$in->matches[$in->match]]['id']);
            $in->reveal = true;
        }
    }

    /** Lists the nodes whose tag, text, label or key holds the query, whatever their case. */
    private static function findMatches(Inspector $in): void
    {
        $in->matches = [];
        if (!$in->finding || $in->query->text === '') { return; }
        $q = mb_strtolower($in->query->text);
        foreach ($in->nodes as $i => $n) {
            foreach (['name', 'text', 'label', 'keyText'] as $f) {
                if (str_contains(mb_strtolower($n[$f]), $q)) { $in->matches[] = $i; break; }
            }
        }
    }

    /** The path of the node chosen, from the root, whose nodes a click chooses. */
    private static function breadcrumbs(Context $c, Inspector $in): void
    {
        $pal = $in->pal;
        $path = [];
        for ($i = $in->nodeOf($in->selected); $i >= 0; $i = $in->nodes[$i]['parent']) { $path[] = $i; }
        if ($path === []) { return; }
        $c->row(0, 0, 0, $pal['toolbar'])->size(0, 24)->shrink(0)->borderWidth(1, 0, 0, 0)->borderColor($pal['border'])->align(Node::STRETCH);
          $c->row(0, 1)->scroll($in->crumbs, true, false)->grow(1)->min(0, 0)->paddingX(4)->align(Node::CENTER);
            for ($k = count($path) - 1; $k >= 0; $k--) {
                $n = $in->nodes[$path[$k]];
                $crumb = $c->key($n['key'])->column()->padding(2, 6)->round(3)->label($n['name'])->shrink(0);
                if ($k === 0) {
                    $crumb->background($pal['selected']);
                } elseif ($c->hovered($crumb)) {
                    $crumb->background($pal['hover']);
                    $in->hovered = $n['id'];
                }
                  $c->text($n['name'], 11, $pal['tag'])->font('monospace')->singleLine();
                $c->end();
                if ($c->clicked($crumb)) { $in->choose($n['id']); $in->reveal = true; }
            }
          $c->end();
        $c->end();
    }

    /** The tabs below the tree: Styles, Computed and Properties of the node chosen. */
    private static function sidebar(Context $c, Inspector $in): void
    {
        $pal = $in->pal;
        $c->column(0, 0, 1)->align(Node::STRETCH);
          $c->row(0, 0, 0, $pal['toolbar'])->size(0, 26)->shrink(0)->paddingX(4)->borderWidth(0, 0, 1, 0)->borderColor($pal['border']);
            foreach (['Styles', 'Computed', 'Properties'] as $i => $label) {
                if (self::tab($c, $in, $label, $in->side === $i, 26)) { $in->side = $i; }
            }
          $c->end();
          $c->column()->scroll($in->sideScroll)->grow(1)->min(0, 0)->align(Node::STRETCH);
            if ($in->sel === null) {
                $c->text('Choose an element in the tree, or pick one in the window.', 12, $pal['muted'])->margin(12);
            } else {
                match ($in->side) {
                    Inspector::COMPUTED => self::computedPane($c, $in),
                    Inspector::PROPERTIES => self::propertiesPane($c, $in),
                    default => self::stylesPane($c, $in),
                };
            }
          $c->end();
        $c->end();
    }

    /** A line of monospaced spans, as the panes show declarations. @param list<Span> $spans */
    private static function mono(Context $c, array $spans): Node
    {
        return $c->richText($spans, 11.5)->font('monospace');
    }

    /** A declaration: its property in $name's color, a colon, and its value, after a swatch of its color. @param array{0: string, 1: string, 2: ?int} $d */
    private static function decl(Context $c, Inspector $in, array $d, int $name): void
    {
        $pal = $in->pal;
        if ($d[2] === null) {
            self::mono($c, [new Span($d[0], color: $name), new Span(': ' . $d[1] . ';', color: $pal['text'])])->selectable();
            return;
        }
        $c->row()->align(Node::CENTER);
          self::mono($c, [new Span($d[0], color: $name), new Span(':', color: $pal['text'])])->shrink(0);
          $c->box(10, 10, $d[2])->margin(0, 4, 0, 6)->borderWidth(1)->borderColor($pal['border'])->shrink(0);
          self::mono($c, [new Span($d[1] . ';', color: $pal['text'])])->shrink(1)->min(0, 0)->selectable();
        $c->end();
    }

    /** The node's styles as CSS rules: its own, then those it inherits, as Chrome's Styles tab. */
    private static function stylesPane(Context $c, Inspector $in): void
    {
        $pal = $in->pal;
        foreach ($in->sel['rules'] as $i => $r) {
            if ($r['inherited']) {
                $c->row(0, 0, 0, $pal['toolbar'])->padding(4, 10);
                  $c->richText([new Span('Inherited from ', color: $pal['muted']), new Span($r['selector'], color: $pal['tag'], font: 'monospace')], 11);
                $c->end();
            }
            $c->column(0, 1)->padding(6, 10, 8)->borderWidth(0, 0, 1, 0)->borderColor($pal['border'])->align(Node::STRETCH);
              $c->row()->align(Node::CENTER);
                self::mono($c, [new Span($r['selector'] . ' {', color: $pal['text'])]);
                $c->spacer();
                if ($i === 0 && $in->sel['source'] !== '') {
                    $c->text(basename($in->sel['source']), 11, $pal['muted'])->underline()->singleLine()->shrink(1)->min(0, 0)->tooltip($in->sel['source']);
                }
              $c->end();
              $c->column(0, 1)->padding(0, 0, 0, 16);
                foreach ($r['decls'] as $d) { self::decl($c, $in, $d, $pal['prop']); }
              $c->end();
              self::mono($c, [new Span('}', color: $pal['text'])]);
            $c->end();
        }
    }

    /** The node's box model and its values as laid out, as Chrome's Computed tab. */
    private static function computedPane(Context $c, Inspector $in): void
    {
        $pal = $in->pal; $d = $in->sel;
        $side = fn(float $v) => match (true) { is_infinite($v) => 'auto', $v == 0 => '-', default => Inspector::num($v) };
        $ink = 0x202124;
        $layer = function (string $name, int $bg, int $line, array $sides, \Closure $inner) use (&$layer, $c, $side, $ink): void {
            $c->column(0, 0, 0, $bg)->borderWidth(1)->borderColor($line)->align(Node::STRETCH)->fontSize(11)->textColor($ink);
              $c->text($name, 10, $ink)->at(4, 2)->opacity(0.7);
              $c->text($side($sides[0]), 11, $ink)->textAlign(Node::CENTER)->margin(3, 0)->alignSelf(Node::CENTER);
              $c->row()->align(Node::CENTER);
                $c->column()->size(30, 0)->shrink(0)->center(); $c->text($side($sides[3]), 11, $ink); $c->end();
                $c->column(0, 0, 1)->align(Node::STRETCH); $inner(); $c->end();
                $c->column()->size(30, 0)->shrink(0)->center(); $c->text($side($sides[1]), 11, $ink); $c->end();
              $c->end();
              $c->text($side($sides[2]), 11, $ink)->margin(3, 0)->alignSelf(Node::CENTER);
            $c->end();
        };
        $c->column(12)->align(Node::STRETCH);
          $layer('margin', 0xf9cc9d, 0x333333, $d['margin'], function () use ($layer, $c, $d, $ink): void {
              $layer('border', 0xfddd9b, 0x000000, $d['border'], function () use ($layer, $c, $d, $ink): void {
                  $layer('padding', 0xc3d08b, 0x808080, $d['padding'], function () use ($c, $d, $ink): void {
                      $c->column(0, 0, 0, 0x8cb6c0)->borderWidth(1)->borderColor(0x808080)->padding(6, 4)->center();
                        $c->text(Inspector::num($d['w']) . ' × ' . Inspector::num($d['h']), 11, $ink)->singleLine();
                      $c->end();
                  });
              });
          });
        $c->end();
        $c->column(0, 1)->padding(0, 10, 10);
          foreach ($d['computed'] as $dc) { self::decl($c, $in, $dc, $in->pal['prop']); }
        $c->end();
    }

    /** What the node is, its state, and what assistive technology sees of it. */
    private static function propertiesPane(Context $c, Inspector $in): void
    {
        $pal = $in->pal;
        foreach ($in->sel['props'] as $s) {
            $c->column(0, 2)->padding(6, 10, 8)->borderWidth(0, 0, 1, 0)->borderColor($pal['border'])->align(Node::STRETCH);
              $c->text($s['title'], 11, $pal['text'])->bold()->margin(0, 0, 2, 0);
              foreach ($s['rows'] as [$name, $value]) {
                  $color = match (true) { str_starts_with($value, '"') => $pal['str'], $value === 'true' || $value === 'false' => $pal['lit'], default => $pal['text'] };
                  $c->row()->padding(0, 0, 0, 8);
                    self::mono($c, [new Span($name, color: $pal['tag']), new Span(': ', color: $pal['text']), new Span($value, color: $color)])->shrink(1)->min(0, 0)->selectable();
                  $c->end();
              }
            $c->end();
        }
    }

    /** The Performance tab: how long the last frames took to build, lay out and paint, against the time between two refreshes of the display. */
    private static function performance(Context $c, Inspector $in, float $width): void
    {
        $pal = $in->pal; $hz = $in->hz;
        $budget = 1e9 / $hz;
        $n = min($in->frames, Inspector::HISTORY);
        $total = 0; $slowest = 0;
        for ($i = 0; $i < $n; $i++) {
            $d = array_sum($in->history[$i]);
            $total += $d; $slowest = max($slowest, $d);
        }
        $c->column(12, 12)->scroll($in->perfScroll)->grow(1)->min(0, 0)->align(Node::STRETCH); // the rows below wrap at $width less the panel's border and this padding
          $c->row(0, 16)->wrap($width - 25);
            $stat = function (string $label, string $value) use ($c, $pal): void {
                $c->column(0, 2);
                  $c->text($label, 11, $pal['muted']);
                  $c->text($value, 18, $pal['text'])->fontWeight(600)->fontFeatures('tnum');
                $c->end();
            };
            $stat('Last frame', Inspector::ms(array_sum($in->times)) . ' ms');
            $stat('Average', Inspector::ms($n > 0 ? $total / $n : 0) . ' ms');
            $stat('Slowest', Inspector::ms($slowest) . ' ms');
            $stat('Display', Inspector::num($hz) . ' Hz');
            $stat('Elements', (string)$in->elements);
          $c->end();
          $c->column()->size(0, 150)->shrink(0)->round(4)->borderWidth(1)->borderColor($pal['border'])
              ->draw(fn(DisplayList $list, Node $box) => self::paintFrames($list, $in, $box));
          $c->end();
          $c->row(0, 14)->wrap($width - 25);
            foreach ([['Build', self::BUILD, 0], ['Layout', self::LAYOUT, 1], ['Paint', self::PAINT, 2]] as [$name, $color, $k]) {
                $c->row(0, 6)->align(Node::CENTER);
                  $c->box(10, 10, $color)->round(2);
                  $c->text($name . ' ' . Inspector::ms($in->times[$k]) . ' ms', 11, $pal['text'])->fontFeatures('tnum');
                $c->end();
            }
            $c->text('— ' . Inspector::ms($budget) . ' ms, a frame at ' . Inspector::num($hz) . ' Hz', 11, $pal['muted']);
          $c->end();
          $c->richText([new Span('Frames happen only when something changes: input, an update, or motion. Set MYPHP_STATS to log them.')], 11, $pal['muted'], wrap: true);
        $c->end();
    }

    /** The last frames as bars in $box, the latest at the right, stacked by what they spent their time on. */
    private static function paintFrames(DisplayList $list, Inspector $in, Node $box): void
    {
        $n = min($in->frames, Inspector::HISTORY);
        $budget = 1e9 / $in->hz;
        $scale = 2 * $budget;
        for ($i = 0; $i < $n; $i++) { $scale = max($scale, array_sum($in->history[$i]) * 1.1); }
        [$x, $y, $w, $h] = [$box->x + 4, $box->y + 6, $box->w - 8, $box->h - 10];
        $ly = $y + $h - $budget / $scale * $h;
        $list->line($x, $ly, $x + $w, $ly, $in->pal['muted'], 0.6, 1.0);
        $bw = $w / Inspector::HISTORY;
        for ($k = 0; $k < $n; $k++) {
            $f = $in->history[($in->frames - $n + $k) % Inspector::HISTORY];
            $bx = $x + $w - ($n - $k) * $bw;
            $bottom = $y + $h;
            foreach ([self::BUILD, self::LAYOUT, self::PAINT] as $j => $color) {
                $bh = $f[$j] / $scale * $h;
                if ($bh <= 0) { continue; }
                $list->rect($bx + $bw * 0.15, $bottom - $bh, $bw * 0.7, $bh, $color);
                $bottom -= $bh;
            }
        }
    }

    /** The Issues tab: the warnings, latest first. */
    private static function issues(Context $c, Runtime $rt, Inspector $in): void
    {
        $pal = $in->pal;
        $c->column()->scroll($in->issuesScroll)->grow(1)->min(0, 0)->align(Node::STRETCH);
          if ($rt->warnings === []) {
              $c->text('No issues so far.', 12, $pal['muted'])->margin(12);
          }
          for ($i = count($rt->warnings) - 1; $i >= 0; $i--) {
              $c->row(0, 8)->padding(8, 10)->borderWidth(0, 0, 1, 0)->borderColor($pal['border']);
                $c->icon(self::icons()['warn'], 14, $pal['warn'])->shrink(0);
                $c->richText([new Span($rt->warnings[$i])], 11.5, $pal['text'], wrap: true)->grow(1)->min(0, 0)->selectable();
              $c->end();
          }
        $c->end();
    }
}
