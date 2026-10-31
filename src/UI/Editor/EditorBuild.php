<?php
declare(strict_types=1);

namespace MyPHP\UI\Editor;

use MyPHP\{Menu, MenuItem, Window};
use MyPHP\UI\{AccessNode, Context, InputEvent, Node, Painter, Role, TextPainter, Theme as UITheme};

/** Building an editor's element and its parts for a frame (mygo's build.go, and parts.go's placing of its parts). */
trait EditorBuild
{
    /** The window's theme, as of the last frame. */
    public ?UITheme $uiTheme = null;

    /** Builds the element of an editor for a frame: a container, to size as the app's layout needs (grow, size). */
    public function build(Context $c, Buffer $buf, Options $o): Node
    {
        $m = $c->metrics();
        if (!$m instanceof TextPainter) { throw new \LogicException('a code editor needs a text engine that paints (TextPainter)'); }
        $this->tp = $m;
        $this->texts = $c->texts();
        $this->uiTheme = $c->theme;
        $this->init($buf, $o, $c->theme, $m);
        $this->services = $c->runtime;
        $this->sync();
        $this->syncGitDiff();
        $this->frameNow = hrtime(true) / 1e9;
        $rt = $c->runtime;
        $e = $c->column()->focusable();
        $e->ownRing = true; // FocusRing(false): the editor's carets show it has the keyboard
        // Focused is false while the window has not the keyboard.
        $focused = $c->focused($e);
        if ($focused !== $this->focused) {
            $this->focused = $focused;
            $this->blinkStart = $this->frameNow;
            if (!$focused) {
                $this->complete->close();
                $this->gesture->active = false;
            }
        }
        if ($this->refocus) {
            $this->refocus = false;
            $rt?->focusOn($e->id);
        }
        if (!$o->readOnly) {
            $e->handleTextInput($this)->inputMethod(!$o->noInputMethod);
            if ($o->noInputMethod && $this->markedActive) {
                // Input methods turned off leave what they composed.
                $this->unmarkText();
            }
        }
        $e->role(Role::TEXT_FIELD)->label($o->label !== '' ? $o->label : $this->texts->get(match ($o->diffSide) { DiffSide::DiffNew => 'newText', DiffSide::DiffOld => 'oldText', default => 'codeEditor' }))
            ->access($this->accessibility(...));
        $e->handleInput(fn(InputEvent $ev) => $this->input($ev));
        $e->cursor($this->region === self::REGION_TEXT ? \MyPHP\UI\Cursor::Text : \MyPHP\UI\Cursor::Default);
        $e->draw(function (Painter $p, array $r) { $this->paint($p->displayList(), $p->textPainter(), $r); });
        if ($c->rightClicked($e) && ($w = Window::focused()) !== null) { (new Menu($this->menu()))->popup($w); }
        $last = $rt?->rectOf($e->id);
        if ($last !== null) { $this->r = $last; }
        if ($o->fitContent) {
            // As high as the lines, at the width of the last frame.
            $w = $this->r[2] > 0 ? $this->r[2] : 600.0;
            $this->layout($w, $this->viewH, max(1.0, $this->view->scale));
            $this->view->measureWraps(0, 1.0);
            $e->fh = $this->view->heights->total();
            $this->viewH = $e->fh; // the height its painting gets, known before it paints: one out of view, which paints nothing, gives its caret's bounds to a reveal on focus
            $this->scrollY = 0.0;
        } elseif ($this->r[2] > 0) {
            // Scroll to what moved before building what depends on it.
            $this->layout($this->r[2], $this->r[3], max(1.0, $this->view->scale));
            if ($this->o->sharedScroll === null) {
                $this->restoreScroll();
                $this->doReveal();
            }
        }
        // Hovering: ask what the text under the resting pointer is. The editor hears no move once the pointer has left it, so its hover tells: what the pointer passed over on its way out asks nothing, and a hover it asked for goes, unless the pointer went into it.
        $h = $this->hover;
        $outside = !$c->hovered($e) && !$this->gesture->active && !$this->scrollbar['active'];
        if ($outside && $this->pointerIn) {
            $this->pointerIn = false;
            $this->hoverLine = -1;
            $h->pending = false; $h->restOff = -1;
        }
        if ($h->pending && $this->pointerIn && !$this->gesture->active) {
            $wait = 0.6 - ($this->frameNow - $h->restAt);
            if ($wait > 0) {
                $rt?->after($wait);
            } else {
                $h->pending = false;
                $this->requestHover($h->restOff);
                $h->byPointer = true;
            }
        }
        // Popups attach to the caret, or to the text hovered: where it is in the window, [x, y, w, h].
        $anchor = function (int $at): ?array {
            $cr = $this->caretRect($at);
            if ($cr === null) { return null; }
            return [$this->r[0] + $this->textX + $cr[0] - $this->scrollX, $this->r[1] + $cr[1] - $this->scrollY, 1.0, $cr[3]];
        };
        $caretAnchor = $this->complete->open() ? $anchor($this->complete->at) : null;
        $hoverAnchor = $h->shown ? $anchor($h->start) : null;
        if (($ds = $this->o->decor) instanceof DiffSideView) { $ds->buildBlocks($c, $this); }
        if ($this->find->open) { $this->buildFindBar($c); }
        if ($this->goTo->open) { $this->buildGoToLine($c); }
        if ($caretAnchor !== null) { $this->buildCompletions($c, $caretAnchor); }
        if ($hoverAnchor !== null) { $this->buildHover($c, $hoverAnchor); }
        $c->end();
        // Once the hover has said whether the pointer or the keyboard is in it: the hover goes a grace after the pointer left the text hovered, or the editor, unless it went into the hover.
        if ($h->shown) {
            if ($h->over || $h->focused) {
                $h->hideAt = 0.0;
            } elseif ($outside && $h->byPointer && $h->hideAt == 0) {
                $h->hideAt = $this->frameNow + Hover::HIDE_DELAY;
            }
            if ($h->hideAt != 0) {
                $wait = $h->hideAt - hrtime(true) / 1e9;
                if ($wait > 0) {
                    $rt?->after($wait);
                } else {
                    $this->closeHover();
                    $rt?->animate();
                }
            }
        }
        $this->needsBuild = false;
        return $e;
    }

