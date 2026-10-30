<?php
// Copyright 2020 The Ebiten Authors
//
// Licensed under the Apache License, Version 2.0 (the "License");
// you may not use this file except in compliance with the License.
// You may obtain a copy of the License at
//
//     http://www.apache.org/licenses/LICENSE-2.0
//
// Unless required by applicable law or agreed to in writing, software
// distributed under the License is distributed on an "AS IS" BASIS,
// WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
// See the License for the specific language governing permissions and
// limitations under the License.

// Ported to PHP from MyGo's internal/shader (shader.go), MyGo's fork of
// Ebitengine's (see ../LICENSE), by the MyPHP contributors.
declare(strict_types=1);

namespace MyPHP\UI\Shader\Compiler;

use MyPHP\UI\Shader\Ir\{BasicType, Block as IrBlock, Constant, Expr, ExprType, Func as IrFunc, Program, Stmt, StmtType, Type};
use MyPHP\UI\Shader\Syntax;
use RuntimeException;

/**
 * Compiles a Kage source, a shader in Go's syntax, into a program of the
 * IR (shader.go's compileState and Compile). The methods keep the Go's
 * names and order, the expressions' in Exprs, the statements' in Stmts,
 * the types' in Types and the typing of integer constants in Unsigned. A
 * method that fails reports its errors and throws Abort, where Go's
 * returns false.
 * @internal
 */
final class Compiler
{
    use Exprs, Stmts, Types, Unsigned;

    /** The line that begins the internal region, where the uniform variables whose names start with __ are declared. */
    public const INTERNAL_REGION_BEGIN = self::INTERNAL_REGION_DIRECTIVE . ' begin';
    /** The line that ends the internal region. */
    public const INTERNAL_REGION_END = self::INTERNAL_REGION_DIRECTIVE . ' end';
    private const INTERNAL_REGION_DIRECTIVE = '//kage:internalregion';

    /** The byte offsets of the internal region in the source, an empty region when the source has none. */
    private int $internalRegionStart = 0;
    private int $internalRegionEnd = 0;
    private ?int $vertexEntryPos = null;
    private ?int $fragmentEntryPos = null;
    private Program $ir;
    /** @var list<Func> */
    private array $funcs = [];
    private Block $global;
    /** @var list<CompileError> */
    private array $errs = [];

    private function __construct(
        private readonly string $src,
        private readonly string $vertexEntry,
        private readonly string $fragmentEntry,
    ) {
        $this->ir = new Program;
        $this->global = new Block(null, new IrBlock);
    }

    /**
     * Compiles $src. A uniform variable whose name starts with __ can be
     * declared only in the internal region, the lines between
     * INTERNAL_REGION_BEGIN and INTERNAL_REGION_END, of which a source has
     * at most one.
     * @throws ParseError
     */
    public static function compile(string $src, string $vertexEntry, string $fragmentEntry, int $textureCount): Program
    {
        try {
            $f = Syntax\Parser::parse($src);
        } catch (Syntax\SyntaxError $e) {
            [$line, $col] = Syntax\Parser::position($src, $e->at);
            throw new ParseError([new CompileError(new Position($e->at, $line, $col), $e->getMessage())]);
        }

        $s = new self($src, $vertexEntry, $fragmentEntry);
        $s->ir->textureCount = $textureCount;
        $s->parseInternalRegion();
        if ($s->errs !== []) {
            throw new ParseError($s->errs);
        }
        try {
            $s->parse($f);
        } catch (Abort) {
            // The errors are reported.
        }
        if ($s->errs !== []) {
            throw new ParseError($s->errs);
        }
        return $s->ir;
    }

    /**
     * Compiles a MyGo shader (bridge.go's CompileFragment): Go's syntax in
     * package main, with uniform variables (exported, package level) that
     * the app sets and the entry point Fragment, which may call functions
     * of its own and read images through the functions the bridge
     * declares.
     * @throws ParseError
     * @throws RuntimeException when it has no entry point
     */
    public static function compileFragment(string $src): Program
    {
        $p = self::compile($src . Bridge::source(), Bridge::VERTEX_ENTRY, Bridge::ENTRY, Bridge::MAX_IMAGES);
        if ($p->fragmentFunc === null) {
            throw new RuntimeException('shader: no entry point ' . Bridge::ENTRY);
        }
        $p->fragmentSource = $src;
        return $p;
    }

