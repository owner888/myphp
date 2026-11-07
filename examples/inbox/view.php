<?php
// The window's view of the state main.php makes, $this in it (Content::file): saved, it hot reloads.
declare(strict_types=1);

// Mailboxes in a sidebar, as Mail's, beside the messages: only the rows in view built, so it holds a million.
return fn() => NavigationSplitView(
    sidebar: ListView(selection: $this->mailbox)(
        Section('Favorites')(
            Label('Inbox', systemImage: 'tray')->badge($this->rows)->tag('inbox'),
            Label('Flagged', systemImage: 'flag')->tag('flagged'),
            Label('Drafts', systemImage: 'doc')->tag('drafts'),
        ),
        Section('On My Mac')(
            Label('Archive', systemImage: 'archivebox')->tag('archive'),
            Label('Trash', systemImage: 'trash')->tag('trash'),
        ),
    ),
    detail: ScrollViewReader(fn(ScrollViewProxy $proxy) => VStack(spacing: 0)(
        HStack(spacing: 8)(
            Text('Inbox')->font(Font::title2)->bold(),
            Text(verbatim: number_format($this->rows) . ' messages')->foregroundStyle(Color::secondary),
            Text('every third unread')->italic()->foregroundStyle(Color::secondary),
            Spacer(),
            Button('Compose', action: function () use ($proxy) { $this->rows++; $this->last = "composed row {$this->rows}"; $proxy->scrollTo($this->rows - 1); })->buttonStyle(ButtonStyle::borderedProminent),
            Button('Refresh', action: function () { $this->replied = []; $this->last = 'refreshed'; }),
            Button('Top', action: fn() => $proxy->scrollTo(0, anchor: UnitPoint::top)), // glides there
            Button('End', action: fn() => $proxy->scrollTo($this->rows - 1, anchor: UnitPoint::bottom)),
            Text(verbatim: $this->last)->foregroundStyle(Color::secondary)->lineLimit(1),
        )->padding(12),
        Divider(),
        ListView($this->rows, selection: $this->chosen)(fn(int $i) => HStack(spacing: 12)(
            Image($this->avatars[$i % count($this->avatars)])->resizable()->frame(width: 36, height: 36)->cornerRadius(8),
            VStack(alignment: HorizontalAlignment::leading, spacing: 2)(
                HStack(spacing: 6)(
                    Text(verbatim: "Sender $i"),
                    Text(verbatim: "主题 {$i}：Subject line number $i is here"),
                )->bold($i % 3 === 0), // bold, as unread mail is
                Text(verbatim: "Preview of the message body, truncated to one line of text $i")->font(Font::caption)->foregroundStyle(Color::secondary),
            )->lineLimit(1),
            Spacer(),
            Text(verbatim: sprintf('%02d:%02d', $i % 24, ($i * 7) % 60))->font(Font::caption)->monospaced()->foregroundStyle(Color::secondary),
            isset($this->replied[$i])
                ? Button('Replied')->buttonStyle(ButtonStyle::borderedProminent)->tint(Color::green)
                : Button('Reply', action: function () use ($i) { $this->replied[$i] = true; $this->last = "replied to $i"; }),
        ))
            ->alternatingRowBackgrounds()
            ->onChange(of: $this->chosen, action: fn() => $this->last = count($this->chosen) === 1 ? "chose row {$this->chosen[0]}" : 'chose ' . count($this->chosen) . ' rows')
            ->contextMenu(menu: fn(array $rows) => [
                Button(count($rows) === 1 ? 'Reply' : 'Reply to ' . count($rows), action: function () use ($rows) { foreach ($rows as $i) { $this->replied[$i] = true; } $this->last = 'replied to ' . implode(', ', $rows); }),
            ], primaryAction: fn(array $rows) => $this->last = 'opened row ' . implode(', ', $rows)),
    )),
)->onChange(of: $this->mailbox, action: fn() => $this->last = "mailbox {$this->mailbox}");
