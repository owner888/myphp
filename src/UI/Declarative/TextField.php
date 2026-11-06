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

    public function __construct(public readonly string $title, string &$text, public readonly ?string $prompt = null, public readonly bool $secure = false, public readonly bool $vertical = false, public readonly bool $editor = false)
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
    public function onKeyPress(\KeyEquivalent $key, Closure $action): static { $this->keys[] = [$key->key(), $action]; return $this; }

    /** What Return in the field does, as SwiftUI's .onSubmit. */
    public function onSubmit(Closure $action): static { $this->submit = $action; return $this; }

    protected function build(Context $c, Env $env): ?Node
    {
        $rt = $c->runtime;
        $kept = $rt === null ? (object)['state' => new TextState($this->text), 'app' => $this->text]
            : $rt->local($c->upcomingId($this->editor || $this->vertical ? Node::TEXTAREA : Node::INPUT), fn() => (object)['state' => new TextState($this->text), 'app' => $this->text]); // keyed by the node it builds, which keeps it
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
                if ($key === $code && $action(\KeyPress::of($mods)) === \KeyPressResult::handled) { return true; }
            }
            return false;
        };
        $placeholder = Localized::string($this->prompt ?? $this->title, $env);
        $n = match (true) {
            $this->editor => $c->textarea($state, '', 0, 0),
            $this->vertical => $c->textarea($state, $placeholder, 0, 0, null, 1, 8),
            default => $c->input($state, $placeholder, 0),
        };
        if ($this->editor) { View::fill($n, $env->axis, View::FLEX_X | View::FLEX_Y); } // TextEditor takes the room it is given
        if ($this->secure) { $n->password(); }
        $n->label(Localized::string($this->title, $env));
        $env->font?->apply($n);
        if ($env->disabled) { $n->disabled(); }
        if ($c->changed($n)) { $this->text = $state->text; }
        $kept->app = $this->text;
        if ($this->submit !== null && $c->submitted($n)) { ($this->submit)(); }
        return $n;
    }
}
