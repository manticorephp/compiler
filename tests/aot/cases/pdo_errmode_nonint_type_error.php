<?php
// PDO::setAttribute(ATTR_ERRMODE, non-int) returns true instead of throwing TypeError
try { $db = new PDO('sqlite::memory:'); var_dump($db->setAttribute(PDO::ATTR_ERRMODE, 'x')); } catch (Throwable $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
