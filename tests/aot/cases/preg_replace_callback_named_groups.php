<?php
// preg_replace_callback hands a named group to the callback under its name too.
echo preg_replace_callback('/\$(?P<ob>\{)?(?P<name>[A-Z_]+)?(?P<cb>\})?/', function ($m) { return '[' . json_encode($m) . ']'; }, 'q ${APP_ENV} x'), "\n";
echo preg_replace_callback('/
   \$ (?P<name>[A-Z_]+)   # name
/x', fn ($m) => strtolower($m['name']), 'a $FOO b'), "\n";
