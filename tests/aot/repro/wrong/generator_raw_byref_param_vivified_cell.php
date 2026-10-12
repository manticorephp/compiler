<?php
// A generator's raw-typed by-ref param loses the lazy write when the caller's variable is an undefined (vivified) local.
// issue: #187
function gen(?array &$r = null) { $r = [1]; yield 1; }
function h() { foreach (gen($y) as $v) { echo count($y), $v, "\n"; } }
h();
