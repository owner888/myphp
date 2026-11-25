<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

use Closure;
use MyPHP\{App, I18n, L10n, NumberOptions, PathName};
use MyPHP\Plugins\Updater\{Checker, Updater as UpdaterPlugin};
use MyPHP\UI\{Context, Cursor, Key, Node, Role, Theme};
use Throwable;

/**
 * Cross-platform in-app update control:
 * - Touch screens (iOS, Android, iPadOS, Android Pad): Telegram-style 40pt accent capsule floating above the tab bar.
 * - Desktops (macOS, Windows, Linux): Arc-style 28pt sidebar pill pinned at the sidebar's bottom that smoothly morphs
 *   upward in place on hover (or keyboard activation) into a card with "Automatic Updates" and "Restart and Update".
 */
final class UpdateCapsule extends View
{
    /** Sentinel for an unbound $automaticUpdates parameter. @internal */
    public const UNBOUND = "\0unbound";

    private bool $autoVal = false;
    private bool $autoNull = false;
    private ?Binding $autoBinding = null;
    private static ?bool $storedAuto = null;

    public function __construct(
        private readonly string $version,
        private readonly string $status = 'available',
        private readonly float $progress = 0.0,
        private readonly ?Closure $onAction = null,
        private readonly mixed $tint = null,
        private readonly ?string $actionTitle = null,
        private readonly int $size = 0,
        private readonly ?string $store = null,
        mixed &$automaticUpdates = self::UNBOUND,
        private readonly bool $autoBound = false,
    ) {
        if (!$this->autoBound) {
            return;
        }
        if ($automaticUpdates === null) {
            $this->autoNull = true;
            return;
        }
        if ($automaticUpdates instanceof \MyPHP\UI\Reactive\State) {
            $this->autoBinding = $automaticUpdates->binding();
            $this->autoVal = (bool) $automaticUpdates->value;
            return;
        }
        if ($automaticUpdates instanceof Binding) {
            $this->autoBinding = $automaticUpdates;
            $this->autoVal = (bool) $automaticUpdates->get();
            return;
        }
        $automaticUpdates = (bool) $automaticUpdates;
        $this->autoVal = &$automaticUpdates;
    }

    protected function build(Context $c, Env $env): ?Node
    {
        if ($c->runtime?->touch ?? false) {
            return $this->buildTouch($c, $env);
        }
        return $this->buildDesktop($c, $env);
    }

    private function buildTouch(Context $c, Env $env): ?Node
    {
        $locale = $env->locale ?? I18n::locale();
        $words = L10n::in($locale);
        $isDownloading = $this->status === 'downloading';
        $isFailed = $this->status === 'failed';
        $waits = $this->status === 'available';
        $color = $isFailed ? \Color::rgb(0xED2020) : ($this->tint ?? \Color::accentColor);
        $title = match ($this->status) {
            'downloading' => $words->get('updateDownloading', ['percent' => (int) round(max(0.0, min(1.0, $this->progress)) * 100)]),
            'installing' => $words->get('updateNow'),
            'failed' => $words->get('updateFailed'),
            default => $this->actionTitle ?? match ($this->store) {
                'appstore' => $words->get('updateOnAppStore'),
                'play' => $words->get('updateOnGooglePlay'),
                default => $words->get('updateApp', ['app' => App::name()]),
            },
        };
        $end = $this->size > 0
            ? I18n::formatNumber($locale, $this->size / 1048576 >= 1 ? round($this->size / 1048576, 1) : round($this->size / 1024, 1), new NumberOptions(maxFractionDigits: 1)) . ($this->size >= 1048576 ? ' MB' : ' KB')
            : ltrim($this->version, 'vV');

        $bar = \HStack(alignment: \VerticalAlignment::center, spacing: 8)(
            \Image(systemName: $waits || $isDownloading ? 'arrow.down.to.line' : 'arrow.clockwise')
                ->font(\Font::caption)
                ->bold()
                ->foregroundStyle($color)
                ->frame(width: 22, height: 22, alignment: \Alignment::center)
                ->background(\Color::white)
                ->cornerRadius(11),
            \Text(verbatim: $title)
                ->font(\Font::subheadline)
                ->bold()
                ->foregroundStyle(\Color::white)
                ->lineLimit(1),
            $waits ? \Text(verbatim: $end)
                ->font(\Font::footnote)
                ->bold()
                ->foregroundStyle(\Color::rgb(0xFFFFFF, 0.8))
                ->lineLimit(1) : null,
        )
            ->padding(\Edge::horizontal, 14)
            ->frame(height: 40, alignment: \Alignment::center)
            ->background($color)
            ->cornerRadius(20);
        if (!$isDownloading && $this->onAction !== null) {
            $bar->onTapGesture($this->onAction);
        }
        return $bar->render($c, $env);
    }

