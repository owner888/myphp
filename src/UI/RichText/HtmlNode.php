<?php
declare(strict_types=1);

namespace MyPHP\UI\RichText;

/**
 * A node of an HTML document as HtmlNode::parse() builds it: an element
 * (its lowercase tag, attributes and children), text, or the document.
 *
 * mygo parses with golang.org/x/net/html, a whole HTML5 tree builder; this
 * is a small one of its own for what a paste brings: tags and attributes,
 * entities, raw text in script and style, void elements, and the end tags
 * HTML implies (a <p> or a block closes an open <p>, an <li> closes the
 * <li> before it, cells and rows close theirs). It does not reparent
 * misnested formatting as HTML5's adoption agency does.
 *
 * @internal
 */
final class HtmlNode
{
    public const DOCUMENT = 0, ELEMENT = 1, TEXT = 2;

    private const VOID = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr'];
    private const RAW = ['script', 'style', 'textarea', 'title', 'xmp', 'noscript', 'template'];
    /** The tags whose start closes an open <p>. */
    private const CLOSES_P = ['address', 'article', 'aside', 'blockquote', 'center', 'details', 'dialog', 'dir', 'div', 'dl', 'fieldset',
        'figcaption', 'figure', 'footer', 'form', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header', 'hgroup', 'hr', 'li', 'main', 'menu', 'nav',
        'ol', 'p', 'pre', 'section', 'summary', 'table', 'ul', 'dd', 'dt'];

    /** @var list<HtmlNode> */
    public array $children = [];

    /** @param array<string, string> $attrs lowercase names */
    public function __construct(
        public int $type,
        /** an element's tag, a text's text */
        public string $data = '',
        public array $attrs = [],
    ) {}

    /** The value of attribute $key, and whether there is one. @return array{0: string, 1: bool} */
    public function attr(string $key): array
    {
        $key = strtolower($key);
        return array_key_exists($key, $this->attrs) ? [$this->attrs[$key], true] : ['', false];
    }

    /** The tree of $s. */
    public static function parse(string $s): HtmlNode
    {
        $doc = new HtmlNode(self::DOCUMENT);
        $stack = [$doc];
        $text = function (string $t) use (&$stack): void {
            if ($t === '') { return; }
            $parent = $stack[count($stack) - 1];
            $t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $last = $parent->children[count($parent->children) - 1] ?? null;
            if ($last !== null && $last->type === self::TEXT) {
                $last->data .= $t;
            } else {
                $parent->children[] = new HtmlNode(self::TEXT, $t);
            }
        };
        $has = function (string $tag, array $stopAt = []) use (&$stack): int {
            for ($k = count($stack) - 1; $k > 0; $k--) {
                if ($stack[$k]->data === $tag) { return $k; }
                if (in_array($stack[$k]->data, $stopAt, true)) { return -1; }
            }
            return -1;
        };
        $n = strlen($s);
        for ($i = 0; $i < $n;) {
            $lt = strpos($s, '<', $i);
            if ($lt === false) { $text(substr($s, $i)); break; }
            $text(substr($s, $i, $lt - $i));
            $i = $lt;
            if (substr_compare($s, '<!--', $i, 4) === 0) {
                $end = strpos($s, '-->', $i + 4);
                $i = $end === false ? $n : $end + 3;
                continue;
            }
            if (preg_match('/\G<(\/?)([a-zA-Z][a-zA-Z0-9:-]*)/', $s, $m, 0, $i) !== 1) {
                if ($i + 1 < $n && ($s[$i + 1] === '!' || $s[$i + 1] === '?')) {
                    $end = strpos($s, '>', $i);
                    $i = $end === false ? $n : $end + 1; // a doctype or a processing instruction
                    continue;
                }
                $text('<');
                $i++;
                continue;
            }
            $closing = $m[1] === '/';
            $tag = strtolower($m[2]);
            $i += strlen($m[0]);
            $attrs = [];
            while ($i < $n) {
                if (preg_match('/\G[\s\/]*>/', $s, $mm, 0, $i) === 1) { $i += strlen($mm[0]); break; }
                if (preg_match('/\G[\s\/]*([^\s\/>=][^\s\/>=]*)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]*)))?/', $s, $mm, 0, $i) !== 1) { $i = $n; break; }
                $i += strlen($mm[0]);
                $key = strtolower($mm[1]);
                if (!array_key_exists($key, $attrs)) {
                    $attrs[$key] = html_entity_decode(($mm[2] ?? '') . ($mm[3] ?? '') . ($mm[4] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                }
            }
            if ($closing) {
                $stop = match ($tag) {
                    'li' => ['ul', 'ol'],
                    'td', 'th', 'tr' => ['table'],
                    default => [],
                };
                if (($k = $has($tag, $stop)) > 0) {
                    array_splice($stack, $k);
                } elseif ($tag === 'p') {
                    $stack[count($stack) - 1]->children[] = new HtmlNode(self::ELEMENT, 'p'); // </p> alone is an empty paragraph
                } elseif ($tag === 'br') {
                    $stack[count($stack) - 1]->children[] = new HtmlNode(self::ELEMENT, 'br');
                }
                continue;
            }
            if (in_array($tag, self::CLOSES_P, true) && ($k = $has('p', ['button'])) > 0) { array_splice($stack, $k); }
            if ($tag === 'li' && ($k = $has('li', ['ul', 'ol'])) > 0) { array_splice($stack, $k); }
            if (($tag === 'dt' || $tag === 'dd') && ($k = max($has('dt', ['dl']), $has('dd', ['dl']))) > 0) { array_splice($stack, $k); }
            if (($tag === 'td' || $tag === 'th') && ($k = max($has('td', ['tr', 'table']), $has('th', ['tr', 'table']))) > 0) { array_splice($stack, $k); }
            if ($tag === 'tr' && ($k = $has('tr', ['table'])) > 0) { array_splice($stack, $k); }
            $el = new HtmlNode(self::ELEMENT, $tag, $attrs);
            $stack[count($stack) - 1]->children[] = $el;
            if (in_array($tag, self::VOID, true)) { continue; }
            if (in_array($tag, self::RAW, true)) {
                $end = stripos($s, "</$tag", $i);
                $raw = substr($s, $i, ($end === false ? $n : $end) - $i);
                if ($raw !== '') { $el->children[] = new HtmlNode(self::TEXT, $raw); }
                $i = $end === false ? $n : (($gt = strpos($s, '>', $end)) === false ? $n : $gt + 1);
                continue;
            }
            $stack[] = $el;
        }
        return $doc;
    }
}
