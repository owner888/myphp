<?php
declare(strict_types=1);

namespace MyPHP\UI;

/**
 * The direction an interface reads in (mygo's Direction): left to right,
 * or right to left, as Arabic and Hebrew are, as Flutter's TextDirection,
 * SwiftUI's LayoutDirection and CSS's direction say.
 *
 * A window takes the direction of the app's language, and a node that of
 * the node it is built in, unless it is given one (Node::direction). Right
 * to left, a row lays its children out from the right, the start of
 * justify, align and textAlign is the right, a grid's columns go from the
 * right, the logical edges (paddingStart, marginStart, borderStartWidth…)
 * are on the right, horizontal scrolling starts at the right with its
 * offset counted from there, and the controls mirror what points along
 * the line: the arrows' keys, the fill of sliders and progress bars,
 * switches. Paragraphs without a strongly directional character, as
 * numbers, read right to left; the others take the direction of their
 * first strong character either way.
 *
 * The coordinate system is not mirrored: x still grows to the right, in
 * rects, in draw functions and in pointer positions, and what is physical
 * stays so, as in CSS: at(), padding()'s left and right, margin(). Text,
 * images and icons are not mirrored; draw what must point the other way
 * after reading Context::direction().
 */
enum Direction
{
    case LeftToRight;
    case RightToLeft;
}
