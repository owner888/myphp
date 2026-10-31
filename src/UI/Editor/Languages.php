<?php
declare(strict_types=1);

namespace MyPHP\UI\Editor;

use MyPHP\UI\Editor\Token as T;

/** The languages MyPHP knows how to highlight (mygo's languages.go), and how a file's or a name's is found. */
final class Languages
{
    /** @var ?list<Language> */
    private static ?array $list = null;

    /** The language of a file by its name, as "main.go" or "Makefile", or null when none is known for it. */
    public static function for(string $filename): ?Language
    {
        $base = basename(str_replace('\\', '/', $filename));
        $ext = strtolower(pathinfo($base, PATHINFO_EXTENSION));
        $ext = $ext === '' ? '' : ".$ext";
        foreach (self::all() as $l) {
            if (in_array($base, $l->filenames, true)) { return $l; }
        }
        foreach (self::all() as $l) {
            if (in_array($ext, $l->extensions, true)) { return $l; }
        }
        return null;
    }

    /** The language known by a name, as "Go", "TypeScript" or "tsx", ignoring case: its name, an extension without the dot, or a usual alias, as Markdown's code blocks name them. */
    public static function named(string $name): ?Language
    {
        $name = strtolower(trim($name));
        if ($name === '') { return null; }
        $name = self::alias($name);
        foreach (self::all() as $l) {
            if (strtolower($l->name) === $name) { return $l; }
            foreach ($l->extensions as $e) { if (substr($e, 1) === $name) { return $l; } }
        }
        return null;
    }

    /** The name of the language $name is another name for. */
    private static function alias(string $name): string
    {
        return match ($name) {
            'golang' => 'go',
            'js', 'node', 'mjs', 'cjs' => 'javascript',
            'ts' => 'typescript',
            'py', 'python3' => 'python',
            'sh', 'bash', 'zsh', 'console', 'shellscript', 'fish' => 'shell',
            'yml' => 'yaml',
            'md' => 'markdown',
            'rs' => 'rust',
            'cpp', 'cc', 'cxx', 'objc', 'objective-c', 'objectivec' => 'c++',
            'cs', 'csharp' => 'c#',
            'kt' => 'kotlin',
            'rb' => 'ruby',
            'patch', 'udiff' => 'diff',
            'docker' => 'dockerfile',
            'make', 'mk' => 'makefile',
            'svg', 'plist' => 'xml',
            'jsonc', 'json5' => 'json',
            'postgres', 'postgresql', 'mysql', 'sqlite', 'plsql' => 'sql',
            'ini', 'cfg', 'conf' => 'toml',
            'proto' => 'protobuf',
            'scss', 'less' => 'css',
            default => $name,
        };
    }

    /** @return list<Language> */
    public static function all(): array
    {
        return self::$list ??= self::make();
    }

    /** ( ), [ ] and { }, and the quotes given, which do not close inside strings and comments. @return list<BracketPair> */
    private static function cAutoClose(string ...$quotes): array
    {
        $out = [new BracketPair('(', ')'), new BracketPair('[', ']'), new BracketPair('{', '}')];
        foreach ($quotes as $q) { $out[] = new BracketPair($q, $q, [T::STRING, T::COMMENT]); }
        return $out;
    }

    private const C_OPERATORS = '+-*/%=&|^!<>~?:@#';

