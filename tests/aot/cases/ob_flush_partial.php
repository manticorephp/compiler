<?php

// ob_flush hands the bytes downstream but KEEPS the level open.
ob_start();
echo "first";
ob_flush();
echo "second";
echo "|len-after-flush=", ob_get_length();
ob_end_flush();
echo "|\n";
echo "level=", ob_get_level(), "\n";

// Flushed bytes reach the ENCLOSING buffer, never the level they came from.
ob_start();
ob_start();
echo "inner1";
ob_flush();
echo "inner2";
ob_end_flush();
$outer = ob_get_clean();
echo "outer=[", $outer, "]\n";

// ob_clean after a flush drops only what accumulated since.
ob_start();
echo "kept-";
ob_flush();
echo "dropped";
ob_clean();
echo "-tail";
ob_end_flush();
echo "|\n";
