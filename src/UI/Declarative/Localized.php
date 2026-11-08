<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

use MyPHP\{Catalog, I18n, Message};

/**
 * SwiftUI's LocalizedStringKey: the text a view shows (Text('Submit'),
 * Button('Save'), a title) is the key of its translation in the app's
 * catalogs (locales/zh-Hans.json: {"Submit": "提交"}), said in the
 * environment's language — environment(locale:), else the app's — and
 * shown as it is where no catalog has it. Its placeholders are ICU's:
 * Text('Hello, {name}!', name: $this->name).
 */
final class Localized
{
    /** $key in the language of $env, $args in its placeholders. @param array<string, mixed> $args */
    public static function string(string $key, Env $env, array $args = []): string
    {
        $locale = $env->locale ?? I18n::locale();
        return Catalog::say($locale, $key, $args) ?? ($args === [] ? $key : Message::parse($key)->format($locale, $args));
    }
}
