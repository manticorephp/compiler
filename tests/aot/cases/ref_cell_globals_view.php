<?php
// A reference to a global that is also written through $GLOBALS[...] stored in an array (WordPress do_action_ref_array(..., [&$wp_admin_bar])).
class Bar { public array $items = []; public function render(): void { echo count($this->items), "\n"; } }
function do_ref(string $h, array $args): void { $GLOBALS['hooks'][$h] = $args; $args[0]->items[] = 'menu'; }
function render(): void {
    global $wp_admin_bar;
    static $rendered = false;
    if ($rendered) { return; }
    if (!is_object($wp_admin_bar)) { return; }
    do_ref('admin_bar_menu', array(&$wp_admin_bar));
    $wp_admin_bar->render();
    $rendered = true;
}
function init(string $cls): void { $GLOBALS['wp_admin_bar'] = new $cls(); }
init('Bar');
render();
echo count($GLOBALS["hooks"]["admin_bar_menu"]), " ", count($wp_admin_bar->items), "
";
