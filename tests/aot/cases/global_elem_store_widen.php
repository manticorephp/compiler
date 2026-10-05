<?php
// A concrete-element array global that another scope element-stores a
// different kind into holds both kinds (symfony/console Application::run
// saving and restoring $_ENV['SHELL_VERBOSITY'] around configureIO's int).
function configure(int $v): void {
    $_ENV['SHELL_VERBOSITY'] = $v;
    $_SERVER['SHELL_VERBOSITY'] = $v;
}
function run(): void {
    $empty = new \stdClass();
    $prev = [$_ENV['SHELL_VERBOSITY'] ?? $empty, $_SERVER['SHELL_VERBOSITY'] ?? $empty];
    configure(1);
    var_dump($_ENV['SHELL_VERBOSITY'], $_SERVER['SHELL_VERBOSITY']);
    if ($empty === $_ENV['SHELL_VERBOSITY'] = $prev[0]) {
        unset($_ENV['SHELL_VERBOSITY']);
    }
    if ($empty === $_SERVER['SHELL_VERBOSITY'] = $prev[1]) {
        unset($_SERVER['SHELL_VERBOSITY']);
    }
    var_dump(isset($_ENV['SHELL_VERBOSITY']), isset($_SERVER['SHELL_VERBOSITY']));
}
run();
var_dump(is_array(getenv()), is_string($_ENV['PATH'] ?? ''));

$g = ['a' => 'x'];
function put(int $v): void { global $g; $g['n'] = $v; }
function back(): void { global $g; $g['n'] = 'str'; $g['f'] = 1.5; $g['l'] = [1, 2]; }
put(1); var_dump($g); back(); var_dump($g);

$list = ['p', 'q'];
function push_int(): void { global $list; $list[] = 3; $list[0] = null; }
push_int(); var_dump($list);

function counter(): array {
    static $seen = ['init' => 'yes'];
    $seen['n'] = ($seen['n'] ?? 0) + 1;
    return $seen;
}
counter(); var_dump(counter());
