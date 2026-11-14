<?php
// `@param callable` in a docblock does not narrow the parameter: strings, pairs
// and closures keep their identity (WordPress add_filter / _wp_filter_build_unique_id).
/** @param callable $callback */
function build_id($callback): ?string {
    if (is_string($callback)) { return $callback; }
    if (is_object($callback)) { return 'obj'; }
    if (!isset($callback[1]) || !is_string($callback[1])) { return null; }
    return (is_object($callback[0]) ? get_class($callback[0]) : $callback[0]) . '::' . $callback[1];
}
class Hook {
    public $callbacks = [];
    /** @param callable $callback */
    public function add_filter(string $hook, $callback, int $priority = 10): void {
        $idx = build_id($callback);
        if ($idx === null) { return; }
        $this->callbacks[$priority][$idx] = $callback;
    }
    public static function lo(string $s): string { return strtolower($s); }
}
$filters = ['init' => new Hook()];
/** @param callable $callback */
function add_filter(string $hook, $callback, int $priority = 10): bool {
    global $filters;
    $filters[$hook]->add_filter($hook, $callback, $priority);
    return true;
}
add_filter('init', 'trim');
add_filter('init', ['Hook', 'lo'], 5);
add_filter('init', [new Hook(), 'add_filter'], 20);
add_filter('init', function () {});
foreach ($filters['init']->callbacks as $p => $cbs) { echo $p, ': ', implode(',', array_keys($cbs)), "\n"; }
echo (($filters['init']->callbacks[5]['Hook::lo']))('ABC'), "\n";
