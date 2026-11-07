<?php
// $o->{$name}() on a receiver that is an assignment to a property is evaluated once, then dispatched
class Job {
    public $instance;
    public int $made = 0;
    public function resolve(string $c) { $this->made++; return new $c; }
    public function fire(string $payload, array $data) {
        [$class, $method] = explode('@', $payload);
        return ($this->instance = $this->resolve($class))->{$method}($this, $data['n']);
    }
}
class H1 { public function go($job, $n) { return "H1 go $n made={$job->made}"; } }
class H2 { public function go($job, $n) { return "H2 go " . ($n * 2); } public function stop($job, $n) { return "H2 stop"; } }
class H3 { public function a($x, $y) { return 'a'; } public function b($x, $y) { return 'b'; } public function c($x, $y) { return 'c'; } }
$j = new Job;
echo $j->fire('H1@go', ['n' => 5]), "\n";
echo $j->fire('H2@go', ['n' => 4]), "\n";
echo $j->fire('H2@stop', ['n' => 0]), "\n";
echo $j->fire('H3@c', ['n' => 0]), "\n";
echo get_class($j->instance), ' ', $j->made, "\n";
