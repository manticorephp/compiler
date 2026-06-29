<?php
class AppError extends Exception { public int $code; public function __construct(int $c) { $this->code = $c; } }
class HttpError extends AppError {}
class NotFound extends HttpError {}

try {
    throw new NotFound(404);
} catch (AppError $e) {
    echo "caught:", $e->code;
}
