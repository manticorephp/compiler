<?php
function dt(mixed $x): string { return gettype($x); }
echo dt(42), " ", dt("hi"), " ", dt(3.14), " ", dt(null), " ", dt(true);
