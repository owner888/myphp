<?php
// TextField's keyboard modifiers, as SwiftUI's (keyboardType, textContentType, submitLabel,
// textInputAutocapitalization, autocorrectionDisabled): what they set on the field's TextState, and what each
// platform's window tells its system of it: iOS's UITextInputTraits, Android's EditorInfo, Windows' InputScope,
// GTK's input purpose and hints. A field that asks nothing is told what the native side starts with.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{Content, Context, TextInputTraits, TextState};
use MyPHP\Platform\iOS\Window as IOSWindow;
use MyPHP\Platform\Android\Window as AndroidWindow;
use MyPHP\Platform\Windows\Window as WindowsWindow;
use MyPHP\Platform\Linux\Window as LinuxWindow;

require_once __DIR__ . '/../src/UI/Declarative/functions.php';

// The modifiers set the field's traits, each frame; a field without them has none, and Swift's shorthand names a case.
$v = new class { public string $email = ''; public string $pin = ''; public string $note = ''; public bool $swap = false; };
$h = new Headless(fn(Context $c) => Content::render(fn() => VStack()(
    TextField('Email', text: $v->email)->keyboardType(\KeyboardType::emailAddress)->textContentType('emailAddress')
        ->submitLabel(\SubmitLabel::next)->textInputAutocapitalization(\TextInputAutocapitalization::never)->autocorrectionDisabled(),
    $v->swap ? TextField('PIN', text: $v->pin) : SecureField('PIN', text: $v->pin)->keyboardType(\KeyboardType::numberPad),
    TextField('Note', text: $v->note, axis: \Axis::vertical),
), $c), 390, 600);
$h->frame(2);
$email = $h->byLabel('Email')?->edit;
$pin = $h->byLabel('PIN')?->edit;
$note = $h->byLabel('Note')?->edit;
same([\KeyboardType::emailAddress, \TextContentType::emailAddress, \SubmitLabel::next, \TextInputAutocapitalization::never, true],
    [$email?->traits?->keyboard, $email?->traits?->contentType, $email?->traits?->submitLabel, $email?->traits?->autocapitalization, $email?->traits?->autocorrectionDisabled], 'the modifiers on the field\'s state');
same([\KeyboardType::numberPad, true], [$pin?->traits?->keyboard, $pin?->password], 'a SecureField\'s keyboard, and its text hidden');
same(null, $note?->traits, 'a field that asks nothing has no traits');
$v->swap = true; $h->frame(2);
same([null, false], [$h->byLabel('PIN')?->edit?->traits, $h->byLabel('PIN')?->edit?->password], 'a TextField in a SecureField\'s place asks nothing of what it asked');
$threw = false;
try { TextField('X', text: $v->note)->keyboardType('emial'); } catch (\Error) { $threw = true; }
check($threw, 'a case name the enum has not is an error, not the default');

// What a window tells the system changes when what the field asks does, and only then.
$a = new TextState('');
$b = new TextState('');
same(TextInputTraits::key($a), TextInputTraits::key($b), 'two plain fields ask the same');
$b->traits = new TextInputTraits(keyboard: \KeyboardType::numberPad);
check(TextInputTraits::key($a) !== TextInputTraits::key($b), 'a keyboard type asks otherwise');
$a->password = true;
check(TextInputTraits::key($a) !== TextInputTraits::key(new TextState('')), 'and a password');
same('', TextInputTraits::key(null), 'none with no field');

// No field, or one that asks nothing: what each native side starts with, so that nothing changes for it.
same([0, 0, 2, 0, null, 0], IOSWindow::keyboardTraits(null), 'iOS: the default keyboard and Return key, sentences capitalized, as the launcher starts');
same([0x1, 6 | 0x2000000], AndroidWindow::editorInfo(null), 'Android: text, Done, never full screen, as MyphpActivity starts');
same(0, WindowsWindow::inputScope(null), 'Windows: IS_DEFAULT');
same([0, 0], LinuxWindow::inputPurpose(null), 'Linux: a free form, no hints');
$java = (string)file_get_contents(__DIR__ . '/../bin/template/android/src/com/owner888/myphp/MyphpActivity.java');
check(str_contains($java, 'sInputType = InputType.TYPE_CLASS_TEXT;') && str_contains($java, 'sImeOptions = EditorInfo.IME_ACTION_DONE | EditorInfo.IME_FLAG_NO_FULLSCREEN;'), 'MyphpActivity starts as editorInfo(null)');
$m = (string)file_get_contents(__DIR__ . '/../src/Platform/iOS/launcher/main.m');
check(str_contains($m, 'g_keyboard_type = UIKeyboardTypeDefault;') && str_contains($m, 'g_autocapitalization = UITextAutocapitalizationTypeSentences;') && str_contains($m, 'g_autocorrection = UITextAutocorrectionTypeDefault;'), 'the launcher starts as keyboardTraits(null)');
foreach (\TextContentType::cases() as $case) {
    check(str_contains($m, "@\"{$case->name}\": UITextContentType"), "the launcher knows TextContentType::{$case->name}");
}

