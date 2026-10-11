<?php
// `$passes = &$this->$property; $passes[$k][] = $v;` (symfony PassConfig::addPass) loses the writes and clobbers the array
// issue: #199
final class PassConfig
{
    public const TYPE_BEFORE_OPTIMIZATION = 'beforeOptimization';
    public const TYPE_REMOVE = 'removing';

    private array $beforeOptimizationPasses = [];
    private array $removingPasses = [];

    public function __construct()
    {
        $this->beforeOptimizationPasses = [100 => [new stdClass()], -1000 => [new ArrayObject()]];
    }

    public function addPass(object $pass, string $type = self::TYPE_BEFORE_OPTIMIZATION, int $priority = 0): void
    {
        $property = $type . 'Passes';
        if (!isset($this->$property)) {
            throw new InvalidArgumentException(sprintf('Invalid type "%s".', $type));
        }
        $passes = &$this->$property;

        if (!isset($passes[$priority])) {
            $passes[$priority] = [];
        }
        $passes[$priority][] = $pass;
    }

    public function counts(): string
    {
        return count($this->beforeOptimizationPasses, COUNT_RECURSIVE) . '/' . count($this->removingPasses, COUNT_RECURSIVE);
    }

    public function all(): array
    {
        $p = $this->beforeOptimizationPasses;
        krsort($p);
        return array_merge(...$p);
    }
}

$c = new PassConfig();
echo $c->counts(), "\n";
$c->addPass(new stdClass());
$c->addPass(new stdClass(), 'beforeOptimization', 200);
$c->addPass(new ArrayObject(), 'removing', -32);
$c->addPass(new stdClass(), 'beforeOptimization', -32);
echo $c->counts(), ' ', count($c->all()), "\n";
