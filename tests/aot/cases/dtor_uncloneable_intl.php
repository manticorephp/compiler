<?php
$f = new IntlListFormatter('en');
try { clone $f; } catch (Throwable $e) { echo get_class($e), ": ", $e->getMessage(), "\n"; }
$r = ResourceBundle::create('en', 'x', false);
if ($r !== null) { try { clone $r; } catch (Throwable $e) { echo get_class($e), "\n"; } }
echo "end\n";
