# Tabs

A `TabView` shows one of its `Tab`s, the one whose value its selection
(bound by reference) holds, as SwiftUI's:

```php
$app = new class { public string $tab = 'general'; };
$view = fn() => TabView(selection: $app->tab)(
    Tab('General', systemImage: 'gearshape', value: 'general')(Text('General settings')),
    Tab('Accounts', systemImage: 'person.crop.circle', value: 'accounts')(Text('Accounts')),
);
```

Each screen shows it as its own: tabs across the top on the desktop; the
tab bar across the bottom of a phone, floating, as iOS 26's; a capsule of
the titles at the top of a tablet held upright. With
`->tabViewStyle(TabViewStyle::sidebarAdaptable)` the desktop and a tablet on
its side show a sidebar of the tabs instead, a `NavigationSplitView`, as
SwiftUI's on iOS 18 and macOS 15: write an app's top-level pages once, and
never branch on the platform for it.

A tab keeps its pages' history as another shows. `->badge()` on a `Tab`
shows a count by it. `Tab(value:, role: TabRole::search)` is the app's
search, `TabRole::prominent` a tab apart at a phone's bar's end;
`->tabBarMinimizeBehavior(TabBarMinimizeBehavior::onScrollDown)` shrinks a
phone's bar as its page scrolls down ([mobile](../mobile.md)).

## Accessibility

Assistive technology sees a tab list of tabs named by their titles, the
chosen one selected.
