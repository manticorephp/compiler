<?php

echo serialize(null), "\n";
echo serialize(true), "\n";
echo serialize(false), "\n";
echo serialize(0), "\n";
echo serialize(42), "\n";
echo serialize(-7), "\n";
echo serialize(PHP_INT_MAX), "\n";
echo serialize(PHP_INT_MIN), "\n";
echo serialize(0.0), "\n";
echo serialize(-0.0), "\n";
echo serialize(1.0), "\n";
echo serialize(0.1), "\n";
echo serialize(0.1 + 0.2), "\n";
echo serialize(-2.5), "\n";
echo serialize(1e100), "\n";
echo serialize(1.0e-7), "\n";
echo serialize(INF), "\n";
echo serialize(-INF), "\n";
echo serialize(NAN), "\n";
