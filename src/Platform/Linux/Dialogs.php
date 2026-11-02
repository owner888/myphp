<?php
declare(strict_types=1);

namespace MyPHP\Platform\Linux;

use FFI\CData;
use MyPHP\{App, L10n, MessageOptions, MessageResult, OpenDialogOptions, SaveDialogOptions};
use MyPHP\Platform;

/**
 * GTK's dialogs (mygo's linux dialog.go): the file chooser through
 * GtkFileChooserNative, which is the desktop portal's where there is one,
 * and messages as GtkMessageDialogs, modal to their parent window. Each
 * returns once the dialog closes, GTK's main loop turning meanwhile.
 */
final class Dialogs implements Platform\Dialogs
{
    private const RESPONSE_ACCEPT = -3, RESPONSE_OK = -5, RESPONSE_CANCEL = -6;
    private const ACTION_OPEN = 0, ACTION_SAVE = 1, ACTION_SELECT_FOLDER = 2;
    private const MODAL = 1, DESTROY_WITH_PARENT = 2;
    private const TYPES = ['info' => 0, 'warning' => 1, 'question' => 2, 'error' => 3];

    /** @return list<string> */
    public function open(OpenDialogOptions $o): array
    {
        $f = Gtk::ffi();
        $t = L10n::in(App::locale());
        [$action, $title] = $o->directory ? [self::ACTION_SELECT_FOLDER, $t->get('selectFolder')] : [self::ACTION_OPEN, $t->get('open')];
        $fc = $f->gtk_file_chooser_native_new($o->title !== '' ? $o->title : $title, self::parent($o->parent), $action,
            $o->buttonLabel !== '' ? $o->buttonLabel : '_' . $t->get('open'), '_' . $t->get('cancel'));
        $f->gtk_file_chooser_set_select_multiple($fc, $o->multiple ? 1 : 0);
        $f->gtk_file_chooser_set_create_folders($fc, $o->createDirectories ? 1 : 0);
        self::setup($fc, $o->defaultPath, $o->filters, $o->showHiddenFiles, false);
        $paths = [];
        if ($f->gtk_native_dialog_run($fc) === self::RESPONSE_ACCEPT) {
            $list = $f->gtk_file_chooser_get_filenames($fc);
            for ($node = $list; $node !== null; $node = $node->next) {
                $paths[] = \FFI::string($f->cast('char*', $node->data));
                $f->g_free($node->data);
            }
            $f->g_slist_free($list);
        }
        $f->g_object_unref($fc);
        return $paths;
    }

    public function save(SaveDialogOptions $o): string
    {
        $f = Gtk::ffi();
        $t = L10n::in(App::locale());
        $fc = $f->gtk_file_chooser_native_new($o->title !== '' ? $o->title : $t->get('save'), self::parent($o->parent), self::ACTION_SAVE,
            $o->buttonLabel !== '' ? $o->buttonLabel : '_' . $t->get('save'), '_' . $t->get('cancel'));
        $f->gtk_file_chooser_set_do_overwrite_confirmation($fc, 1);
        $f->gtk_file_chooser_set_create_folders($fc, $o->createDirectories ? 1 : 0);
        self::setup($fc, $o->defaultPath, $o->filters, $o->showHiddenFiles, true);
        $path = '';
        if ($f->gtk_native_dialog_run($fc) === self::RESPONSE_ACCEPT) {
            $p = $f->gtk_file_chooser_get_filename($fc);
            if ($p !== null) {
                $path = \FFI::string($p);
                $f->g_free($p);
            }
        }
        $f->g_object_unref($fc);
        return $path;
    }

    /** The folder and name a chooser starts at, and its filters (mygo's setupChooser). @param list<\MyPHP\FileFilter> $filters */
    private static function setup(CData $fc, string $defaultPath, array $filters, bool $hidden, bool $save): void
    {
        $f = Gtk::ffi();
        $f->gtk_file_chooser_set_show_hidden($fc, $hidden ? 1 : 0);
        if ($defaultPath !== '') {
            if (is_dir($defaultPath)) {
                $f->gtk_file_chooser_set_current_folder($fc, $defaultPath);
            } else {
                $f->gtk_file_chooser_set_current_folder($fc, dirname($defaultPath));
                if ($save) { $f->gtk_file_chooser_set_current_name($fc, basename($defaultPath)); }
            }
        }
        foreach ($filters as $filter) {
            $ff = $f->gtk_file_filter_new();
            $f->gtk_file_filter_set_name($ff, $filter->name !== '' ? $filter->name : implode(', ', $filter->extensions));
            foreach ($filter->extensions as $e) {
                $e = ltrim($e, '.');
                if ($e === '*') {
                    $f->gtk_file_filter_add_pattern($ff, '*');
                    continue;
                }
                $f->gtk_file_filter_add_pattern($ff, "*.$e");
                if (strtoupper($e) !== $e) { $f->gtk_file_filter_add_pattern($ff, '*.' . strtoupper($e)); }
            }
            $f->gtk_file_chooser_add_filter($fc, $ff);
        }
    }

