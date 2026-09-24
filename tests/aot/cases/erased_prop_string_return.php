<?php
// An erased (unhinted) property returned from a `: string` function decodes
// like a call argument does: the slot holds a boxed cell (php-cs-fixer's
// ConfigurationResolver::getProgressType, `@var null|T::*`).
final class PT { public const NONE = 'none'; public const BAR = 'bar'; public static function all(): array { return [self::NONE, self::BAR]; } }
final class R
{
    private array $options = [];
    /** @var null|PT::* */
    private $progress;
    public function __construct(array $o) { $this->options = $o; }
    public function hide(): bool { return false; }
    public function getProgressType(): string
    {
        if (null === $this->progress) {
            $progressType = $this->options['show-progress'];
            if (null === $progressType) {
                $progressType = $this->hide() ? PT::NONE : PT::BAR;
            } elseif (!\in_array($progressType, PT::all(), true)) {
                throw new \RuntimeException('bad');
            }
            $this->progress = $progressType;
        }
        return $this->progress;
    }
}
$r = new R(['show-progress' => null, 'x' => 1]);
echo $r->getProgressType(), "\n", $r->getProgressType(), "\n";
$r = new R(['show-progress' => 'none', 'x' => 1]);
echo $r->getProgressType(), "\n";