    private function addError(int $pos, string $str): void
    {
        [$line, $col] = Syntax\Parser::position($this->src, $pos);
        $this->errs[] = new CompileError(new Position($pos, $line, $col), $str);
    }

    /** Reports $str at $pos and stops. */
    private function fail(int $pos, string $str): never
    {
        $this->addError($pos, $str);
        throw new Abort;
    }

    private function findFunction(string $name): ?int
    {
        foreach ($this->funcs as $i => $f) {
            if ($f->name === $name) {
                return $i;
            }
        }
        return null;
    }

    private function findUniformVariable(string $name): ?int
    {
        $i = array_search($name, $this->ir->uniformNames, true);
        return $i === false ? null : $i;
    }

    /** Whether a variable in $vars or a constant in $consts is named $name; a blank name never is. @param list<Variable> $vars @param list<NamedConstant> $consts */
    private static function declared(string $name, array $vars, array $consts): bool
    {
        if ($name === '_') {
            return false;
        }
        foreach ($vars as $v) {
            if ($v->name === $name) {
                return true;
            }
        }
        foreach ($consts as $c) {
            if ($c->name === $name) {
                return true;
            }
        }
        return false;
    }

    /** Records where the internal region is, and reports malformed directives of it. */
    private function parseInternalRegion(): void
    {
        $begin = -1; $end = -1;
        for ($offset = 0, $n = strlen($this->src); $offset < $n; $offset = $next) {
            $lineOffset = $offset;
            $nl = strpos($this->src, "\n", $offset);
            $next = $nl === false ? $n : $nl + 1;
            $directive = trim(substr($this->src, $lineOffset, $next - $lineOffset), " \t\n\r\v\f");
            if (!str_starts_with($directive, self::INTERNAL_REGION_DIRECTIVE)) {
                continue;
            }
            $arg = substr($directive, strlen(self::INTERNAL_REGION_DIRECTIVE));
            // Skip another directive whose name merely starts with the same prefix.
            if ($arg !== '' && $arg[0] !== ' ' && $arg[0] !== "\t") {
                continue;
            }
            switch (trim($arg, " \t\n\r\v\f")) {
                case 'begin':
                    if ($begin >= 0) {
                        $this->addError($lineOffset, 'at most one internal region can exist in a shader');
                        return;
                    }
                    $begin = $lineOffset;
                    break;
                case 'end':
                    if ($end >= 0) {
                        $this->addError($lineOffset, 'at most one internal region can exist in a shader');
                        return;
                    }
                    $end = $lineOffset;
                    break;
                default:
                    $this->addError($lineOffset, "invalid directive: $directive");
                    return;
            }
        }

        if ($begin < 0 && $end < 0) {
            return;
        }
        if ($begin < 0 || $end < 0 || $end < $begin) {
            $this->addError(max($begin, $end), sprintf('%s and %s must appear in this order', self::INTERNAL_REGION_BEGIN, self::INTERNAL_REGION_END));
            return;
        }
        $this->internalRegionStart = $begin;
        $this->internalRegionEnd = $end;
    }

    private function inInternalRegion(int $pos): bool
    {
        return $this->internalRegionStart <= $pos && $pos < $this->internalRegionEnd;
    }

    /** Reports an error and returns false if two package-level declarations of $f have the same name. */
    private function checkPackageLevelNames(Syntax\File $f): bool
    {
        $names = [];
        foreach ($f->decls as $d) {
            $idents = [];
            if ($d instanceof Syntax\GenDecl) {
                foreach ($d->specs as $s) {
                    if ($s instanceof Syntax\TypeSpec) {
                        $idents[] = $s->name;
                    } elseif ($s instanceof Syntax\ValueSpec) {
                        array_push($idents, ...$s->names);
                    }
                }
            } else {
                $idents[] = $d->name;
            }
            foreach ($idents as $n) {
                if ($n->name === '_') {
                    continue;
                }
                if (isset($names[$n->name])) {
                    $first = $names[$n->name];
                    // A name the internal region declares again is the user's
                    // mistake, at their declaration.
                    if ($this->inInternalRegion($n->pos) && !$this->inInternalRegion($first)) {
                        $this->addError($first, "{$n->name} redeclared in this block: MyGo declares it for shaders");
                        return false;
                    }
                    $this->addError($n->pos, "{$n->name} redeclared in this block");
                    return false;
                }
                $names[$n->name] = $n->pos;
            }
        }
        return true;
    }

