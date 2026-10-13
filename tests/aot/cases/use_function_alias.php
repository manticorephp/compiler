<?php
// use function X as alias is not resolved
namespace App;

use function strtoupper as up;

echo up('abc'), "\n";
