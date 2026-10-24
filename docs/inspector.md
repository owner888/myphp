# Inspector

The inspector shows the nodes of a window of native UI in a panel docked
at the right of the content, as Chrome's developer tools show a page's:
the tree of nodes as markup, the styles, box model and properties of the
one chosen, highlighted over the content, how long frames take, and
issues.

Windows whose developer tools are on open it with View → Toggle Developer
Tools (the menu role `toggleDevTools`), `F12` or `Alt+Cmd+I`, and
`Shift+Cmd+C` opens it picking a node. Developer tools are on while the
app runs from its project (`myphp dev`, `php app.php`), as the web
inspector is, and `WindowOptions::$devTools` turns them on or off for a
window:

```php
new Window(new WindowOptions(
    content: Content::view($view),
    devTools: DevTools::Disabled,
));
```

In a window showing a page, the same option, menu item and
`Window::toggleDevTools()` open WebKit's web inspector instead.

The content gives the panel the right of the window: the view gets a
narrower window, as `$c->width` tells. Drag the panel's left edge to
resize it. The panel follows the appearance, light or dark.

The toolbar's RTL toggle lays the window out right to left, or back, to
try an interface before it is translated; the panel itself stays left to
right. A node's computed styles show its `direction`.

## Elements

The tree shows each node as a tag: its role, as `<Button>` or `<List>`,
else its kind, `<Row>`, `<Column>`, `<Text>` and the like, with its key
and label as attributes, and the text of a text inside it. Nodes near the
root show what is inside them; the arrow of a node opens and closes it,
and Option-click opens or closes everything inside.

- Hovering a row highlights its node over the content, as Chrome does:
  its content in blue, padding in green, border in yellow and margins in
  orange, with a tip telling its size, colors, font and spacing, and what
  assistive technology sees: its name and role, and whether the keyboard
  can focus it. Rows of nodes with a transition say so.
- Clicking a row chooses its node; the arrows move the choice, Left and
  Right close and open the node chosen, or go to its parent and into it.
  The breadcrumbs below the tree show the path to it, and choose a node
  of the path.
- The picker of the toolbar, then a click on the content, chooses the
  node under the pointer: the click goes to the inspector, not to the
  node. Escape stops picking.
- `Cmd+F` finds nodes by their tag, text, label or key.

Below the tree, the node chosen has three tabs:

- **Styles**: the styles it sets, as a CSS rule, with where the app built
  it (the file and line of the call that created it), then the text
  styles it inherits, by the node setting them. MyPHP's layout is CSS's,
  so the properties are CSS's: `flex-direction`, `gap`, `padding`,
  `flex-grow`, `width`, `color`, …
- **Computed**: the box model, its margins, border, padding and content,
  with their sizes, and the values the node has as laid out: its size
  and place in the window, its font, its scroll offset.
- **Properties**: what it is (its type, id, key, text), its state
  (focused, under the pointer, pressed, disabled, moving), and what
  assistive technology sees of it.

## Performance and issues

**Performance** shows how long the last frames took to build, lay out and
paint, as bars against the time between two refreshes of the window's
display (8.3 ms at 120 Hz, 16.7 ms at 60 Hz), with the last, the average
and the slowest. Set `MYPHP_STATS` to log frames as well.

**Issues** lists mistakes found while building, which the toolbar counts:
two nodes given the same key in one scope, say, share one state, so that
a click, the focus or scrolling meant for one goes to the other. Apps log
each one once; the headless tests throw where the second key was given,
so that the test fails there.