    private function parse(Syntax\File $f): void
    {
        foreach ($f->decls as $d) {
            if ($d instanceof Syntax\FuncDecl && $d->recv !== null) {
                // Go reports a method at its receiver's opening parenthesis, the first after func.
                $this->addError(strpos($this->src, '(', $d->pos), 'method is not supported');
                return;
            }
        }

        if (!$this->checkPackageLevelNames($f)) {
            return;
        }

        // Parse GenDecl for global variables, and then parse functions.
        foreach ($f->decls as $d) {
            if (!$d instanceof Syntax\FuncDecl) {
                array_push($this->global->ir->stmts, ...$this->parseDecl($this->global, '', $d));
            }
        }

        // Sort the uniform variables so that the special ones, starting with __, come first.
        $unames = []; $utypes = [];
        foreach ([true, false] as $special) {
            foreach ($this->ir->uniformNames as $i => $u) {
                if (str_starts_with($u, '__') === $special) {
                    $unames[] = $u;
                    $utypes[] = $this->ir->uniforms[$i];
                }
            }
        }
        $this->ir->uniformNames = $unames;
        $this->ir->uniforms = $utypes;

        // Parse function names so that any function can call the other functions.
        // The function data is provisional and will be updated soon.
        $vertexInParams = []; $vertexOutParams = [];
        $fragmentInParams = []; $fragmentInParamPositions = []; $fragmentOutParams = [];
        $fragmentReturnType = new Type;
        foreach ($f->decls as $d) {
            if (!$d instanceof Syntax\FuncDecl) {
                continue;
            }
            $n = $d->name->name;
            [$inParams, $outParams, $ret] = $this->parseFuncParams($this->global, $n, $d);

            if ($n === $this->vertexEntry) {
                $this->vertexEntryPos = $d->pos;
                $vertexInParams = $inParams;
                $vertexOutParams = $outParams;
                continue;
            }
            if ($n === $this->fragmentEntry) {
                $this->fragmentEntryPos = $d->pos;
                $fragmentInParams = $inParams;
                $fragmentOutParams = $outParams;
                $fragmentReturnType = $ret;
                foreach ($d->type->params as $field) {
                    if ($field->names === []) {
                        $fragmentInParamPositions[] = $field->type->pos;
                        continue;
                    }
                    foreach ($field->names as $name) {
                        $fragmentInParamPositions[] = $name->pos;
                    }
                }
                continue;
            }

            $this->funcs[] = new Func($n, $d->pos, new IrFunc(
                count($this->funcs),
                array_map(fn(Variable $v): Type => $v->typ, $inParams),
                array_map(fn(Variable $v): Type => $v->typ, $outParams),
                $ret,
                new IrBlock,
            ));
        }

        // Check varying variables.
        // In tests, there might not be vertex and fragment entry points.
        if ($vertexOutParams !== [] && $fragmentInParams !== []) {
            foreach ($vertexOutParams as $i => $p) {
                if (count($fragmentInParams) <= $i) {
                    break;
                }
                $t = $fragmentInParams[$i]->typ;
                if (!$p->typ->equal($t)) {
                    $arg = 'fragment argument ' . $fragmentInParams[$i]->name;
                    // A blank or unnamed argument has no name to identify it.
                    if ($fragmentInParams[$i]->name === '_') {
                        $arg = sprintf('the %s fragment argument', self::ordinal($i + 1));
                    }
                    $this->addError($fragmentInParamPositions[$i], sprintf('%s must be %s but was %s', $arg, $p->typ->string(), $t->string()));
                }
            }
            if (count($fragmentInParams) > count($vertexOutParams)) {
                $this->addError($this->fragmentEntryPos, sprintf('the number of the fragment arguments (%d) must not be greater than the number of the vertex returning values (%d)', count($fragmentInParams), count($vertexOutParams)));
            }
        }
        if ($this->vertexEntryPos !== null) {
            // The first out-param is treated as gl_Position in GLSL.
            if ($vertexOutParams === [] || $vertexOutParams[0]->typ->main !== BasicType::VEC4) {
                $this->addError($this->vertexEntryPos, 'vertex entry point must have at least one returning vec4 value for a position');
            }
        }
        if ($this->fragmentEntryPos !== null) {
            if ($fragmentOutParams !== [] || $fragmentReturnType->main !== BasicType::VEC4) {
                $this->addError($this->fragmentEntryPos, 'fragment entry point must have one returning vec4 value for a color');
            }
        }

        if ($this->errs !== []) {
            return;
        }

        // Set attribute and varying variables.
        foreach ($vertexInParams as $p) {
            $this->ir->attributes[] = $p->typ;
        }
        // The 0th out-param is a special variable for position and is not included in varying variables.
        foreach (array_slice($vertexOutParams, 1) as $p) {
            $this->ir->varyings[] = $p->typ;
        }

        // Parse functions.
        foreach ($f->decls as $d) {
            if ($d instanceof Syntax\FuncDecl) {
                array_push($this->global->ir->stmts, ...$this->parseDecl($this->global, $d->name->name, $d));
            }
        }

        if ($this->errs !== []) {
            return;
        }

        $this->checkRecursiveCalls();
        if ($this->errs !== []) {
            return;
        }

        foreach ($this->funcs as $fn) {
            $this->ir->funcs[] = $fn->ir;
        }
    }

