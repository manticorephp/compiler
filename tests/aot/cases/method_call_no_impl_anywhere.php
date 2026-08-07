<?php

// A method call whose receiver resolves to NO implementation anywhere is php's
// runtime `Error: Call to undefined method`, raised when the call is reached.
// The dispatch already drops every switch ARM whose candidate cannot resolve
// the method; the DEFAULT arm was the one place still naming a callee on faith,
// so the module got a symbol nothing defines and clang failed the build.
//
// symfony/cache reaches this through an OPTIONAL dependency: a
// `MessageBusInterface` property with symfony/messenger absent, whose
// `dispatch()` result is then asked for `->last(...)`.

final class Envelope
{
    public function __construct(public string $tag) {}
}

final class Sender
{
    public function send(string $m): Envelope { return new Envelope($m); }
}

$never = getenv('MANTICORE_NEVER_SET_XYZ');

// Never reached, and must not cost the build anything.
if ($never === 'yes') {
    $e = (new Sender())->send('x');
    echo $e->missingMethod(), "\n";
}
echo "unreached branch cost nothing\n";

// Reached: php raises, catchable as Error.
try {
    $e = (new Sender())->send('ping');
    echo $e->noSuchThing(), "\n";
} catch (Error $err) {
    echo get_class($err), ': ', $err->getMessage(), "\n";
}

// The arguments are still evaluated before the raise, as php does.
function mark(string $s): string
{
    echo 'arg evaluated: ', $s, "\n";
    return $s;
}

try {
    $e = new Envelope('z');
    echo $e->alsoMissing(mark('one')), "\n";
} catch (Error $err) {
    echo get_class($err), ': ', $err->getMessage(), "\n";
}

// The real method on the same object still works.
$ok = (new Sender())->send('fine');
echo $ok->tag, "\n";
