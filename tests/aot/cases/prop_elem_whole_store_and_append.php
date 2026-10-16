<?php
// A whole-property array store and a later element append must both count: the
// slot holds strings and an int, so it cannot be typed by the append alone.
class Decorated
{
    private ?array $d = null;

    public function decorate(?string $id, ?string $inner, int $priority, int $invalid): static
    {
        if (null === $id) {
            $this->d = null;
        } else {
            $this->d = [$id, $inner];
            $this->d[] = $priority;
            if ($invalid !== 1) {
                $this->d[] = $invalid;
            }
        }
        return $this;
    }

    public function get(): ?array
    {
        return $this->d;
    }
}

function show(Decorated $x): void
{
    $d = $x->get();
    if (null === $d) {
        echo "none\n";
    } else {
        echo $d[0], ' ', $d[1] ?? '-', ' ', $d[2], ' ', $d[3] ?? 'default', "\n";
    }
}

show(new Decorated());
show((new Decorated())->decorate('a', 'b', 3, 1));
show((new Decorated())->decorate('c', null, 4, 7));
var_dump((new Decorated())->decorate('a', 'b', 3, 1)->get());
