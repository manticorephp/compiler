<?php
class Base {
    /** @var array */
    protected $casts = [];
    public function __construct() { $this->init(); }
    protected function extra() { return []; }
    protected function init() { $this->casts = array_merge($this->casts, $this->extra()); }
    public function casts() { return $this->casts; }
}
class Note extends Base { protected $casts = ['done' => 'boolean']; }
class Plain extends Base {}
echo json_encode((new Note)->casts()), json_encode((new Plain)->casts()), "\n";
