<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Updater\Native;

use Closure;
use MyPHP\App;
use MyPHP\Plugins\Updater\{Checker, Session, Text};
use MyPHP\UI\{Content, Context, Font, Image, Key, Node, ScrollState, Span, Theme};
use RuntimeException;

/**
 * The update window of a session drawn in native UI (mygo's
 * native/window.go): what the web page shows, built from the session's
 * view each frame.
 *
 * @internal
 */
final class UpdateWindow
{
    /** The room around the window's content and between its parts, in points, as in the web page. */
    public const PAD_TOP = 18, PAD_SIDE = 20, PAD_BOTTOM = 20, ICON_SIZE = 64, ICON_GAP = 18, FOOT_GAP = 12, ASIDE_GAP = 40;

    /** @var Closure(int, string, bool): void answers a prompt: the session's respond */
    public Closure $respond;
    /** @var Closure(int, int): void asks for the size of the content: the session's fitContent, after the frame */
    public Closure $fit;

    // What the view keeps from frame to frame: the prompt of the view it
    // shows, the prompt the user answered, and the checkbox, which each
    // prompt resets.
    private int $prompt = -1;
    private int $answered = -1;
    public bool $checked = false;
    /** @var list<mixed> what the last frame laid out, and the size last asked of the window for it */
    private array $built = [];
    /** @var array{0: int, 1: int} */
    private array $fitted = [0, 0];

    public function __construct(private readonly Session $s, private readonly Text $texts, public ?Image $icon)
    {
        $this->respond = fn(int $prompt, string $action, bool $automatic) => $s->respond($prompt, $action, $automatic);
        $this->fit = fn(int $w, int $h) => App::later(fn() => $s->fitContent($w, $h));
    }

    /** Creates the window of $s, which shows at once. */
    public static function open(Checker $u, Session $s): void
    {
        $w = self::for($u, $s);
        $win = $s->newWindow(Content::view(fn(Context $c) => $w->view($c)));
        Session::present($win, $s->user);
    }

    /** The window of $s, with the icon of $u when it is an image. */
    public static function for(Checker $u, Session $s): self
    {
        $png = $u->icon();
        $icon = null;
        if ($png !== null) {
            try {
                $icon = Image::fromBytes($png);
            } catch (RuntimeException) {
                // no icon when it is not an image, as mygo's
            }
        }
        return new self($s, $u->text(), $icon);
    }

    /** Builds the update window. */
    public function view(Context $c): void
    {
        $v = $this->s->view;
        if ($v['prompt'] !== $this->prompt) {
            $this->prompt = $v['prompt'];
            $this->checked = !empty($v['checked']);
        }
        $t = self::theme($c->theme);
        $c->theme = $t;
        $this->keys($c, $v);

        $c->column(0, self::FOOT_GAP, 1, $t->background)->padding(self::PAD_TOP, self::PAD_SIDE, self::PAD_BOTTOM);
          $top = $this->row($c, self::ICON_GAP)->align(!empty($v['release']) ? Node::STRETCH : Node::START);
          $top->grow = 1;
            if ($this->icon !== null) {
                $c->image($this->icon, self::ICON_SIZE, self::ICON_SIZE)->shrink(0)->alignSelf(Node::START);
            }
            $body = $c->column(0, 0, 1);
              $this->body($c, $t, $v);
            $c->end();
          $c->end();
          $this->row($c, 10)->shrink(0);
            $aside = $this->row($c, 10)->shrink(0);
              $this->buttons($c, $t, $v, true);
            $c->end();
            $c->spacer();
            $buttons = $this->row($c, 10)->shrink(0);
              $this->buttons($c, $t, $v, false);
            $c->end();
          $c->end();
        $c->end();
        $this->fitContent($c, $v, $body, $aside, $buttons);
    }

    /** A row, laid out from the right in a window of a language written from right to left. */
    private function row(Context $c, float $gap = 0): Node
    {
        $r = $c->row(0, $gap);
        if ($this->texts->rtl) {
            $r->reverse();
        }
        return $r;
    }

    /** A text that wraps at the width of the column. */
    private static function para(Context $c, string $s, ?float $size = null): Node
    {
        return $c->richText([new Span($s)], $size, wrap: true);
    }

    /**
     * Builds the texts, progress bar, release notes and checkbox of the
     * view. @param array<string, mixed> $v
     */
    private function body(Context $c, Theme $t, array $v): void
    {
        $small = $t->fontSize * 11 / 13;
        self::para($c->key('title'), $v['title'])->bold()->margin(0, 0, 4, 0);
        if (($v['message'] ?? '') !== '') {
            self::para($c->key('message'), $v['message'])->margin(0, 0, 6, 0);
        }
        if (!empty($v['bar'])) {
            $bar = $c->key('bar')->progress((float)$v['progress'], 0)->margin(6, 0, 4, 0);
            if ($this->texts->rtl) {
                $bar->reverse();
            }
        }
        if (($v['detail'] ?? '') !== '') {
            self::para($c->key('detail'), $v['detail'], $small)->textColor($t->textMuted)->maxLines(2)->tooltip($v['detail'])->selectable();
        }
        if (($v['notes'] ?? []) !== []) {
            $c->key('notes')->column(0, 0, 1)->margin(6, 0, 0, 0);
              $c->text($this->texts->s->releaseNotes, $small, style: Font::BOLD)->margin(0, 0, 4, 0);
              $panel = $c->column(0, 0, 1, Notes::panel($t))->border($t->border)->padding(8, 12);
              $panel->scroll($c->runtime?->local($panel->id, fn() => new ScrollState) ?? new ScrollState);
                Notes::build($c, $t, $v['notes']);
              $c->end();
            $c->end();
        }
        if (!empty($v['checkbox'])) {
            $c->key('checkbox');
            $this->row($c)->margin(10, 0, 0, 0);
              $box = $c->checkbox($this->texts->s->automaticDownloads, $this->checked);
              if ($this->texts->rtl) {
                  $box->reverse();
              }
            $c->end();
        }
    }