    /** @param list<string> $buttons */
    public function message(MessageOptions $o, array $buttons, int $cancel): MessageResult
    {
        $f = Gtk::ffi();
        $dlg = self::dialog($o->parent, $o->type, $o->message !== '' ? $o->message : $o->title, $o->detail);
        if ($o->title !== '') { $f->gtk_window_set_title($dlg, $o->title); }
        foreach ($buttons as $i => $label) {
            $b = $f->gtk_dialog_add_button($dlg, $label, $i);
            if (in_array($i, $o->destructiveButtons, true)) {
                $f->gtk_style_context_add_class($f->gtk_widget_get_style_context($b), 'destructive-action');
            }
        }
        $f->gtk_dialog_set_default_response($dlg, $o->defaultButton);
        $check = null;
        if ($o->checkboxLabel !== '') {
            $check = $f->gtk_check_button_new_with_label($o->checkboxLabel);
            $f->gtk_toggle_button_set_active($check, $o->checkboxChecked ? 1 : 0);
            $f->gtk_container_add($f->gtk_message_dialog_get_message_area($dlg), $check);
            $f->gtk_widget_show($check);
        }
        $response = $f->gtk_dialog_run($dlg);
        $result = new MessageResult($response < 0 ? $cancel : $response, $check !== null && $f->gtk_toggle_button_get_active($check)); // below 0: Escape or the window's close button
        $f->gtk_widget_destroy($dlg);
        return $result;
    }

    /**
     * A message with a text field (IINA's prompt), OK and Cancel: what is
     * wrong with the text, as $check says while it is typed, shows under
     * the field with OK disabled.
     */
    public function prompt(?\MyPHP\Window $window, string $prompt, string $default, string $detail = '', ?\Closure $check = null): ?string
    {
        $f = Gtk::ffi();
        $dlg = $this->okCancel($window, $prompt, $detail);
        $area = $f->gtk_message_dialog_get_message_area($dlg);
        $entry = $f->gtk_entry_new();
        $f->gtk_entry_set_text($entry, $default);
        $f->gtk_entry_set_activates_default($entry, 1);
        $f->gtk_container_add($area, $entry);
        $f->gtk_widget_show($entry);
        if ($check !== null) {
            $label = $f->gtk_label_new('');
            $f->gtk_label_set_xalign($label, 0.0);
            $f->gtk_style_context_add_class($f->gtk_widget_get_style_context($label), 'dim-label');
            $f->gtk_container_add($area, $label);
            $f->gtk_widget_show($label);
            $ok = $f->gtk_dialog_get_widget_for_response($dlg, self::RESPONSE_OK);
            $validate = function () use ($check, $entry, $label, $ok, $f): void {
                $wrong = $check($f->gtk_entry_get_text($entry));
                $f->gtk_label_set_label($label, $wrong);
                $f->gtk_widget_set_sensitive($ok, $wrong === '' ? 1 : 0);
            };
            Gtk::connect($entry, 'changed', 'widget', fn($e, $data) => $validate());
            $validate();
        }
        $text = $f->gtk_dialog_run($dlg) === self::RESPONSE_OK ? $f->gtk_entry_get_text($entry) : null;
        $f->gtk_widget_destroy($dlg);
        return $text;
    }

    /** A user's name and password (IINA's), the fields' placeholders $user and $password. @return ?array{0: string, 1: string} */
    public function login(?\MyPHP\Window $window, string $prompt, string $detail, string $user, string $password): ?array
    {
        $f = Gtk::ffi();
        $dlg = $this->okCancel($window, $prompt, $detail);
        $area = $f->gtk_message_dialog_get_message_area($dlg);
        $fields = [];
        foreach ([$user, $password] as $i => $placeholder) {
            $entry = $f->gtk_entry_new();
            $f->gtk_entry_set_placeholder_text($entry, $placeholder);
            $f->gtk_entry_set_activates_default($entry, 1);
            if ($i === 1) { $f->gtk_entry_set_visibility($entry, 0); }
            $f->gtk_container_add($area, $entry);
            $f->gtk_widget_show($entry);
            $fields[] = $entry;
        }
        $got = $f->gtk_dialog_run($dlg) === self::RESPONSE_OK ? [$f->gtk_entry_get_text($fields[0]), $f->gtk_entry_get_text($fields[1])] : null;
        $f->gtk_widget_destroy($dlg);
        return $got;
    }

    /** A page's file input: the file chooser over its window, null when canceled. @return ?list<string> */
    public function chooseForPage(\MyPHP\Window $window, bool $multiple, bool $dirs): ?array
    {
        $paths = $this->open(new OpenDialogOptions($window, directory: $dirs, multiple: $multiple));
        return $paths === [] ? null : $paths;
    }

    /** A message dialog with Cancel and OK, OK the default. */
    private function okCancel(?\MyPHP\Window $window, string $prompt, string $detail): CData
    {
        $f = Gtk::ffi();
        $t = L10n::in(App::locale());
        $dlg = self::dialog($window, 'question', $prompt, $detail);
        $f->gtk_dialog_add_button($dlg, '_' . $t->get('cancel'), self::RESPONSE_CANCEL);
        $f->gtk_dialog_add_button($dlg, '_' . $t->get('ok'), self::RESPONSE_OK);
        $f->gtk_dialog_set_default_response($dlg, self::RESPONSE_OK);
        return $dlg;
    }

    /** A GtkMessageDialog of no buttons, modal to $parent's window. */
    private static function dialog(?\MyPHP\Window $parent, string $type, string $message, string $detail): CData
    {
        $f = Gtk::ffi();
        $dlg = $f->gtk_message_dialog_new(self::parent($parent), self::MODAL | self::DESTROY_WITH_PARENT, self::TYPES[$type] ?? 4, 0, '%s', $message); // GTK_MESSAGE_OTHER, GTK_BUTTONS_NONE
        if ($detail !== '') {
            $f->gtk_message_dialog_format_secondary_text($dlg, '%s', $detail);
        }
        return $dlg;
    }

    /** The GtkWindow of $w, the dialog's parent; null for none, or one gone. */
    private static function parent(?\MyPHP\Window $w): ?CData
    {
        $n = $w?->native();
        return $n instanceof Window && $n->isVisible() ? $n->handle() : null;
    }
}
