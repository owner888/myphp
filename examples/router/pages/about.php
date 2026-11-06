<?php
// About.
declare(strict_types=1);

return fn() => VStack(spacing: 10)(
    Text('Router')->font(Font::title)->bold(),
    Text('A NavigationStack of pages, each a file of pages/.')->foregroundStyle(Color::secondary),
)->padding(20)->navigationTitle('About');
