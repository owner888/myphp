<?php
declare(strict_types=1);

namespace MyPHP\UI;

/**
 * What a field asks of the system's keyboard and input methods, as
 * UIKit's UITextInputTraits: the keyboard it shows, what the field holds
 * (for autofill), the Return key's label, how it capitalizes, whether it
 * corrects. TextField's modifiers set it on the field's TextState each
 * frame (keyboardType, textContentType, submitLabel,
 * textInputAutocapitalization, autocorrectionDisabled); each platform's
 * window gives it to the system while the field has the keyboard, as its
 * own keyboard and input methods take such hints. Null where the app did
 * not say: the system's default. Not mygo's.
 */
final class TextInputTraits
{
    public function __construct(
        public readonly ?\KeyboardType $keyboard = null,
        public readonly ?\TextContentType $contentType = null,
        public readonly ?\SubmitLabel $submitLabel = null,
        public readonly ?\TextInputAutocapitalization $autocapitalization = null,
        public readonly bool $autocorrectionDisabled = false,
    ) {}

    /**
     * What the keyboard is told for the field $f (null: none has it), its
     * traits, whether it hides its text and whether it takes lines, as a
     * string that changes when any of it does: a window tells the system
     * again only then.
     */
    public static function key(?TextState $f): string
    {
        if ($f === null) {
            return '';
        }
        $t = $f->traits;
        return implode(',', [$t?->keyboard?->name, $t?->contentType?->name, $t?->submitLabel?->name, $t?->autocapitalization?->name, (int)($t?->autocorrectionDisabled ?? false), (int)$f->password, (int)$f->multiline]);
    }
}
