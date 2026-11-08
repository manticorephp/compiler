<?php
if (\PHP_VERSION_ID < 90000 && \PHP_VERSION_ID >= 80100) {
    enum Dir
    {
        case Asc;
        case Desc;
    }
}
function f($d = Dir::Asc) { return $d === Dir::Asc ? 'asc' : 'desc'; }
echo f(), f(Dir::Desc), "\n";
