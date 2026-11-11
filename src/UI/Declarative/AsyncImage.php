<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

use Closure;
use MyPHP\{App, Http};
use MyPHP\UI\{Context, Image, Node};

/**
 * AsyncImage, as SwiftUI's: loads a picture from $url over HTTP(S) in a
 * fiber, caches it in memory across frames and views, and shows a spinner
 * (or $placeholder) while it loads, then the ImageView (or $content($img)).
 */
final class AsyncImage extends View
{
    private const MAX_CACHE = 64;
    /** @var array<string, Image> */
    private static array $cache = [];
    /** @var array<string, true> */
    private static array $loading = [];
    /** @var array<string, string> */
    private static array $failed = [];

    private bool $resizable = false, $fit = false, $fill = false;

    public function __construct(
        public readonly ?string $url,
        public readonly float $scale = 1.0,
        public readonly ?Closure $content = null,
        public readonly ?Closure $placeholder = null,
        public readonly int $maxSize = 1024,
    ) {}

    public function resizable(): static { $this->resizable = true; return $this; }
    public function scaledToFit(): static { $this->fit = true; $this->fill = false; return $this; }
    public function scaledToFill(): static { $this->fill = true; $this->fit = false; return $this; }
    public function aspectRatio(\ContentMode $contentMode = \ContentMode::fit): static
    {
        return $contentMode === \ContentMode::fill ? $this->scaledToFill() : $this->scaledToFit();
    }

    /** Clears the in-memory image cache. */
    public static function clearCache(): void
    {
        self::$cache = [];
        self::$failed = [];
    }

    protected function build(Context $c, Env $env): ?Node
    {
        $url = trim((string)$this->url);
        if ($url !== '' && isset(self::$cache[$url])) {
            $img = self::$cache[$url];
            $view = $this->content !== null ? ($this->content)(new ImageView($img)) : new ImageView($img);
            if ($view instanceof ImageView) {
                if ($this->resizable) { $view->resizable(); }
                if ($this->fit) { $view->scaledToFit(); }
                if ($this->fill) { $view->scaledToFill(); }
            }
            return $view instanceof View ? $view->render($c, $env) : null;
        }
        if ($url !== '' && !isset(self::$loading[$url]) && !isset(self::$failed[$url])) {
            self::$loading[$url] = true;
            $rt = $c->runtime;
            $max = $this->maxSize;
            App::go(static function () use ($url, $max, $rt): void {
                try {
                    $r = Http::get($url);
                    if ($r->ok() && $r->body !== '') {
                        if (count(self::$cache) >= self::MAX_CACHE) {
                            array_shift(self::$cache);
                        }
                        self::$cache[$url] = Image::fromBytes($r->body, $max);
                    } else {
                        self::$failed[$url] = "HTTP {$r->status}";
                    }
                } catch (\Throwable $e) {
                    self::$failed[$url] = $e->getMessage();
                } finally {
                    unset(self::$loading[$url]);
                    $rt?->requestFrame?->__invoke();
                }
            });
        }
        if ($this->placeholder !== null) {
            $p = ($this->placeholder)();
            return $p instanceof View ? $p->render($c, $env) : null;
        }
        $box = $c->column(0, 0)->center();
          if ($url !== '' && !isset(self::$failed[$url])) {
              $c->spinner();
          }
        $c->end();
        if ($this->resizable) {
            View::takeRoom($box, $env->axis, View::FLEX_X | View::FLEX_Y);
        }
        return $box;
    }
}