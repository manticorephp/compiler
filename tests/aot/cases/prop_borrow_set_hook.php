<?php

// A `set` hook receives the value as a CALL argument before any store — the
// hook body may keep it anywhere, so the source slot must stay vetoed.

final class Keeper
{
    /** @var string[] */
    public static array $seen = [];
}

final class Hooked
{
    public string $label {
        set(string $v) {
            Keeper::$seen[] = $v;
            $this->label = strtoupper($v);
        }
    }
}

final class Source
{
    private string $name = '';

    public function rename(string $n): void { $this->name = $n; }

    public function push(Hooked $h): void
    {
        $h->label = $this->name;
    }
}

$h = new Hooked();
$s = new Source();
for ($i = 0; $i < 5; $i++) {
    $s->rename('lbl-' . $i . str_repeat('z', 40));
    $s->push($h);
}
$s->rename('final' . str_repeat('q', 40));
echo count(Keeper::$seen), "\n";
echo substr(Keeper::$seen[0], 0, 5), "\n";
echo substr(Keeper::$seen[4], 0, 5), "\n";
echo substr($h->label, 0, 5), "\n";
