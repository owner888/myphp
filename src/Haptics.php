<?php
declare(strict_types=1);

namespace MyPHP;

use InvalidArgumentException;

/**
 * Haptic feedback, as UIKit's feedback generators and SwiftUI's
 * sensoryFeedback give it (not mygo's, which has no phones): a tap for
 * something the user did, a tick as a selection changes, a pattern for
 * an outcome. Call it from the action, once, as the HIG asks: as a
 * switch flips, a picker ticks, a long press opens a menu, a save
 * succeeds — never on every button, which the system does not do either.
 *
 * Plays on a phone's Taptic Engine or vibrator, on the Mac's trackpad
 * while a finger rests on it; nothing on Linux. The user's setting that
 * turns haptics off is the system's to honor, and it does.
 */
final class Haptics
{
    /** The kinds the platforms play: UIImpactFeedbackGenerator's styles, a selection change, UINotificationFeedbackGenerator's types. */
    public const KINDS = ['light', 'medium', 'heavy', 'soft', 'rigid', 'selection', 'success', 'warning', 'error'];

    /** A tap for a collision or a press, of $style 'light', 'medium', 'heavy', 'soft' or 'rigid' (UIImpactFeedbackGenerator). */
    public static function impact(string $style = 'medium'): void
    {
        if (!in_array($style, ['light', 'medium', 'heavy', 'soft', 'rigid'], true)) {
            throw new InvalidArgumentException("Not an impact style: $style (light, medium, heavy, soft, rigid)");
        }
        self::play($style);
    }

    /** A tick as a selection changes, as a picker's wheel turns (UISelectionFeedbackGenerator). */
    public static function selection(): void
    {
        self::play('selection');
    }

    /** The pattern for a task that succeeded (UINotificationFeedbackGenerator's success). */
    public static function success(): void
    {
        self::play('success');
    }

    /** The pattern for a warning (UINotificationFeedbackGenerator's warning). */
    public static function warning(): void
    {
        self::play('warning');
    }

    /** The pattern for a task that failed (UINotificationFeedbackGenerator's error). */
    public static function error(): void
    {
        self::play('error');
    }

    private static function play(string $kind): void
    {
        App::backend()->shell()->haptic($kind);
    }
}
