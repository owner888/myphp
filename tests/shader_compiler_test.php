<?php
// The shader compiler (UI\Shader\Compiler, the port of mygo's internal/shader)
// against its oracle: for every source mygo's compiler took, the same
// syntax error, the same compile error, or the same program. Then the
// shaders of mygo's examples and ui/shader tests, compiled as ui/shader
// compiles them (compileFragment, with the bridge).
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\Shader\Compiler\{Compiler, ParseError};
use MyPHP\UI\Shader\Syntax\{Parser, SyntaxError};

require_once __DIR__ . '/ShaderOracle.php';

(function (): void {
    // The one allowance: an error Go prints with %#v, a syntax node's fields
    // and the addresses (0xc...) of the nodes in them, which no port can
    // give. The port gives the node's kind, &ast.FuncLit{...}, so these
    // compare up to its fields (5 of the oracle's cases: a function literal,
    // a go statement, a channel type, two slice expressions).
    $addressed = fn(string $msg): string => preg_replace('/(&ast\.\w+)\{[^\n]*\(0x[0-9a-f]+\)[^\n]*$/m', '$1{...}', $msg);

    // What $compile makes of $src against what Go made: null when it matches, or how it doesn't.
    $judge = function (\Closure $compile, array $case) use ($addressed): ?string {
        $want = $case['error'] ?? null;
        if (isset($case['syntax']) || $want !== null && $want !== 'panic') {
            try {
                $compile();
            } catch (ParseError $e) {
                if (isset($case['syntax']) || $addressed($e->getMessage()) === $addressed($want)) {
                    return null;
                }
                return "error " . json_encode($e->getMessage()) . ", want " . json_encode($want);
            } catch (\Throwable $e) {
                return 'threw ' . get_class($e) . ': ' . $e->getMessage() . ' at ' . basename($e->getFile()) . ':' . $e->getLine() . ', want ' . json_encode($case['syntax'] ?? $want);
            }
            return 'compiled, want ' . json_encode($case['syntax'] ?? $want);
        }
        try {
            $p = $compile();
        } catch (\Throwable $e) {
            return 'threw ' . get_class($e) . ': ' . $e->getMessage() . ' at ' . basename($e->getFile()) . ':' . $e->getLine();
        }
        $got = irNormalize(irDump($p));
        $want = irNormalize($case['ir']);
        if ($got == $want) {
            return null;
        }
        // The first place they differ, as a path into the dump.
        $diff = function (mixed $a, mixed $b, string $path) use (&$diff): string {
            if (is_array($a) && is_array($b)) {
                foreach (array_unique([...array_keys($a), ...array_keys($b)]) as $k) {
                    if (($a[$k] ?? null) != ($b[$k] ?? null)) {
                        return $diff($a[$k] ?? null, $b[$k] ?? null, "$path.$k");
                    }
                }
            }
            return "$path: got " . json_encode($a) . ', want ' . json_encode($b);
        };
        return 'program differs at ' . $diff($got, $want, 'ir');
    };

    // Reports the cases that don't match, the first few in full.
    $report = function (string $what, array $bad, int $total): void {
        $lines = [];
        foreach (array_slice($bad, 0, 5, true) as $i => $why) {
            $lines[] = "  #$i: $why";
        }
        check($bad === [], sprintf("%s: %d of %d cases don't match mygo's%s", $what, count($bad), $total, $bad === [] ? '' : ":\n" . implode("\n", $lines)));
    };

    $cases = shaderOracle();

    // The parser: every source Go's parser took parses, every other fails.
    $bad = [];
    foreach ($cases as $i => $case) {
        try {
            Parser::parse($case['src']);
            if (isset($case['syntax'])) {
                $bad[$i] = 'parsed, want ' . $case['syntax'];
            }
        } catch (SyntaxError $e) {
            if (!isset($case['syntax'])) {
                [$line, $col] = Parser::position($case['src'], $e->at);
                $bad[$i] = "$line:$col: {$e->getMessage()}";
            }
        }
    }
    $report('shader parser', $bad, count($cases));

    // The compiler.
    $bad = [];
    foreach ($cases as $i => $case) {
        $why = $judge(fn() => Compiler::compile($case['src'], $case['vertex'], $case['fragment'], $case['textures']), $case);
        if ($why !== null) {
            $bad[$i] = $why;
        }
    }
    $report('shader compiler', $bad, count($cases));

    // The shaders of mygo's examples and tests, with the bridge.
    $fragments = json_decode(gzdecode((string)file_get_contents(__DIR__ . '/testdata/shader/fragment.json.gz')), true, 512, JSON_THROW_ON_ERROR);
    $bad = [];
    foreach ($fragments as $i => $case) {
        $why = $judge(fn() => Compiler::compileFragment($case['full']), $case);
        if ($why !== null) {
            $bad["$i {$case['file']}"] = $why;
        }
    }
    $report('shader compileFragment', $bad, count($fragments));

    // An error's positions, as Go's ParseError.Positions gives them.
    try {
        Compiler::compile("package main\n\nfunc Fragment() vec4 {\n\tx := 1\n\treturn vec4(0)\n}\n", 'Vertex', 'Fragment', 0);
        check(false, 'shader compiler: an unused variable is an error');
    } catch (ParseError $e) {
        same('4:2: local variable x is not used', $e->getMessage(), 'shader compiler: the error');
        same([[4, 2, 38]], array_map(fn($p) => [$p->line, $p->column, $p->offset], $e->positions()), 'shader compiler: its position');
    }
})();
