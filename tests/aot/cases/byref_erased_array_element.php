<?php
// An element of a decoded / erased array handed to `array &$p`: the write must land in the container.
function w(array &$c, bool $v): void { $c['enabled'] = $v; }
function viaParam(array $config): void {
    w($config['a'], true);
    w($config['form']['csrf'], false);
    echo json_encode($config), "\n";
}
$m = json_decode('{"a":{"q":1},"form":{"csrf":{"x":2}}}', true);
w($m['a'], true);
echo json_encode($m), "\n";
viaParam($m);
class E {
    private array $en = [];
    function write(string $path, bool $value, array &$config): void {
        if (isset($this->en[$path])) { throw new LogicException('read'); }
        $this->en[$path] = $value;
        $config['enabled'] = $value;
    }
    function run(array $configs): void {
        $config = $configs[0];
        $this->write('csrf', $config['csrf_protection']['x'] > 0 && true, $config['csrf_protection']);
        $this->write('validation', true, $config['validation']);
        echo json_encode($config), "\n";
    }
}
(new E)->run(json_decode('[{"csrf_protection":{"x":1},"validation":{}}]', true));