    /** The editor's context menu: the editing commands, folding, and the app's items. @return list<MenuItem> */
    public function menu(): array
    {
        $tx = $this->texts;
        $has = false;
        foreach ($this->sels as $s) { if (!$s->empty()) { $has = true; } }
        $ro = $this->o->readOnly;
        $item = fn(string $label, int $cmd, string $accel = '', bool $disabled = false) => new MenuItem($label, function (MenuItem $it, ?Window $w) use ($cmd) {
            $this->run($cmd);
            $w?->update();
        }, $accel, disabled: $disabled);
        $items = [];
        if ($this->o->onDefinition !== null) {
            $items[] = $item($tx->get('goToDefinition'), Command::GO_TO_DEFINITION, 'F12');
            $items[] = MenuItem::separator();
        }
        $items[] = $item($tx->get('cut'), Command::CUT, 'Cmd+X', $ro);
        $items[] = $item($tx->get('copy'), Command::COPY, 'Cmd+C');
        $items[] = $item($tx->get('paste'), Command::PASTE, 'Cmd+V', $ro);
        $items[] = MenuItem::separator();
        $items[] = $item($tx->get('selectAll'), Command::SELECT_ALL, 'Cmd+A');
        if (!$ro) { $items[] = $item($tx->get('toggleComment'), Command::TOGGLE_COMMENT, 'Cmd+/'); }
        $items[] = new MenuItem($tx->get('selection'), submenu: [
            $item($tx->get('selectNextOccurrence'), Command::SELECT_NEXT, 'Cmd+D'),
            $item($tx->get('selectAllOccurrences'), Command::SELECT_ALL_MATCHES, 'Cmd+Shift+L'),
            $item($tx->get('expandSelection'), Command::SELECT_LARGER),
            $item($tx->get('splitIntoLines'), Command::SPLIT_SELECTION_INTO_LINES, '', !$has),
        ]);
        if (!$ro) {
            $sub = [];
            foreach ([['upperCase', Command::UPPERCASE], ['lowerCase', Command::LOWERCASE], ['titleCase', Command::TITLE_CASE],
                ['snakeCase', Command::SNAKE_CASE], ['kebabCase', Command::KEBAB_CASE], ['camelCase', Command::CAMEL_CASE], ['pascalCase', Command::PASCAL_CASE],
                ['', Command::NO_COMMAND], ['sortLines', Command::SORT_LINES], ['reverseLines', Command::REVERSE_LINES], ['uniqueLines', Command::UNIQUE_LINES],
                ['joinLines', Command::JOIN_LINES], ['trimTrailingWhitespace', Command::TRIM_TRAILING_WHITESPACE]] as [$key, $cmd]) {
                $sub[] = $cmd === Command::NO_COMMAND ? MenuItem::separator() : $item($tx->get($key), $cmd);
            }
            $items[] = new MenuItem($tx->get('transform'), submenu: $sub);
        }
        if ($this->o->folding) {
            $items[] = new MenuItem($tx->get('folding'), submenu: [
                $item($tx->get('fold'), Command::FOLD), $item($tx->get('unfold'), Command::UNFOLD),
                $item($tx->get('foldAll'), Command::FOLD_ALL), $item($tx->get('unfoldAll'), Command::UNFOLD_ALL),
            ]);
        }
        if ($this->o->contextMenu !== null) {
            $items[] = MenuItem::separator();
            array_push($items, ...($this->o->contextMenu)());
        }
        return $items;
    }

