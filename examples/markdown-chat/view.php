<?php
// The window's view of the state main.php makes, $this in it (Content::file): saved, it hot reloads.
declare(strict_types=1);

use MyPHP\UI\{Animation, Context, Node};
use MyPHP\UI\Markdown\{Markdown, Reveal, RevealUnit};

/** A message: the user's in a bubble on the right, the model's as Markdown. */
$message = function (Context $c, Chat $app, Message $m): void {
    $t = $c->theme;
    if ($m->mine) {
        $c->row();
          $c->spacer();
          $c->text($m->text)->selectable()->padding(8, 12)->radius(14)->background($t->surface)->maxWidth(520);
        $c->end();
        return;
    }
    $c->column()->maxWidth(760);
      $m->done ? Markdown::view($c, $m->text, $app->md) : Markdown::stream($c, $m->text, $app->md);
    $c->end();
};

return function (Context $c) use (&$message): void {
    $t = $c->theme;
    $this->list->key = fn(int $i) => $this->messages[$i]->id;
    $this->list->followEnd = true;
    $this->list->followAnimation = Animation::smooth(); // glide up as the reply grows
    $this->md->reveal = new Reveal([RevealUnit::ByWord, RevealUnit::BySentence, RevealUnit::ByLine, RevealUnit::ByParagraph, RevealUnit::AtOnce][$this->reveal]);
    $c->column(0, 0, 1);
      $c->row(0, 8)->alignItems(Node::CENTER)->padding(8, 20)->borderWidth(0, 0, 1, 0)->borderColor($t->border);
        $c->muted('Stream by');
        $c->segmented(REVEAL_NAMES, $this->reveal)->label('Stream by');
      $c->end();
      $c->list($this->list, count($this->messages), fn(int $i) => $message($c, $this, $this->messages[$i]),
          fn() => $c->muted('Ask anything: the replies are canned, streamed as a model streams them.')->margin(Node::AUTO))->grow(1)->padding(16, 20)->gap(16)->justify(Node::END);
      $c->row(0, 8)->padding(12, 20)->borderWidth(1, 0, 0, 0)->borderColor($t->border);
        $in = $c->input($this->input, 'Message', 0)->autoFocus();
        $last = $this->messages === [] ? null : $this->messages[count($this->messages) - 1];
        if ($last !== null && !$last->done) {
            if ($c->clicked($c->button('Stop'))) { $last->done = true; }
        } else {
            $send = $c->primaryButton('Send')->disabled(trim($this->input->text) === '');
            if (($c->clicked($send) || $c->submitted($in)) && trim($this->input->text) !== '') { $this->send(); }
        }
      $c->end();
    $c->end();
};
