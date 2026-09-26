<?php
// An untyped param whose docblock names no declared type (a phpstan alias, a
// missing class) is mixed: a string and an array both reach it intact.

/**
 * @phpstan-type _Proto array{0: int, 1: string}|string
 */
final class Tok
{
    private string $content;

    /** @param _Proto $token */
    public function __construct($token)
    {
        if (\is_array($token)) {
            $this->content = $token[1];
        } elseif (\is_string($token)) {
            $this->content = $token;
        } else {
            throw new \InvalidArgumentException('neither');
        }
    }

    public function c(): string { return $this->content; }
}

/** @param Nope $x */
function kind($x): string
{
    return \is_array($x) ? 'arr' : (\is_string($x) ? "str:$x" : \gettype($x));
}

foreach (token_get_all('<?php echo 1;') as $t) {
    echo (new Tok($t))->c(), '|';
}
echo "\n", (new Tok([T_STRING, 'foo']))->c(), (new Tok('('))->c(), "\n";
echo kind([1]), ' ', kind('a'), ' ', kind(3), "\n";
