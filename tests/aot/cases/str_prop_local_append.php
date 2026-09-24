<?php
// A string read out of a property into a local is the local's OWN value: `.=`
// on it must not write into the property (symfony Finder's
// RecursiveDirectoryIterator::current grew its cached subPath per iteration).
class It
{
    private string $subPath;
    private string $rootPath;
    private string $sep = '/';
    public static string $s = '';

    public function __construct(string $root) { $this->rootPath = $root; self::$s = \str_repeat('ef', 2); }

    public function current(int $i): string
    {
        if (!isset($this->subPath)) { $this->subPath = \str_repeat('sub', 2); }
        $subPathname = $this->subPath;
        if ('' !== $subPathname) { $subPathname .= $this->sep; }
        $subPathname .= 'f' . $i . '.php';
        $basePath = $this->rootPath;
        if ('/' !== $basePath && !str_ends_with($basePath, $this->sep)) { $basePath .= $this->sep; }
        return $basePath . $subPathname . '|' . $this->subPath . '|' . $this->rootPath;
    }
}

$it = new It(\str_repeat('root', 2));
for ($i = 0; $i < 5; $i++) { echo $it->current($i), "\n"; }
$s = It::$s;
$s .= '/';
echo $s, '|', It::$s, "\n";
function keep(It $o): string { $x = It::$s; It::$s = \str_repeat('zz', 3); $y = \str_repeat('qq', 2); return $x . '|' . It::$s . $y; }
echo keep($it), "\n";
