<?php
declare(strict_types=1);

namespace MyPHP\UI;

/** Turns a laid-out tree into display-list quads: fills, labels, clips. */
final class Painter
{
    private const BUTTON_RADIUS = 6.0;
    private const BOX_RADIUS = 18.0;

    public static function paint(Node $n, DisplayList $list, Text $text): void
    {
        if ($list->clipped($n->x, $n->y, $n->w, $n->h)) {
            return;
        }
        if ($n->color !== null) {
            $radius = match ($n->kind) { Node::BUTTON => self::BUTTON_RADIUS, Node::BOX => self::BOX_RADIUS, default => 0.0 };
            $list->rect($n->x, $n->y, $n->w, $n->h, $n->color, 1.0, $radius);
        }
        if ($n->text !== null) {
            $x = $n->x; $y = $n->y;
            if ($n->kind === Node::BUTTON) { // the label sits centered in the button's padding
                [$tw, $th] = $text->measure($n->text, $n->fontSize);
                $x += ($n->w - $tw) / 2; $y += ($n->h - $th) / 2;
            }
            $text->paint($list, $n->text, $n->fontSize, $x, $y, $n->fg);
        }
        if ($n->scroll !== null) {
            $list->pushClip($n->x, $n->y, $n->w, $n->h);
        }
        foreach ($n->kids as $k) {
            self::paint($k, $list, $text);
        }
        if ($n->scroll !== null) {
            $list->popClip();
        }
    }
}
