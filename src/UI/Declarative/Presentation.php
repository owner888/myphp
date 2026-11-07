<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

use Closure;
use MyPHP\UI\Context;

/** A sheet, an alert or a confirmation dialog a view presents while its binding is true (View::sheet, alert, confirmationDialog). @internal */
final class Presentation
{
    public bool $shown;
    public ?Closure $content = null;
    public string $title = '', $message = '';
    /** @var list<Button> */
    public array $actions = [];

    public function __construct(public readonly string $kind, bool &$shown)
    {
        $this->shown = &$shown;
    }

    public function render(Context $c, Env $env): void
    {
        switch ($this->kind) {
            case 'sheet':
                $c->sheet($this->shown, function () use ($c, $env): void { View::renderAll($c, $env->along(\MyPHP\UI\Node::COLUMN), ($this->content)()); });
                break;
            case 'alert':
                $chosen = $c->alertDialog($this->shown, Localized::string($this->title, $env), Localized::string($this->message, $env), ...array_map(fn(Button $b) => Localized::string($b->title, $env), $this->actions));
                if ($chosen >= 0) { $this->actions[$chosen]->run(); }
                elseif ($chosen === \MyPHP\UI\Context::ALERT_DISMISSED) { foreach ($this->actions as $b) { if ($b->role === \ButtonRole::cancel) { $b->run(); break; } } } // Escape: its cancel button's action, as SwiftUI's
                break;
            case 'confirm':
                $chosen = $c->actionSheet($this->shown, Localized::string($this->title, $env), ...array_map(fn(Button $b) => ($b->role === \ButtonRole::destructive ? '!' : '') . Localized::string($b->title, $env), $this->actions));
                if ($chosen >= 0) { $this->actions[$chosen]->run(); }
                break;
        }
    }
}
