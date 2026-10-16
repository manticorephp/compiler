<?php
// foreach binding the variable it iterates iterates the array the variable held when the loop began.
trait ContentLoaderTrait
{
    private function loadContent(array $config, string $path, string $file): void
    {
        foreach ($config as $name => $config) {
            if (!str_starts_with($when = $name, 'when@')) {
                $config = [$name => $config];
            } elseif (!$this->env || 'when@' . $this->env !== $name) {
                continue;
            } else {
                $when .= '" when "@' . $this->env;
            }
            foreach ($config as $name => $config) {
                $this->validate($config, $when, $path);
                if (isset($config['resource'])) {
                    echo "import $name\n";
                } else {
                    echo "route $name\n";
                }
            }
        }
    }
    private function validate(mixed $config, string $name, string $path): void
    {
        if (!\is_array($config)) {
            throw new \InvalidArgumentException(\sprintf('The definition of "%s" in "%s" must be an array.', $name, $path));
        }
        echo "ok $name\n";
    }
}
abstract class FileLoader { public function __construct(protected ?string $env = null) {} }
class YamlFileLoader extends FileLoader
{
    use ContentLoaderTrait;
    public function load(mixed $file): void
    {
        $parsedConfig = $this->parse($file);
        if (!\is_array($parsedConfig)) { throw new \InvalidArgumentException('not array'); }
        $this->loadContent($parsedConfig, 'p.yaml', $file);
    }
    private function parse(string $f): mixed { return ['controllers' => ['resource' => 'routing.controllers'], 'when@dev' => ['_errors' => ['resource' => 'x', 'prefix' => '/_error']]]; }
}
(new YamlFileLoader('dev'))->load('f');
function g(array $x): void { $name = "a"; $y = [$name => 1, "b" => 2]; foreach ($y as $k => $y) { echo $k, "=", $y, " "; } foreach ($x as $x => $v) { echo $x, $v, " "; } echo "
"; }
g(["p" => 1, "q" => 2]);
