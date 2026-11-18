<?php
// myphp upgrade, as flutter upgrade: MyPHP brought to its latest release the way it was installed (bin/myphp's
// upgrade()). Read from bin/myphp, which runs as a script: the routes, and what an install of install.sh keeps.
declare(strict_types=1);

namespace MyPHP\Tests;

$bin = (string)file_get_contents(dirname(__DIR__) . '/bin/myphp');
check(str_contains($bin, "'upgrade' => upgrade(\$args),"), 'myphp upgrade is a command');
check(preg_match('/^\tupgrade \[--check\]\s+upgrade MyPHP to its latest release/m', $bin) === 1, 'myphp help lists it');
check(str_contains($bin, "'https://api.github.com/repos/owner888/myphp/releases/latest'"), 'the latest release is GitHub\'s latest, prereleases left out');
check(str_contains($bin, "foreach (['build', 'dist', 'vendor', '.myphp'] as \$kept)"), 'an install of install.sh keeps what MyPHP made there (build/: the phones\' runtimes) and git ignores');
check(str_contains($bin, "Cmd::output(['git', '-C', \$root, 'status', '--porcelain'])") && str_contains($bin, "'pull', '--ff-only'"), 'a checkout pulls, never over changes of yours');
check(str_contains($bin, "sh(['composer', 'global', 'update', 'owner888/myphp'])"), 'Composer\'s global install updates the package');
check(str_contains($bin, "fail(\"MyPHP here is a project's dependency (\$root): in that project, composer update owner888/myphp\")"), 'a project\'s dependency is the project\'s to update');
check(str_contains($bin, "\$section('MyPHP', ["), 'myphp doctor says when a newer MyPHP is out');
check(str_contains((string)file_get_contents(dirname(__DIR__) . '/docs/cli.md'), '## myphp upgrade'), 'docs/cli.md has myphp upgrade');
