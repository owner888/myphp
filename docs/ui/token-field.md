# Token field

`$c->tokenField()` creates a field of tokens, as of tags or the recipients
of a mail, editing an array of strings (by reference): the tokens show as
chips, each with a button taking it out, before an input where Enter or a
comma adds what was typed, and Backspace in it while empty takes out the
last. The suggestions containing what was typed show below it, those not
already tokens: Up and Down move among them, and Enter or a click adds one.
`$c->changed()` reports a change.

```php
$app = new class { public array $tags = ['php']; };
$allTags = ['php', 'desktop', 'native', 'gpu', 'ffi'];
$view = function (Context $c) use ($app, $allTags) {
    $c->tokenField($app->tags, $allTags, 320, 'Add a tag…')->label('Tags');
};
```

Chips wrap onto more lines as they fill the field. The last argument shows
a text in its input while it is empty, as "Add a tag…"; it shows none
without one. `autoFocus()` on the field gives the focus to its input.

## Accessibility

Assistive technology sees the field named by its `label()`, its input as a
text field, and each chip's button, named "Remove" and its token.
