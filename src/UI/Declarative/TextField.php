<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

use Closure;
use MyPHP\UI\{Context, Node, TextState};

/**
 * TextField, as SwiftUI's: one line editing $text, bound, its title the
 * placeholder (or $prompt); SecureField hides what is typed; axis: vertical
 * grows to more lines. The caret, the selection, the undo history and a
 * word being composed with an input method are the field's, kept by the
 * runtime with it; the app sees the text.
 */
final class TextField extends View
{
    private string $text;
    private ?Closure $submit = null;

    public function __construct(public readonly string $title, string &$text, public readonly ?string $prompt = null, public readonly bool $secure = false, public readonly bool $vertical = false, public readonly bool $editor = false, public readonly bool $search = false)
    {
        $this->text = &$text;
    }

    /** @var list<array{0: int, 1: Closure}> */
    private array $keys = [];

    /**
     * Runs $action(KeyPress) as $key is pressed in the field, before the
     * field takes it, as SwiftUI's onKeyPress: KeyPressResult::handled
     * keeps it from the field (Return sending a message, say),
     * ignored lets the field have it (Shift+Return breaking the line).
     */
    /** Runs $action(KeyPress) for $key pressed in the field, a key or a character ('q', with its modifiers in the KeyPress), as SwiftUI's onKeyPress; KeyPressResult::handled keeps it from the field. */
    public function onKeyPress(\KeyEquivalent|string $key, Closure $action): static { $this->keys[] = [$key instanceof \KeyEquivalent ? $key->key() : mb_strtolower($key), $action]; return $this; }

    /** @var list<string>|null */
    private ?array $suggestions = null;

    /** What the field suggests as the user types, those holding what is typed, chosen with the arrows and Return, as SwiftUI's textInputSuggestions. @param list<string> $suggestions */
    public function textInputSuggestions(array $suggestions): static { $this->suggestions = $suggestions; return $this; }

    /** What Return in the field does, as SwiftUI's .onSubmit. */
    public function onSubmit(Closure $action): static { $this->submit = $action; return $this; }

    protected function build(Context $c, Env $env): ?Node
    {
        $rt = $c->runtime;
        $box = $this->search || $this->suggestions !== null ? $c->row(0, 0)->alignItems(Node::CENTER) : null; // a search field's or suggesting one's: its own state is the input's, the text's kept by the box around it
        $kept = $rt === null ? (object)['state' => new TextState($this->text), 'app' => $this->text]
            : $rt->local($box?->id ?? $c->upcomingId($this->editor || $this->vertical ? Node::TEXTAREA : Node::INPUT), fn() => (object)['state' => new TextState($this->text), 'app' => $this->text]); // keyed by the node it builds, which keeps it
        $state = $kept->state;
        if ($this->text !== $kept->app) { // the app changed it since: shown, the caret at its end
            $state->text = $this->text;
            $state->setCursor(strlen($this->text));
        } elseif ($state->text !== $this->text) { // typed in: the app's
            $this->text = $state->text;
        }
        $keys = $this->keys;
        $state->onTextKey = $keys === [] ? null : static function (int $code, string $chars, int $mods) use ($keys): bool {
            foreach ($keys as [$key, $action]) {
                if ((is_string($key) ? $key === mb_strtolower($chars) : $key === $code) && $action(\KeyPress::of($mods)) === \KeyPressResult::handled) { return true; }
            }
            return false;
        };
        $placeholder = Localized::string($this->prompt ?? $this->title, $env);
        $touch = $c->runtime?->touch ?? false;
        $plain = $env->textFieldStyle === \TextFieldStyle::plain || (($env->textFieldStyle ?? \TextFieldStyle::automatic) === \TextFieldStyle::automatic && $env->formRow && $touch); // in a phone's form, as SwiftUI's on iOS: no box, the row is the field
        $n = match (true) {
            $this->editor => $c->textarea($state, '', 0, 0),
            $this->search => $c->searchField($state, 0),
            $this->suggestions !== null => $c->autocomplete($state, $this->suggestions, $placeholder, 0),
            $this->vertical => $c->textarea($state, $placeholder, 0, 0, null, $env->linesReserved ? ($env->lineLimit ?? 1) : 1, $env->lineLimit ?? 8), // growing with its lines to lineLimit's
            $plain => $c->textInputBase($state),
            default => $c->input($state, $placeholder, 0),
        };
        if ($plain && !$this->editor && !$this->vertical) { $n->text = $placeholder; } // its prompt, as an input()'s
        View::takeRoom($n, $env->axis, $this->editor ? View::FLEX_X | View::FLEX_Y : View::FLEX_X); // as SwiftUI's: a field takes the width it is given, a TextEditor the room
        if ($this->secure) { $n->password(); }
        $n->label(Localized::string($this->title, $env));
        $env->font?->apply($n);
        if ($env->disabled) { $n->disabled(); }
        if ($c->changed($n)) { $this->text = $state->text; }
        $kept->app = $this->text;
        if ($this->submit !== null && $c->submitted($n)) { ($this->submit)(); }
        if ($box !== null) {
            $n->grow = max($n->grow, 1);
            $c->end();
            View::takeRoom($box, $env->axis, View::FLEX_X);
            return $box;
        }
        return $n;
    }
}
