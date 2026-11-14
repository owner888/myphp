<?php
// The window's view of the 3D game main.php makes, $this in it (Content::file): saved, it hot reloads.
declare(strict_types=1);

return fn() => SpriteView(scene: $this);
