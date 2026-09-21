<?php
// A `mixed` property's array stored whole into a superglobal (the cell's
// type lifts to `cell`), then the superglobal is written: the property keeps
// its elements.
final class S
{
    public mixed $data;

    public function __construct(int $n)
    {
        $this->data = ['k' => str_repeat('v', 8) . $n, 'w' => [str_repeat('w', 8) . $n]];
    }
}
$s = new S(1);
$_SESSION = $s->data;
$_SESSION['x'] = 1;
$_SESSION['k'] = 'changed';
echo $s->data['k'], ' ', $s->data['w'][0], ' ', count($s->data), "\n";
echo $_SESSION['k'], ' ', $_SESSION['x'], ' ', count($_SESSION), "\n";
for ($i = 2; $i < 5; $i++) {
    $t = new S($i);
    $_SESSION = $t->data;
    $_SESSION['y'] = $i;
    echo $t->data['k'], ' ', $t->data['w'][0], ' ', count($t->data), ' ', count($_SESSION), "\n";
}
echo $s->data['k'], ' ', $s->data['w'][0], "\n";
