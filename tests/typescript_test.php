<?php
// The TypeScript client: a backed enum is the union of its values, as
// mygo's typed constants are, and a call's enum argument comes from its
// value.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\{CallContext, Ipc, TypeScript};

/** Which todos show. */
enum TsFilter: string
{
    case All = 'all';
    case Open = 'open';
    case Done = 'done';
}

/** A todo. */
final class TsItem
{
    public function __construct(public string $title = '') {}
}

final class TsTodos
{
    /** @return list<string> */
    public function list(TsFilter $f): array { return [$f->value]; }

    /** @return list<TsItem> */
    public function items(): array { return [new TsItem('a')]; }
}

Ipc::bind(new TsTodos(), 'TsTodos');
$ts = TypeScript::generate();
check(str_contains($ts, "/** Which todos show. */\nexport type TsFilter = \"all\" | \"open\" | \"done\";"), 'a backed enum is the union of its values');
check(str_contains($ts, 'list(f: TsFilter): Promise<string[]>'), 'a parameter of it is typed so');
check(str_contains($ts, 'items(): Promise<TsItem[]>') && str_contains($ts, "export interface TsItem {\n  title: string;\n}"), 'a class a PHPDoc names is read in the namespace of the code it documents');
$m = new \ReflectionMethod(TsTodos::class, 'list');
$args = Ipc::arguments($m, ['open'], new CallContext(null), fn(int $id) => throw new \LogicException('no channel'));
same([TsFilter::Open], $args, 'the page\'s value is the case');
try { Ipc::arguments($m, ['late'], new CallContext(null), fn(int $id) => throw new \LogicException('no channel')); check(false, 'a value not a case'); } catch (\ValueError) { check(true, 'a value that is no case fails the call'); }
