<?php
$f = new SplFixedArray(2);
try { var_dump(isset($f["x"])); } catch (Throwable $e) { echo get_class($e), ": ", $e->getMessage(), "\n"; }
try { var_dump(isset($f["1"])); var_dump(isset($f[5])); var_dump(isset($f[null])); } catch (Throwable $e) { echo get_class($e), ": ", $e->getMessage(), "\n"; }
try { var_dump(isset($f[[]])); } catch (Throwable $e) { echo get_class($e), ": ", $e->getMessage(), "\n"; }
try { var_dump($f->offsetExists("x")); } catch (Throwable $e) { echo get_class($e), ": ", $e->getMessage(), "\n"; }
try { var_dump(empty($f["x"])); } catch (Throwable $e) { echo get_class($e), ": ", $e->getMessage(), "\n"; }
