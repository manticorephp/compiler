<?php

// A constructor param that only SHARES a bare-array property's name (symfony's
// HeaderBag::__construct(array $headers) feeds set(), which stores [$values])
// does not type the property: it was given the arg's assoc[string, string],
// the element drop then released each inner array as a string, and every
// header leaked 80 B — per request in a shared-kernel server.
// memory_get_peak_usage() answers the peak RSS here. @serial: a memory measurement.

class HB
{
    protected array $headers = [];

    public function __construct(array $headers = [])
    {
        foreach ($headers as $key => $values) {
            $this->set($key, $values);
        }
    }

    public function set(string $key, string|array|null $values): void
    {
        if (\is_array($values)) {
            $this->headers[$key] = array_values($values);
        } else {
            $this->headers[$key] = [$values];
        }
    }

    public function first(string $key): ?string
    {
        return $this->headers[$key][0] ?? null;
    }
}

$h = new HB(['host' => 'localhost', 'accept' => 'a/b']);
echo $h->first('host'), ' ', $h->first('accept'), "\n";

$base = 0;
for ($i = 0; $i < 200000; $i++) {
    $h = new HB(['host' => 'localhost', 'user-agent' => 'x', 'accept' => 'a/b']);
    if ($i === 1000) { $base = memory_get_peak_usage(); }
}
echo memory_get_peak_usage() - $base < 3000000 ? "flat\n" : "grows\n";
