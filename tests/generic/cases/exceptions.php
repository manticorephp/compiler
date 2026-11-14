<?php
// Errors raised inside prelude functions, caught in user code.
class AppError extends RuntimeException {}
function risky(int $d): int { return intdiv(10, $d); }
try { risky(0); } catch (DivisionByZeroError $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
try { array_combine([1, 2], [1]); } catch (ValueError $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
try { throw new AppError('boom'); } catch (AppError $e) { echo $e->getMessage(), "\n"; }
