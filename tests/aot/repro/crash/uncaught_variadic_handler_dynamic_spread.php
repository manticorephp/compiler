<?php
// A variadic closure forwarding to $this->{$m}(...$args), installed as the exception handler, crashes (SIGBUS) when an exception is uncaught.
// issue: #575
class H
{
    public function handle(Throwable $e): void { echo "handled: ", $e->getMessage(), "\n"; }
    public function forwardsTo(string $m)
    {
        return fn (...$arguments) => $this->{$m}(...$arguments);
    }
}
$h = new H();
set_exception_handler($h->forwardsTo('handle'));
throw new RuntimeException('boom');
