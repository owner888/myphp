<?php
// The shader compiler's oracle: what mygo's compiler (its internal/shader,
// internal/shaderir and shaderir/msl, run under Go) made of the sources its
// own tests compile, in testdata/shader/oracle.json.gz: whether Go's
// parser took each, the compiler's error or its program (as plain data,
// constants as [kind, exact value]) and the program's MSL. The shader tests
// compare the PHP port with it, and load its programs as the MSL tests'
// input.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\Shader\Ir\{Block, Constant, Expr, Func, Program, Stmt, Type};

/** The oracle's cases: src, vertex, fragment, textures, and syntax (Go's parser's error), error, ir and msl where there are. @return list<array<string, mixed>> */
function shaderOracle(): array
{
    static $cases = null;
    return $cases ??= json_decode(gzdecode((string)file_get_contents(__DIR__ . '/testdata/shader/oracle.json.gz')), true, 512, JSON_THROW_ON_ERROR);
}

/** $p as the oracle has its programs, Go's field names and all. @return array<string, mixed> */
function irDump(Program $p): array
{
    $type = function (Type $t) use (&$type): array {
        return ['Length' => $t->length, 'Main' => $t->main, 'Sub' => $t->sub === [] ? null : array_map($type, $t->sub)];
    };
    $types = fn(array $ts): ?array => $ts === [] ? null : array_map($type, $ts);
    $const = fn(?Constant $c): ?array => $c === null ? null : [[Constant::BOOL => 'Bool', Constant::INT => 'Int', Constant::FLOAT => 'Float', Constant::STRING => 'String'][$c->kind] ?? 'Unknown', $c->kind === Constant::BOOL ? ($c->value ? 'true' : 'false') : (is_float($c->value) ? var_export($c->value, true) : (string)$c->value)]; // a float's shortest round trip, whatever php.ini's precision
    $expr = function (Expr $e) use (&$expr, $const): array {
        return ['BuiltinFunc' => $e->builtinFunc, 'Const' => $const($e->const), 'Exprs' => $e->exprs === [] ? null : array_map($expr, $e->exprs), 'Index' => $e->index, 'Op' => $e->op,
            'Swizzling' => $e->swizzling, 'SwizzlingSet' => $e->swizzlingSet, 'Type' => $e->type, 'Unsigned' => $e->unsigned, 'Untyped' => $e->untyped];
    };
    $block = function (?Block $b) use (&$block, $expr, $types, $type, $const): ?array {
        if ($b === null) {
            return null;
        }
        $stmts = array_map(fn(Stmt $s): array => ['Blocks' => $s->blocks === [] ? null : array_map($block, $s->blocks), 'Exprs' => $s->exprs === [] ? null : array_map($expr, $s->exprs),
            'ForDelta' => $const($s->forDelta), 'ForEnd' => $const($s->forEnd), 'ForInit' => $const($s->forInit), 'ForOp' => $s->forOp, 'ForVarIndex' => $s->forVarIndex,
            'ForVarType' => $type($s->forVarType), 'InitIndex' => $s->initIndex, 'Type' => $s->type], $b->stmts);
        return ['LocalVarIndexOffset' => $b->localVarIndexOffset, 'LocalVars' => $types($b->localVars), 'Stmts' => $stmts === [] ? null : $stmts];
    };
    return [
        'Attributes' => $types($p->attributes),
        'FragmentFunc' => ['Block' => $block($p->fragmentFunc)],
        'Funcs' => $p->funcs === [] ? null : array_map(fn(Func $f): array => ['Block' => $block($f->block), 'InParams' => $types($f->inParams), 'Index' => $f->index,
            'OutParams' => $types($f->outParams), 'Return' => $type($f->return)], $p->funcs),
        'TextureCount' => $p->textureCount,
        'UniformNames' => $p->uniformNames === [] ? null : $p->uniformNames,
        'Uniforms' => $types($p->uniforms),
        'Varyings' => $types($p->varyings),
        'VertexFunc' => ['Block' => $block($p->vertexFunc)],
    ];
}

/** The oracle's program $ir as a Program, the input of the MSL backend's tests. @param array<string, mixed> $ir */
function irLoad(array $ir): Program
{
    $type = function (?array $t) use (&$type): Type {
        return $t === null ? new Type : new Type($t['Main'], array_map($type, $t['Sub'] ?? []), $t['Length']);
    };
    $types = fn(?array $ts): array => array_map($type, $ts ?? []);
    $const = function (?array $c): ?Constant {
        if ($c === null) {
            return null;
        }
        [$kind, $v] = $c;
        if ($kind === 'Bool') {
            return Constant::bool($v === 'true');
        }
        if (str_contains($v, '/')) { // a fraction, as Go keeps floats exactly
            [$n, $d] = explode('/', $v);
            $f = (float)$n / (float)$d;
        } else {
            $f = (float)$v;
        }
        return $kind === 'Int' ? Constant::int(preg_match('/^-?\d+$/', $v) && abs($f) < 9e18 ? (int)$v : $f) : Constant::float($f);
    };
    $expr = function (array $e) use (&$expr, $const): Expr {
        return new Expr($e['Type'], array_map($expr, $e['Exprs'] ?? []), $const($e['Const']), $e['BuiltinFunc'], $e['Swizzling'], $e['SwizzlingSet'], $e['Index'], $e['Op'], $e['Unsigned'], $e['Untyped']);
    };
    $block = function (?array $b) use (&$block, $expr, $types, $type, $const): ?Block {
        if ($b === null) {
            return null;
        }
        $stmts = array_map(fn(array $s): Stmt => new Stmt($s['Type'], array_map($expr, $s['Exprs'] ?? []), array_map($block, $s['Blocks'] ?? []), $type($s['ForVarType']), $s['ForVarIndex'],
            $const($s['ForInit']), $const($s['ForEnd']), $s['ForOp'], $const($s['ForDelta']), $s['InitIndex']), $b['Stmts'] ?? []);
        return new Block($types($b['LocalVars']), $b['LocalVarIndexOffset'], $stmts);
    };
    return new Program($ir['UniformNames'] ?? [], $types($ir['Uniforms']), $ir['TextureCount'], $types($ir['Attributes']), $types($ir['Varyings']),
        array_map(fn(array $f): Func => new Func($f['Index'], $types($f['InParams']), $types($f['OutParams']), $type($f['Return']), $block($f['Block'])), $ir['Funcs'] ?? []),
        $block($ir['VertexFunc']['Block']), $block($ir['FragmentFunc']['Block']));
}

/** $ir with its constants as numbers, a fraction's divided, so that two programs compare equal when their constants are the same numbers. @param array<string, mixed> $ir */
function irNormalize(mixed $ir): mixed
{
    if (!is_array($ir)) {
        return $ir;
    }
    if (count($ir) === 2 && array_is_list($ir) && in_array($ir[0], ['Int', 'Float', 'Bool', 'String', 'Unknown'], true) && is_string($ir[1])) {
        [$kind, $v] = $ir;
        if ($kind === 'Int' || $kind === 'Float') {
            if (str_contains($v, '/')) { [$n, $d] = explode('/', $v); $v = (float)$n / (float)$d; } else { $v = (float)$v; }
            return [$kind, $v];
        }
        return $ir;
    }
    return array_map('MyPHP\Tests\irNormalize', $ir);
}
