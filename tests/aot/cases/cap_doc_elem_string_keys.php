<?php

// A PHPDoc `T[]` constrains the ELEMENT, not the KEY. php has no packed-vs-hash
// distinction in a type hint at all, and `array<K, V>` is the spelling that
// commits a key type. Manticore lowers `T[]` to a packed int-keyed vec, so a
// string-keyed argument to such a parameter is refused with an "array KEY repr
// conflict" — on code php runs fine.
//
// Witness in the corpus: symfony/var-dumper ServerDumper argument 3 is
// `@param ContextProviderInterface[] $contextProviders Context providers
// indexed by context name`, and VarDumper::getDefaultContextProviders() fills
// it with `$contextProviders['request'] = new RequestContextProvider(...)`.
// It is what tier 2 stops on.
//
// ⚠ The producer must NOT carry a `@return T[]` docblock: that lowers its
// return to a vec too and both sides agree on a wrong answer, hiding the
// conflict. The reproducing shape is an INFERRED string-keyed return meeting a
// `T[]`-DECLARED parameter — which is exactly symfony's shape, since
// getDefaultContextProviders() is annotated nowhere.

interface ContextProviderInterface
{
    public function name(): string;
}

final class RequestContextProvider implements ContextProviderInterface
{
    public function name(): string { return 'request'; }
}

final class ServerDumper
{
    /** @var ContextProviderInterface[] */
    private array $contextProviders;

    /**
     * @param ContextProviderInterface[] $contextProviders indexed by context name
     */
    public function __construct(
        private string $host,
        array $contextProviders = [],
    ) {
        $this->contextProviders = $contextProviders;
    }

    public function names(): string
    {
        $out = [];
        foreach ($this->contextProviders as $key => $provider) {
            $out[] = $key . '=' . $provider->name();
        }
        return implode(',', $out);
    }
}

function defaultContextProviders(): array
{
    $contextProviders = [];
    $contextProviders['request'] = new RequestContextProvider();
    return $contextProviders;
}

$dumper = new ServerDumper("tcp://x", defaultContextProviders());
echo $dumper->names(), "\n";
