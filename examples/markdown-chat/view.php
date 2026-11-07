<?php
// The window's view of the Chat main.php makes, $this in it (Content::file): saved, it hot reloads.
declare(strict_types=1);

use MyPHP\UI\Markdown\{Reveal, RevealUnit};

// How replies stream in, the messages (only those in view built, the end followed as a reply grows), and the field.
return function () {
    $this->md->reveal = new Reveal([RevealUnit::ByWord, RevealUnit::BySentence, RevealUnit::ByLine, RevealUnit::ByParagraph, RevealUnit::AtOnce][$this->reveal]);
    $last = $this->messages === [] ? null : $this->messages[count($this->messages) - 1];
    return VStack(spacing: 0)(
        HStack(spacing: 8)(
            Text('Stream by')->foregroundStyle(Color::secondary),
            Picker('Stream by', selection: $this->reveal)(Each(REVEAL_NAMES)(fn(string $name, int $i) => Text($name)->tag($i)))->pickerStyle(PickerStyle::segmented)->labelsHidden(),
            Spacer(),
        )->padding(Edge::vertical, 8)->padding(Edge::horizontal, 20),
        Divider(),
        $this->messages === []
            ? Text('Ask anything: the replies are canned, streamed as a model streams them.')->foregroundStyle(Color::secondary)->padding(16)->frame(maxWidth: INF, maxHeight: INF, alignment: Alignment::top)
            : ScrollView()(LazyVStack(spacing: 16)(Each($this->messages, id: 'id')(fn(Message $m) => $m->mine
                // the user's in a bubble on the right, the model's as Markdown
                ? HStack()(Spacer(), Text(verbatim: $m->text)->textSelection(TextSelectability::enabled)->padding(Edge::vertical, 8)->padding(Edge::horizontal, 12)
                    ->background(Color::gray->opacity(0.15))->cornerRadius(14)->frame(maxWidth: 520, alignment: Alignment::trailing))
                : Markdown($m->text, $this->md, streaming: !$m->done)->frame(maxWidth: 760, alignment: Alignment::leading),
            )))->defaultScrollAnchor(UnitPoint::bottom)->padding(Edge::vertical, 16)->padding(Edge::horizontal, 20),
        Divider(),
        HStack(spacing: 8)(
            TextField('Message', text: $this->input)->defaultFocus()->onSubmit(fn() => trim($this->input) !== '' && $this->send()),
            $last !== null && !$last->done
                ? Button('Stop', action: fn() => $last->done = true)
                : Button('Send', action: fn() => $this->send())->buttonStyle(ButtonStyle::borderedProminent)->disabled(trim($this->input) === ''),
        )->padding(Edge::vertical, 12)->padding(Edge::horizontal, 20),
    );
};
