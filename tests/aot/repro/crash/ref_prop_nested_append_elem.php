<?php
// A property bound by reference to a new element appended to a nested array in another property (`$this->data = &$this->data['child'][$k][];`) is refused at emit.
// issue: #613
class P {
    public $data = [];
    public $datas = [];
    public function open(string $ns, string $el): void {
        $this->datas[] = &$this->data;
        $this->data = &$this->data['child'][$ns][$el][];
        $this->data = ['name' => $el];
    }
    public function close(): void {
        $this->data = &$this->datas[count($this->datas) - 1];
        array_pop($this->datas);
    }
}
$p = new P();
$p->open('atom', 'feed');
$p->open('atom', 'title');
$p->close();
$p->close();
echo json_encode($p->data), "\n";