    /** $n written as an English ordinal number. */
    private static function ordinal(int $n): string
    {
        if ($n % 100 >= 11 && $n % 100 <= 13) {
            return "{$n}th";
        }
        return $n . match ($n % 10) { 1 => 'st', 2 => 'nd', 3 => 'rd', default => 'th' };
    }

    /** Reports each function that calls itself directly or indirectly (#3536). */
    private function checkRecursiveCalls(): void
    {
        $callees = array_map(fn(Func $f): array => self::calledFunctionIndices($f->ir->block), $this->funcs);
        foreach ($this->funcs as $i => $f) {
            if (self::callsFunction($callees, $i, $i)) {
                $this->addError($f->pos, "function {$f->name} must not be called recursively");
            }
        }
    }

    /** Whether the function at $from calls the one at $to directly or indirectly, $callees[$i] the functions the one at $i calls. @param list<list<int>> $callees */
    private static function callsFunction(array $callees, int $from, int $to): bool
    {
        $visited = [];
        $stack = $callees[$from];
        while ($stack !== []) {
            $i = array_pop($stack);
            if ($i === $to) {
                return true;
            }
            if (isset($visited[$i])) {
                continue;
            }
            $visited[$i] = true;
            array_push($stack, ...$callees[$i]);
        }
        return false;
    }

    /** The indices of the functions of the program that $b calls, once each. @return list<int> */
    private static function calledFunctionIndices(?IrBlock $b): array
    {
        $seen = [];
        $walkExprs = function (array $exprs) use (&$walkExprs, &$seen): void {
            foreach ($exprs as $e) {
                if ($e->type === ExprType::FUNCTION_EXPR) {
                    $seen[$e->index] ??= true;
                }
                $walkExprs($e->exprs);
            }
        };
        $walkBlock = function (?IrBlock $b) use (&$walkBlock, $walkExprs): void {
            foreach ($b?->stmts ?? [] as $s) {
                $walkExprs($s->exprs);
                foreach ($s->blocks as $bb) {
                    $walkBlock($bb);
                }
            }
        };
        $walkBlock($b);
        return array_keys($seen);
    }