    /** Places the find bar over the editor's top right corner, and builds it. */
    private function buildFindBar(Context $c): void
    {
        $f = $this->find;
        $f->update($this);
        $t = $c->theme;
        if ($f->queryField->text !== $f->query) { $f->queryField->text = $f->query; $f->queryField->setCursor(strlen($f->query)); }
        if ($f->replaceField->text !== $f->replace) { $f->replaceField->text = $f->replace; $f->replaceField->setCursor(strlen($f->replace)); }
        // Over the top right corner: as wide as it was last frame.
        $bar = $c->key('code find')->row()->cursor(\MyPHP\UI\Cursor::Default)->handleInput(self::shield(...))->role(Role::TOOLBAR)->label($c->texts()->get('find'));
        $last = $c->runtime?->rectOf($bar->id);
        $bar->at(max(0.0, $this->r[2] - $t->space(4) - ($last[2] ?? 0.0)), $t->space(1));
        ($this->o->renderFindBar ?? FindBar::default(...))($c, $bar, new FindBar($c, $this));
        $c->end();
        // What the bar typed or toggled shows at once.
        $f->query = $f->queryField->text;
        $f->replace = $f->replaceField->text;
        $f->update($this);
        if ($c->shortcut('g', \MyPHP\UI\Key::COMMAND)) {
            $f->step($this, 1);
        } elseif ($c->shortcut('g', \MyPHP\UI\Key::SHIFT | \MyPHP\UI\Key::COMMAND)) {
            $f->step($this, -1);
        }
    }

    /** Places the go to line bar in the middle of the editor's top, builds it, and shows the line typed. */
    private function buildGoToLine(Context $c): void
    {
        $t = $c->theme;
        $g = $this->goTo;
        $bar = $c->key('code go to line')->row()->cursor(\MyPHP\UI\Cursor::Default)->handleInput(self::shield(...))->role(Role::DIALOG)->label($c->texts()->get('goToLine'));
        $last = $c->runtime?->rectOf($bar->id);
        $bar->at(max(0.0, ($this->r[2] - ($last[2] ?? 0.0)) / 2), $t->space(1));
        ($this->o->renderGoToLine ?? GoToLineBar::default(...))($c, $bar, new GoToLineBar($c, $this));
        $c->end();
        if (!$g->open) { return; }
        $target = $g->target($this);
        if ($target !== null && $target[0] !== $g->preview) {
            $g->preview = $target[0];
            if (($cr = $this->caretRect(Buffer::offsetOf($this->view->text, new Pos($target[0], $target[1])))) !== null) {
                $this->setScroll($cr[1] - ($this->viewH - $this->view->lineHeight) / 2);
            }
        }
    }

