<?php
declare(strict_types=1);

namespace MyPHP\Native;

use RuntimeException;

/**
 * Rules in WebKit's content blocker format matched in PHP (mygo's
 * contentrules package), for the engines that have no content blockers
 * (WebView2): parse() takes the rules as WebKit compiles them, failing on
 * what it would not; match() tells what lists do to a load;
 * hidingScript() hides what their css-display-none rules select.
 *
 * A list indexes its rules as mygo's does: those whose url-filter
 * matches only a domain and its subdomains (as converters write Adblock
 * Plus's "||") by that domain, the others by four bytes of the literal
 * every URL they match has, so a load tries few of them.
 *
 * @internal Platform\Windows
 */
final class ContentRuleList
{
    public const DOCUMENT = 1, IMAGE = 2, STYLE_SHEET = 4, SCRIPT = 8, FONT = 16, RAW = 32, SVG_DOCUMENT = 64, MEDIA = 128,
        POPUP = 256, PING = 512, FETCH = 1024, WEBSOCKET = 2048, OTHER = 4096;
    private const TYPES = ['document' => self::DOCUMENT, 'image' => self::IMAGE, 'style-sheet' => self::STYLE_SHEET, 'script' => self::SCRIPT,
        'font' => self::FONT, 'raw' => self::RAW, 'svg-document' => self::SVG_DOCUMENT, 'media' => self::MEDIA, 'popup' => self::POPUP,
        'ping' => self::PING, 'fetch' => self::FETCH, 'websocket' => self::WEBSOCKET, 'other' => self::OTHER];
    private const BLOCK = 1, BLOCK_COOKIES = 2, MAKE_HTTPS = 3, HIDE = 4, IGNORE_PREVIOUS = 5;
    private const ACTIONS = ['block' => self::BLOCK, 'block-cookies' => self::BLOCK_COOKIES, 'make-https' => self::MAKE_HTTPS,
        'css-display-none' => self::HIDE, 'ignore-previous-rules' => self::IGNORE_PREVIOUS];
    private const ANY_PARTY = 0, FIRST_PARTY = 1, THIRD_PARTY = 2;
    /** Starts the url-filters that match a domain and its subdomains. */
    private const DOMAIN_PREFIX = '^[^:]+://+([^:/]+\.)?';

    /**
     * The suffixes under which sites are registered of more than one label
     * that the registrable domain (eTLD+1) takes: a short list of
     * publicsuffix.org's, where mygo has the whole of it
     * (docs/upstream-deviations.md); besides them a country's two letters
     * under one of SLDS are one.
     */
    private const SUFFIXES = ['github.io', 'gitlab.io', 'pages.dev', 'workers.dev', 'vercel.app', 'netlify.app', 'herokuapp.com',
        'appspot.com', 'blogspot.com', 'cloudfront.net', 'azurewebsites.net', 'web.app', 'firebaseapp.com', 'fly.dev', 'onrender.com',
        'glitch.me', 'repl.co', 's3.amazonaws.com', 'readthedocs.io', 'surge.sh', 'neocities.org', 'myshopify.com', 'wordpress.com', 'tumblr.com'];
    private const SLDS = ['co', 'com', 'net', 'org', 'gov', 'edu', 'ac', 'ne', 'or', 'go', 'gob', 'mil', 'nic', 'sch', 'ltd', 'plc'];

    /**
     * The rules: [filter, types (0 for every type), load type, domains,
     * unless (the domains are unless-domain's), top URL patterns, unless
     * top (theirs are unless-top-url's), action]; a pattern is [regex,
     * literal (lowercase unless case-sensitive), case-sensitive].
     * @var list<array{0: array{0: string, 1: string, 2: bool}, 1: int, 2: int, 3: list<string>, 4: bool, 5: list<array{0: string, 1: string, 2: bool}>, 6: bool, 7: int}>
     */
    private array $rules = [];
    /** @var array<string, list<int>> the rules matching a domain and its subdomains alone, by that domain */
    private array $byDomain = [];
    /** @var array<string, list<int>> the others whose literal has four bytes or more, by four of them */
    private array $byGram = [];
    /** @var list<int> the rest, which every load tries */
    private array $others = [];
    /** The css-display-none and ignore-previous-rules rules, as JSON, for hidingScript; '' for none. */
    private string $cosmetic = '';

