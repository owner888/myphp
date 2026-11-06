<?php
// Settings, {section} of /settings/{section}: General, or Account with the name.
declare(strict_types=1);

return fn(string $section) => Form()(
    Section()(
        NavigationLink('General', value: '/settings/general'),
        NavigationLink('Account', value: '/settings/account'),
    ),
    $section === 'account'
        ? Section(header: 'Account')(TextField('Your name', text: $this->name))
        : Section(header: 'General', footer: 'Each section is a page of the stack, Back the way to the one before.')(LabeledContent('Version', '1.0')),
)->navigationTitle($section === 'account' ? 'Account' : 'General');