    private function buildDesktop(Context $c, Env $env): Node
    {
        $t = $env->theme;
        $rt = $c->runtime;
        $locale = $env->locale ?? I18n::locale();
        $words = L10n::in($locale);
        $isDownloading = $this->status === 'downloading';
        $isFailed = $this->status === 'failed';
        $pct = max(0.0, min(1.0, $this->progress));
        $pctInt = (int) round($pct * 100);

        $headerTitle = match ($this->status) {
            'downloading' => $words->get('updateDownloading', ['percent' => $pctInt]),
            'failed' => $words->get('updateFailed'),
            default => $words->get('updateAvailable'),
        };
        $ctaTitle = $this->actionTitle ?? match ($this->status) {
            'downloading' => $words->get('updateDownloading', ['percent' => $pctInt]),
            'failed' => $words->get('updateFailed'),
            default => match ($this->store) {
                'appstore' => $words->get('updateOnAppStore'),
                'play' => $words->get('updateOnGooglePlay'),
                default => $words->get('updateRestart'),
            },
        };

        $showAuto = $this->store === null && !($this->autoBound && $this->autoNull);
        $collapsedH = 28.0;
        $expandedH = $showAuto ? 96.0 : 73.0;

        $slot = $c->open(new Node(Node::CONTAINER, Node::COLUMN))
            ->alignSelf(Node::STRETCH)
            ->size($env->axis === Node::ROW ? 216.0 : 0.0, $collapsedH)
            ->minWidth(180.0)
            ->shrink(0);

        $kept = $rt?->local($slot->id, static fn() => new class {
            public bool $hovered = false, $pinned = false;
            public int $cardId = 0;
        }, null, 'cardId');

        if ($kept !== null && $rt !== null) {
            $overCard = ($kept->cardId !== 0 && $rt->hovered($kept->cardId)) || $rt->hovered($slot->id);
            if ($overCard) {
                $kept->hovered = true;
            } elseif ($kept->hovered) {
                [$sx, $sy, $sw, $sh] = $rt->rectOf($slot->id) ?? [0.0, 0.0, 0.0, 0.0];
                $mx = $rt->input->mouseX;
                $my = $rt->input->mouseY;
                $inTarget = $rt->input->mouseInside && $sw > 0
                    && $mx >= $sx - 2.0 && $mx <= $sx + $sw + 2.0
                    && $my >= $sy + $sh - $expandedH - 2.0 && $my <= $sy + $sh + 2.0
                    && ($kept->cardId === 0 || $rt->reachable($kept->cardId));
                if (!$inTarget) {
                    $kept->hovered = false;
                }
            }
            if ($kept->pinned && $c->shortcut(Key::ESCAPE)) {
                $kept->pinned = false;
            }
        }

        $isOpen = ($kept?->hovered ?? false) || ($kept?->pinned ?? false);
        $open = $c->animate($slot, 'expand', $isOpen ? 1.0 : 0.0, 0.18);
        $h = $collapsedH + ($expandedH - $collapsedH) * $open;
        $radius = 14.0 - 3.0 * $open;

        $accentRgb = $isFailed ? 0xED2020 : $this->resolveTint($env, $t);
        if ($t->dark) {
            $pillBg = $isFailed ? Theme::mix($t->background, 0xED2020, 0.22) : Theme::mix($t->background, 0xFFFFFF, 0.10);
            $cardBg = Theme::mix($t->background, 0x000000, 0.50);
            $bg = Theme::mix($pillBg, $cardBg, $open);
            $bgAlpha = 0.72 + 0.25 * $open;
            $borderRgb = $isFailed && $open < 0.5 ? 0xED2020 : 0xFFFFFF;
            $borderAlpha = $isFailed && $open < 0.5 ? 0.38 : (0.15 + 0.05 * $open);
        } else {
            $pillBg = $isFailed ? Theme::mix($t->surface, 0xED2020, 0.12) : Theme::mix($t->surface, 0x000000, 0.05);
            $cardBg = $t->background;
            $bg = Theme::mix($pillBg, $cardBg, $open);
            $bgAlpha = 0.80 + 0.19 * $open;
            $borderRgb = $isFailed && $open < 0.5 ? 0xED2020 : 0x000000;
            $borderAlpha = $isFailed && $open < 0.5 ? 0.28 : (0.11 + 0.03 * $open);
        }

        $c->key('card');
        $card = $c->open(new Node(Node::CONTAINER, Node::COLUMN))
            ->absolute()->bottom(0)->start(0)->end(0)
            ->size(0, $h)
            ->radius($radius)
            ->background($bg, $bgAlpha)
            ->border(1.0, $borderRgb, $borderAlpha)
            ->clip();
        $card->floats = true;
        if ($open > 0.02) {
            $card->shadow(0, 6.0 * $open, 18.0 * $open, 0, 0x000000, ($t->dark ? 0.45 : 0.14) * $open);
        }
        if ($kept !== null) {
            $kept->cardId = $card->id;
            if ($kept->pinned && $c->pressedOutside($card)) {
                $kept->pinned = false;
            }
        }
        if (!$isOpen && $open <= 0.01) {
            $card->focusable()->role(Role::BUTTON)->label($headerTitle)->cursor(Cursor::Pointer);
            if ($kept !== null && $c->clicked($card)) {
                $kept->pinned = true;
                $rt?->changed();
            }
        } else {
            $card->role(Role::GROUP)->label($headerTitle);
        }

          // Collapsed downloading progress fill behind the pill text
          if ($isDownloading && $pct > 0 && $open < 0.25) {
              $prog = $c->box(0, 0, $accentRgb)->absolute()->top(0)->bottom(0)->start(0)->widthPercent($pct * 100)->role(Role::NONE);
              $prog->bgAlpha = 0.20 * (1.0 - $open * 4.0);
          }

          // Top header band (26pt inner + 2pt border = 28pt collapsed pill)
          $c->key('header');
          $c->open(new Node(Node::CONTAINER, Node::ROW))
              ->size(0, 26.0)->shrink(0)->alignSelf(Node::STRETCH)->padding(0, 10)
              ->justify(Node::CENTER)->alignItems(Node::CENTER);
            $c->text($headerTitle, 12, $isFailed && $open < 0.3 ? ($t->dark ? 0xFF7B7B : 0xC91C1C) : $t->text)
                ->fontWeight(600)->singleLine();
          $c->end();

          if ($isOpen || $open > 0.01) {
              $reveal = max(0.0, min(1.0, ($open - 0.12) / 0.88));
              $div = $c->box(0, 1, $t->dark ? 0xFFFFFF : 0x000000)->shrink(0)->fillWidth()->role(Role::NONE);
              $div->bgAlpha = ($t->dark ? 0.10 : 0.08) * $reveal;

              $c->key('body');
              $body = $c->open(new Node(Node::CONTAINER, Node::COLUMN))
                  ->alignSelf(Node::STRETCH)->shrink(0)
                  ->padding($showAuto ? 0 : 8, 8, 8, 8)
                  ->opacity($reveal);
              if (!$isOpen) {
                  $body->inert = true;
              }

                if ($showAuto) {
                    $autoChecked = $this->readAuto();
                    $wasAuto = $autoChecked;
                    $c->key('auto');
                    $c->open(new Node(Node::CONTAINER, Node::ROW))
                        ->size(0, 31.0)->shrink(0)->alignSelf(Node::STRETCH)
                        ->justify(Node::CENTER)->alignItems(Node::CENTER);
                      $cb = $c->checkbox($words->get('updateAutomatic'), $autoChecked, 12.0);
                      $cb->fg = $t->textMuted;
                      $cb->cursor(Cursor::Pointer);
                    $c->end();
                    if ($autoChecked !== $wasAuto) {
                        $this->writeAuto($autoChecked);
                    }
                }

                $c->key('cta');
                $cta = $c->open(new Node(Node::CONTAINER, Node::ROW))
                    ->size(0, 28.0)->shrink(0)->alignSelf(Node::STRETCH)->padding(0, 10)
                    ->radius(7.0)->justify(Node::CENTER)->alignItems(Node::CENTER)
                    ->clip();
                $canAct = !$isDownloading && $this->onAction !== null;
                if ($isDownloading) {
                    $cta->background($accentRgb, 0.24)->role(Role::PROGRESS)->label($ctaTitle);
                    if ($pct > 0) {
                        $barFill = $c->box(0, 0, $accentRgb)->absolute()->top(0)->bottom(0)->start(0)->widthPercent($pct * 100)->role(Role::NONE);
                        $barFill->bgAlpha = 0.65;
                    }
                } else {
                    $ctaHovered = $canAct && $c->hovered($cta);
                    $ctaPressed = $ctaHovered && ($rt?->input->mouseDown ?? false);
                    $btnBg = $ctaPressed
                        ? Theme::mix($accentRgb, 0x000000, 0.14)
                        : ($ctaHovered ? Theme::mix($accentRgb, 0xFFFFFF, 0.12) : $accentRgb);
                    $cta->background($btnBg, $canAct ? 1.0 : 0.55)
                        ->border(1.0, 0xFFFFFF, 0.16)
                        ->role(Role::BUTTON)->label($ctaTitle);
                    if ($canAct) {
                        $cta->focusable()->cursor(Cursor::Pointer);
                    }
                }
                  $c->text($ctaTitle, 12, 0xFFFFFF)->fontWeight(600)->singleLine();
                $c->end();
                if ($canAct && $isOpen && $c->clicked($cta)) {
                    ($this->onAction)();
                }

              $c->end();
          }

        $c->end();
        $c->end();
        return $slot;
    }

