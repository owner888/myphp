<?php
// A note, {id} of /notes/{id}.
declare(strict_types=1);

return fn(int $id) => VStack(alignment: HorizontalAlignment::leading, spacing: 10)(
    Text('Back, Cmd+[, or the back button of a mouse, goes back to the list.')->foregroundStyle(Color::secondary),
    Spacer(),
)->padding(20)->frame(maxWidth: INF, maxHeight: INF, alignment: Alignment::topLeading)->navigationTitle($this->notes[$id] ?? 'No such note');
