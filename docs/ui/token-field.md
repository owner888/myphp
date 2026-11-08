# Token field

A `TokenField` edits an array of strings (bound by reference), as of tags
or the recipients of a mail, as AppKit's NSTokenField: the tokens show as
chips, each with a button taking it out, before an input where Enter or a
comma adds what was typed, and Backspace in it while empty takes out the
last. The `suggestions:` containing what was typed show below it, those not
already tokens: Up and Down move among them, and Enter or a click adds one.

```php
$app = new class { public array $tags = ['php', 'ui']; };
$view = fn() => Form()(
    TokenField('Tags', tokens: $app->tags, suggestions: ['php', 'swift', 'ui', 'gpu'], prompt: 'Add a tag…'),
);
```

Chips wrap onto more lines as they fill the field.

## Accessibility

Assistive technology sees the field named by its title, its input as a
text field, and each chip's button, named "Remove" and its token.