    /** @return list<Stmt> */
    private function parseDecl(Block $b, string $fname, Syntax\Decl $d): array
    {
        $stmts = [];
        if ($d instanceof Syntax\GenDecl) {
            switch ($d->tok) {
                case 'type':
                    // TODO: Support type declarations (#1273, #2344).
                    $this->fail($d->pos, 'type declaration is not supported');
                case 'const':
                    foreach ($d->specs as $s) {
                        array_push($b->consts, ...$this->parseConstant($b, $fname, $s));
                    }
                    break;
                case 'var':
                    foreach ($d->specs as $s) {
                        [$vs, $inits, $ss] = $this->parseVariable($b, $fname, $s);
                        array_push($stmts, ...$ss);
                        if ($b === $this->global) {
                            if ($inits !== []) {
                                $this->fail($s->pos, 'a uniform variable cannot have initial values');
                            }
                            foreach ($vs as $i => $v) {
                                // A uniform variable starting with __ is reserved for the internal region.
                                $reserved = str_starts_with($v->name, '__') && $this->inInternalRegion($s->names[$i]->pos);
                                if (!$reserved && ($v->name[0] < 'A' || 'Z' < $v->name[0])) {
                                    $this->addError($s->names[$i]->pos, "global variables must be exposed: {$v->name}");
                                }
                                $this->ir->uniformNames[] = $v->name;
                                $this->ir->uniforms[] = $v->typ;
                            }
                            continue;
                        }

                        // base must be obtained before adding the variables.
                        $base = $b->totalLocalVariableCount();
                        foreach ($vs as $v) {
                            $b->addNamedLocalVariable($v->name, $v->typ, $d->pos);
                        }
                        if ($inits !== []) {
                            foreach ($vs as $i => $_) {
                                $stmts[] = new Stmt(StmtType::ASSIGN, [new Expr(ExprType::LOCAL_VARIABLE, index: $base + $i), $inits[$i]]);
                            }
                        }
                    }
                    break;
                case 'import':
                    $this->addError($d->pos, 'import is forbidden');
                    break;
                default:
                    $this->addError($d->pos, 'unexpected token');
            }
            return $stmts;
        }

        $f = $this->parseFunc($b, $d);
        if ($b !== $this->global) {
            $this->fail($d->pos, 'non-global function is not implemented');
        }
        switch ($d->name->name) {
            case $this->vertexEntry:
                $this->ir->vertexFunc = $f->ir->block;
                break;
            case $this->fragmentEntry:
                $this->ir->fragmentFunc = $f->ir->block;
                break;
            default:
                // The function is already registered by the provisional parsing. A blank name can be declared more than once, so
                // match the declaration by its position; its index is the provisional one.
                foreach ($this->funcs as $i => $g) {
                    if ($g->pos === $d->pos) {
                        $f->ir->index = $g->ir->index;
                        $this->funcs[$i] = $f;
                        break;
                    }
                }
        }
        return $stmts;
    }

    /**
     * The types of the values a call returns, out-params and the result,
     * if $expr is a call of a function of the program: parseExpr gives
     * the types of the IR's, not the function's.
     * @return ?list<Type>
     */
    private function functionReturnTypes(Syntax\Expr $expr): ?array
    {
        if (!$expr instanceof Syntax\CallExpr || !$expr->fun instanceof Syntax\Ident) {
            return null;
        }
        foreach ($this->funcs as $f) {
            if ($f->name === $expr->fun->name) {
                // TODO: Is it correct to combine out-params and return param?
                $ts = $f->ir->outParams;
                if ($f->ir->return->main !== BasicType::NONE) {
                    $ts[] = $f->ir->return;
                }
                return $ts;
            }
        }
        return null;
    }

