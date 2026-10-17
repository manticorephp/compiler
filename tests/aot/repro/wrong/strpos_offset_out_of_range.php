<?php
// strpos/stripos with an offset past the end must throw ValueError, not return false.
// issue: #316
try { var_dump(strpos('abc', 'a', 4)); } catch (ValueError $e) { echo $e->getMessage(), "\n"; }
try { var_dump(stripos('abc', 'a', -4)); } catch (ValueError $e) { echo $e->getMessage(), "\n"; }