// iOS: KeyboardType's cases are UIKeyboardType's, in its order; the Return key, capitalization, secure entry, content type.
same(['default', 'asciiCapable', 'numbersAndPunctuation', 'URL', 'numberPad', 'phonePad', 'namePhonePad', 'emailAddress', 'decimalPad', 'twitter', 'webSearch'],
    array_map(fn($c) => $c->name, \KeyboardType::cases()), 'KeyboardType in UIKeyboardType\'s order (0 to 10)');
same([7, 4, 0, 0, 'emailAddress', 1], IOSWindow::keyboardTraits($email), 'iOS: an email address\'s keyboard, Next, never capitalized, not corrected (UITextAutocorrectionTypeNo)');
$h->frame(2);
$v->swap = false; $h->frame(2);
same([4, 0, 2, 1, null, 0], IOSWindow::keyboardTraits($h->byLabel('PIN')?->edit), 'iOS: a PIN\'s number pad, secure entry');
$done = new TextState(''); $done->traits = new TextInputTraits(submitLabel: \SubmitLabel::done);
$send = new TextState(''); $send->traits = new TextInputTraits(submitLabel: \SubmitLabel::send, autocapitalization: \TextInputAutocapitalization::characters);
same([[0, 9, 2, 0, null, 0], [0, 7, 3, 0, null, 0]], [IOSWindow::keyboardTraits($done), IOSWindow::keyboardTraits($send)], 'iOS: Done is UIReturnKeyDone (9), Send 7, all characters 3');

// Android: the class and its variation, the text flags, the Return key's action.
same([0x1 | 0x20 | 0x80000, 5 | 0x2000000], AndroidWindow::editorInfo($email), 'Android: an email address, no suggestions, Next');
same([0x2 | 0x10, 6 | 0x2000000 | 0x1000000], AndroidWindow::editorInfo($h->byLabel('PIN')?->edit), 'Android: a PIN, a number password, learning nothing');
$secret = new TextState(''); $secret->password = true;
same([0x1 | 0x80, 6 | 0x2000000 | 0x1000000], AndroidWindow::editorInfo($secret), 'Android: a password');
same([0x1 | 0x20000, 0x40000000 | 0x2000000], AndroidWindow::editorInfo($note), 'Android: a field of lines: multi-line, Return breaks the line');
$name = new TextState(''); $name->traits = new TextInputTraits(contentType: \TextContentType::givenName, autocapitalization: \TextInputAutocapitalization::words, submitLabel: \SubmitLabel::search);
same([0x1 | 0x60 | 0x2000, 3 | 0x2000000], AndroidWindow::editorInfo($name), 'Android: a given name: a person\'s name, words capitalized, Search');
$code = new TextState(''); $code->traits = new TextInputTraits(contentType: \TextContentType::oneTimeCode);
$decimal = new TextState(''); $decimal->traits = new TextInputTraits(keyboard: \KeyboardType::decimalPad, contentType: \TextContentType::emailAddress);
same([[0x2, 6 | 0x2000000], [0x2 | 0x2000, 6 | 0x2000000]], [AndroidWindow::editorInfo($code), AndroidWindow::editorInfo($decimal)], 'Android: a one-time code\'s numbers; a keyboard type over what the field holds');

// Windows: the touch keyboard's input scope; a password's text services are off, so its scope is the default.
same([5, 28, 0, 29, 9, 28], [WindowsWindow::inputScope($email), WindowsWindow::inputScope($h->byLabel('PIN')?->edit), WindowsWindow::inputScope($secret), WindowsWindow::inputScope($decimal), WindowsWindow::inputScope($name), WindowsWindow::inputScope($code)],
    'Windows: email, a PIN\'s digits, a password\'s default, number, given name, a code\'s digits');

// Linux: GTK's purpose and hints.
same([[6, (1 << 3) | (1 << 1)], [9, 0], [8, 0], [7, 1 << 5], [2, 0]],
    [LinuxWindow::inputPurpose($email), LinuxWindow::inputPurpose($h->byLabel('PIN')?->edit), LinuxWindow::inputPurpose($secret), LinuxWindow::inputPurpose($name), LinuxWindow::inputPurpose($code)],
    'Linux: email in lowercase without spell checking, a PIN, a password, a name\'s words capitalized, a code\'s digits');
