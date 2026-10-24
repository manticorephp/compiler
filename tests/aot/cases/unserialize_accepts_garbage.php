<?php
// unserialize accepts malformed floats and counts (d:1e;, a:N;0:{}) that php rejects with false.
foreach (['d:1e;', 'd:-;', 'd:M0;', 'a:N;0:{}'] as $s) {
    var_dump(@unserialize($s));
}
