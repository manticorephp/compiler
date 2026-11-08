<?php
class Legacy implements Serializable {
    public $d = 'x';
    public function serialize() { return $this->d; }
    public function unserialize(string $data) { $this->d = $data; }
    public function __serialize(): array { return ['d' => $this->d]; }
    public function __unserialize(array $a): void { $this->d = $a['d']; }
}
$l = new Legacy();
var_dump($l instanceof Serializable);
var_dump(interface_exists('Serializable'));
var_dump((new ReflectionClass('Serializable'))->isInterface());
var_dump(in_array('Serializable', class_implements($l)));
var_dump(is_subclass_of('Legacy', 'Serializable'));
