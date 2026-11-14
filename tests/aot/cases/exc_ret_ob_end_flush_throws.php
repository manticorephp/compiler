<?php
// A throwing output-buffer callback: ob_end_flush() writes the buffer as it was, closes the level, and the exception reaches the caller.
ob_start(function ($b) { throw new Exception("x"); });
echo "a\n";
try { ob_end_flush(); } catch (Exception $e) { echo "caught ", $e->getMessage(), " level=", ob_get_level(), "\n"; }
ob_start(function ($b) { throw new Exception("y"); });
echo "b\n";
try { $s = ob_get_flush(); } catch (Exception $e) { echo "caught ", $e->getMessage(), " level=", ob_get_level(), "\n"; }
echo "end\n";
