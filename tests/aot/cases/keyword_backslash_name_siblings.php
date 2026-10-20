<?php
// A reserved word glued to \Name is one name (print\x, list\x, int\x). 
namespace A;

function f(): string { return "f"; }

foreach (['print', 'list', 'int'] as $w) {
    try {
        if ($w === 'print') { print\A\f(); }
        if ($w === 'list') { list\f(); }
        if ($w === 'int') { int\f(); }
    } catch (\Error $e) {
        echo $e->getMessage(), "\n";
    }
}
