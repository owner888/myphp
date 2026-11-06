<?php
// The window's view of the state main.php makes, $this in it (Content::file): saved, it hot reloads.
declare(strict_types=1);

return fn() => SpriteView(scene: $this)->background(Color::rgb(0x4b9a2a))->accessibilityLabel('Farm');