    private function resolveTint(Env $env, Theme $t): int
    {
        if (is_int($this->tint)) {
            return $this->tint;
        }
        if ($this->tint instanceof \Color || $this->tint instanceof ColorValue) {
            return ColorValue::of($this->tint)->rgb($env);
        }
        return $env->tint?->rgb($env) ?? $t->accent;
    }

    private function readAuto(): bool
    {
        if ($this->autoBound) {
            return $this->autoBinding !== null ? (bool) $this->autoBinding->get() : $this->autoVal;
        }
        if (UpdaterPlugin::isActive()) {
            return UpdaterPlugin::automaticDownloads();
        }
        if (self::$storedAuto !== null) {
            return self::$storedAuto;
        }
        try {
            $raw = @file_get_contents(App::path(PathName::UserData) . '/' . Checker::STATE_FILE);
            $json = $raw === false ? null : json_decode($raw, true);
            return self::$storedAuto = is_array($json) && ($json['automaticDownloads'] ?? false) === true;
        } catch (Throwable) {
            return self::$storedAuto = false;
        }
    }

    private function writeAuto(bool $on): void
    {
        if ($this->autoBound) {
            $this->autoVal = $on;
            $this->autoBinding?->set($on);
            return;
        }
        if (UpdaterPlugin::isActive()) {
            UpdaterPlugin::setAutomaticDownloads($on);
            return;
        }
        self::$storedAuto = $on;
        try {
            $file = App::path(PathName::UserData) . '/' . Checker::STATE_FILE;
            $raw = @file_get_contents($file);
            $json = is_string($raw) ? json_decode($raw, true) : null;
            $data = is_array($json) ? $json : [];
            if ($on) {
                $data['automaticDownloads'] = true;
            } else {
                unset($data['automaticDownloads']);
            }
            @mkdir(dirname($file), 0755, true);
            if (@file_put_contents("$file.tmp", json_encode((object) $data)) !== false) {
                @rename("$file.tmp", $file);
            }
        } catch (Throwable) {
        }
    }
}
