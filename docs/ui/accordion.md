# Accordion

An accordion is `DisclosureGroup`s one after another; to keep one open at a
time, close the others as one opens:

```php
$app = new class {
    public bool $shipping = true; public bool $returns = false;
    public function opened(string $which): void { if ($this->$which) { foreach (['shipping', 'returns'] as $g) { if ($g !== $which) { $this->$g = false; } } } }
};
$view = fn() => VStack(alignment: HorizontalAlignment::leading)(
    DisclosureGroup('Shipping', isExpanded: $app->shipping)(Text('3 to 5 days'))->onChange(of: $app->shipping, action: fn() => $app->opened('shipping')),
    DisclosureGroup('Returns', isExpanded: $app->returns)(Text('30 days'))->onChange(of: $app->returns, action: fn() => $app->opened('returns')),
)->padding();
```

Without a binding each opens and closes on its own ([disclosure
group](collapsible.md)).
