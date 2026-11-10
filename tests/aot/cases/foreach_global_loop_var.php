<?php
// A foreach loop variable that is also a global (some function says `global $action`)
// is written through the global's storage: top level, keyed, and inside a function.
function uses_global() { global $action, $key; return "$key=$action"; }
$key = 'k0';
function hook(string $h): void { echo "hook $h\n"; }
foreach (array('user_register', 'deleted_user') as $action) {
    hook($action);
}
echo uses_global(), "\n";
foreach (['a' => 1, 'b' => 2] as $key => $action) { echo "$key:$action "; }
echo "\n", uses_global(), "\n";
function inner(): void {
    global $action;
    foreach (['x', 'y'] as $action) { echo "in $action\n"; }
}
inner();
echo uses_global(), "\n";
