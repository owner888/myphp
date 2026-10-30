<?php
declare(strict_types=1);

namespace MyPHP\Native;

use MyPHP\App;
use RuntimeException;

/**
 * Content rules in WebKit (mygo's darwin content_rules.go): the default
 * WKContentRuleListStore, which keeps compiled lists in the app's
 * container across launches under an identifier of the id and a hash of
 * the rules (MyPHP\ContentRules::identifier); a page's content controller
 * applies them (WebView::setContentRules).
 * @internal MyPHP\ContentRules
 */
final class ContentRuleStore
{
    /**
     * The list kept as $identifier, else one compiled from $rules, after
     * which the lists of $id's earlier rules go (those $stale says are):
     * a WKContentRuleList retained for good, as a page may apply it at
     * any time. Waits for the store, the app handling events meanwhile.
     * @param \Closure(string): bool $stale
     */
    public static function compile(string $identifier, string $rules, \Closure $stale)
    {
        App::running() || throw new RuntimeException('myphp: content rules need the running app (call ContentRules::compile from App::whenReady)');
        $store = ObjC::msg('p:', ObjC::cls('WKContentRuleListStore'), 'defaultStore');
        [$list] = self::await('pp', fn($block) => ObjC::msg('v:pp', $store, 'lookUpContentRuleListForIdentifier:completionHandler:', ObjC::str($identifier), $block));
        if ($list !== null) {
            return $list;
        }
        [$list, $err] = self::await('pp', fn($block) => ObjC::msg('v:ppp', $store, 'compileContentRuleListForIdentifier:encodedContentRuleList:completionHandler:', ObjC::str($identifier), ObjC::str($rules), $block));
        if ($list === null) {
            throw new RuntimeException($err === null ? 'WebKit made no list' : self::reason($err));
        }
        [$names] = self::await('p', fn($block) => ObjC::msg('v:p', $store, 'getAvailableContentRuleListIdentifiers:', $block));
        for ($i = 0, $n = $names === null ? 0 : ObjC::msg('L:', $names, 'count'); $i < $n; $i++) {
            $name = ObjC::phpStr(ObjC::msg('p:L', $names, 'objectAtIndex:', $i));
            if ($stale($name)) {
                ObjC::msg('v:pp', $store, 'removeContentRuleListForIdentifier:completionHandler:', ObjC::str($name), ObjC::block('p', function ($err): void {}));
            }
        }
        return $list;
    }

    /** Runs $start with a block, and waits until it was called; what it got, the objects retained. @return list<mixed> */
    private static function await(string $args, \Closure $start): array
    {
        $got = null;
        $start(ObjC::block($args, function (...$a) use (&$got): void {
            $got = array_map(fn($o) => $o === null ? null : ObjC::msg('p:', $o, 'retain'), $a);
        }));
        App::wait(function () use (&$got): bool { return $got !== null; });
        return $got;
    }

    /** What is wrong with the rules, which the store puts in NSHelpAnchorErrorKey, else the error's description. */
    private static function reason($err): string
    {
        $info = ObjC::msg('p:', $err, 'userInfo');
        $anchor = $info === null ? null : ObjC::msg('p:p', $info, 'objectForKey:', ObjC::str('NSHelpAnchor'));
        return ObjC::phpStr($anchor ?? ObjC::msg('p:', $err, 'localizedDescription'));
    }
}
