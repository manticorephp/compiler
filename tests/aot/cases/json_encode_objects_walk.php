<?php
// Objects encode by walking their public properties (the descriptor's
// visit_fn): visibility, declared-then-dynamic order, nested objects and
// arrays, enums, null / uninitialized typed slots, pretty print, depth and
// recursion errors, a numeric dynamic name.
enum Suit: string { case Hearts = 'H'; }
enum Pure { case A; }
class Leaf { public int $n = 1; public ?Leaf $next = null; }
class Row {
    public int $id;
    public string $name;
    public float $price;
    public bool $active = true;
    public ?string $note = null;
    public array $tags = ['a', 'b'];
    public array $meta = ['k' => 1];
    public Suit $suit = Suit::Hearts;
    public Leaf $leaf;
    protected int $hidden = 5;
    private string $secret = 'x';
    public function __construct(int $i) {
        $this->id = $i; $this->name = "item/$i"; $this->price = $i + 0.5;
        $this->leaf = new Leaf();
    }
}
#[AllowDynamicProperties]
class Dyn { public $a = 1; }
$rows = [new Row(1), new Row(2)];
echo json_encode($rows), "\n";
echo json_encode($rows[0], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
$d = new Dyn(); $d->b = [1, 2]; $d->{'7'} = 'seven'; $d->{'c d'} = null;
echo json_encode($d), " ", json_encode($d, JSON_PRETTY_PRINT), "\n";
$s = new stdClass; $s->x = 1; $s->y = new stdClass; $s->y->z = [true];
echo json_encode($s), " ", json_encode(json_decode('{"a":{"b":[1,{"c":"d"}]},"0":5}')), "\n";
echo json_encode(new stdClass), json_encode((object)[]), json_encode([new stdClass]), "\n";
var_dump(json_encode(new Row(3), 0, 1), json_last_error());
var_dump(json_encode(new Row(3), JSON_PARTIAL_OUTPUT_ON_ERROR, 2), json_last_error());
$l = new Leaf(); $l->next = $l;
var_dump(json_encode($l), json_last_error(), json_encode($l, JSON_PARTIAL_OUTPUT_ON_ERROR));
class U { public Pure $p = Pure::A; public int $q = 2; }
var_dump(json_encode(new U), json_last_error(), json_encode(new U, JSON_PARTIAL_OUTPUT_ON_ERROR));
var_dump(get_object_vars(new Row(4))["tags"]);