    private function __construct() {}

    /** The rules parsed, as WebKit compiles them; throws with what is wrong (mygo's Parse). */
    public static function parse(string $rules): self
    {
        $raw = json_decode($rules);
        if (!is_array($raw)) {
            throw new RuntimeException('rules are not a JSON array of rules');
        }
        $l = new self();
        $cosmetic = [];
        foreach ($raw as $i => $r) {
            $t = is_object($r) && isset($r->trigger) && is_object($r->trigger) ? $r->trigger : null;
            $filter = $t?->{'url-filter'} ?? null;
            $sensitive = $t?->{'url-filter-is-case-sensitive'} ?? false;
            $lists = [];
            foreach (['if-domain', 'unless-domain', 'resource-type', 'load-type', 'if-top-url', 'unless-top-url'] as $k) {
                $v = $t?->{$k} ?? [];
                $lists[$k] = is_array($v) && array_filter($v, 'is_string') === $v ? $v : null;
            }
            if (!is_string($filter) || !is_bool($sensitive) || in_array(null, $lists, true)) {
                throw new RuntimeException("rule $i: a trigger needs a url-filter");
            }
            $act = is_object($r->action ?? null) ? $r->action : new \stdClass();
            $type = is_string($act->type ?? null) ? $act->type : '';
            $selector = is_string($act->selector ?? null) ? $act->selector : '';
            $action = self::ACTIONS[$type] ?? throw new RuntimeException("rule $i: invalid action type \"$type\"");
            if ($action === self::HIDE && trim($selector) === '') {
                throw new RuntimeException("rule $i: css-display-none needs a selector");
            }
            $pattern = self::pattern($filter, $sensitive, $i);
            $types = 0;
            foreach ($lists['resource-type'] as $name) {
                $types |= self::TYPES[$name] ?? throw new RuntimeException("rule $i: invalid resource-type \"$name\"");
            }
            $load = self::ANY_PARTY;
            foreach ($lists['load-type'] as $name) {
                $load = match ($name) {
                    'first-party' => self::FIRST_PARTY,
                    'third-party' => self::THIRD_PARTY,
                    default => throw new RuntimeException("rule $i: invalid load-type \"$name\""),
                };
            }
            if (count($lists['load-type']) > 1) {
                $load = self::ANY_PARTY;
            }
            if (count(array_filter([$lists['if-domain'], $lists['unless-domain'], $lists['if-top-url'], $lists['unless-top-url']])) > 1) {
                throw new RuntimeException("rule $i: a trigger takes one of if-domain, unless-domain, if-top-url and unless-top-url");
            }
            $unless = $lists['unless-domain'] !== [];
            $domains = $unless ? $lists['unless-domain'] : $lists['if-domain'];
            foreach ($domains as $d) {
                if (preg_match('/[A-Z\x80-\xff]/', $d) === 1) {
                    throw new RuntimeException("rule $i: domains must be lowercase ASCII (punycode)");
                }
            }
            $unlessTop = $lists['unless-top-url'] !== [];
            $top = [];
            foreach ([...$lists['if-top-url'], ...$lists['unless-top-url']] as $src) {
                $top[] = self::pattern($src, $sensitive, $i);
            }
            if ($action === self::HIDE || $action === self::IGNORE_PREVIOUS) {
                $e = ['u' => $filter === '.*' ? '' : $filter, 'c' => $sensitive, 'd' => $lists['if-domain'], 'n' => $lists['unless-domain'],
                    't' => $lists['if-top-url'], 'nt' => $lists['unless-top-url'], 'i' => $action === self::IGNORE_PREVIOUS];
                $trigger = json_encode($t);
                $last = count($cosmetic) - 1;
                if ($action === self::HIDE && $last >= 0 && !$cosmetic[$last][0]['i'] && $cosmetic[$last][1] === $trigger) {
                    $cosmetic[$last][0]['s'][] = $selector; // a run of the same trigger's
                } else {
                    if ($action === self::HIDE) { $e = ['s' => [$selector]] + $e; }
                    $cosmetic[] = [$e, $trigger];
                }
                if ($action === self::HIDE) {
                    continue;
                }
            }
            $l->rules[] = [$pattern, $types, $load, $domains, $unless, $top, $unlessTop, $action];
            $n = count($l->rules) - 1;
            if (($d = self::filterDomain($filter)) !== '') {
                $l->byDomain[$d][] = $n;
            } elseif (($g = $l->rarestGram(strtolower($pattern[1]))) !== null) {
                $l->byGram[$g][] = $n;
            } else {
                $l->others[] = $n;
            }
        }
        if ($raw === []) {
            throw new RuntimeException('no rules');
        }
        if (array_filter($cosmetic, fn($e) => !$e[0]['i']) !== []) {
            $l->cosmetic = json_encode(array_map(fn($e) => array_filter($e[0], fn($v) => $v !== '' && $v !== false && $v !== []), $cosmetic),
                JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }
        return $l;
    }

    /**
     * What the lists do to a load of $url for the page at $top ('' for
     * the page itself), of $type (TYPE bits, any of which a rule's
     * resource-type may name): each list applies its rules in order, and
     * its ignore-previous-rules undoes only what its own did (mygo's Match).
     * @param list<self> $lists
     * @return array{block: bool, blockCookies: bool, makeHTTPS: bool}
     */
    public static function match(array $lists, string $url, string $top, int $type): array
    {
        $top = $top === '' ? $url : $top;
        $lu = strtolower($url); $lt = strtolower($top);
        $host = self::urlHost($lu); $topHost = self::urlHost($lt);
        $c = ['url' => $url, 'lower' => $lu, 'top' => $top, 'lowerTop' => $lt, 'topHost' => $topHost, 'type' => $type,
            'third' => self::site($host) !== self::site($topHost)];
        $out = ['block' => false, 'blockCookies' => false, 'makeHTTPS' => false];
        foreach ($lists as $l) {
            foreach ($l->apply($c, $host) as $k => $v) { $out[$k] = $out[$k] || $v; }
        }
        return $out;
    }

    /**
     * JavaScript that hides, in a document of a page applying $lists, the
     * elements their css-display-none rules select for its URL, '' when
     * they hide nothing (mygo's HidingScript): a style sheet the document
     * adopts, each selector a rule of its own, so that one the engine does
     * not take leaves the others be. @param list<self> $lists
     */
    public static function hidingScript(array $lists): string
    {
        $data = array_values(array_filter(array_map(fn(self $l) => $l->cosmetic, $lists)));
        return $data === [] ? '' : '(()=>{const lists=[' . implode(',', $data) . '];' . self::COSMETIC . '})();';
    }

    /** @return list<array<string, mixed>> the cosmetic entries, decoded, @internal tests */
    public function cosmeticEntries(): array
    {
        return $this->cosmetic === '' ? [] : json_decode($this->cosmetic, true);
    }

    /** @return array{block: bool, blockCookies: bool, makeHTTPS: bool} */
    private function apply(array $c, string $host): array
    {
        $candidates = [];
        for ($h = $host; $h !== ''; ) {
            foreach ($this->byDomain[$h] ?? [] as $n) { $candidates[] = $n; }
            $dot = strpos($h, '.');
            if ($dot === false) { break; }
            $h = substr($h, $dot + 1);
        }
        if ($this->byGram !== []) {
            for ($i = 0, $len = strlen($c['lower']); $i + 4 <= $len; $i++) {
                foreach ($this->byGram[substr($c['lower'], $i, 4)] ?? [] as $n) { $candidates[] = $n; }
            }
        }
        if ($candidates === []) {
            $candidates = $this->others;
        } else {
            $candidates = array_unique([...$candidates, ...$this->others]);
            sort($candidates);
        }
        $out = ['block' => false, 'blockCookies' => false, 'makeHTTPS' => false];
        foreach ($candidates as $n) {
            $r = $this->rules[$n];
            if (!self::matches($r, $c)) {
                continue;
            }
            switch ($r[7]) {
                case self::BLOCK: $out['block'] = true; break;
                case self::BLOCK_COOKIES: $out['blockCookies'] = true; break;
                case self::MAKE_HTTPS: $out['makeHTTPS'] = str_starts_with($c['lower'], 'http:') || str_starts_with($c['lower'], 'ws:'); break;
                case self::IGNORE_PREVIOUS: $out = ['block' => false, 'blockCookies' => false, 'makeHTTPS' => false]; break;
            }
        }
        return $out;
    }

    private static function matches(array $r, array $c): bool
    {
        [$filter, $types, $load, $domains, $unless, $top, $unlessTop] = $r;
        if ($types !== 0 && ($types & $c['type']) === 0) {
            return false;
        }
        if ($load === self::FIRST_PARTY && $c['third'] || $load === self::THIRD_PARTY && !$c['third']) {
            return false;
        }
        if ($domains !== [] && self::domainListed($domains, $c['topHost']) === $unless) {
            return false;
        }
        if ($top !== []) {
            $matched = false;
            foreach ($top as $p) {
                if (self::test($p, $c['top'], $c['lowerTop'])) { $matched = true; break; }
            }
            if ($matched === $unlessTop) {
                return false;
            }
        }
        return self::test($filter, $c['url'], $c['lower']);
    }

    /** @param array{0: string, 1: string, 2: bool} $p */
    private static function test(array $p, string $s, string $lower): bool
    {
        if ($p[1] !== '' && !str_contains($p[2] ? $s : $lower, $p[1])) {
            return false;
        }
        return preg_match($p[0], $s) === 1;
    }

    /** Whether $host is one of $domains, where "*" before a domain takes its subdomains too. @param list<string> $domains */
    private static function domainListed(array $domains, string $host): bool
    {
        foreach ($domains as $d) {
            if (str_starts_with($d, '*')) {
                $sub = substr($d, 1);
                if ($host === $sub || str_ends_with($host, ".$sub")) { return true; }
            } elseif ($host === $d) {
                return true;
            }
        }
        return false;
    }

    // Patterns.

    /**
     * A regular expression of WebKit's, as PCRE, with the longest literal
     * every match contains (mygo's newPattern): WebKit's content blockers
     * take no alternatives, counted repetitions, classes as \d, "^" but at
     * the start, nor what is not ASCII. @return array{0: string, 1: string, 2: bool}
     */
    private static function pattern(string $source, bool $sensitive, int $rule): array
    {
        if (($why = self::unsupported($source)) !== null) {
            throw new RuntimeException("rule $rule: invalid or unsupported regular expression \"$source\": $why");
        }
        $re = '~' . str_replace('~', '\~', $source) . '~' . ($sensitive ? '' : 'i');
        if (@preg_match($re, '') === false) {
            throw new RuntimeException("rule $rule: invalid regular expression \"$source\"");
        }
        $pos = 0;
        $literal = self::literalOf(self::parseConcat($source, $pos));
        if ($pos < strlen($source)) {
            throw new RuntimeException("rule $rule: invalid regular expression \"$source\"");
        }
        return [$re, $sensitive ? $literal : strtolower($literal), $sensitive];
    }

    /** What of $source WebKit's content blockers do not take, null for none (mygo's webKitRegexp). */
    private static function unsupported(string $source): ?string
    {
        $class = false;
        for ($i = 0, $n = strlen($source); $i < $n; $i++) {
            $c = $source[$i];
            if (ord($c) >= 0x80) {
                return 'not ASCII';
            }
            if ($c === '\\') {
                $i++;
                if ($i < $n && str_contains('dDwWsSbB', $source[$i])) { return '\\' . $source[$i]; }
            } elseif ($class) {
                $class = $c !== ']';
            } elseif ($c === '[') {
                $class = true;
                if (($source[$i + 1] ?? '') === '^') { $i++; }
            } elseif ($c === '|' || $c === '{') {
                return "\"$c\"";
            } elseif ($c === '^' && $i > 0) {
                return '"^" but at the start';
            }
        }
        return null;
    }

    /**
     * The items of a concatenation up to ")" or the end: [kind, value,
     * quantifier], kind 'c' a character, 'g' a group (value its items), 'x'
     * anything else (".", a class, an anchor).
     * @return list<array{0: string, 1: mixed, 2: string}>
     */
    private static function parseConcat(string $s, int &$i): array
    {
        $items = [];
        $n = strlen($s);
        while ($i < $n && $s[$i] !== ')') {
            $c = $s[$i++];
            if ($c === '(') {
                if (substr($s, $i, 2) === '?:') { $i += 2; }
                $item = ['g', self::parseConcat($s, $i), ''];
                $i++; // ")"
            } elseif ($c === '[') {
                if (($s[$i] ?? '') === '^') { $i++; }
                if (($s[$i] ?? '') === ']') { $i++; } // a "]" first is one of the class's
                while ($i < $n && $s[$i] !== ']') { $i += $s[$i] === '\\' ? 2 : 1; }
                $i++;
                $item = ['x', null, ''];
            } elseif ($c === '\\') {
                $item = ['c', $s[$i++] ?? '', ''];
            } elseif ($c === '.' || $c === '^' || $c === '$') {
                $item = ['x', null, ''];
            } else {
                $item = ['c', $c, ''];
            }
            if ($i < $n && str_contains('*+?', $s[$i])) {
                $item[2] = $s[$i++];
                if (($s[$i] ?? '') === '?') { $i++; } // lazy
            }
            $items[] = $item;
        }
        return $items;
    }

    /** The longest string every match of the items contains (mygo's longestLiteral). */
    private static function literalOf(array $items): string
    {
        $best = $run = '';
        foreach ($items as [$kind, $value, $q]) {
            if ($kind === 'c' && $q === '') {
                $run .= $value;
                continue;
            }
            $best = strlen($run) > strlen($best) ? $run : $best;
            $run = '';
            $lit = $q === '*' || $q === '?' ? '' : match ($kind) { 'c' => $value, 'g' => self::literalOf($value), default => '' };
            $best = strlen($lit) > strlen($best) ? $lit : $best;
        }
        return strlen($run) > strlen($best) ? $run : $best;
    }

    /** The domain a url-filter matches only URLs of, with its subdomains, as DOMAIN_PREFIX starts it; '' for none (mygo's filterDomain). */
    public static function filterDomain(string $filter): string
    {
        if (!str_starts_with($filter, self::DOMAIN_PREFIX)) {
            return '';
        }
        $rest = substr($filter, strlen(self::DOMAIN_PREFIX));
        $domain = '';
        while ($rest !== '') {
            if (preg_match('/^[a-zA-Z0-9_-]/', $rest) === 1) {
                $domain .= strtolower($rest[0]);
                $rest = substr($rest, 1);
            } elseif (str_starts_with($rest, '\.')) {
                $domain .= '.';
                $rest = substr($rest, 2);
            } else {
                break;
            }
        }
        foreach (['[:/]', '[/:]', '/', ':'] as $end) { // the domain ends where the host does
            if (str_starts_with($rest, $end)) {
                $after = substr($rest, strlen($end));
                if (!str_starts_with($after, '*') && !str_starts_with($after, '?') && !str_starts_with($after, '+')
                    && $domain !== '' && !str_starts_with($domain, '.') && !str_ends_with($domain, '.')) {
                    return $domain;
                }
            }
        }
        return '';
    }

    /** The four bytes of $literal fewest rules are indexed by, null when it has fewer than four. */
    private function rarestGram(string $literal): ?string
    {
        $best = null; $count = -1;
        for ($i = 0; $i + 4 <= strlen($literal); $i++) {
            $g = substr($literal, $i, 4);
            $n = count($this->byGram[$g] ?? []);
            if ($count < 0 || $n < $count) { [$best, $count] = [$g, $n]; }
        }
        return $best;
    }

    // Sites.

    /** The host of a lowercase URL, without its port. */
    private static function urlHost(string $u): string
    {
        if (($p = strpos($u, '://')) === false) {
            return '';
        }
        $rest = substr($u, $p + 3);
        if (($i = strcspn($rest, '/?#')) < strlen($rest)) { $rest = substr($rest, 0, $i); }
        if (($i = strrpos($rest, '@')) !== false) { $rest = substr($rest, $i + 1); }
        if (str_starts_with($rest, '[') && ($i = strpos($rest, ']')) !== false) {
            return substr($rest, 0, $i + 1);
        }
        if (($i = strpos($rest, ':')) !== false) { $rest = substr($rest, 0, $i); }
        return rtrim($rest, '.');
    }

    /** The registrable domain of a host, which tells first-party loads from third-party ones; the host itself for an IP address. */
    public static function site(string $host): string
    {
        if ($host === '' || str_starts_with($host, '[') || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $host;
        }
        $labels = explode('.', $host);
        $n = count($labels);
        $suffix = 1;
        foreach (self::SUFFIXES as $s) {
            if ($host === $s || str_ends_with($host, ".$s")) { $suffix = max($suffix, substr_count($s, '.') + 1); }
        }
        if ($suffix === 1 && $n >= 3 && strlen($labels[$n - 1]) === 2 && in_array($labels[$n - 2], self::SLDS, true)) {
            $suffix = 2;
        }
        return $n <= $suffix ? $host : implode('.', array_slice($labels, $n - $suffix - 1));
    }

    /**
     * Hides what the lists select, through a style sheet the document
     * adopts, which needs no element to exist yet and which a page setting
     * its adoptedStyleSheets keeps; the rules' domains are the page's, as
     * in WebKit, which a frame of another origin knows by its referrer
     * (mygo's cosmeticScript).
     */
    private const COSMETIC = <<<'JS'
        const url=location.href;
        let top;try{top=window.top.location.href}catch(e){top=document.referrer||url}
        let host;try{host=new URL(top).hostname.toLowerCase().replace(/\.$/,"")}catch(e){host=location.hostname}
        const listed=ds=>ds.some(d=>d[0]==="*"?host===d.slice(1)||host.endsWith("."+d.slice(1)):host===d);
        const test=(src,s,c)=>new RegExp(src,c?"":"i").test(s);
        const css=[];
        for(const list of lists){let hidden=[];for(const e of list){
        if(e.u&&!test(e.u,url,e.c))continue;
        if(e.d&&!listed(e.d)||e.n&&listed(e.n))continue;
        if(e.t&&!e.t.some(t=>test(t,top,e.c))||e.nt&&e.nt.some(t=>test(t,top,e.c)))continue;
        if(e.i)hidden=[];else hidden.push(...e.s)}
        for(const s of hidden)css.push(s+"{display:none!important}")}
        if(css.length===0)return;
        const sheet=new CSSStyleSheet();sheet.replaceSync(css.join("\n"));
        const adopted=Object.getOwnPropertyDescriptor(Document.prototype,"adoptedStyleSheets");
        adopted.set.call(document,[...adopted.get.call(document),sheet]);
        Object.defineProperty(document,"adoptedStyleSheets",{configurable:true,enumerable:true,
        get(){return adopted.get.call(this)},
        set(v){adopted.set.call(this,Array.prototype.includes.call(v,sheet)?v:[...v,sheet])}});
        JS;
}