    /** @return array{0: list<Variable>, 1: list<Expr>, 2: list<Stmt>} */
    private function parseVariable(Block $block, string $fname, Syntax\ValueSpec $vs): array
    {
        $nn = count($vs->names); $nv = count($vs->values);
        if ($nn !== $nv && $nv !== 1 && $nv !== 0) {
            $this->fail($vs->pos, "the numbers of lhs and rhs don't match");
        }

        $declt = new Type;
        if ($vs->type !== null) {
            $declt = $this->parseType($block, $fname, $vs->type);
        }

        $vars = []; $inits = []; $stmts = [];
        // These are used only in multiple-value context.
        $inittypes = []; $initexprs = [];

        foreach ($vs->names as $i => $n) {
            $t = $declt;
            if ($nv === 0) {
                // No initialization
            } elseif ($nn === $nv) {
                // Single-value context
                $init = $vs->values[$i];
                [$es, $rts, $ss] = $this->parseExpr($block, $fname, $init, true);
                if ($es === [] || $rts === []) {
                    $this->fail($init->pos, 'the right-hand side of the variable declaration has no value');
                }
                if (count($es) > 1 || count($rts) > 1) {
                    $this->fail($init->pos, "the numbers of lhs and rhs don't match");
                }

                if ($t->main === BasicType::NONE) {
                    $ts = $this->functionReturnTypes($init) ?? $rts;
                    if (count($ts) > 1) {
                        $this->addError($init->pos, "the numbers of lhs and rhs don't match");
                    }
                    if ($ts === []) {
                        $this->fail($init->pos, 'the right-hand side of the variable declaration has no value');
                    }
                    $t = $ts[0];
                    if ($t->main === BasicType::NONE) {
                        $t = self::toDefaultType($es[0]->const);
                    }
                }

                foreach ($rts as $j => $rt) {
                    if (!self::canAssign($t, $rt, $es[$j])) {
                        $this->addError($n->pos, sprintf('cannot use type %s as type %s in variable declaration', self::typeString($rt, $es[$j]->const), $t->string()));
                    }
                    if ($es[$j]->const !== null) {
                        $es[$j]->const = match ($t->main) {
                            BasicType::INT, BasicType::UINT => Constant::toInt($es[$j]->const),
                            BasicType::FLOAT => Constant::toFloat($es[$j]->const),
                            default => $es[$j]->const,
                        };
                    }
                }

                array_push($inits, ...$es);
                array_push($stmts, ...$ss);
            } else {
                // Multiple-value context
                if ($i === 0) {
                    $init = $vs->values[0];
                    [$initexprs, $inittypes, $ss] = $this->parseExpr($block, $fname, $init, true);
                    array_push($stmts, ...$ss);
                    if ($t->main === BasicType::NONE) {
                        $inittypes = $this->functionReturnTypes($init) ?? $inittypes;
                    }
                    if (count($initexprs) !== $nn || count($inittypes) !== $nn) {
                        $this->fail($vs->pos, "the numbers of lhs and rhs don't match");
                    }
                }

                if ($t->main === BasicType::NONE && $inittypes !== []) {
                    $t = $inittypes[$i];
                    // TODO: Is it possible to reach this?
                    if ($t->main === BasicType::NONE) {
                        $t = self::toDefaultType($initexprs[$i]->const);
                    }
                }

                if (!self::canAssign($t, $inittypes[$i], $initexprs[$i])) {
                    $this->addError($n->pos, sprintf('cannot use type %s as type %s in variable declaration', $inittypes[$i]->string(), $t->string()));
                }

                // Add the same initexprs for each variable.
                array_push($inits, ...$initexprs);
            }

            $this->checkLocalVariableType($n->pos, $t);

            if (self::declared($n->name, [...$block->vars, ...$vars], $block->consts)) {
                $this->fail($n->pos, "{$n->name} redeclared in this block");
            }
            $vars[] = new Variable($n->name, $t);
        }
        return [$vars, $inits, $stmts];
    }

    /** @return list<NamedConstant> */
    private function parseConstant(Block $block, string $fname, Syntax\ValueSpec $vs): array
    {
        $nn = count($vs->names); $nv = count($vs->values);
        if ($nn > $nv) {
            $this->fail($vs->names[$nv]->pos, 'missing init expr for const declaration');
        }
        if ($nn < $nv) {
            $this->fail($vs->values[$nn]->pos, 'extra init expr for const declaration');
        }

        $declt = new Type;
        if ($vs->type !== null) {
            $declt = $this->parseType($block, $fname, $vs->type);
        }

        $cs = [];
        foreach ($vs->names as $i => $n) {
            $name = $n->name;
            if (self::declared($name, $block->vars, [...$block->consts, ...$cs])) {
                $this->fail($n->pos, "$name redeclared in this block");
            }

            [$es, $ts, $ss] = $this->parseExpr($block, $fname, $vs->values[$i], false);
            if ($ss !== []) {
                $this->fail($vs->values[$i]->pos, "invalid constant expression: $name");
            }
            if (count($ts) !== 1 || count($es) !== 1) {
                $this->fail($vs->values[$i]->pos, "invalid constant expression: $name");
            }
            if ($es[0]->type !== ExprType::NUMBER_EXPR) {
                $this->fail($vs->values[$i]->pos, "constant expression must be a number but not: $name");
            }

            // A constant without a declared type has the type of its value, which is none for an untyped value.
            $t = $vs->type === null ? $ts[0] : $declt;
            if (!$t->equal(new Type) && !self::canAssign($t, $ts[0], $es[0])) {
                $this->fail($vs->values[$i]->pos, sprintf('cannot use %s as %s value in constant declaration', $es[0]->const, $t->string()));
            }

            $c = $es[0]->const;
            switch ($t->main) {
                case BasicType::INT:
                    $c = Constant::toInt($c);
                    if (!$this->checkIntConstRange($vs->pos, $c)) {
                        throw new Abort;
                    }
                    break;
                case BasicType::UINT:
                    $c = Constant::toInt($c);
                    if (!$this->checkUintConstRange($vs->pos, $c)) {
                        throw new Abort;
                    }
                    break;
                case BasicType::FLOAT:
                    $c = Constant::toFloat($c);
                    break;
            }
            $cs[] = new NamedConstant($name, $t, $c);
        }
        return $cs;
    }

