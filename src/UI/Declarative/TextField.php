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

    public function __construct(public readonly string $title, string &$text, public readonly ?string $prompt = null, public readonly bool $secure = false, public readonly bool $vertical = false)
    {
        $this->text = &$text;
    }

    /** What Return in the field does, as SwiftUI's .onSubmit. */
    public function onSubmit(Closure $action): static { $this->submit = $action; return $this; }

    protected function build(Context $c, Env $env): ?Node
    {
        $rt = $c->runtime;
        $kept = $rt === null ? (object)['state' => new TextState($this->text), 'app' => $this->text]
            : $rt->local($c->upcomingId(Node::INPUT), fn() => (object)['state' => new TextState($this->text), 'app' => $this->text]);
        $state = $kept->state;
        if ($this->text !== $kept->app) { // the app changed it since: shown, the caret at its end
            $state->text = $this->text;
            $state->setCursor(strlen($this->text));
        } elseif ($state->text !== $this->text) { // typed in: the app's
            $this->text = $state->text;
        }
        $placeholder = $this->prompt ?? $this->title;
        $n = $this->vertical ? $c->textarea($state, $placeholder, 0, 0, null, 1, 8) : $c->input($state, $placeholder, 0);
        if ($this->secure) { $n->password(); }
        $n->label($this->title);
        $env->font?->apply($n);
        if ($env->disabled) { $n->disabled(); }
        if ($c->changed($n)) { $this->text = $state->text; }
        $kept->app = $this->text;
        if ($this->submit !== null && $c->submitted($n)) { ($this->submit)(); }
        return $n;
    }
}
