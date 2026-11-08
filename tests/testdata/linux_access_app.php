<?php
// An app of native UI that reads its own window through ATK, as Orca does
// through AT-SPI (linux_access_test.php): the tree, the states, a field's
// text, a press, and the tree after it. Prints JSON and quits.
declare(strict_types=1);

use MyPHP\{App, Window, WindowOptions};
use MyPHP\UI\Content;

require __DIR__ . '/../../src/autoload.php';

$s = new class { public int $n = 0; public bool $on = true; public string $name = 'Ada'; };
App::setName('AccessTest');
App::whenReady(function () use ($s) {
    $win = new Window(new WindowOptions(title: 'Access', width: 400, height: 300, content: Content::view(fn() => VStack()(
        Text('Hello'),
        Button('Add', action: fn() => $s->n++),
        Toggle('Wi-Fi', isOn: $s->on),
        TextField('Name', text: $s->name),
        Text('Count {n}', n: $s->n),
    ))));
    $a = FFI::cdef('
        void* gtk_widget_get_accessible(void*); void* gtk_widget_get_toplevel(void*);
        int atk_object_get_n_accessible_children(void*); void* atk_object_ref_accessible_child(void*, int);
        const char* atk_object_get_name(void*); int atk_object_get_role(void*); const char* atk_role_get_name(int);
        int atk_action_do_action(void*, int); const char* atk_action_get_name(void*, int);
        void* atk_object_ref_state_set(void*); int atk_state_set_contains_state(void*, int); int atk_state_type_for_name(const char*);
        char* atk_text_get_text(void*, int, int); int atk_text_get_character_count(void*); void g_free(void*);
        int atk_object_get_index_in_parent(void*);', 'libgtk-3.so.0');
    $tree = function () use ($a, $win): array {
        $area = (fn() => $this->area)->call((fn() => $this->native)->call($win));
        $acc = $a->gtk_widget_get_accessible($area);
        $out = [];
        for ($i = 0; $i < $a->atk_object_get_n_accessible_children($acc); $i++) {
            $o = $a->atk_object_ref_accessible_child($acc, $i);
            $st = $a->atk_object_ref_state_set($o);
            $role = $a->atk_role_get_name($a->atk_object_get_role($o)); // ATK_ROLE_PUSH_BUTTON's name: "push button", "button" in at-spi2-core's ATK of late
            $e = ['role' => $role === 'button' ? 'push button' : $role, 'name' => (string)$a->atk_object_get_name($o), 'index' => $a->atk_object_get_index_in_parent($o),
                'checked' => (bool)$a->atk_state_set_contains_state($st, $a->atk_state_type_for_name('checked')),
                'editable' => (bool)$a->atk_state_set_contains_state($st, $a->atk_state_type_for_name('editable'))];
            if ($e['role'] === 'push button') { $e['action'] = $a->atk_action_get_name($o, 0); }
            if ($e['role'] === 'entry') { $t = $a->atk_text_get_text($o, 0, -1); $e['text'] = FFI::string($t); $a->g_free($t); }
            $out[] = $e;
        }
        return [$out, $acc];
    };
    App::after(2.0, function () use ($tree, $a, $s) {
        [$before, $acc] = $tree();
        foreach ($before as $i => $e) { if ($e['name'] === 'Add') { $a->atk_action_do_action($a->atk_object_ref_accessible_child($acc, $i), 0); } }
        App::after(1.0, function () use ($tree, $before, $s) {
            echo json_encode(['before' => $before, 'after' => $tree()[0], 'n' => $s->n]), "\n";
            App::quit();
        });
    });
});
App::run();
