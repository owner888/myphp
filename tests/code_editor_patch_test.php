<?php
// Patches (mygo's patch_test.go): parsing git's unified diffs, applying
// them, and showing one in the diff viewer without its files.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\Editor\{DiffState, FilePatch};

require_once __DIR__ . '/DiffTest.php';

$files = FilePatch::parse(<<<'PATCH'
diff --git a/greet.go b/greet.go
index 3b18e51..a2e8f0c 100644
--- a/greet.go
+++ b/greet.go
@@ -1,5 +1,6 @@ package main
 package main
 
-func greet() string {
-	return "hello"
+// greet greets.
+func greet(name string) string {
+	return "hello " + name
 }
diff --git a/old.txt b/new.txt
similarity 90%
rename from old.txt
rename to new.txt
--- a/old.txt
+++ b/new.txt
@@ -1 +1 @@
-a
\ No newline at end of file
+b
diff --git a/added.md b/added.md
new file mode 100644
--- /dev/null
+++ b/added.md
@@ -0,0 +1,2 @@
+# Title
+text

PATCH);
same(3, count($files), 'three files');
$f = $files[0];
same(['greet.go', 'greet.go', 1], [$f->oldName, $f->newName, count($f->hunks)], 'the first file');
$h = $f->hunks[0];
same([1, 5, 1, 6, 'package main', 8], [$h->oldStart, $h->oldLines, $h->newStart, $h->newLines, $h->section, count($h->lines)], 'its hunk');
same("package main\n\n// greet greets.\nfunc greet(name string) string {\n\treturn \"hello \" + name\n}\n",
    $f->apply("package main\n\nfunc greet() string {\n\treturn \"hello\"\n}\n"), 'it applies');
$r = $files[1];
same(['old.txt', 'new.txt'], [$r->oldName, $r->newName], 'a rename');
same("b\n", $r->apply('a'), 'a rename applies, past its missing newline');
$n = $files[2];
same(['', 'added.md', '100644'], [$n->oldName, $n->newName, $n->newMode], 'a new file');
same("# Title\ntext\n", $n->apply(''), 'a new file applies');
$threw = false;
try { $f->apply("something else\n"); } catch (\RuntimeException) { $threw = true; }
check($threw, 'a patch does not apply to another text');

// A patch shows without the files, numbered as they are.
$files = FilePatch::parse("--- a/x.go\n+++ b/x.go\n@@ -10,3 +10,3 @@ func f() {\n \ta()\n-\tb()\n+\tc()\n \td()\n@@ -40,2 +40,3 @@\n \te()\n+\tf()\n }\n");
$d = new DiffState;
$d->setPatch($files[0]);
diffViewer($d, 800, 400);
same(2, count($d->hunks()), 'the patch\'s two hunks');
same(40, $d->sides[1]->number(3), 'line 3 of the new text is line 40 of the file');
same(11, $d->sides[0]->number(1), 'line 1 of the old text is line 11 of the file');
check($d->new->language() !== null && str_starts_with((string)$d->sides[1]->blks[0]->id, '@@ -10,3 +10,3 @@'), 'the file\'s language, and the hunks\' headers');
