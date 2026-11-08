<?php
// A docblock does not make a param an array: the (array) cast below is converting what arrives.
class Row { public $id = 7; public $name = 'x'; }
class Model {
    protected $attrs = [];
    /**
     * @param  array  $attributes
     * @return static
     */
    public function fromRow($attributes = [])
    {
        $this->attrs = (array) $attributes;
        return $this;
    }
    public function all() { return $this->attrs; }
}
/** @param array $a */
function toArr($a) { return (array) $a; }
$obj = new stdClass; $obj->q = 1; $obj->r = 'two';
echo json_encode((new Model)->fromRow($obj)->all()), json_encode((new Model)->fromRow(['k' => 1])->all()), json_encode((new Model)->fromRow(new Row)->all()), "\n";
echo json_encode(toArr($obj)), json_encode(toArr([1, 2])), json_encode(toArr(5)), "\n";
