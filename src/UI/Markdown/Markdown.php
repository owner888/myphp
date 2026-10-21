<?php
declare(strict_types=1);

namespace MyPHP\UI\Markdown;

use MyPHP\UI\{Context, Image, Node, Role, SVG};

/**
 * Markdown views (mygo's ui/markdown): view() shows finished Markdown,
 * stream() Markdown still being written, as a language model's reply.
 */
final class Markdown
{
    /**
     * Shows $src, finished Markdown, as it reads: CommonMark and what
     * Options::$extensions add, parsed again only where $src changed since
     * the last frame. Its text is selectable, copied as text, as HTML, which
     * keeps its formatting where it is pasted, and as Markdown, or, with the
     * context menu's Copy as Markdown (Shift+Cmd+C), as Markdown's text.
     * $o may be null (mygo's View).
     *
     *     Markdown::view($c, $app->readme, $app->md);
     */
    public static function view(Context $c, string $src, ?Options $o = null): Node
    {
        return ViewState::build($c, $src, $o, false);
    }

    /**
     * Shows $src, Markdown still being written, as a language model's reply:
     * text appended since the last frame is parsed alone, the end shows what
     * its half-written syntax will most likely become, and text comes in as
     * Options::$reveal says, smoothly, fading in. Show the message with
     * view() once it is complete, in the same place, so that its parse
     * carries on (mygo's Stream).
     *
     *     $msg->done ? Markdown::view($c, $msg->text, $app->md) : Markdown::stream($c, $msg->text, $app->md);
     */
    public static function stream(Context $c, string $src, ?Options $o = null): Node
    {
        return ViewState::build($c, $src, $o, true);
    }

    /** Builds a code block as views do without hooks: a box with the language and a copy button above the highlighted code (mygo's DefaultCodeBlock). */
    public static function defaultCodeBlock(Context $c, CodeBlockView $b): Node
    {
        $th = $b->state()->th;
        $box = $c->column()->background($th->codeBlockBackground[0], $th->codeBlockBackground[1])->borderWidth(1)->borderColor($th->codeBlockBorder[0])
            ->round($th->codeBlockRadius)->alignSelf(Node::STRETCH)->clip();
        $box->role(Role::GROUP)->label($b->language === '' ? 'Code' : 'Code, ' . $b->language);
        $c->row()->align(Node::CENTER)->padding($th->em(0.35), $th->em(0.4), 0, $th->em($th->codeBlockPadding));
          $c->text($b->language)->fontSize($th->em(0.8))->textColor($th->muted[0], $th->muted[1])->unselectable();
          $c->spacer();
          $b->copyButton($c);
        $c->end();
        $b->body($c);
        $c->end();
        return $box;
    }

    /** The anchor of a heading of $text, as GitHub makes it: in lower case, spaces as hyphens, without punctuation (mygo's Slug). */
    public static function slug(string $text): string
    {
        $out = '';
        foreach (preg_split('//u', mb_strtolower(trim($text), 'UTF-8'), -1, PREG_SPLIT_NO_EMPTY) as $r) {
            if ($r === ' ') {
                $out .= '-';
            } elseif ($r === '-' || $r === '_' || preg_match('/^[\p{L}\p{N}]$/u', $r) === 1) {
                $out .= $r;
            }
        }
        return $out;
    }

    /**
     * A loader of Options::$loadImage for the files of directory $dir, which
     * paths without a scheme are relative to: PNG, JPEG, GIF, WebP, BMP and
     * SVG; other addresses do not load (mygo's FileImages).
     * @return \Closure(string): (Image|SVG|null)
     */
    public static function fileImages(string $dir): \Closure
    {
        return static function (string $url) use ($dir): Image|SVG|null {
            if (str_contains($url, '://') || str_starts_with($url, 'data:')) {
                return null;
            }
            $path = str_starts_with($url, 'file:') ? substr($url, 5) : $url;
            if (!str_starts_with($path, '/')) {
                $path = rtrim($dir, '/') . '/' . $path;
            }
            if (!is_file($path)) {
                return null;
            }
            if (strcasecmp(pathinfo($path, PATHINFO_EXTENSION), 'svg') === 0) {
                return SVG::parse((string)file_get_contents($path));
            }
            return Image::fromFile($path);
        };
    }
}
