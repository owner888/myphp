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
                $views = ($this->content)(); // closed too: what goes down as it closes
                $first = is_array($views) ? ($views[0] ?? null) : $views;
                $detents = $first instanceof View ? ($first->sheetDetents ?? []) : []; // the content's presentationDetents, as SwiftUI's
                $large = $c->height - $c->windowSafeArea[0] - 10;
                $c->sheet($this->shown, function () use ($c, $env, $views): void { View::renderAll($c, $env->along(\MyPHP\UI\Node::COLUMN), $views); },
                    array_map(fn(\PresentationDetent $d) => $d->points($large), $detents));
                break;
            case 'alert':
                $chosen = $c->alertDialog($this->shown, Localized::string($this->title, $env), Localized::string($this->message, $env), ...array_map(fn(Button $b) => Localized::string($b->title, $env), $this->actions));
                if ($chosen >= 0) { $this->actions[$chosen]->run(); }
                elseif ($chosen === \MyPHP\UI\Context::ALERT_DISMISSED) { foreach ($this->actions as $b) { if ($b->role === \ButtonRole::cancel) { $b->run(); break; } } } // Escape: its cancel button's action, as SwiftUI's
                break;
            case 'confirm':
                $choices = array_values(array_filter($this->actions, fn(Button $b) => $b->role !== \ButtonRole::cancel)); // a cancel role's is the sheet's own Cancel button, apart below, as UIKit's
                $cancel = array_values(array_filter($this->actions, fn(Button $b) => $b->role === \ButtonRole::cancel))[0] ?? null;
                $chosen = $c->actionSheet($this->shown, Localized::string($this->title, $env), ...array_map(fn(Button $b) => ($b->role === \ButtonRole::destructive ? '!' : '') . Localized::string($b->title, $env), $choices));
                if ($chosen >= 0) { $choices[$chosen]->run(); }
                elseif ($chosen === \MyPHP\UI\Context::SHEET_DISMISSED) { $cancel?->run(); }
                break;
        }
    }
}
