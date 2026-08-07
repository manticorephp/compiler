<?php

// A promoted constructor parameter is a PARAMETER and a PROPERTY at once, so an
// attribute there is valid when it targets EITHER -- php attaches it to whichever
// side accepts it. Checking the two targets in two strict passes demanded that
// every attribute satisfy BOTH, so a parameter-only attribute on a promoted
// param was refused at compile time. symfony/http-foundation's UriSigner writes
// exactly that: `#[\SensitiveParameter] private string $secret`.

final class Signer
{
    public function __construct(
        #[\SensitiveParameter] private string $secret,
        private string $name = 'default',
    ) {}

    public function reveal(): string { return $this->secret . '/' . $this->name; }
}

$s = new Signer('s3cr3t', 'main');
echo $s->reveal(), "\n";

// Which SIDE php attaches it to is not asserted here: ReflectionParameter::
// getAttributes() is still absent (the refl-param-attributes finding, owned by
// the reflection epic). php's answer is param=1, prop=0 -- a parameter-only
// attribute reaches the parameter alone.

// A plain (non-promoted) parameter keeps taking it too.
function hash_it(#[\SensitiveParameter] string $pw): string
{
    return 'h(' . strlen($pw) . ')';
}

echo hash_it('abcdef'), "\n";

// An attribute targeting NEITHER side is still refused at COMPILE time, with
// php's own wording ("cannot target parameter") -- widening the acceptance must
// not widen it to everything. That path is a fatal in php, so it cannot be
// exercised from inside a running program; it is pinned by the refusal message
// the compiler emits instead.
