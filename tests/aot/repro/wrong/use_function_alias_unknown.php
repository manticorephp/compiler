<?php
// `use function name as alias` is not resolved: calling the alias throws "Call to undefined function"
// issue: #180
namespace App;

use function strtoupper as up;

echo up('abc'), "\n";
