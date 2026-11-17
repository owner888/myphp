<?php
// iOS: the keyboard reads the text of the field it types into as its document (the launcher's UITextInput), so that it
// corrects, predicts and capitalizes by what is typed, as in a UITextField; what it changes there comes back as a range
// replaced or selected, in UTF-16 units, which the field applies (iOS\Window::document, replace, select).
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\Platform\iOS\Window as IOSWindow;
use MyPHP\UI\{Input, TextInputTraits, TextState};

$input = new Input();
same([0, '', 0, 0], IOSWindow::document($input), 'no field: no document, the keyboard reads what is composed alone');
same([0, '', 0, 0], IOSWindow::document(null), 'nor with no input');

$f = new TextState('😀 I teh cat');
$input->focus = $f;
$f->setCursor(strlen('😀 I teh'));
same([1, '😀 I teh cat', 8, 8], IOSWindow::document($input), 'the field\'s text, the caret in UTF-16 units (the emoji is two)');
$f->setCursor(strlen('😀 '), false); $f->setCursor(strlen('😀 I teh'), true);
same([1, '😀 I teh cat', 3, 8], IOSWindow::document($input), 'a selection, in order');

// A correction: the keyboard replaces "teh" (UTF-16 5..8, after the emoji) with "the".
$f->setCursor(strlen('😀 I teh'));
IOSWindow::replace($input, 5, 8, 'the');
same(['😀 I the cat', strlen('😀 I the')], [$f->text, $f->cursor], 'the word corrected, the caret after it');
$f->undo();
same('😀 I teh cat', $f->text, 'an undo step of its own');
IOSWindow::replace($input, 0, 0, 'Oh ');
same('Oh 😀 I teh cat', $f->text, 'a prediction inserted where the keyboard says');

IOSWindow::select($input, 3, 5);
same(['😀', strlen('Oh '), strlen('Oh 😀')], [$f->selectedText(), $f->anchor, $f->cursor], 'the keyboard\'s selection, as the space bar\'s drag moves it');

$f->password = true;
same([0, '', 0, 0], IOSWindow::document($input), 'a secure field\'s text is not the keyboard\'s');
$f->password = false;
$f->traits = new TextInputTraits(autocorrectionDisabled: true);
same(1, IOSWindow::keyboardTraits($f)[5], 'autocorrectionDisabled(): UITextAutocorrectionTypeNo');
same(0, IOSWindow::keyboardTraits(new TextState(''))[5], 'by default UIKit\'s, which corrects');

// The launcher and the cdef agree on the event, and the launcher keeps its rules.
$m = (string)file_get_contents(__DIR__ . '/../src/Platform/iOS/launcher/main.m');
$cdef = (string)file_get_contents(__DIR__ . '/../src/Native/iOS.php');
$fields = function (string $src): string {
    preg_match('/typedef struct \{(.*?)\}\s*MyphpInputEvent;/s', $src, $m);
    return (string)preg_replace(['~//[^\n]*~', '/\s+/'], ['', ' '], trim($m[1] ?? ''));
};
same($fields($cdef), $fields($m), 'MyphpInputEvent the same in main.m and Native\iOS: ' . $fields($m));
check(str_contains($m, 'void myphp_ios_set_text_document(int on, const char *text, int from, int to, int acked)') && str_contains($cdef, 'void myphp_ios_set_text_document(int on, const char *text, int from, int to, int acked);'), 'myphp_ios_set_text_document, in both');
check(str_contains($m, 'void myphp_ios_set_text_traits(int keyboard, int return_key, int autocapitalization, int secure, const char *content_type, int autocorrection)') && str_contains($cdef, 'void myphp_ios_set_text_traits(int keyboard, int return_key, int autocapitalization, int secure, const char *content_type, int autocorrection);'), 'myphp_ios_set_text_traits, with the correction, in both');
check(str_contains($m, 'if (acked != input_pushed() || [self hasMarked]) {'), 'a document told before PHP took the keyboard\'s edits, or while it composes, is not taken');
check(str_contains($m, '- (UITextAutocorrectionType)autocorrectionType { return g_doc_on ? g_autocorrection : UITextAutocorrectionTypeNo; }'), 'corrections only where the keyboard reads the field\'s text');
$w = (string)file_get_contents(__DIR__ . '/../src/Platform/iOS/Window.php');
check(preg_match('/myphp_ios_poll_input_event\(\\\\FFI::addr\(\$iev\)\) > 0\) \{\s*\$this->inputTaken\+\+;/', $w) === 1, 'the window counts every input event it takes');
check(str_contains($w, 'myphp_ios_set_text_document($on, $text, $from, $to, $this->inputTaken);'), 'and tells it with the document');