    /** @return list<Language> */
    private static function make(): array
    {
        $cBrackets = [new BracketPair('(', ')'), new BracketPair('[', ']'), new BracketPair('{', '}')];
        $cComment = ['/*', '*/'];
        $cBlock = [['/*', '*/']];
        $q = fn(string $s) => LexString::c($s);
        $go = new Language('Go', imports: '/^import\\b/', extensions: ['.go'], lineComment: '//', blockComment: $cComment, brackets: $cBrackets, autoClose: self::cAutoClose('"', "'", '`'), tabSize: 4, hardTabs: true,
            tokenizer: new Lexer(lineComments: ['//'], blockComments: $cBlock,
                strings: [$q('"'), $q("'"), new LexString('`', '`', multiline: true)],
                words: Lexer::words(T::KEYWORD, 'break case chan const continue default defer else fallthrough for func go goto if import interface map package range return select struct switch type var',
                    T::TYPE, 'any bool byte comparable complex64 complex128 error float32 float64 int int8 int16 int32 int64 rune string uint uint8 uint16 uint32 uint64 uintptr',
                    T::CONSTANT, 'true false nil iota',
                    T::FUNCTION, 'append cap clear close complex copy delete imag len make max min new panic print println real recover'),
                calls: true, operators: self::C_OPERATORS));
        $jsWords = [T::KEYWORD, 'async await break case catch class const continue debugger default delete do else export extends finally for from function get if import in instanceof let new of return set static super switch this throw try typeof var void while with yield as',
            T::CONSTANT, 'true false null undefined NaN Infinity',
            T::TYPE, 'Array Boolean Date Error Function JSON Map Math Number Object Promise Proxy Reflect RegExp Set String Symbol WeakMap WeakSet BigInt console window document globalThis'];
        $jsStrings = [$q('"'), $q("'"), new LexString('`', '`', '\\', multiline: true, interpOpen: '${', interpClose: '}')];
        $javascript = new Language('JavaScript', imports: '/^import[\\s{*\'"]/', extensions: ['.js', '.mjs', '.cjs', '.jsx'], lineComment: '//', blockComment: $cComment, brackets: $cBrackets, autoClose: self::cAutoClose('"', "'", '`'), wordChars: '$', tabSize: 2,
            tokenizer: new Lexer(lineComments: ['//'], blockComments: $cBlock, strings: $jsStrings, words: Lexer::words(...$jsWords), identChars: '$', calls: true, capitalTypes: true, regexps: true, decorator: '@', operators: self::C_OPERATORS));
        $tsWords = [...$jsWords, T::KEYWORD, 'abstract declare enum implements interface keyof namespace private protected public readonly type infer is satisfies override module',
            T::TYPE, 'any boolean never number object string symbol unknown void bigint'];
        $typescript = new Language('TypeScript', imports: '/^import[\\s{*\'"]/', extensions: ['.ts', '.mts', '.cts', '.tsx'], lineComment: '//', blockComment: $cComment, brackets: $cBrackets, autoClose: self::cAutoClose('"', "'", '`'), wordChars: '$', tabSize: 2,
            tokenizer: new Lexer(lineComments: ['//'], blockComments: $cBlock, strings: $jsStrings, words: Lexer::words(...$tsWords), identChars: '$', calls: true, capitalTypes: true, regexps: true, decorator: '@', operators: self::C_OPERATORS));
        $json = new Language('JSON', extensions: ['.json', '.jsonc', '.json5', '.webmanifest'], filenames: ['.babelrc', '.eslintrc', 'tsconfig.json'], lineComment: '//', blockComment: $cComment, brackets: $cBrackets, autoClose: self::cAutoClose('"'), tabSize: 2,
            tokenizer: new JsonLexer(lineComments: ['//'], blockComments: $cBlock, strings: [$q('"'), $q("'")], words: Lexer::words(T::CONSTANT, 'true false null'), operators: ':'));
        $pyStrings = [];
        foreach (['f', 'F', 'fr', 'rf', 'Fr', 'rF', '', 'r', 'R', 'b', 'B', 'u', 'U', 'rb', 'br', 'Rb', 'bR'] as $p) { // the f-strings first: the lexer's stack holds the index of a string with interpolations in five bits
            $raw = strpbrk($p, 'rR') !== false; $f = strpbrk($p, 'fF') !== false;
            foreach (['"""', "'''", '"', "'"] as $qq) {
                $pyStrings[] = new LexString($qq, $qq, $raw ? '' : '\\', strlen($qq) === 3, $f ? '{' : '', $f ? '}' : '', $p);
            }
        }
        $python = new Language('Python', imports: '/^(import|from)\\s/', extensions: ['.py', '.pyi', '.pyw'], filenames: ['SConstruct', 'SConscript'], lineComment: '#', brackets: $cBrackets, autoClose: self::cAutoClose('"', "'"), tabSize: 4,
            increaseIndent: '/^\s*(class|def|elif|else|except|finally|for|if|try|while|with|async|match|case)\b.*:\s*(#.*)?$|[\[({]\s*$/',
            tokenizer: new Lexer(lineComments: ['#'], strings: $pyStrings,
                words: Lexer::words(T::KEYWORD, 'and as assert async await break class continue def del elif else except finally for from global if import in is lambda nonlocal not or pass raise return try while with yield match case',
                    T::CONSTANT, 'True False None NotImplemented Ellipsis __name__ __file__',
                    T::TYPE, 'int float str bytes bool list dict set tuple object type complex frozenset bytearray range Exception',
                    T::FUNCTION, 'print len open isinstance super self cls'),
                calls: true, capitalTypes: true, decorator: '@', operators: self::C_OPERATORS));
        $rust = new Language('Rust', imports: '/^(pub(\\([^)]*\\))?\\s+)?(use|extern\\s+crate)\\s/', extensions: ['.rs'], lineComment: '//', blockComment: $cComment, brackets: $cBrackets, autoClose: self::cAutoClose('"'), tabSize: 4,
            tokenizer: new Lexer(lineComments: ['//'], blockComments: $cBlock, nestComments: true,
                strings: [new LexString('"', '"', '\\', true), new LexString('"', '"', '\\', true, prefix: 'b'), new LexString('#"', '"#', multiline: true, prefix: 'r'), new LexString('"', '"', multiline: true, prefix: 'r'), $q("'")],
                words: Lexer::words(T::KEYWORD, 'as async await break const continue crate dyn else enum extern fn for if impl in let loop match mod move mut pub ref return self Self static struct super trait type unsafe use where while yield',
                    T::TYPE, 'bool char f32 f64 i8 i16 i32 i64 i128 isize str u8 u16 u32 u64 u128 usize String Vec Option Result Box',
                    T::CONSTANT, 'true false None Some Ok Err'),
                calls: true, capitalTypes: true, decorator: '#', operators: self::C_OPERATORS));
        $cWords = [T::KEYWORD, 'auto break case const continue default do else enum extern for goto if inline register restrict return sizeof static struct switch typedef union volatile while _Alignas _Alignof _Atomic _Bool _Generic _Noreturn _Static_assert _Thread_local',
            T::TYPE, 'char double float int long short signed unsigned void size_t ssize_t int8_t int16_t int32_t int64_t uint8_t uint16_t uint32_t uint64_t bool FILE',
            T::CONSTANT, 'NULL true false'];
        $c = new Language('C', imports: '/^#\\s*(include|import)\\b/', extensions: ['.c', '.h'], lineComment: '//', blockComment: $cComment, brackets: $cBrackets, autoClose: self::cAutoClose('"', "'"), tabSize: 4,
            tokenizer: new Lexer(lineComments: ['//'], blockComments: $cBlock, strings: [$q('"'), $q("'")], words: Lexer::words(...$cWords), calls: true, directives: true, operators: self::C_OPERATORS));
        $cpp = new Language('C++', imports: '/^#\\s*(include|import)\\b/', extensions: ['.cpp', '.cc', '.cxx', '.hpp', '.hh', '.hxx', '.ino', '.mm', '.m'], lineComment: '//', blockComment: $cComment, brackets: $cBrackets, autoClose: self::cAutoClose('"', "'"), tabSize: 4,
            tokenizer: new Lexer(lineComments: ['//'], blockComments: $cBlock, strings: [$q('"'), $q("'"), new LexString('R"(', ')"', multiline: true)],
                words: Lexer::words(...$cWords, ...[T::KEYWORD, 'alignas alignof and asm catch class concept consteval constexpr constinit co_await co_return co_yield decltype delete dynamic_cast explicit export friend mutable namespace new noexcept not operator or private protected public reinterpret_cast requires static_assert static_cast template this thread_local throw try typeid typename using virtual xor override final @interface @implementation @end @property self',
                    T::TYPE, 'wchar_t char8_t char16_t char32_t std string vector map', T::CONSTANT, 'nullptr nil YES NO']),
                calls: true, capitalTypes: true, directives: true, operators: self::C_OPERATORS));
        $javaWords = [T::KEYWORD, 'abstract assert break case catch class const continue default do else enum extends final finally for goto if implements import instanceof interface native new package private protected public return static strictfp super switch synchronized this throw throws transient try volatile while var record sealed permits yield',
            T::TYPE, 'boolean byte char double float int long short void String Object Integer',
            T::CONSTANT, 'true false null'];
        $java = new Language('Java', imports: '/^import\\s/', extensions: ['.java'], lineComment: '//', blockComment: $cComment, brackets: $cBrackets, autoClose: self::cAutoClose('"', "'"), tabSize: 4,
            tokenizer: new Lexer(lineComments: ['//'], blockComments: $cBlock, strings: [new LexString('"""', '"""', '\\', true), $q('"'), $q("'")],
                words: Lexer::words(...$javaWords), calls: true, capitalTypes: true, decorator: '@', operators: self::C_OPERATORS));
        $kotlin = new Language('Kotlin', imports: '/^import\\s/', extensions: ['.kt', '.kts'], lineComment: '//', blockComment: $cComment, brackets: $cBrackets, autoClose: self::cAutoClose('"', "'"), tabSize: 4,
            tokenizer: new Lexer(lineComments: ['//'], blockComments: $cBlock, nestComments: true,
                strings: [new LexString('"""', '"""', multiline: true, interpOpen: '${', interpClose: '}', dollar: true), new LexString('"', '"', '\\', interpOpen: '${', interpClose: '}', dollar: true), $q("'")],
                words: Lexer::words(T::KEYWORD, 'as break class continue do else for fun if in interface is object package return super this throw try typealias typeof val var when while by catch constructor delegate dynamic field file finally get import init param property receiver set setparam where actual abstract annotation companion const crossinline data enum expect external final infix inline inner internal lateinit noinline open operator out override private protected public reified sealed suspend tailrec vararg',
                    T::CONSTANT, 'true false null', T::TYPE, 'Any Boolean Byte Char Double Float Int Long Nothing Short String Unit'),
                calls: true, capitalTypes: true, decorator: '@', operators: self::C_OPERATORS));
        // Scala's interpolated strings first, as the lexer's stack holds the index of a string with interpolations in five bits.
        $scalaStrings = [];
        foreach (['s', 'f', 'raw'] as $p) {
            foreach (['"""', '"'] as $qq) {
                $scalaStrings[] = new LexString($qq, $qq, $p === 'raw' ? '' : '\\', strlen($qq) === 3, interpOpen: '${', interpClose: '}', prefix: $p, dollar: true);
            }
        }
        array_push($scalaStrings, new LexString('"""', '"""', multiline: true), $q('"'), $q("'"), new LexString('`', '`', kind: T::VARIABLE));
        $scala = new Language('Scala', imports: '/^import\\s/', extensions: ['.scala', '.sc', '.sbt'], lineComment: '//', blockComment: $cComment, brackets: $cBrackets, autoClose: self::cAutoClose('"', "'"), tabSize: 2,
            tokenizer: new Lexer(lineComments: ['//'], blockComments: $cBlock, nestComments: true, strings: $scalaStrings,
                words: Lexer::words(T::KEYWORD, 'abstract case catch class def derives do else end enum export extends extension final finally for forSome given if implicit import inline lazy match new object opaque override package private protected return sealed super then this throw trait transparent try type using val var while with yield',
                    T::TYPE, 'Any AnyRef AnyVal Boolean Byte Char Double Float Int Long Nothing Null Short String Unit',
                    T::CONSTANT, 'true false null None Some Nil'),
                calls: true, capitalTypes: true, decorator: '@', operators: self::C_OPERATORS));
        // Dart's interpolated strings first, then its raw ones, r'…'.
        $dartStrings = [];
        foreach ([false, true] as $raw) {
            foreach (['"""', "'''", '"', "'"] as $qq) {
                $dartStrings[] = $raw ? new LexString($qq, $qq, multiline: strlen($qq) === 3, prefix: 'r')
                    : new LexString($qq, $qq, '\\', strlen($qq) === 3, interpOpen: '${', interpClose: '}', dollar: true);
            }
        }
        $dart = new Language('Dart', imports: '/^(import|export|part)\\s/', extensions: ['.dart'], lineComment: '//', blockComment: $cComment, brackets: $cBrackets, autoClose: self::cAutoClose('"', "'"), wordChars: '$', tabSize: 2,
            tokenizer: new Lexer(lineComments: ['//'], blockComments: $cBlock, nestComments: true, strings: $dartStrings,
                words: Lexer::words(T::KEYWORD, 'abstract as assert async await base break case catch class const continue covariant default deferred do else enum export extends extension external factory final finally for get hide if implements import in interface is late library mixin new on operator part required rethrow return sealed set show static super switch sync this throw try typedef var when while with yield',
                    T::TYPE, 'bool double dynamic int num void Never Null Object String List Map Set Iterable Future FutureOr Stream Function Record Symbol Type',
                    T::CONSTANT, 'true false null'),
                identChars: '$', calls: true, capitalTypes: true, decorator: '@', operators: self::C_OPERATORS));
        $swift = new Language('Swift', imports: '/^(@\\w+(\\([^)]*\\))?\\s+)?import\\s/', extensions: ['.swift'], lineComment: '//', blockComment: $cComment, brackets: $cBrackets, autoClose: self::cAutoClose('"'), tabSize: 4,
            tokenizer: new Lexer(lineComments: ['//'], blockComments: $cBlock, nestComments: true,
                strings: [new LexString('"""', '"""', '\\', true), $q('"')],
                words: Lexer::words(T::KEYWORD, 'associatedtype class deinit enum extension fileprivate func import init inout internal let open operator private precedencegroup protocol public rethrows static struct subscript typealias var break case catch continue default defer do else fallthrough for guard if in repeat return throw switch where while as is try await async throws some any self Self super actor nonisolated isolated mutating nonmutating override weak unowned lazy final required convenience',
                    T::CONSTANT, 'true false nil', T::TYPE, 'Int Double Float String Bool Character Array Dictionary Set Optional Void'),
                calls: true, capitalTypes: true, decorator: '@', directives: true, operators: self::C_OPERATORS));
        $csharp = new Language('C#', imports: '/^(global\\s+)?using\\s+(static\\s+)?[\\w.]+(\\s*=\\s*[^;]+)?;/', extensions: ['.cs', '.csx'], lineComment: '//', blockComment: $cComment, brackets: $cBrackets, autoClose: self::cAutoClose('"', "'"), tabSize: 4,
            tokenizer: new Lexer(lineComments: ['//'], blockComments: $cBlock,
                strings: [new LexString('@"', '"', multiline: true), new LexString('$"', '"', '\\', interpOpen: '{', interpClose: '}'), $q('"'), $q("'")],
                words: Lexer::words(T::KEYWORD, 'abstract as base break case catch checked class const continue default delegate do else enum event explicit extern finally fixed for foreach goto if implicit in interface internal is lock namespace new operator out override params private protected public readonly ref return sealed sizeof stackalloc static struct switch this throw try typeof unchecked unsafe using virtual volatile while async await var get set init record when where yield',
                    T::TYPE, 'bool byte char decimal double float int long object sbyte short string uint ulong ushort void dynamic',
                    T::CONSTANT, 'true false null'),
                calls: true, capitalTypes: true, directives: true, operators: self::C_OPERATORS));
        $shell = new Language('Shell', extensions: ['.sh', '.bash', '.zsh', '.fish', '.ksh', '.command'], filenames: ['.bashrc', '.zshrc', '.profile', '.bash_profile', 'PKGBUILD', '.envrc'], lineComment: '#', brackets: $cBrackets, autoClose: self::cAutoClose('"', "'", '`'), tabSize: 2,
            increaseIndent: '/(\bthen|\bdo|\{|\(|\belse)\s*$/', decreaseIndent: '/^\s*(fi|done|esac|else|elif|\}|\))\b/',
            tokenizer: new Lexer(lineComments: ['#'],
                strings: [new LexString('"', '"', '\\', true, dollar: true), new LexString("'", "'", multiline: true), new LexString('`', '`', '\\')],
                words: Lexer::words(T::KEYWORD, 'if then else elif fi case esac for select while until do done in function time coproc return exit break continue local export declare readonly unset shift source alias eval exec set trap',
                    T::FUNCTION, 'echo printf cd pwd test read mkdir rm cp mv ls cat grep sed awk find xargs sudo chmod chown curl git go bun npm'),
                identChars: '-', variables: true, operators: '|&;<>=!*?'));
        $yaml = new Language('YAML', extensions: ['.yaml', '.yml'], filenames: ['.clang-format'], lineComment: '#', brackets: $cBrackets, autoClose: self::cAutoClose('"', "'"), tabSize: 2,
            increaseIndent: '/:\s*(#.*)?$|^\s*-\s*$/',
            tokenizer: new Lexer(lineComments: ['#'], strings: [$q('"'), new LexString("'", "'")], words: Lexer::words(T::CONSTANT, 'true false null yes no on off True False Null ~'), keys: true, identChars: '-./', operators: '|>-*&!?:'));
        $toml = new Language('TOML', extensions: ['.toml', '.ini', '.cfg', '.conf', '.properties', '.editorconfig'], filenames: ['Cargo.lock', '.gitconfig', '.npmrc'], lineComment: '#', brackets: $cBrackets, autoClose: self::cAutoClose('"', "'"), tabSize: 2,
            tokenizer: new TomlLexer(lineComments: ['#', ';'],
                strings: [new LexString('"""', '"""', '\\', true), new LexString("'''", "'''", multiline: true), $q('"'), new LexString("'", "'")],
                words: Lexer::words(T::CONSTANT, 'true false'), identChars: '-.', operators: '='));
        $sql = new Language('SQL', extensions: ['.sql'], lineComment: '--', blockComment: $cComment, brackets: $cBrackets, autoClose: self::cAutoClose('"', "'"), tabSize: 2,
            tokenizer: new Lexer(lineComments: ['--'], blockComments: $cBlock, foldCase: true,
                strings: [new LexString("'", "'", multiline: true), new LexString('"', '"', kind: T::VARIABLE), new LexString('`', '`', kind: T::VARIABLE)],
                words: Lexer::words(T::KEYWORD, 'select from where and or not insert into values update set delete create table drop alter add column index view as on join left right inner outer full cross group by order having limit offset union all distinct case when then else end is null in exists between like primary key foreign references default unique check constraint begin commit rollback transaction if returning with recursive asc desc trigger function procedure replace temporary temp database schema grant revoke cascade conflict do nothing using natural explain analyze pragma vacuum',
                    T::TYPE, 'int integer bigint smallint tinyint real float double decimal numeric text varchar char boolean bool date time timestamp blob json jsonb uuid serial',
                    T::CONSTANT, 'true false null'),
                calls: true, operators: '+-*/%=<>!|'));
        // R's strings go on over lines; its raw strings, r"(…)", R'[…]' and the like, have no escapes; and its %op% operators, as %in% and
        // %>%, are lexed as strings are.
        $rStrings = [new LexString('"', '"', '\\', true), new LexString("'", "'", '\\', true), new LexString('%', '%', kind: T::OPERATOR), new LexString('`', '`', kind: T::VARIABLE)];
        foreach (['r', 'R'] as $p) {
            foreach (['"', "'"] as $qq) {
                foreach (['()', '[]', '{}'] as $br) {
                    $rStrings[] = new LexString($qq . $br[0], $br[1] . $qq, multiline: true, prefix: $p);
                }
            }
        }
        $r = new Language('R', imports: '/^(library|require)\\(/', extensions: ['.r'], filenames: ['.Rprofile'], lineComment: '#', brackets: $cBrackets, autoClose: self::cAutoClose('"', "'", '`'), wordChars: '.', tabSize: 2,
            tokenizer: new Lexer(lineComments: ['#'], strings: $rStrings,
                words: Lexer::words(T::KEYWORD, 'if else repeat while function for in next break return',
                    T::CONSTANT, 'TRUE FALSE NULL NA NA_integer_ NA_real_ NA_complex_ NA_character_ Inf NaN',
                    T::FUNCTION, 'library require source print cat paste paste0 stop warning message invisible'),
                identChars: '.', calls: true, operators: '+-*/^=&|!<>~?:$@\\'));
        $ruby = new Language('Ruby', imports: '/^require(_relative)?\\b/', extensions: ['.rb', '.rake', '.gemspec'], filenames: ['Gemfile', 'Rakefile', 'Podfile', 'Fastfile'], lineComment: '#', brackets: $cBrackets, autoClose: self::cAutoClose('"', "'"), tabSize: 2,
            increaseIndent: '/^\s*(class|module|def|if|unless|else|elsif|while|until|for|begin|rescue|ensure|case|when)\b|\bdo(\s*\|.*\|)?\s*$|[\[({]\s*$/', decreaseIndent: '/^\s*(end|else|elsif|rescue|ensure|when)\b/',
            tokenizer: new Lexer(lineComments: ['#'],
                strings: [new LexString('"', '"', '\\', true, interpOpen: '#{', interpClose: '}'), new LexString("'", "'", '\\', true)],
                words: Lexer::words(T::KEYWORD, 'alias and begin break case class def defined? do else elsif end ensure for if in module next not or redo rescue retry return self super then undef unless until when while yield require require_relative attr_accessor attr_reader attr_writer private protected public',
                    T::CONSTANT, 'true false nil'),
                identChars: '?!', calls: true, capitalTypes: true, operators: self::C_OPERATORS));
        $lua = new Language('Lua', imports: '/^local\\s+\\w+\\s*=\\s*require\\b/', extensions: ['.lua'], lineComment: '--', blockComment: ['--[[', ']]'], brackets: $cBrackets, autoClose: self::cAutoClose('"', "'"), tabSize: 2,
            increaseIndent: '/\b(then|do|else|function\b.*\))\s*$|^\s*(local\s+)?function\b|[\[({]\s*$/', decreaseIndent: '/^\s*(end|else|elseif|until|\}|\))/',
            tokenizer: new Lexer(lineComments: ['--'], blockComments: [['--[[', ']]']],
                strings: [new LexString('[[', ']]', multiline: true), $q('"'), $q("'")],
                words: Lexer::words(T::KEYWORD, 'and break do else elseif end for function goto if in local not or repeat return then until while', T::CONSTANT, 'true false nil'),
                calls: true, operators: '+-*/%^#=<>~.:'));
        $php = new Language('PHP', imports: '/^(use|require|require_once|include|include_once)\\s/', extensions: ['.php'], lineComment: '//', blockComment: $cComment, brackets: $cBrackets, autoClose: self::cAutoClose('"', "'"), tabSize: 4,
            tokenizer: new PhpLexer(new Lexer(lineComments: ['//'], blockComments: $cBlock, // # comments, heredocs and tags are PhpLexer's
                strings: [new LexString('"', '"', '\\', true, dollar: true), new LexString("'", "'", '\\', true)],
                words: Lexer::words(T::KEYWORD, 'abstract and array as break callable case catch class clone const continue declare default die do echo else elseif empty enddeclare endfor endforeach endif endswitch endwhile enum exit extends final finally fn for foreach function global goto if implements include include_once instanceof insteadof interface isset list match namespace new or print private protected public readonly require require_once return static switch throw trait try unset use var while xor yield',
                    T::TYPE, 'bool float int iterable mixed never object parent self string void',
                    T::CONSTANT, 'true false null TRUE FALSE NULL'),
                calls: true, capitalTypes: true, variables: true, operators: self::C_OPERATORS)));
        $css = new Language('CSS', imports: '/^@(import|use|forward)\\s/', extensions: ['.css', '.scss', '.less', '.sass', '.pcss'], blockComment: $cComment, brackets: $cBrackets, autoClose: self::cAutoClose('"', "'"), wordChars: '-', tabSize: 2, tokenizer: new CssLexer);
        $html = new Language('HTML', extensions: ['.html', '.htm', '.xhtml', '.vue', '.svelte'], blockComment: ['<!--', '-->'],
            brackets: [new BracketPair('<', '>'), new BracketPair('(', ')'), new BracketPair('[', ']'), new BracketPair('{', '}')],
            autoClose: self::cAutoClose('"', "'"), wordChars: '-', tabSize: 2,
            increaseIndent: '/<(\w[\w-]*)(\s[^>]*)?>[^<]*$|[\[({]\s*$/', decreaseIndent: '/^\s*<\//',
            tokenizer: new MarkupLexer(true, $javascript->tokenizer, $css->tokenizer)); // HTML's tokenizer highlights the scripts and styles inside it
        $xml = new Language('XML', extensions: ['.xml', '.svg', '.plist', '.xsd', '.xsl', '.rss', '.atom', '.csproj', '.storyboard', '.xib'], blockComment: ['<!--', '-->'],
            brackets: [new BracketPair('<', '>')], autoClose: self::cAutoClose('"', "'"), wordChars: '-:', tabSize: 2,
            increaseIndent: '/<(\w[\w:-]*)(\s[^>]*)?>[^<]*$/', decreaseIndent: '/^\s*<\//', tokenizer: new MarkupLexer);
        $markdown = new Language('Markdown', extensions: ['.md', '.markdown', '.mdx', '.mkd'], filenames: ['README', 'CHANGELOG'], blockComment: ['<!--', '-->'], brackets: $cBrackets,
            autoClose: [new BracketPair('(', ')'), new BracketPair('[', ']'), new BracketPair('{', '}'), new BracketPair('`', '`', [T::STRING, T::COMMENT])],
            tabSize: 2, tokenizer: new MarkdownLexer);
        $diff = new Language('Diff', extensions: ['.diff', '.patch', '.rej'], tabSize: 4, tokenizer: new DiffTokenizer);
        $dockerfile = new Language('Dockerfile', extensions: ['.dockerfile'], filenames: ['Dockerfile', 'Containerfile'], lineComment: '#', brackets: $cBrackets, autoClose: self::cAutoClose('"', "'"), tabSize: 4,
            tokenizer: new Lexer(lineComments: ['#'],
                strings: [new LexString('"', '"', '\\', dollar: true), new LexString("'", "'")],
                words: Lexer::words(T::KEYWORD, 'FROM AS RUN CMD LABEL MAINTAINER EXPOSE ENV ADD COPY ENTRYPOINT VOLUME USER WORKDIR ARG ONBUILD STOPSIGNAL HEALTHCHECK SHELL'),
                variables: true, identChars: '-', operators: '=&|;<>'));
        $makefile = new Language('Makefile', extensions: ['.mk', '.mak'], filenames: ['Makefile', 'makefile', 'GNUmakefile'], lineComment: '#', brackets: $cBrackets, autoClose: self::cAutoClose('"', "'"), tabSize: 4, hardTabs: true,
            tokenizer: new Lexer(lineComments: ['#'],
                strings: [new LexString('"', '"', '\\', dollar: true), new LexString("'", "'")],
                words: Lexer::words(T::KEYWORD, 'ifeq ifneq ifdef ifndef else endif include define endef export override .PHONY'),
                variables: true, keys: true, identChars: '-./', operators: '=:?+!@'));
        $protobuf = new Language('Protobuf', imports: '/^import\\s/', extensions: ['.proto'], lineComment: '//', blockComment: $cComment, brackets: $cBrackets, autoClose: self::cAutoClose('"'), tabSize: 2,
            tokenizer: new Lexer(lineComments: ['//'], blockComments: $cBlock, strings: [$q('"'), $q("'")],
                words: Lexer::words(T::KEYWORD, 'syntax package import option message enum service rpc returns repeated optional required oneof map reserved extend extensions to max stream public weak',
                    T::TYPE, 'double float int32 int64 uint32 uint64 sint32 sint64 fixed32 fixed64 sfixed32 sfixed64 bool string bytes',
                    T::CONSTANT, 'true false'),
                capitalTypes: true, operators: '=<>'));
        $graphql = new Language('GraphQL', extensions: ['.graphql', '.gql', '.graphqls'], lineComment: '#', brackets: $cBrackets, autoClose: self::cAutoClose('"'), tabSize: 2,
            tokenizer: new Lexer(lineComments: ['#'],
                // A spread, ..., is a string that ends where it starts, so that the name after it is no property, as one after a dot is.
                strings: [new LexString('"""', '"""', multiline: true), $q('"'), new LexString('...', '', kind: T::PUNCTUATION)],
                words: Lexer::words(T::KEYWORD, 'query mutation subscription fragment on type interface union enum input scalar schema extend implements directive repeatable',
                    T::TYPE, 'Int Float String Boolean ID',
                    T::CONSTANT, 'true false null'),
                capitalTypes: true, keys: true, variables: true, decorator: '@', operators: '!=:|&'));
        $zig = new Language('Zig', imports: '/^(pub\\s+)?const\\s+\\w+\\s*=\\s*@import\\(/', extensions: ['.zig', '.zon'], lineComment: '//', brackets: $cBrackets, autoClose: self::cAutoClose('"', "'"), tabSize: 4,
            tokenizer: new Lexer(lineComments: ['//'], strings: [$q('"'), $q("'"), new LexString('\\\\', "\0")],
                words: Lexer::words(T::KEYWORD, 'addrspace align allowzero and anyframe anytype asm async await break callconv catch comptime const continue defer else enum errdefer error export extern fn for if inline linksection noalias noinline nosuspend opaque or orelse packed pub resume return struct suspend switch test threadlocal try union unreachable usingnamespace var volatile while',
                    T::TYPE, 'i8 u8 i16 u16 i32 u32 i64 u64 i128 u128 isize usize f16 f32 f64 f80 f128 bool void noreturn type anyerror comptime_int comptime_float',
                    T::CONSTANT, 'true false null undefined'),
                calls: true, capitalTypes: true, decorator: '@', operators: self::C_OPERATORS));
        return [$go, $javascript, $typescript, $json, $python, $rust, $c, $cpp, $java, $kotlin, $scala, $swift, $dart, $csharp,
            $shell, $yaml, $toml, $sql, $r, $ruby, $lua, $php, $css, $html, $xml, $markdown, $diff, $dockerfile, $makefile, $protobuf, $graphql, $zig];
    }
}
