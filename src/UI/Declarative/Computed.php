<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

use Closure;
use MyPHP\UI\{Context, Node};
use MyPHP\UI\Reactive\Observer;

/**
 * A fine-grained reactive view scope: runs $body inside Observer::run(),
 * automatically tracking every PHP 8.4 Property Hook or State<T> read, and
 * retains the resulting Node subtree across frames until a tracked reactive
 * dependency changes.
 */
final class Computed extends View
{
    public function __construct(
        private readonly Closure $body,
        private readonly string|int|null $key = null,
    ) {}

    protected function build(Context $c, Env $env): ?Node
    {
        $rt = $c->runtime;
        if ($rt === null) {
            $res = ($this->body)();
            if ($res instanceof View) {
                return $res->render($c, $env);
            }
            View::renderAll($c, $env, $res);
            return null;
        }
        $memoKey = $this->key ?? $this->identity ?? ('__computed_' . $c->upcomingId());
        return $c->reactiveMemo($memoKey, $env, function () use ($c, $env): void {
            $res = ($this->body)();
            if ($res instanceof View && !$res instanceof Group && !$res instanceof Each && $res->identity === null) {
                $n = $res->render($c, $env);
                if ($n !== null) {
                    return;
                }
            }
            $box = $c->open(new Node(Node::CONTAINER, $env->axis));
            if ($res === null || $res === false || $res === []) {
                $box->hidden();
            } else {
                View::renderAll($c, $env, $res);
                View::takeRoom($box, $env->axis, View::flexOfKids($box));
            }
            $c->end();
        });
    }
}
