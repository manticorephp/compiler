<?php
function h(Missing\G $g) {}
function s($x) {
    var_dump($x instanceof Missing\E);
    try { h($x); } catch (Missing\F $e) {}
}
s(1);
