<?php
declare(strict_types=1);

namespace MyPHP;

use InvalidArgumentException;
use MyPHP\Native\ContentRuleStore;
use RuntimeException;

/**
 * Rules that block what pages load and hide parts of them, as an ad or
 * tracker blocker does, compiled by compile() for pages to apply
 * (PageOptions::$contentRules, Page::setContentRules) (mygo's
 * ContentRules). One serves every page that applies it.
 */
final class ContentRules
{
    /** How many hexadecimal digits of the rules' hash identifier() puts after the id. */
    private const HASH = 16;

    /** @param mixed $list the compiled WKContentRuleList, @internal */
    private function __construct(private readonly string $id, public readonly mixed $list) {}

    /** The id the rules were compiled under. */
    public function id(): string
    {
        return $this->id;
    }

    /**
     * Compiles $rules in WebKit's content blocker format, a JSON array of
     * rules, each with a trigger and an action, applied in order (mygo's
     * CompileContentRules):
     *
     *     [{"trigger": {"url-filter": "^https?://ads\\.example\\.com/"}, "action": {"type": "block"}},
     *      {"trigger": {"url-filter": ".*", "if-domain": ["*example.com"]},
     *       "action": {"type": "css-display-none", "selector": ".banner"}}]
     *
     * A trigger matches a load by its url-filter, a regular expression of
     * the URL (case-insensitive unless url-filter-is-case-sensitive), and
     * narrows it with one of if-domain or unless-domain (domains of the
     * page, in lowercase punycode, "*" before one taking its subdomains
     * too), or if-top-url or unless-top-url (regular expressions of the
     * page's URL); and with resource-type ("document", "image",
     * "style-sheet", "script", "font", "raw", "svg-document", "media",
     * "popup", "ping", "fetch", "websocket" or "other") and load-type
     * ("first-party" or "third-party"). An action is block (the load does
     * not happen), block-cookies (it goes without cookies), make-https,
     * css-display-none (the elements its selector selects are hidden in
     * the pages its trigger matches) or ignore-previous-rules.
     *
     * The regular expressions are WebKit's: ASCII, without alternatives
     * ("|"), counted repetitions ("{2}") or classes such as "\d". A rule
     * whose selector WebKit cannot parse is skipped; any other mistake
     * fails the whole list, as an empty list does, with what is wrong.
     *
     * Compiling takes seconds for lists as long as EasyList: it waits, the
     * app handling events meanwhile. WebKit keeps what it compiled under
     * $id, with a hash of the rules: the same rules on a later launch load
     * without compiling again, and compiling other rules under the same id
     * removes the list of the earlier ones. An id is letters, digits, "-",
     * "_" and ".", at most 64 of them. WebKit limits the rules of a list
     * (to 150,000, or 50,000 in older versions): split longer lists and
     * apply them together. Call it once the app runs.
     */
    public static function compile(string $id, string $rules): self
    {
        if (!self::validID($id)) {
            throw new InvalidArgumentException("myphp: content rules: invalid id \"$id\"");
        }
        $identifier = self::identifier($id, $rules);
        try {
            $list = ContentRuleStore::compile($identifier, $rules, fn(string $name): bool => self::stale($id, $identifier, $name));
        } catch (RuntimeException $e) {
            throw new RuntimeException("myphp: content rules $id: " . $e->getMessage(), 0, $e);
        }
        return new self($id, $list);
    }

    /** @internal letters, digits, "-", "_" and ".", 1 to 64 of them */
    public static function validID(string $id): bool
    {
        return preg_match('/^[A-Za-z0-9._-]{1,64}$/D', $id) === 1;
    }

    /**
     * The name of the list compiled from $rules for $id: the id and a hash
     * of the rules, so that a later launch with the same rules loads what
     * it compiled before, and changed rules compile again (mygo's
     * platform.ContentRulesIdentifier).
     * @internal
     */
    public static function identifier(string $id, string $rules): string
    {
        return $id . '-' . substr(hash('sha256', $rules), 0, self::HASH);
    }

    /** Whether $name is a list compiled for $id from other rules than $current, identifier()'s (mygo's platform.StaleContentRules). @internal */
    public static function stale(string $id, string $current, string $name): bool
    {
        return $name !== $current && str_starts_with($name, "$id-") && preg_match('/^[0-9a-f]{' . self::HASH . '}$/D', substr($name, strlen($id) + 1)) === 1;
    }
}
