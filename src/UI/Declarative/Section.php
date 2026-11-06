<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

use MyPHP\UI\{Context, Node};

/** Section, as SwiftUI's in a List or Form: its rows in a rounded group, its header above in small capitals, its footer below. */
final class Section extends View
{
    /** @var list<mixed> */
    private array $content = [];

    public function __construct(public readonly string $header = '', public readonly string $footer = '') {}

    public function __invoke(View|array|null ...$content): static
    {
        $this->content = $content;
        return $this;
    }

    /** Its views. @internal @return array<mixed> */
    public function content(): array { return $this->content; }

    protected function build(Context $c, Env $env): ?Node
    {
        $rows = ListView::flat($this->content);
        $rowEnv = $env->along(Node::COLUMN)->with(['inList' => true]);
        return $c->section(Localized::string($this->header, $env), function () use ($c, $rowEnv, $rows): void {
            foreach ($rows as $v) { ListView::row($c, $rowEnv, $v); }
        }, Localized::string($this->footer, $env));
    }
}
