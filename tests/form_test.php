<?php
// Fields and forms (mygo's form_test.go): what assistive technology hears
// of a field's control, its description and error, the groups a field
// names, clicks on labels; a disabled fieldset; a form's columns and its
// labels level with their controls.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{AccessNode, Context, Node, Role, TextState};

/** The node of the access tree with $role named $label. */
function formNode(Headless $h, int $role, string $label): ?AccessNode
{
    foreach ($h->rt->accessTree?->nodes ?? [] as $n) { if ($n->role === $role && $n->label === $label) { return $n; } }
    return null;
}

// A field: its label names the control, its texts describe it, the error first; a control naming itself names the field's group; label clicks.
$name = new TextState(''); $email = new TextState(''); $font = 'Arial'; $size = 'Small'; $notify = false; $express = false;
$emailError = 'Enter an email address.';
$h = new Headless(function (Context $c) use ($name, $email, &$font, &$size, &$notify, &$express, $emailError): void {
    $c->column(0, 12)->alignItems(Node::START);
      $c->field('Name', fn() => $c->input($name), 'As on your passport.');
      $c->field('Email', fn() => $c->input($email), 'For receipts.', $emailError);
      $c->field('Notifications', function () use ($c, &$notify): void { $c->checkbox('Send emails', $notify); }); // by reference: an arrow function would copy it
      $c->field('Delivery', function () use ($c, &$size): void {
          $c->radioGroup(function () use ($c, &$size): void { $c->radio('Small', $size, 'Small'); $c->radio('Large', $size, 'Large'); });
      });
      $c->field('Font', fn() => $c->combobox($font, ['Arial', 'Helvetica', 'Times'], 200));
      $c->field('Express', function () use ($c, &$express): void { $c->switch('', $express); });
      $c->button('Cut')->tooltip('Cut the selection');
    $c->end();
}, 500, 700);
$h->frame();
$h->input->push(['accessOn']); $h->frame();
$n = formNode($h, Role::TEXT_FIELD, 'Name');
check($n !== null && $n->description === 'As on your passport.' && ($n->states & AccessNode::INVALID) === 0, 'Name: described, valid');
$n = formNode($h, Role::TEXT_FIELD, 'Email');
check($n !== null && $n->description === "$emailError\nFor receipts." && ($n->states & AccessNode::INVALID) !== 0, 'Email: the error first, invalid');
check($h->byText($emailError) !== null && $h->byText('For receipts.') !== null, 'the texts below show');
check(formNode($h, Role::CHECK_BOX, 'Send emails') !== null && formNode($h, Role::GROUP, 'Notifications') !== null, 'a check box names itself, the field its group');
check(formNode($h, Role::RADIO_GROUP, 'Delivery') !== null, 'a radio group named by its field');
check(formNode($h, Role::COMBO_BOX, 'Font') !== null && formNode($h, Role::SWITCH, 'Express') !== null, 'a combobox and a switch named by theirs');
same('Cut the selection', formNode($h, Role::BUTTON, 'Cut')?->description, 'a tooltip describes its button');
$h->clickText('Name');
$h->type('Ada');
same('Ada', $name->text, 'a click on the label focuses the input');
$h->clickText('Notifications'); $h->frame();
check($notify, 'a click on the label checks the check box');
$h->clickText('Express'); $h->frame();
check($express, 'and turns the switch on');
$size = 'Small';
$h->clickText('Delivery'); $h->frame();
check($size === 'Small' && in_array('Small', $h->texts($h->focused()), true), 'in a group, the one chosen takes the focus');

// A disabled fieldset disables what is inside it: label clicks, its button, its input; and says so.
$name = new TextState(''); $saved = 0;
$h = new Headless(function (Context $c) use ($name, &$saved): void {
    $fs = $c->fieldset('Account', function () use ($c, $name, &$saved): void {
        $c->field('Name', fn() => $c->input($name));
        if ($c->clicked($c->button('Save'))) { $saved++; }
    });
    $fs->disabled = true;
}, 400, 300);
$h->frame();
$h->clickText('Name');
check($h->focused() === null, 'a click on the label focuses no disabled input');
$h->clickText('Save');
check($saved === 0 && $h->focused() === null, 'a disabled button takes no click');
$r = $h->rect($h->byText('Name'));
$h->click($r[0] + $r[2] / 2, $r[1] + $r[3] + 20);
check($h->focused() === null, 'nor the input a click');
$h->input->push(['accessOn']); $h->frame();
check((formNode($h, Role::GROUP, 'Account')?->states & AccessNode::DISABLED) !== 0, 'the fieldset says it is disabled');

// A form: the controls at one edge beside the widest label, a fieldset's too; labels ending at one edge, level with their controls' text; the description below its input.
$name = new TextState(''); $email = new TextState(''); $city = new TextState(''); $notify = false; $express = false;
$h = new Headless(function (Context $c) use ($name, $email, $city, &$notify, &$express): void {
    $c->form(function () use ($c, $name, $email, $city, &$notify, &$express): void {
        $c->field('Name', fn() => $c->input($name, '', 0));
        $c->field('Email address', fn() => $c->input($email, '', 0), 'For receipts.');
        $c->field('Notifications', function () use ($c, &$notify): void { $c->checkbox('Send emails', $notify); }); // by reference: an arrow function would copy it
        $c->field('Express', function () use ($c, &$express): void { $c->switch('', $express); });
        $c->fieldset('Shipping', function () use ($c, $city): void { $c->field('City', fn() => $c->input($city, '', 0)); });
    })->width(480);
}, 500, 500);
$h->frame(); $h->frame();
$h->input->push(['accessOn']); $h->frame();
$box = fn(int $role, string $label) => formNode($h, $role, $label)?->bounds ?? [NAN, NAN, NAN, NAN];
$near = fn(float $a, float $b) => abs($a - $b) < 1;
$nameIn = $box(Role::TEXT_FIELD, 'Name'); $emailIn = $box(Role::TEXT_FIELD, 'Email address'); $cityIn = $box(Role::TEXT_FIELD, 'City');
check($near($nameIn[0], $emailIn[0]) && $near($nameIn[0], $cityIn[0]) && $near($nameIn[0], $box(Role::CHECK_BOX, 'Send emails')[0]), 'the controls start at one edge: ' . json_encode([$nameIn[0], $emailIn[0], $cityIn[0]]));
$nameL = $h->rect($h->byText('Name')); $emailL = $h->rect($h->byText('Email address'));
check($near($nameL[0] + $nameL[2], $emailL[0] + $emailL[2]) && $emailL[0] + $emailL[2] <= $emailIn[0], 'the labels end at one edge, before the controls');
check($near($nameL[1], $nameIn[1] + 7) && $near($emailL[1], $emailIn[1] + 7), 'labels level with the text inside the inputs, their border and padding, as mygo\'s: ' . json_encode([$nameL[1], $nameIn[1]]));
$notifyL = $h->rect($h->byText('Notifications')); $own = $h->rect($h->byText('Send emails') ?? $h->byLabel('Send emails'));
check($near($notifyL[1] + $notifyL[3] / 2, $own[1] + $own[3] / 2), 'on the check box\'s text');
$sw = $box(Role::SWITCH, 'Express'); $l = $h->rect($h->byText('Express'));
check(abs($l[1] + $l[3] / 2 - ($sw[1] + $sw[3] / 2)) <= 1.5, 'centered on the switch');
$d = $h->rect($h->byText('For receipts.'));
check($near($d[0], $emailIn[0]) && $d[1] >= $emailIn[1] + $emailIn[3], 'the description below its input');