    /**
     * The parameters, the named or unnamed out-params and the result of
     * $d, as far as they are right: a parameter's type that is wrong is
     * reported, and ends them.
     * @return array{0: list<Variable>, 1: list<Variable>, 2: Type}
     */
    private function parseFuncParams(Block $block, string $fname, Syntax\FuncDecl $d): array
    {
        $in = []; $out = [];
        // Parameters and named results share one scope, so a name must not appear twice among them.
        $names = [];
        $checkName = function (Syntax\Ident $n) use (&$names): bool {
            if ($n->name === '_') {
                return true;
            }
            if (isset($names[$n->name])) {
                $this->addError($n->pos, "duplicate argument {$n->name}");
                return false;
            }
            $names[$n->name] = true;
            return true;
        };

        try {
            foreach ($d->type->params as $f) {
                $t = $this->parseType($block, $fname, $f->type);
                if ($f->names === []) {
                    // An unnamed parameter cannot be referred to, just like a blank identifier.
                    $in[] = new Variable('_', $t);
                    continue;
                }
                foreach ($f->names as $n) {
                    if (!$checkName($n)) {
                        return [$in, $out, new Type];
                    }
                    $in[] = new Variable($n->name, $t);
                }
            }

            foreach ($d->type->results ?? [] as $f) {
                $t = $this->parseType($block, $fname, $f->type);
                if ($f->names === []) {
                    $out[] = new Variable('', $t);
                    continue;
                }
                foreach ($f->names as $n) {
                    if (!$checkName($n)) {
                        return [$in, $out, new Type];
                    }
                    $out[] = new Variable($n->name, $t);
                }
            }
        } catch (Abort) {
            return [$in, $out, new Type];
        }

        // If there is only one returning value, it is treated as a returning value.
        // An array cannot be a returning value, especially for HLSL (#2923).
        //
        // For the vertex entry, a parameter (variable) is used as a returning value.
        // For example, GLSL doesn't treat gl_Position as a returning value.
        // Thus, the returning value is not set for the vertex entry.
        if (count($out) === 1 && $out[0]->name === '' && $out[0]->typ->main !== BasicType::ARRAY && $fname !== $this->vertexEntry) {
            return [$in, [], $out[0]->typ];
        }
        return [$in, $out, new Type];
    }

    private function parseFunc(Block $block, Syntax\FuncDecl $d): Func
    {
        if ($d->name->name === 'init') {
            $this->fail($d->pos, 'init function is not implemented');
        }
        if ($d->body === null) {
            $this->fail($d->pos, 'function must have a body');
        }

        [$inParams, $outParams, $returnType] = $this->parseFuncParams($block, $d->name->name, $d);
        if ($d->name->name === $this->fragmentEntry) {
            if ($inParams === []) {
                $inParams[] = new Variable('_', new Type(BasicType::VEC4));
            }
            // The 0th inParams is a special variable for position and is not included in varying variables.
            // inParams is not enough when the vertex shader has more returning values than the fragment shader's arguments.
            $orig = count($inParams) - 1;
            for ($i = 0, $diff = count($this->ir->varyings) - $orig; $i < $diff; $i++) {
                $inParams[] = new Variable('_', $this->ir->varyings[$orig + $i]);
            }
        }
        $b = $this->parseBlock($block, $d->name->name, $d->body->list, $inParams, $outParams, $returnType, true);

        if ($outParams !== [] || $returnType->main !== BasicType::NONE) {
            if (!self::isTerminating($b->ir->stmts)) {
                $this->fail($d->body->rbrace, "function {$d->name->name} must end with a return statement on every path but does not");
            }
        }

        return new Func($d->name->name, $d->pos, new IrFunc(
            0,
            array_map(fn(Variable $v): Type => $v->typ, $inParams),
            array_map(fn(Variable $v): Type => $v->typ, $outParams),
            $returnType,
            $b->ir,
        ));
    }

