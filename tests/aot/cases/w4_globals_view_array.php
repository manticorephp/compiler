<?php
$counter = 7;
$list = [1, 2, 3];
$name = "x";
function viaGlobalKw(): void {
    global $counter, $list, $name;
    $counter++;
    $list[] = 4;
    $name .= "y";
    var_dump($counter, $list, $name);
}
function viaGlobalsView(): void {
    var_dump($GLOBALS['counter'], $GLOBALS['list'], $GLOBALS['name']);
    $GLOBALS['counter'] += 10;
    $GLOBALS['list'][] = 5;
}
viaGlobalKw();
viaGlobalsView();
viaGlobalKw();
var_dump($counter, $list, $name);
