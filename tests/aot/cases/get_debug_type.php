<?php
function dt(mixed $x): string { return get_debug_type($x); }
echo dt(42), " ", dt("hi"), " ", dt(3.14), " ", dt(null), " ", dt(true);