    /** Reports an error at $pos and returns false when $v is an integer constant that does not fit in a 32-bit integer. */
    private function checkIntConstRange(int $pos, ?Constant $v): bool
    {
        if ($v === null || $v->kind !== Constant::INT) {
            return true;
        }
        [$x, $exact] = Constant::int64Val($v);
        if ($exact && -2147483648 <= $x && $x <= 2147483647) {
            return true;
        }
        $this->addError($pos, "constant $v overflows int");
        return false;
    }

    /** Reports an error at $pos and returns false when $v is an integer constant that does not fit in a 32-bit unsigned integer. */
    private function checkUintConstRange(int $pos, ?Constant $v): bool
    {
        if ($v === null || $v->kind !== Constant::INT) {
            return true;
        }
        [$x, $exact] = Constant::uint64Val($v);
        if ($exact && $x >= 0 && $x <= 4294967295) {
            return true;
        }
        $this->addError($pos, "constant $v overflows uint");
        return false;
    }

    /** Whether $stmts ends in a statement that returns or discards on every path. @param list<Stmt> $stmts */
    private static function isTerminating(array $stmts): bool
    {
        if ($stmts === []) {
            return false;
        }
        $last = $stmts[count($stmts) - 1];
        return match ($last->type) {
            StmtType::RETURN, StmtType::DISCARD => true,
            StmtType::BLOCK_STMT => self::isTerminating($last->blocks[0]->stmts),
            // An if-statement without an else branch falls through when its condition is false.
            StmtType::IF => count($last->blocks) === 2 && self::isTerminating($last->blocks[0]->stmts) && self::isTerminating($last->blocks[1]->stmts),
            // A for-statement never terminates. Every Kage loop has a condition or a range clause, either of which
            // makes a loop non-terminating in Go.
            default => false,
        };
    }

    /**
     * Parses $stmts into a block in $outer: a function's body when $outer
     * is the global block, its parameters and out-params its first
     * variables.
     * @param list<Syntax\Stmt> $stmts
     * @param list<Variable> $inParams
     * @param list<Variable> $outParams
     */
    private function parseBlock(Block $outer, string $fname, array $stmts, array $inParams, array $outParams, Type $returnType, bool $checkLocalVariableUsage): Block
    {
        $offset = $outer->totalLocalVariableCount();
        $params = $outer === $this->global ? [...$inParams, ...$outParams] : [];
        $block = new Block($outer, new IrBlock(localVarIndexOffset: $offset + count($params)));
        $block->vars = $params;

        if ($outer->outer === null && $outParams !== [] && $outParams[0]->name !== '') {
            foreach ($outParams as $i => $_) {
                $block->ir->stmts[] = new Stmt(StmtType::INIT, initIndex: count($inParams) + $i);
            }
        }

        foreach ($stmts as $stmt) {
            $ss = $this->parseStmt($block, $fname, $stmt, $inParams, $outParams, $returnType);
            $this->typeConsts($stmt->pos, $block, $ss, $returnType);
            array_push($block->ir->stmts, ...$ss);
        }

        if ($checkLocalVariableUsage && $block->unusedVars !== []) {
            ksort($block->unusedVars);
            foreach ($block->unusedVars as $idx => $pos) {
                $this->addError($pos, "local variable {$block->vars[$idx]->name} is not used");
            }
            throw new Abort;
        }

        // The local variables of the block but the parameters; a loop's counter is declared by its loop.
        foreach (array_slice($block->vars, count($params)) as $v) {
            $block->ir->localVars[] = $v->forLoopCounter ? new Type : $v->typ;
        }
        return $block;
    }
}
