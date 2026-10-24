<?php
declare(strict_types=1);

namespace MyPHP\UI;

/**
 * A control's identity across builds, bound to its node in each pass
 * (Node::bind), in each window apart (mygo's Handle): asks for the focus
 * from anywhere, waiting while the control is not built, and reads the
 * focus and the box the last frame committed, whatever the order of the
 * build.
 */
final class Handle
{
    /** @var \WeakMap<Runtime, object{id: int, node: ?Node, frame: int, pass: int, requested: bool}> the binding in each window */
    private \WeakMap $bindings;
    /** A focus asked for before the first binding, which that binding takes. */
    private bool $pending = false;

    public function __construct()
    {
        $this->bindings = new \WeakMap();
    }

    /** The binding in $rt's window, made when $create. @internal */
    public function binding(?Runtime $rt, bool $create): ?object
    {
        if ($rt === null) {
            return null;
        }
        if (!isset($this->bindings[$rt])) {
            if (!$create) {
                return null;
            }
            $this->bindings[$rt] = (object)['id' => 0, 'node' => null, 'frame' => -1, 'pass' => -1, 'requested' => $this->pending];
            $this->pending = false;
            $rt->addHandle($this);
        }
        return $this->bindings[$rt];
    }

    /** Asks for the focus, waiting while the control is not built; $c selects the window, which a handle bound in several needs (mygo's Focus). */
    public function focus(?Context $c = null): void
    {
        $rt = $c?->runtime; $b = null;
        if ($c !== null) {
            $b = $this->binding($rt, true);
        } else {
            foreach ($this->bindings as $r => $candidate) {
                if ($b !== null) { throw new \LogicException('Handle::focus needs a Context when bound in several windows'); }
                [$rt, $b] = [$r, $candidate];
            }
            if ($b === null) {
                $this->pending = true; // the first binding takes it
                return;
            }
        }
        if ($b !== null) {
            $b->requested = true;
            $rt->changed();
        }
    }

    /** As focus() (mygo's RequestFocus). */
    public function requestFocus(?Context $c = null): void
    {
        $this->focus($c);
    }

    /** Takes back a focus asked for in $c's window, or in every one (mygo's CancelFocus). */
    public function cancelFocus(?Context $c = null): void
    {
        if ($c !== null) {
            if (($b = $this->binding($c->runtime, false)) !== null) { $b->requested = false; }
            return;
        }
        $this->pending = false;
        foreach ($this->bindings as $b) { $b->requested = false; }
    }

    /** Whether the control has the keyboard focus in $c's window, as the last frame committed it. */
    public function focused(Context $c): bool
    {
        $b = $this->binding($c->runtime, false);
        return $b !== null && $b->id !== 0 && $c->runtime->focus === $b->id && $c->runtime->windowFocus;
    }

    /** Whether the control, or one inside it, has the keyboard focus in $c's window. */
    public function focusWithin(Context $c): bool
    {
        $b = $this->binding($c->runtime, false);
        return $b !== null && $b->id !== 0 && $c->runtime->focusWithin($b->id);
    }

    /** The control's box in the last frame, in the window's points; empty when it was not built. @return array{0: float, 1: float, 2: float, 3: float} */
    public function bounds(Context $c): array
    {
        $b = $this->binding($c->runtime, false);
        return ($b === null || $b->id === 0 ? null : $c->runtime->rectOf($b->id)) ?? [0.0, 0.0, 0.0, 0.0];
    }

    /** The node bound in this pass of $c's window, or null (mygo's Resolve). */
    public function resolve(Context $c): ?Node
    {
        $b = $this->binding($c->runtime, false);
        return $b !== null && $b->frame === $c->runtime->frameNumber && $b->pass === $c->runtime->pass ? $b->node : null;
    }

    /** Runs $fn when $key with exactly $mods is pressed while the control bound in this pass, or one inside it, has the focus; bound before or after (mygo's Handle.OnShortcut). */
    public function onShortcut(Context $c, int|string $key, int $mods, \Closure $fn): void
    {
        $c->runtime?->addAction(null, 'shortcut', $fn, $key, $mods, $this);
    }
}