    /** Builds the buttons of the view set aside, or the others. @param array<string, mixed> $v */
    private function buttons(Context $c, Theme $t, array $v, bool $aside): void
    {
        $height = round($t->fontSize * 24 / 13);
        foreach ($v['buttons'] as $b) {
            if (!empty($b['aside']) !== $aside) {
                continue;
            }
            // Keyed by action, so that the focus leaves with the button.
            $c->key($b['action']);
            $e = !empty($b['default']) ? $c->primaryButton($b['label']) : $c->button($b['label']);
            $e->borderColor = $t->border;
            $e->fh = $height;
            $e->fw = max($e->fw, 84);
            $e->disabled = $this->off($v, $b);
            if ($c->clicked($e)) {
                $this->respond($v, $b);
            }
        }
    }

    /** Answers with the button of Escape, and that of Enter when no button has the focus. @param array<string, mixed> $v */
    private function keys(Context $c, array $v): void
    {
        foreach ($v['buttons'] as $b) {
            if (!empty($b['cancel']) && $c->shortcut(Key::ESCAPE) || !empty($b['default']) && ($c->shortcut(Key::RETURN) || $c->shortcut(Key::ENTER))) {
                $this->respond($v, $b);
            }
        }
    }

    /** Whether a button is disabled: by the view, or because the user answered its prompt, until the next view. @param array<string, mixed> $v @param array<string, mixed> $b */
    private function off(array $v, array $b): bool
    {
        return !empty($b['disabled']) || $this->answered === $v['prompt'];
    }

    /** @param array<string, mixed> $v @param array<string, mixed> $b */
    private function respond(array $v, array $b): void
    {
        if ($this->off($v, $b)) {
            return;
        }
        $this->answered = $v['prompt'];
        ($this->respond)($v['prompt'], $b['action'], $this->checked);
    }

    /**
     * Asks for the size the content needs, as measured in the last frame
     * when it laid out the same view at the same size: the width of the
     * buttons, and for status views the height of the text.
     * @param array<string, mixed> $v
     */
    private function fitContent(Context $c, array $v, Node $body, Node $aside, Node $buttons): void
    {
        $l = [$v['prompt'], $v['title'], $v['message'] ?? '', $v['detail'] ?? '', !empty($v['bar']), !empty($v['checkbox']), !empty($v['release']), $c->width, $c->height];
        if ($l !== $this->built) {
            // Measure the layout of this frame in the next.
            $this->built = $l;
            $c->runtime?->after(0);
            return;
        }
        [, , , $bh] = $c->bounds($body);
        [, , $aw, $ah] = $c->bounds($aside);
        [, , $fw, $fh] = $c->bounds($buttons);
        $width = self::PAD_SIDE + $aw + $fw + self::PAD_SIDE;
        if ($aw > 0) {
            $width += self::ASIDE_GAP;
        }
        $height = self::PAD_TOP + $bh + self::FOOT_GAP + max($ah, $fh) + self::PAD_BOTTOM;
        if ($this->icon !== null) {
            $height = max($height, self::PAD_TOP + self::ICON_SIZE + self::FOOT_GAP + max($ah, $fh) + self::PAD_BOTTOM);
        }
        $size = [(int)ceil($width), (int)ceil($height)];
        if ($size !== $this->fitted) {
            $this->fitted = $size;
            ($this->fit)(...$size);
        }
    }

    /** The look of the update window, that of the web page, over $base. */
    public static function theme(Theme $base): Theme
    {
        $t = clone $base;
        if ($t->dark) {
            $t->background = (int)hexdec(substr(Session::DARK, 1));
            $t->surface = 0x5b5b5f;
            $t->border = Theme::mix($t->background, 0xffffff, 0.15);
            [$t->text, $t->textMuted] = [0xf5f5f7, 0x98989d];
            $t->accent = 0x0a84ff;
        } else {
            $t->background = (int)hexdec(substr(Session::LIGHT, 1));
            $t->surface = 0xffffff;
            $t->border = Theme::mix($t->background, 0x000000, 0.15);
            [$t->text, $t->textMuted] = [0x1d1d1f, 0x6e6e73];
            $t->accent = 0x007aff;
        }
        [$t->surfaceHover, $t->surfacePressed] = [Theme::mix($t->surface, 0x000000, 0.05), Theme::mix($t->surface, 0x000000, 0.1)];
        [$t->accentHover, $t->accentPressed] = [Theme::mix($t->accent, 0x000000, 0.08), Theme::mix($t->accent, 0x000000, 0.16)];
        [$t->selection, $t->selectionAlpha, $t->focus, $t->focusAlpha] = [$t->accent, 0.25, $t->accent, 0.55];
        return $t;
    }
}