    /** Places the list of completions below the word being completed, $anchor, above the window's content, and builds it. */
    private function buildCompletions(Context $c, array $anchor): void
    {
        $cc = $this->complete;
        $c->overlay(function () use ($c, $cc, $anchor) {
            $panel = $c->key('code completions')->column()->at($anchor[0], $anchor[1] + $anchor[3])->cursor(\MyPHP\UI\Cursor::Default)->keepFocus()->handleInput(self::shield(...))
                ->role(Role::LIST)->label($c->texts()->get('completions'));
            $m = new CompletionMenu($c, $this);
            ($this->o->renderCompletions ?? CompletionMenu::default(...))($c, $panel, $m);
            $c->end();
            if ($m->chosen >= 0) {
                $cc->selected = $m->chosen;
                $this->acceptCompletion();
                $c->runtime?->animate();
            } elseif ($m->closing) {
                $cc->close();
                $c->runtime?->animate();
            }
        });
        $cc->scrollTo = false;
    }

    /** Places the hover above the text hovered, $anchor, above the window's content, and builds it. */
    private function buildHover(Context $c, array $anchor): void
    {
        $h = $this->hover;
        if (!$h->shown || $h->build === null && $h->text === '' && ($h->diags ?? []) === []) { return; }
        $c->overlay(function () use ($c, $h, $anchor) {
            // The hover takes the keyboard as it is pressed, for its text to be selected and copied; Escape gives it back to the editor.
            $panel = $c->key('code hover')->column()->handleInput(self::shield(...))->role(Role::TOOLTIP);
            $rt = $c->runtime;
            $last = $rt?->rectOf($panel->id);
            $panel->at($anchor[0], $anchor[1] - ($last[3] ?? 0.0) - $c->theme->space(1));
            $h->over = $c->hovered($panel);
            $h->focused = $rt !== null && $rt->focus !== null && $last !== null && $rt->focus !== $panel->id && self::inside($rt->rectOf($rt->focus), $last);
            if ($h->focused && $c->shortcut(\MyPHP\UI\Key::ESCAPE)) { $this->closeHover(); }
            ($this->o->renderHover ?? HoverCard::default(...))($c, $panel, new HoverCard($c, $this));
            $c->end();
        });
    }

    /** What assistive technology sees of the editor beyond its role and name (mygo's accessibility): its text, of many lines, and the newest selection. */
    private function accessibility(AccessNode $n): void
    {
        $n->states |= AccessNode::MULTILINE;
        if ($this->o->readOnly) { $n->states |= AccessNode::READ_ONLY; }
        $r = $this->view->text;
        if ($r->len() > 4 << 20) {
            // Assistive technology gets the lines in view of a very large text.
            $n->value = $this->slice($r->lineStart($this->anchorLine), $r->lineEnd(min($r->lineCount() - 1, $this->anchorLine + 200)));
            return;
        }
        $n->value = (string)$r;
        $p = $this->primary();
        $n->selStart = mb_strlen(substr($n->value, 0, min($p->start(), strlen($n->value))), 'UTF-8');
        $n->selEnd = $n->selStart + mb_strlen(substr($n->value, min($p->start(), strlen($n->value)), max(0, min($p->end(), strlen($n->value)) - min($p->start(), strlen($n->value)))), 'UTF-8');
        if ($n->placeholder === '') { $n->placeholder = $this->o->placeholder; }
    }

    /** Whether rect $a lies within rect $b. */
    private static function inside(?array $a, array $b): bool
    {
        return $a !== null && $a[0] >= $b[0] && $a[1] >= $b[1] && $a[0] + $a[2] <= $b[0] + $b[2] && $a[1] + $a[3] <= $b[1] + $b[3];
    }

    /**
     * Takes no input, but keeps the presses on what an editor shows over
     * its text, as its find bar, from the editor around it: the input goes
     * to the innermost node taking it as it comes, which then leaves it to
     * the buttons and fields inside, as they take it.
     */
    public static function shield(InputEvent $ev): bool { return false; }
}
