<?php

// A REFINEMENT type: no `repr`, the carrier is whatever the property declares,
// and `__invoke` is the normaliser. The validation runs once, at construction —
// afterwards the TYPE carries the proof, and `deliver(Email $to)` re-checks
// nothing. It costs a raw string pointer, not a wrapper object.
#[TypeDef]
final class Email
{
    public readonly string $address;

    public function __construct(string $raw)
    {
        $this->address = $this($raw);
    }

    public function __invoke(string $raw): string
    {
        $raw = strtolower(trim($raw));
        if (strpos($raw, '@') === false) {
            throw new InvalidArgumentException('not an email: ' . $raw);
        }
        return $raw;
    }

    public function domain(): string
    {
        $at = strpos($this->address, '@');
        return substr($this->address, $at + 1);
    }
}

// A sanitising newtype over an int carrier — no repr needed for that either.
#[TypeDef]
final class Percent
{
    public readonly int $amount;

    public function __construct(int $raw)
    {
        $this->amount = $this($raw);
    }

    public function __invoke(int $raw): int
    {
        if ($raw < 0) { return 0; }
        if ($raw > 100) { return 100; }
        return $raw;
    }
}

function deliver(Email $to): string
{
    return 'sent to ' . $to->address . ' via ' . $to->domain();
}

$e = new Email('  Taras@Example.COM ');
echo $e->address, "\n";
echo $e->domain(), "\n";
echo deliver($e), "\n";

echo (new Percent(150))->amount, "\n";
echo (new Percent(-7))->amount, "\n";
echo (new Percent(42))->amount, "\n";

try {
    $bad = new Email('nope');
    echo "unreachable\n";
} catch (InvalidArgumentException $ex) {
    echo 'rejected: ', $ex->getMessage(), "\n";
}
