<?php
// A pure data file under a psr-4 root (`return array (...)`, polyfill-mbstring's
// Resources/unidata shape) is a compile unit its `require` can read, while a
// script under the same root still never runs.
echo \Acme\Mb\Tables::lower('ABÉx'), "\n";
