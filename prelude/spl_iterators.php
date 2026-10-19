<?php

/**
 * SPL iterators, file-system iterators and data structures — injected into a
 * program only when it names one of them (see Main::lower_module). Plain PHP
 * over the runtime's own primitives (opendir/readdir, stat, spl_object_id), so
 * every class here also runs under `php` with its names prefixed — which is how
 * each is checked against Zend's.
 *
 * Semantics follow php 8.5's, including the parts code leans on: a
 * DirectoryIterator is positioned on its first entry by the CONSTRUCTOR (symfony
 * Finder skips the first rewind on that basis), getChildren() builds `static`,
 * and a FilterIterator re-tests from the inner iterator's current position.
 */

class IteratorIterator implements OuterIterator
{
    private Iterator $__inner;
    private bool $__valid = false;
    private mixed $__key = null;
    private mixed $__current = null;

    public function __construct(Traversable $iterator, ?string $class = null)
    {
        while ($iterator instanceof IteratorAggregate) {
            $iterator = $iterator->getIterator();
        }
        $this->__inner = $iterator;
    }

    public function getInnerIterator(): ?Iterator
    {
        return $this->__inner;
    }

    public function rewind(): void
    {
        $this->__inner->rewind();
        $this->__fetch();
    }

    public function valid(): bool
    {
        return $this->__valid;
    }

    public function key(): mixed
    {
        return $this->__key;
    }

    public function current(): mixed
    {
        return $this->__current;
    }

    public function next(): void
    {
        $this->__inner->next();
        $this->__fetch();
    }

    /** Drop the cached position (spl_dual_it_free). */
    protected function __free(): void
    {
        $this->__valid = false;
        $this->__current = null;
        $this->__key = null;
    }

    /** Cache the inner iterator's position without asking valid() (spl_dual_it_fetch, check_more=0). */
    protected function __fetchRaw(): void
    {
        $this->__valid = true;
        $this->__current = $this->__inner->current();
        $this->__key = $this->__inner->key();
    }

    protected function __setCurrent(mixed $v): void
    {
        $this->__current = $v;
    }

    protected function __setKey(mixed $k): void
    {
        $this->__key = $k;
    }

    /** Cache the inner iterator's position, as php's does. */
    protected function __fetch(): void
    {
        $this->__valid = $this->__inner->valid();
        if ($this->__valid) {
            $this->__current = $this->__inner->current();
            $this->__key = $this->__inner->key();
        } else {
            $this->__current = null;
            $this->__key = null;
        }
    }
}

abstract class FilterIterator extends IteratorIterator
{
    abstract public function accept(): bool;

    public function __construct(Iterator $iterator)
    {
        parent::__construct($iterator);
    }

    public function rewind(): void
    {
        parent::rewind();
        $this->__skipRejected();
    }

    public function next(): void
    {
        parent::next();
        $this->__skipRejected();
    }

    private function __skipRejected(): void
    {
        while ($this->valid() && !$this->accept()) {
            parent::next();
        }
    }
}

class CallbackFilterIterator extends FilterIterator
{
    private Closure $__callback;

    public function __construct(Iterator $iterator, callable $callback)
    {
        parent::__construct($iterator);
        $this->__callback = Closure::fromCallable($callback);
    }

    public function accept(): bool
    {
        $cb = $this->__callback;
        return (bool)$cb($this->current(), $this->key(), $this->getInnerIterator());
    }
}

abstract class RecursiveFilterIterator extends FilterIterator implements RecursiveIterator
{
    public function __construct(RecursiveIterator $iterator)
    {
        parent::__construct($iterator);
    }

    public function hasChildren(): bool
    {
        $inner = $this->getInnerIterator();
        return $inner instanceof RecursiveIterator && $inner->hasChildren();
    }

    public function getChildren(): ?RecursiveFilterIterator
    {
        $inner = $this->getInnerIterator();
        return new static($inner->getChildren());
    }
}

class RecursiveCallbackFilterIterator extends CallbackFilterIterator implements RecursiveIterator
{
    private Closure $__rcallback;

    public function __construct(RecursiveIterator $iterator, callable $callback)
    {
        parent::__construct($iterator, $callback);
        $this->__rcallback = Closure::fromCallable($callback);
    }

    public function hasChildren(): bool
    {
        $inner = $this->getInnerIterator();
        return $inner instanceof RecursiveIterator && $inner->hasChildren();
    }

    public function getChildren(): RecursiveCallbackFilterIterator
    {
        $inner = $this->getInnerIterator();
        return new static($inner->getChildren(), $this->__rcallback);
    }
}

class AppendIterator extends IteratorIterator
{
    /** @var Iterator[] */
    private array $__iterators = [];
    private int $__index = 0;

    public function __construct()
    {
        parent::__construct(new EmptyIterator());
    }

    public function append(Iterator $iterator): void
    {
        $this->__iterators[] = $iterator;
        if (\count($this->__iterators) === 1) {
            $this->__index = 0;
            $iterator->rewind();
            $this->__settle();
        } elseif (!$this->valid()) {
            $this->__settle();
        }
    }

    public function getInnerIterator(): ?Iterator
    {
        return $this->__iterators[$this->__index] ?? null;
    }

    public function getIteratorIndex(): ?int
    {
        return isset($this->__iterators[$this->__index]) ? $this->__index : null;
    }

    public function getArrayIterator(): ArrayIterator
    {
        return new ArrayIterator($this->__iterators);
    }

    public function rewind(): void
    {
        $this->__index = 0;
        if (isset($this->__iterators[0])) { $this->__iterators[0]->rewind(); }
        $this->__settle();
    }

    public function valid(): bool
    {
        $it = $this->__iterators[$this->__index] ?? null;
        return $it !== null && $it->valid();
    }

    public function current(): mixed
    {
        $it = $this->__iterators[$this->__index] ?? null;
        return $it !== null && $it->valid() ? $it->current() : null;
    }

    public function key(): mixed
    {
        $it = $this->__iterators[$this->__index] ?? null;
        return $it !== null && $it->valid() ? $it->key() : null;
    }

    public function next(): void
    {
        $it = $this->__iterators[$this->__index] ?? null;
        if ($it === null) { return; }
        $it->next();
        $this->__settle();
    }

    /** Move past exhausted iterators, rewinding each one entered. */
    private function __settle(): void
    {
        $n = \count($this->__iterators);
        while ($this->__index < $n && !$this->__iterators[$this->__index]->valid()) {
            if ($this->__index + 1 >= $n) { return; }
            $this->__index = $this->__index + 1;
            $this->__iterators[$this->__index]->rewind();
        }
    }
}

class EmptyIterator implements Iterator
{
    public function current(): mixed { throw new BadMethodCallException('Accessing the value of an EmptyIterator'); }
    public function key(): mixed { throw new BadMethodCallException('Accessing the key of an EmptyIterator'); }
    public function next(): void {}
    public function rewind(): void {}
    public function valid(): bool { return false; }
}

class RecursiveIteratorIterator implements OuterIterator
{
    public const LEAVES_ONLY = 0;
    public const SELF_FIRST = 1;
    public const CHILD_FIRST = 2;
    public const CATCH_GET_CHILD = 16;

    // Per-level state of the cursor.
    private const __TEST = 0;    // position not examined yet
    private const __LEAF = 1;    // yielded as a leaf
    private const __SELF = 2;    // yielded before its children (SELF_FIRST)
    private const __POST = 3;    // yielded after its children (CHILD_FIRST)
    private const __DOWN = 4;    // its children are being walked

    /** @var RecursiveIterator[] */
    private array $__stack = [];
    /** @var int[] */
    private array $__state = [];
    private RecursiveIterator $__root;
    private int $__mode;
    private int $__flags;
    private int $__maxDepth = -1;
    private bool $__done = true;

    public function __construct(Traversable $iterator, int $mode = self::LEAVES_ONLY, int $flags = 0)
    {
        while ($iterator instanceof IteratorAggregate) {
            $iterator = $iterator->getIterator();
        }
        if (!$iterator instanceof RecursiveIterator) {
            throw new InvalidArgumentException('An instance of RecursiveIterator or IteratorAggregate creating it is required');
        }
        $this->__root = $iterator;
        $this->__mode = $mode;
        $this->__flags = $flags;
        $this->__stack = [$iterator];
        $this->__state = [self::__TEST];
    }

    public function rewind(): void
    {
        while (\count($this->__stack) > 1) {
            \array_pop($this->__stack);
            \array_pop($this->__state);
            $this->endChildren();
        }
        $this->__stack = [$this->__root];
        $this->__state = [self::__TEST];
        $this->__root->rewind();
        $this->__done = false;
        $this->beginIteration();
        $this->__settle();
    }

    public function valid(): bool
    {
        return !$this->__done && $this->__top()->valid();
    }

    public function key(): mixed
    {
        return $this->__top()->key();
    }

    public function current(): mixed
    {
        return $this->__top()->current();
    }

    public function next(): void
    {
        if ($this->__done) { return; }
        $d = \count($this->__stack) - 1;
        $s = $this->__state[$d];
        if ($s === self::__SELF) {
            $this->__descend($d);
        } else {
            $this->__stack[$d]->next();
            $this->__state[$d] = self::__TEST;
        }
        $this->__settle();
    }

    public function getDepth(): int
    {
        return \count($this->__stack) - 1;
    }

    public function getSubIterator(?int $level = null): ?RecursiveIterator
    {
        $d = $level ?? \count($this->__stack) - 1;
        return $this->__stack[$d] ?? null;
    }

    public function getInnerIterator(): RecursiveIterator
    {
        return $this->__top();
    }

    public function callHasChildren(): bool
    {
        return $this->__top()->hasChildren();
    }

    public function callGetChildren(): ?RecursiveIterator
    {
        return $this->__top()->getChildren();
    }

    public function setMaxDepth(int $maxDepth = -1): void
    {
        if ($maxDepth < -1) {
            throw new OutOfRangeException('RecursiveIteratorIterator::setMaxDepth(): Argument #1 ($maxDepth) must be greater than or equal to -1');
        }
        $this->__maxDepth = $maxDepth;
    }

    public function getMaxDepth(): int|false
    {
        return $this->__maxDepth < 0 ? false : $this->__maxDepth;
    }

    public function beginIteration(): void {}
    public function endIteration(): void {}
    public function beginChildren(): void {}
    public function endChildren(): void {}
    public function nextElement(): void {}

    private function __top(): RecursiveIterator
    {
        return $this->__stack[\count($this->__stack) - 1];
    }

    private function __descend(int $d): void
    {
        $this->__state[$d] = self::__DOWN;
        if (($this->__flags & self::CATCH_GET_CHILD) !== 0) {
            try {
                $child = $this->callGetChildren();
            } catch (\Throwable $e) {
                $this->__stack[$d]->next();
                $this->__state[$d] = self::__TEST;
                return;
            }
        } else {
            $child = $this->callGetChildren();
        }
        $child->rewind();
        $this->__stack[] = $child;
        $this->__state[] = self::__TEST;
        $this->beginChildren();
    }

    /** Walk until the cursor rests on an element this mode yields, or ends. */
    private function __settle(): void
    {
        while (true) {
            $d = \count($this->__stack) - 1;
            $it = $this->__stack[$d];
            if (!$it->valid()) {
                if ($d === 0) {
                    $this->__done = true;
                    $this->endIteration();
                    return;
                }
                \array_pop($this->__stack);
                \array_pop($this->__state);
                $this->endChildren();
                $p = $d - 1;
                if ($this->__mode === self::CHILD_FIRST) {
                    $this->__state[$p] = self::__POST;
                    $this->nextElement();
                    return;
                }
                $this->__stack[$p]->next();
                $this->__state[$p] = self::__TEST;
                continue;
            }
            if ($this->__state[$d] !== self::__TEST) {
                // Only a DOWN level can be seen here: its children just ended.
                $it->next();
                $this->__state[$d] = self::__TEST;
                continue;
            }
            $has = ($this->__maxDepth < 0 || $d < $this->__maxDepth) && $this->callHasChildren();
            if (!$has) {
                $this->__state[$d] = self::__LEAF;
                $this->nextElement();
                return;
            }
            if ($this->__mode === self::SELF_FIRST) {
                $this->__state[$d] = self::__SELF;
                $this->nextElement();
                return;
            }
            $this->__descend($d);
        }
    }
}

class RecursiveTreeIterator extends RecursiveIteratorIterator
{
    public const BYPASS_CURRENT = 4;
    public const BYPASS_KEY = 8;
    public const PREFIX_LEFT = 0;
    public const PREFIX_MID_HAS_NEXT = 1;
    public const PREFIX_MID_LAST = 2;
    public const PREFIX_END_HAS_NEXT = 3;
    public const PREFIX_END_LAST = 4;
    public const PREFIX_RIGHT = 5;

    /** @var string[] */
    private array $__prefix = ['', '| ', '  ', '|-', '\\-', ''];
    private string $__postfix = '';
    private int $__rtFlags;

    public function __construct(
        mixed $iterator,
        int $flags = self::BYPASS_KEY,
        int $cachingIteratorFlags = 16,
        int $mode = self::SELF_FIRST,
    ) {
        parent::__construct($iterator, $mode, $flags);
        $this->__rtFlags = $flags;
    }

    public function setPrefixPart(int $part, string $value): void
    {
        if ($part < 0 || $part > 5) {
            throw new OutOfRangeException('RecursiveTreeIterator::setPrefixPart(): Argument #1 ($part) must be a RecursiveTreeIterator::PREFIX_* constant');
        }
        $this->__prefix[$part] = $value;
    }

    public function setPostfix(string $postfix): void
    {
        $this->__postfix = $postfix;
    }

    public function getPostfix(): string
    {
        return $this->__postfix;
    }

    public function getPrefix(): string
    {
        $out = $this->__prefix[self::PREFIX_LEFT];
        $depth = $this->getDepth();
        for ($level = 0; $level < $depth; $level++) {
            $out .= $this->__hasNextAt($level) ? $this->__prefix[self::PREFIX_MID_HAS_NEXT] : $this->__prefix[self::PREFIX_MID_LAST];
        }
        $out .= $this->__hasNextAt($depth) ? $this->__prefix[self::PREFIX_END_HAS_NEXT] : $this->__prefix[self::PREFIX_END_LAST];
        return $out . $this->__prefix[self::PREFIX_RIGHT];
    }

    public function getEntry(): string
    {
        $v = parent::current();
        return \is_array($v) ? 'Array' : (string)$v;
    }

    public function current(): mixed
    {
        if (($this->__rtFlags & self::BYPASS_CURRENT) !== 0) { return parent::current(); }
        return $this->getPrefix() . $this->getEntry() . $this->getPostfix();
    }

    public function key(): mixed
    {
        if (($this->__rtFlags & self::BYPASS_KEY) !== 0) { return parent::key(); }
        return $this->getPrefix() . (string)parent::key() . $this->getPostfix();
    }

    /** Whether the iterator at `$level` has an element after its current one. */
    private function __hasNextAt(int $level): bool
    {
        $it = $this->getSubIterator($level);
        if ($it === null) { return false; }
        // Probe a sibling without moving the real cursor: iterate a clone.
        $probe = clone $it;
        $probe->next();
        return $probe->valid();
    }
}

class SplFileInfo implements Stringable
{
    private string $__path;
    private string $__infoClass = SplFileInfo::class;
    private string $__fileClass = SplFileObject::class;

    public function __construct(string $filename)
    {
        // php drops trailing slashes, but a path of only slashes stays itself.
        $t = \rtrim($filename, '/');
        $this->__path = $t === '' && $filename !== '' ? '/' : $t;
    }

    public function getPathname(): string
    {
        return $this->__path;
    }

    public function getFilename(): string
    {
        $p = $this->__path;
        $slash = \strrpos($p, '/');
        if ($slash === false) { return $p; }
        if ($p === '/') { return '/'; }
        return \substr($p, $slash + 1);
    }

    public function getPath(): string
    {
        $p = $this->__path;
        $slash = \strrpos($p, '/');
        if ($slash === false) { return ''; }
        return \substr($p, 0, $slash);
    }

    public function getBasename(string $suffix = ''): string
    {
        return \basename($this->getFilename(), $suffix);
    }

    public function getExtension(): string
    {
        $f = $this->getFilename();
        $dot = \strrpos($f, '.');
        return $dot === false ? '' : \substr($f, $dot + 1);
    }

    public function getRealPath(): string|false
    {
        return \realpath($this->__path === '' ? '.' : $this->__path);
    }

    public function getPerms(): int|false { return $this->__stat('getPerms', @\fileperms($this->__path)); }
    public function getInode(): int|false { return $this->__stat('getInode', @\fileinode($this->__path)); }
    public function getSize(): int|false { return $this->__stat('getSize', @\filesize($this->__path)); }
    public function getOwner(): int|false { return $this->__stat('getOwner', @\fileowner($this->__path)); }
    public function getGroup(): int|false { return $this->__stat('getGroup', @\filegroup($this->__path)); }
    public function getATime(): int|false { return $this->__stat('getATime', @\fileatime($this->__path)); }
    public function getMTime(): int|false { return $this->__stat('getMTime', @\filemtime($this->__path)); }
    public function getCTime(): int|false { return $this->__stat('getCTime', @\filectime($this->__path)); }

    public function getType(): string|false
    {
        $t = @\filetype($this->__path);
        if ($t === false) {
            throw new RuntimeException('SplFileInfo::getType(): Lstat failed for ' . $this->__path);
        }
        return $t;
    }

    public function isWritable(): bool { return \is_writable($this->__path); }
    public function isReadable(): bool { return \is_readable($this->__path); }
    public function isExecutable(): bool { return \is_executable($this->__path); }
    public function isFile(): bool { return \is_file($this->__path); }
    public function isDir(): bool { return \is_dir($this->__path); }
    public function isLink(): bool { return \is_link($this->__path); }

    public function getLinkTarget(): string|false
    {
        $t = \readlink($this->__path);
        if ($t === false) {
            throw new RuntimeException('Unable to read link ' . $this->__path . ', error: Invalid argument');
        }
        return $t;
    }

    public function getFileInfo(?string $class = null): SplFileInfo
    {
        $c = $class ?? $this->__infoClass;
        return new $c($this->__path);
    }

    public function getPathInfo(?string $class = null): ?SplFileInfo
    {
        $c = $class ?? $this->__infoClass;
        return new $c($this->getPath());
    }

    public function openFile(string $mode = 'r', bool $useIncludePath = false, $context = null): SplFileObject
    {
        $c = $this->__fileClass;
        return new $c($this->__path, $mode, $useIncludePath, $context);
    }

    public function setFileClass(string $class = SplFileObject::class): void
    {
        $this->__fileClass = $class;
    }

    public function setInfoClass(string $class = SplFileInfo::class): void
    {
        $this->__infoClass = $class;
    }

    public function __toString(): string
    {
        return $this->getPathname();
    }

    /** Re-point this info at another path (DirectoryIterator moves it per entry). */
    protected function __setPathname(string $path): void
    {
        $this->__path = $path;
    }

    /** php's stat-family answer: the value, or a RuntimeException naming the call. */
    private function __stat(string $method, int|false $v): int
    {
        if ($v === false) {
            throw new RuntimeException('SplFileInfo::' . $method . '(): stat failed for ' . $this->__path);
        }
        return $v;
    }
}

/**
 * php's ext/spl SplFileObject over a plain stream, state machine for state
 * machine with spl_directory.c: `$__line` is `current_line` (null = none read),
 * `$__row` the READ_CSV `current_zval`, `$__num` `current_line_num`.
 */
class SplFileObject extends SplFileInfo implements RecursiveIterator, SeekableIterator
{
    public const DROP_NEW_LINE = 1;
    public const READ_AHEAD = 2;
    public const SKIP_EMPTY = 4;
    public const READ_CSV = 8;

    private ?\Resource $__fp = null;
    private ?string $__line = null;
    /** @var array<int, string|null>|null */
    private ?array $__row = null;
    private int $__num = 0;
    private int $__flags = 0;
    private int $__maxLen = 0;
    private string $__sep = ',';
    private string $__enc = '"';
    private string $__esc = '\\';
    private string $__name;

    public function __construct(string $filename, string $mode = 'r', bool $useIncludePath = false, $context = null)
    {
        parent::__construct($filename);
        $this->__name = $filename;
        $this->__open($filename, $mode);
    }

    /** spl_filesystem_file_open: php's own warning text, thrown. */
    protected function __open(string $filename, string $mode): void
    {
        if ($filename !== '' && \is_dir($filename)) {
            throw new LogicException('Cannot use SplFileObject with directories');
        }
        $fp = $filename === '' ? false : @\fopen($filename, $mode);
        if ($fp === false) {
            $why = \file_exists($filename) ? 'Permission denied' : 'No such file or directory';
            throw new RuntimeException('SplFileObject::__construct(' . $filename . '): Failed to open stream: ' . $why);
        }
        $this->__fp = $fp;
    }

    private function __stream(): \Resource
    {
        $fp = $this->__fp;
        if ($fp === null) {
            throw new Error('Object not initialized');
        }
        return $fp;
    }

    private function __freeLine(): void
    {
        $this->__line = null;
        $this->__row = null;
    }

    /** spl_filesystem_file_read_ex */
    private function __readEx(bool $silent, int $lineAdd, bool $csv): bool
    {
        $fp = $this->__stream();
        $this->__freeLine();
        if (\feof($fp)) {
            if (!$silent) {
                throw new RuntimeException('Cannot read from file ' . $this->__name);
            }
            return false;
        }
        $buf = $this->__maxLen > 0 ? \fgets($fp, $this->__maxLen + 1) : \fgets($fp);
        if ($buf === false) {
            $this->__line = '';
        } else {
            $s = (string)$buf;
            if (!$csv && ($this->__flags & self::DROP_NEW_LINE) !== 0) {
                $n = \strlen($s);
                if ($n > 0 && $s[$n - 1] === "\n") {
                    $n--;
                    if ($n > 0 && $s[$n - 1] === "\r") { $n--; }
                    $s = \substr($s, 0, $n);
                }
            }
            $this->__line = $s;
        }
        $this->__num += $lineAdd;
        return true;
    }

    /** spl_filesystem_file_read */
    private function __read(bool $silent, bool $csv): bool
    {
        return $this->__readEx($silent, $this->__line !== null ? 1 : 0, $csv);
    }

    private function __lineEmpty(): bool
    {
        $l = (string)$this->__line;
        if ($l === '') { return true; }
        return ($this->__flags & self::READ_CSV) !== 0 && ($this->__flags & self::DROP_NEW_LINE) !== 0
            && ($l === "\n" || $l === "\r\n");
    }

    /** spl_filesystem_file_read_csv — a quoted field may span lines, as in fgetcsv(). */
    private function __readCsv(string $sep, string $enc, string $esc, bool $silent): bool
    {
        do {
            if (!$this->__read($silent, true)) { return false; }
        } while ($this->__lineEmpty() && ($this->__flags & self::SKIP_EMPTY) !== 0);
        $buf = (string)$this->__line;
        $e = $enc === '' ? '"' : $enc[0];
        $x = $esc === '' ? '' : $esc[0];
        if (\__mc_csv_open_quote($buf, $e, $x)) {
            $parts = [$buf];
            $fp = $this->__stream();
            while (true) {
                $next = \fgets($fp);
                if ($next === false) { break; }
                $parts[] = (string)$next;
                if (!\__mc_csv_open_quote(\implode('', $parts), $e, $x)) { break; }
            }
            $buf = \implode('', $parts);
        }
        $this->__row = \str_getcsv($buf, $sep, $enc, $esc);
        return true;
    }

    /** spl_filesystem_file_read_line_ex */
    private function __readLineEx(bool $silent): bool
    {
        if (($this->__flags & self::READ_CSV) !== 0) {
            return $this->__readCsv($this->__sep, $this->__enc, $this->__esc, $silent);
        }
        return $this->__read($silent, false);
    }

    /** spl_filesystem_file_read_line */
    private function __readLine(bool $silent): bool
    {
        $ok = $this->__readLineEx($silent);
        while (($this->__flags & self::SKIP_EMPTY) !== 0 && $ok && $this->__lineEmpty()) {
            $this->__freeLine();
            $ok = $this->__readLineEx($silent);
        }
        return $ok;
    }

    public function rewind(): void
    {
        if (!\rewind($this->__stream())) {
            throw new RuntimeException('Cannot rewind file ' . $this->__name);
        }
        $this->__freeLine();
        $this->__num = 0;
        if (($this->__flags & self::READ_AHEAD) !== 0) {
            $this->__readLine(true);
        }
    }

    public function eof(): bool
    {
        return \feof($this->__stream());
    }

    public function valid(): bool
    {
        if (($this->__flags & self::READ_AHEAD) !== 0) {
            return $this->__line !== null || $this->__row !== null;
        }
        return $this->__fp !== null && !\feof($this->__fp);
    }

    public function fgets(): string
    {
        $this->__readEx(false, 1, false);
        return (string)$this->__line;
    }

    public function getCurrentLine(): string
    {
        return $this->fgets();
    }

    /** @return string|array<int, string|null>|false */
    public function current(): string|array|false
    {
        if ($this->__line === null && $this->__row === null) {
            $this->__readLine(true);
        }
        if ($this->__line !== null && (($this->__flags & self::READ_CSV) === 0 || $this->__row === null)) {
            return $this->__line;
        }
        if ($this->__row !== null) {
            return $this->__row;
        }
        return false;
    }

    public function key(): int
    {
        return $this->__num;
    }

    public function next(): void
    {
        $this->__freeLine();
        if (($this->__flags & self::READ_AHEAD) !== 0) {
            $this->__readLine(true);
        }
        $this->__num++;
    }

    public function seek(int $line): void
    {
        if ($line < 0) {
            throw new ValueError('SplFileObject::seek(): Argument #1 ($line) must be greater than or equal to 0');
        }
        $this->rewind();
        for ($i = 0; $i < $line; $i++) {
            if (!$this->__readLine(true)) { return; }
        }
        if ($line > 0 && ($this->__flags & self::READ_AHEAD) === 0) {
            $this->__num++;
            $this->__freeLine();
        }
    }

    public function fgetc(): string|false
    {
        $this->__freeLine();
        $c = \fgetc($this->__stream());
        if ($c === false) { return false; }
        if ($c === "\n") { $this->__num++; }
        return $c;
    }

    public function fread(int $length): string|false
    {
        if ($length <= 0) {
            throw new ValueError('SplFileObject::fread(): Argument #1 ($length) must be greater than 0');
        }
        return \fread($this->__stream(), $length);
    }

    public function fwrite(string $data, int $length = 0): int|false
    {
        if (\func_num_args() > 1) {
            $data = $length >= 0 ? \substr($data, 0, $length) : '';
        }
        if ($data === '') { return 0; }
        return \fwrite($this->__stream(), $data);
    }

    /** @return array<int, string|null>|false */
    public function fgetcsv(string $separator = ',', string $enclosure = '"', string $escape = '\\'): array|false
    {
        if (!$this->__readCsv($separator, $enclosure, $escape, true)) { return false; }
        return $this->__row ?? false;
    }

    /** @param array<int, mixed> $fields */
    public function fputcsv(array $fields, string $separator = ',', string $enclosure = '"', string $escape = '\\', string $eol = "\n"): int|false
    {
        return \fputcsv($this->__stream(), $fields, $separator, $enclosure, $escape, $eol);
    }

    public function setCsvControl(string $separator = ',', string $enclosure = '"', string $escape = '\\'): void
    {
        $this->__sep = $separator;
        $this->__enc = $enclosure;
        $this->__esc = $escape;
    }

    /** @return string[] */
    public function getCsvControl(): array
    {
        return [$this->__sep, $this->__enc, $this->__esc];
    }

    public function flock(int $operation, &$wouldBlock = null): bool
    {
        return \flock($this->__stream(), $operation, $wouldBlock);
    }

    public function fflush(): bool
    {
        return \fflush($this->__stream());
    }

    public function ftell(): int|false
    {
        return \ftell($this->__stream());
    }

    public function fseek(int $offset, int $whence = SEEK_SET): int
    {
        $this->__freeLine();
        return \fseek($this->__stream(), $offset, $whence);
    }

    public function ftruncate(int $size): bool
    {
        return \ftruncate($this->__stream(), $size);
    }

    public function fpassthru(): int
    {
        return \fpassthru($this->__stream());
    }

    /** @return array<int|string, int> */
    public function fstat(): array
    {
        $st = \fstat($this->__stream());
        return $st === false ? [] : $st;
    }

    public function setFlags(int $flags): void
    {
        $this->__flags = $flags;
    }

    public function getFlags(): int
    {
        return $this->__flags;
    }

    public function setMaxLineLen(int $maxLength): void
    {
        if ($maxLength < 0) {
            throw new ValueError('SplFileObject::setMaxLineLen(): Argument #1 ($maxLength) must be greater than or equal to 0');
        }
        $this->__maxLen = $maxLength;
    }

    public function getMaxLineLen(): int
    {
        return $this->__maxLen;
    }

    public function hasChildren(): bool
    {
        return false;
    }

    public function getChildren(): ?RecursiveIterator
    {
        return null;
    }

    public function __toString(): string
    {
        return (string)$this->current();
    }
}

class SplTempFileObject extends SplFileObject
{
    public function __construct(int $maxMemory = 2 * 1024 * 1024)
    {
        $name = $maxMemory < 0 ? 'php://memory'
            : (\func_num_args() > 0 ? 'php://temp/maxmemory:' . (string)$maxMemory : 'php://temp');
        parent::__construct($name, 'wb');
    }

    public function getFilename(): string
    {
        return $this->getPathname();
    }

    public function getPath(): string
    {
        return '';
    }
}

class DirectoryIterator extends SplFileInfo implements SeekableIterator
{
    /** @var string[] */
    private array $__entries = [];
    private int $__pos = 0;
    private string $__dir;

    public function __construct(string $directory)
    {
        if ($directory === '') {
            throw new ValueError(static::class . '::__construct(): Argument #1 ($directory) cannot be empty');
        }
        $h = @\opendir($directory);
        if ($h === false) {
            throw new UnexpectedValueException(static::class . '::__construct(' . $directory
                . '): Failed to open directory: No such file or directory');
        }
        $entries = [];
        while (($e = \readdir($h)) !== false) { $entries[] = $e; }
        \closedir($h);
        $this->__load($entries, $directory);
    }

    /** Adopt a ready entry list (DirectoryIterator's constructor, and GlobIterator's). */
    protected function __load(array $entries, string $directory): void
    {
        $this->__entries = $entries;
        $this->__dir = \rtrim($directory, '/');
        if ($this->__dir === '' ) { $this->__dir = '/'; }
        $this->__pos = 0;
        $this->__skipHidden();
        parent::__construct($this->__entryPath());
    }

    public function isDot(): bool
    {
        $f = $this->__entries[$this->__pos] ?? '';
        return $f === '.' || $f === '..';
    }

    public function getFilename(): string
    {
        return $this->__entries[$this->__pos] ?? '';
    }

    public function getPath(): string
    {
        return $this->__dir;
    }

    public function getPathname(): string
    {
        return $this->__entryPath();
    }

    public function current(): mixed
    {
        return $this;
    }

    public function key(): mixed
    {
        return $this->__pos;
    }

    public function next(): void
    {
        $this->__pos = $this->__pos + 1;
        $this->__skipHidden();
        $this->__retarget();
    }

    public function rewind(): void
    {
        $this->__pos = 0;
        $this->__skipHidden();
        $this->__retarget();
    }

    public function valid(): bool
    {
        return $this->__pos < \count($this->__entries);
    }

    public function seek(int $offset): void
    {
        $this->rewind();
        for ($i = 0; $i < $offset && $this->valid(); $i++) { $this->next(); }
        if (!$this->valid()) {
            throw new OutOfBoundsException('Seek position ' . $offset . ' is out of range');
        }
    }

    /** FilesystemIterator's SKIP_DOTS; the plain DirectoryIterator shows them. */
    protected function __skipsDots(): bool
    {
        return false;
    }

    private function __skipHidden(): void
    {
        if (!$this->__skipsDots()) { return; }
        $n = \count($this->__entries);
        while ($this->__pos < $n) {
            $f = $this->__entries[$this->__pos];
            if ($f !== '.' && $f !== '..') { return; }
            $this->__pos = $this->__pos + 1;
        }
    }

    private function __entryPath(): string
    {
        $f = $this->__entries[$this->__pos] ?? '';
        if ($f === '') { return ''; }
        return $this->__dir === '/' ? '/' . $f : $this->__dir . '/' . $f;
    }

    private function __retarget(): void
    {
        $this->__setPathname($this->__entryPath());
    }
}

class FilesystemIterator extends DirectoryIterator
{
    public const CURRENT_MODE_MASK = 240;
    public const CURRENT_AS_PATHNAME = 32;
    public const CURRENT_AS_FILEINFO = 0;
    public const CURRENT_AS_SELF = 16;
    public const KEY_MODE_MASK = 3840;
    public const KEY_AS_PATHNAME = 0;
    public const FOLLOW_SYMLINKS = 16384;
    public const KEY_AS_FILENAME = 256;
    public const NEW_CURRENT_AND_KEY = 256;
    public const OTHER_MODE_MASK = 28672;
    public const SKIP_DOTS = 4096;
    public const UNIX_PATHS = 8192;

    private int $__fsFlags;

    public function __construct(
        string $directory,
        int $flags = FilesystemIterator::KEY_AS_PATHNAME | FilesystemIterator::CURRENT_AS_FILEINFO | FilesystemIterator::SKIP_DOTS,
    ) {
        $this->__fsFlags = $flags;
        parent::__construct($directory);
    }

    public function getFlags(): int
    {
        return $this->__fsFlags & (self::CURRENT_MODE_MASK | self::KEY_MODE_MASK | self::OTHER_MODE_MASK);
    }

    public function setFlags(int $flags): void
    {
        $this->__fsFlags = $flags;
    }

    public function current(): mixed
    {
        $f = $this->__fsFlags;
        if (($f & self::CURRENT_AS_PATHNAME) !== 0) { return $this->getPathname(); }
        if (($f & self::CURRENT_AS_SELF) !== 0) { return $this; }
        return $this->getFileInfo();
    }

    public function key(): mixed
    {
        if (($this->__fsFlags & self::KEY_AS_FILENAME) !== 0) { return $this->getFilename(); }
        return $this->getPathname();
    }

    protected function __skipsDots(): bool
    {
        return ($this->__fsFlags & self::SKIP_DOTS) !== 0;
    }

    protected function __flags(): int
    {
        return $this->__fsFlags;
    }

    protected function __setFsFlags(int $flags): void
    {
        $this->__fsFlags = $flags;
    }
}

class RecursiveDirectoryIterator extends FilesystemIterator implements RecursiveIterator
{
    private string $__subPath = '';

    public function __construct(string $directory, int $flags = FilesystemIterator::KEY_AS_PATHNAME | FilesystemIterator::CURRENT_AS_FILEINFO)
    {
        parent::__construct($directory, $flags);
    }

    public function hasChildren(bool $allowLinks = false): bool
    {
        if ($this->isDot()) { return false; }
        if (!$allowLinks && ($this->__flags() & self::FOLLOW_SYMLINKS) === 0 && $this->isLink()) {
            return false;
        }
        return $this->isDir();
    }

    public function getChildren(): RecursiveDirectoryIterator
    {
        $child = new static($this->getPathname(), $this->__flags());
        $child->__subPath = $this->__subPath === '' ? $this->getFilename() : $this->__subPath . '/' . $this->getFilename();
        return $child;
    }

    public function getSubPath(): string
    {
        return $this->__subPath;
    }

    public function getSubPathname(): string
    {
        return $this->__subPath === '' ? $this->getFilename() : $this->__subPath . '/' . $this->getFilename();
    }

    public function key(): mixed
    {
        if (($this->__flags() & self::KEY_AS_FILENAME) !== 0) { return $this->getFilename(); }
        return $this->getPathname();
    }
}

class LimitIterator extends IteratorIterator
{
    private int $__lOffset = 0;
    private int $__lCount = -1;
    private int $__lPos = 0;

    public function __construct(Iterator $iterator, int $offset = 0, int $limit = -1)
    {
        if ($offset < 0) {
            throw new ValueError('LimitIterator::__construct(): Argument #2 ($offset) must be greater than or equal to 0');
        }
        if ($limit < -1) {
            throw new ValueError('LimitIterator::__construct(): Argument #3 ($limit) must be greater than or equal to -1');
        }
        parent::__construct($iterator);
        $this->__lOffset = $offset;
        $this->__lCount = $limit;
    }

    public function rewind(): void
    {
        $this->__free();
        $this->__lPos = 0;
        $inner = $this->getInnerIterator();
        $inner->rewind();
        $this->seek($this->__lOffset);
    }

    public function valid(): bool
    {
        return ($this->__lCount === -1 || $this->__lPos < $this->__lOffset + $this->__lCount) && parent::valid();
    }

    public function next(): void
    {
        $this->__free();
        $inner = $this->getInnerIterator();
        $inner->next();
        $this->__lPos = $this->__lPos + 1;
        if ($this->__lCount === -1 || $this->__lPos < $this->__lOffset + $this->__lCount) {
            $this->__fetch();
        }
    }

    public function seek(int $offset): int
    {
        $this->__free();
        if ($offset < $this->__lOffset) {
            throw new OutOfBoundsException('Cannot seek to ' . $offset . ' which is below the offset ' . $this->__lOffset);
        }
        if ($this->__lCount !== -1 && $offset >= $this->__lOffset + $this->__lCount) {
            throw new OutOfBoundsException('Cannot seek to ' . $offset . ' which is behind offset '
                . $this->__lOffset . ' plus count ' . $this->__lCount);
        }
        $inner = $this->getInnerIterator();
        if ($offset !== $this->__lPos && $inner instanceof SeekableIterator) {
            $inner->seek($offset);
            $this->__lPos = $offset;
            if (($this->__lCount === -1 || $this->__lPos < $this->__lOffset + $this->__lCount) && $inner->valid()) {
                $this->__fetch();
            }
        } else {
            if ($offset < $this->__lPos) {
                $this->__free();
                $this->__lPos = 0;
                $inner->rewind();
            }
            while ($offset > $this->__lPos && $inner->valid()) {
                $this->__free();
                $inner->next();
                $this->__lPos = $this->__lPos + 1;
            }
            if ($inner->valid()) {
                $this->__fetch();
            }
        }
        return $this->__lPos;
    }

    public function getPosition(): int
    {
        return $this->__lPos;
    }
}

class InfiniteIterator extends IteratorIterator
{
    public function __construct(Iterator $iterator)
    {
        parent::__construct($iterator);
    }

    public function next(): void
    {
        $inner = $this->getInnerIterator();
        $this->__free();
        $inner->next();
        if ($inner->valid()) {
            $this->__fetchRaw();
        } else {
            $this->__free();
            $inner->rewind();
            if ($inner->valid()) {
                $this->__fetchRaw();
            }
        }
    }
}

class NoRewindIterator extends IteratorIterator
{
    public function __construct(Iterator $iterator)
    {
        parent::__construct($iterator);
    }

    public function rewind(): void {}

    public function valid(): bool
    {
        $inner = $this->getInnerIterator();
        return $inner->valid();
    }

    public function key(): mixed
    {
        $inner = $this->getInnerIterator();
        return $inner->key();
    }

    public function current(): mixed
    {
        $inner = $this->getInnerIterator();
        return $inner->current();
    }

    public function next(): void
    {
        $inner = $this->getInnerIterator();
        $inner->next();
    }
}

class CachingIterator extends IteratorIterator implements ArrayAccess, Countable, Stringable
{
    public const CALL_TOSTRING = 1;
    public const CATCH_GET_CHILD = 16;
    public const TOSTRING_USE_KEY = 2;
    public const TOSTRING_USE_CURRENT = 4;
    public const TOSTRING_USE_INNER = 8;
    public const FULL_CACHE = 256;

    private int $__cf = 1;
    private bool $__cvalid = false;
    /** @var array<mixed> */
    private array $__cache = [];
    private string $__zstr = '';
    private bool $__zhas = false;
    protected ?CachingIterator $__zchildren = null;

    public function __construct(Iterator $iterator, int $flags = CachingIterator::CALL_TOSTRING)
    {
        if (!self::__flagsOk($flags)) {
            throw new ValueError('CachingIterator::__construct(): Argument #2 ($flags) must contain only one of CachingIterator::CALL_TOSTRING, CachingIterator::TOSTRING_USE_KEY, CachingIterator::TOSTRING_USE_CURRENT, or CachingIterator::TOSTRING_USE_INNER');
        }
        parent::__construct($iterator);
        $this->__cf = $flags & 0xFFFF;
    }

    private static function __flagsOk(int $flags): bool
    {
        $n = 0;
        foreach ([1, 2, 4, 8] as $bit) {
            if (($flags & $bit) !== 0) { $n = $n + 1; }
        }
        return $n <= 1;
    }

    public function rewind(): void
    {
        $this->__free();
        $inner = $this->getInnerIterator();
        $inner->rewind();
        $this->__cache = [];
        $this->__cnext();
    }

    public function valid(): bool
    {
        return $this->__cvalid;
    }

    public function next(): void
    {
        $this->__cnext();
    }

    private function __cnext(): void
    {
        $inner = $this->getInnerIterator();
        $this->__free();
        $this->__zstr = '';
        $this->__zhas = false;
        $this->__zchildren = null;
        if (!$inner->valid()) {
            $this->__cvalid = false;
            return;
        }
        $this->__fetchRaw();
        $this->__cvalid = true;
        if (($this->__cf & 256) !== 0) {
            $this->__cache[$this->key()] = $this->current();
        }
        if ($this instanceof RecursiveCachingIterator) {
            try {
                if ($inner->hasChildren()) {
                    $this->__zchildren = new RecursiveCachingIterator($inner->getChildren(), $this->__cf);
                }
            } catch (Throwable $e) {
                if (($this->__cf & 16) === 0) { throw $e; }
            }
        }
        if (($this->__cf & (8 | 1)) !== 0) {
            if (($this->__cf & 8) !== 0) {
                if (!($inner instanceof Stringable)) {
                    throw new Error('Object of class ' . \get_class($inner) . ' could not be converted to string');
                }
                $this->__zstr = (string)$inner;
            } else {
                $this->__zstr = (string)$this->current();
            }
            $this->__zhas = true;
        }
        $inner->next();
    }

    public function hasNext(): bool
    {
        $inner = $this->getInnerIterator();
        return $inner->valid();
    }

    public function __toString(): string
    {
        if (($this->__cf & (1 | 2 | 4 | 8)) === 0) {
            throw new BadMethodCallException(static::class . ' does not fetch string value (see CachingIterator::__construct)');
        }
        if (($this->__cf & 2) !== 0) { return (string)$this->key(); }
        if (($this->__cf & 4) !== 0) { return (string)$this->current(); }
        return $this->__zhas ? $this->__zstr : '';
    }

    public function getFlags(): int
    {
        return $this->__cf;
    }

    public function setFlags(int $flags): void
    {
        if (!self::__flagsOk($flags)) {
            throw new ValueError('CachingIterator::setFlags(): Argument #1 ($flags) must contain only one of CachingIterator::CALL_TOSTRING, CachingIterator::TOSTRING_USE_KEY, CachingIterator::TOSTRING_USE_CURRENT, or CachingIterator::TOSTRING_USE_INNER');
        }
        if (($this->__cf & 1) !== 0 && ($flags & 1) === 0) {
            throw new InvalidArgumentException('Unsetting flag CALL_TO_STRING is not possible');
        }
        if (($this->__cf & 8) !== 0 && ($flags & 8) === 0) {
            throw new InvalidArgumentException('Unsetting flag TOSTRING_USE_INNER is not possible');
        }
        if (($flags & 256) !== 0 && ($this->__cf & 256) === 0) {
            $this->__cache = [];
        }
        $this->__cf = $flags & 0xFFFF;
    }

    private function __needCache(): void
    {
        if (($this->__cf & 256) === 0) {
            throw new BadMethodCallException(static::class . ' does not use a full cache (see CachingIterator::__construct)');
        }
    }

    public function offsetGet(mixed $key): mixed
    {
        $this->__needCache();
        return $this->__cache[$key] ?? null;
    }

    public function offsetSet(mixed $key, mixed $value): void
    {
        $this->__needCache();
        $this->__cache[$key] = $value;
    }

    public function offsetExists(mixed $key): bool
    {
        $this->__needCache();
        return isset($this->__cache[$key]);
    }

    public function offsetUnset(mixed $key): void
    {
        $this->__needCache();
        unset($this->__cache[$key]);
    }

    public function getCache(): array
    {
        $this->__needCache();
        return $this->__cache;
    }

    public function count(): int
    {
        $this->__needCache();
        return \count($this->__cache);
    }
}

class RecursiveCachingIterator extends CachingIterator implements RecursiveIterator
{
    public function __construct(Iterator $iterator, int $flags = RecursiveCachingIterator::CALL_TOSTRING)
    {
        parent::__construct($iterator, $flags);
    }

    public function hasChildren(): bool
    {
        return $this->__zchildren !== null;
    }

    public function getChildren(): ?RecursiveCachingIterator
    {
        return $this->__zchildren;
    }
}

class RegexIterator extends FilterIterator
{
    public const USE_KEY = 1;
    public const INVERT_MATCH = 2;
    public const MATCH = 0;
    public const GET_MATCH = 1;
    public const ALL_MATCHES = 2;
    public const SPLIT = 3;
    public const REPLACE = 4;

    public ?string $replacement = null;
    private string $__rx = '';
    private int $__rmode = 0;
    private int $__rflags = 0;
    private int $__rpreg = 0;

    public function __construct(Iterator $iterator, string $pattern, int $mode = RegexIterator::MATCH, int $flags = 0, int $pregFlags = 0)
    {
        if ($mode < 0 || $mode > 4) {
            throw new ValueError('RegexIterator::__construct(): Argument #3 ($mode) must be RegexIterator::MATCH, RegexIterator::GET_MATCH, RegexIterator::ALL_MATCHES, RegexIterator::SPLIT, or RegexIterator::REPLACE');
        }
        parent::__construct($iterator);
        $this->__rx = $pattern;
        $this->__rmode = $mode;
        $this->__rflags = $flags;
        $this->__rpreg = $pregFlags;
    }

    public function accept(): bool
    {
        if (!parent::valid()) { return false; }
        if (($this->__rflags & 1) !== 0) {
            $subject = (string)$this->key();
        } else {
            $cur = $this->current();
            if (\is_array($cur)) { return false; }
            $subject = (string)$cur;
        }
        $ok = false;
        $mode = $this->__rmode;
        if ($mode === 0) {
            $ok = \preg_match($this->__rx, $subject) > 0;
        } elseif ($mode === 1) {
            $m = [];
            $ok = \preg_match($this->__rx, $subject, $m, $this->__rpreg) > 0;
            $this->__setCurrent($m);
        } elseif ($mode === 2) {
            $m = [];
            $n = \preg_match_all($this->__rx, $subject, $m, $this->__rpreg === 0 ? 1 : $this->__rpreg);
            $this->__setCurrent($m);
            $ok = $n > 0;
        } elseif ($mode === 3) {
            $parts = \preg_split($this->__rx, $subject, -1, $this->__rpreg);
            $this->__setCurrent($parts);
            $ok = \count($parts) > 1;
        } else {
            $count = 0;
            $res = \preg_replace($this->__rx, (string)$this->replacement, $subject, -1, $count);
            if (($this->__rflags & 1) !== 0) { $this->__setKey($res); } else { $this->__setCurrent($res); }
            $ok = $count > 0;
        }
        if (($this->__rflags & 2) !== 0) { return !$ok; }
        return $ok;
    }

    public function getMode(): int
    {
        return $this->__rmode;
    }

    public function setMode(int $mode): void
    {
        if ($mode < 0 || $mode > 4) {
            throw new ValueError('RegexIterator::setMode(): Argument #1 ($mode) must be RegexIterator::MATCH, RegexIterator::GET_MATCH, RegexIterator::ALL_MATCHES, RegexIterator::SPLIT, or RegexIterator::REPLACE');
        }
        $this->__rmode = $mode;
    }

    public function getFlags(): int
    {
        return $this->__rflags;
    }

    public function setFlags(int $flags): void
    {
        $this->__rflags = $flags;
    }

    public function getPregFlags(): int
    {
        return $this->__rpreg;
    }

    public function setPregFlags(int $pregFlags): void
    {
        $this->__rpreg = $pregFlags;
    }

    public function getRegex(): string
    {
        return $this->__rx;
    }
}

class RecursiveRegexIterator extends RegexIterator implements RecursiveIterator
{
    public function __construct(RecursiveIterator $iterator, string $pattern, int $mode = RecursiveRegexIterator::MATCH, int $flags = 0, int $pregFlags = 0)
    {
        parent::__construct($iterator, $pattern, $mode, $flags, $pregFlags);
    }

    public function accept(): bool
    {
        if (!$this->valid()) { return false; }
        $cur = $this->current();
        if (\is_array($cur)) { return \count($cur) > 0; }
        return parent::accept();
    }

    public function hasChildren(): bool
    {
        $inner = $this->getInnerIterator();
        return $inner instanceof RecursiveIterator && $inner->hasChildren();
    }

    public function getChildren(): RecursiveRegexIterator
    {
        $inner = $this->getInnerIterator();
        return new static($inner->getChildren(), $this->getRegex(), $this->getMode(), $this->getFlags(), $this->getPregFlags());
    }
}

class ParentIterator extends RecursiveFilterIterator
{
    public function __construct(RecursiveIterator $iterator)
    {
        parent::__construct($iterator);
    }

    public function accept(): bool
    {
        return $this->getInnerIterator()->hasChildren();
    }
}

class RecursiveArrayIterator extends ArrayIterator implements RecursiveIterator
{
    public const CHILD_ARRAYS_ONLY = 4;

    public function hasChildren(): bool
    {
        $c = $this->current();
        return \is_array($c) || (\is_object($c) && ($this->getFlags() & 4) === 0);
    }

    public function getChildren(): ?RecursiveArrayIterator
    {
        $c = $this->current();
        if ($c instanceof RecursiveArrayIterator) { return $c; }
        return new static($c, $this->getFlags());
    }
}

class MultipleIterator implements Iterator
{
    public const MIT_NEED_ANY = 0;
    public const MIT_NEED_ALL = 1;
    public const MIT_KEYS_NUMERIC = 0;
    public const MIT_KEYS_ASSOC = 2;

    /** @var Iterator[] */
    private array $__mits = [];
    /** @var array<mixed> */
    private array $__minfos = [];
    private int $__mflags = 1;

    public function __construct(int $flags = MultipleIterator::MIT_NEED_ALL | MultipleIterator::MIT_KEYS_NUMERIC)
    {
        $this->__mflags = $flags;
    }

    public function getFlags(): int
    {
        return $this->__mflags;
    }

    public function setFlags(int $flags): void
    {
        $this->__mflags = $flags;
    }

    public function attachIterator(Iterator $iterator, string|int|null $info = null): void
    {
        if ($info === null && ($this->__mflags & 2) !== 0) {
            // php defers this to current()/key(), when the info is read
        }
        if ($info !== null) {
            foreach ($this->__mits as $i => $it) {
                if ($it !== $iterator && $this->__minfos[$i] === $info) {
                    throw new InvalidArgumentException('Key duplication error');
                }
            }
        }
        foreach ($this->__mits as $i => $it) {
            if ($it === $iterator) {
                $this->__minfos[$i] = $info;
                return;
            }
        }
        $this->__mits[] = $iterator;
        $this->__minfos[] = $info;
    }

    public function detachIterator(Iterator $iterator): void
    {
        $its = [];
        $infos = [];
        foreach ($this->__mits as $i => $it) {
            if ($it !== $iterator) {
                $its[] = $it;
                $infos[] = $this->__minfos[$i];
            }
        }
        $this->__mits = $its;
        $this->__minfos = $infos;
    }

    public function containsIterator(Iterator $iterator): bool
    {
        foreach ($this->__mits as $it) {
            if ($it === $iterator) { return true; }
        }
        return false;
    }

    public function countIterators(): int
    {
        return \count($this->__mits);
    }

    public function rewind(): void
    {
        foreach ($this->__mits as $it) { $it->rewind(); }
    }

    public function valid(): bool
    {
        if (\count($this->__mits) === 0) { return false; }
        $expect = ($this->__mflags & 1) !== 0;
        foreach ($this->__mits as $it) {
            if ($it->valid() !== $expect) { return !$expect; }
        }
        return $expect;
    }

    public function next(): void
    {
        foreach ($this->__mits as $it) { $it->next(); }
    }

    public function current(): mixed
    {
        return $this->__mall(true);
    }

    public function key(): mixed
    {
        return $this->__mall(false);
    }

    private function __mall(bool $cur): array
    {
        $what = $cur ? 'current' : 'key';
        if (\count($this->__mits) === 0) {
            throw new RuntimeException('Called ' . $what . '() on an invalid iterator');
        }
        $out = [];
        foreach ($this->__mits as $i => $it) {
            if ($it->valid()) {
                $v = $cur ? $it->current() : $it->key();
            } elseif (($this->__mflags & 1) !== 0) {
                throw new RuntimeException('Called ' . $what . '() with non valid sub iterator');
            } else {
                $v = null;
            }
            if (($this->__mflags & 2) !== 0) {
                $info = $this->__minfos[$i];
                if ($info === null) {
                    throw new InvalidArgumentException('Sub-Iterator is associated with NULL');
                }
                $out[$info] = $v;
            } else {
                $out[] = $v;
            }
        }
        return $out;
    }
}

interface SplObserver
{
    public function update(SplSubject $subject): void;
}

interface SplSubject
{
    public function attach(SplObserver $observer): void;

    public function detach(SplObserver $observer): void;

    public function notify(): void;
}

class GlobIterator extends FilesystemIterator implements Countable
{
    private int $__globCount = 0;

    public function __construct(string $pattern, int $flags = FilesystemIterator::KEY_AS_PATHNAME | FilesystemIterator::CURRENT_AS_FILEINFO)
    {
        $this->__setFsFlags($flags);
        $found = \glob($pattern);
        $names = [];
        // ponytail: one directory for every match (wildcards only in the last
        // path segment); a pattern like /a/*/x would need a per-entry directory.
        foreach ($found === false ? [] : $found as $path) { $names[] = \basename($path); }
        $this->__globCount = \count($names);
        $this->__load($names, \dirname($pattern));
    }

    public function count(): int
    {
        return $this->__globCount;
    }
}

/**
 * @template T
 */
class SplFixedArray implements IteratorAggregate, ArrayAccess, Countable, JsonSerializable
{
    /** The native CELL buffer (MemoryAbi::BUF_KIND_CELL) holding the elements.
     *  The compiler owns its lifetime: freed with the object, deep-copied by
     *  `clone`, hidden from every property view — so a subclass's own
     *  `__destruct` / `__clone` / `__construct` need not know about it. 0 until
     *  the first sizing (a subclass constructor that skips parent's). */
    private int $__mcbuf = 0;

    public function __construct(int $size = 0)
    {
        if ($size < 0) {
            throw new ValueError('SplFixedArray::__construct(): Argument #1 ($size) must be greater than or equal to 0');
        }
        $this->__mcbuf = __mc_nbuf_alloc(11, $size);
    }

    public function count(): int
    {
        return __mc_nbuf_len($this->__mcbuf);
    }

    public function getSize(): int
    {
        return __mc_nbuf_len($this->__mcbuf);
    }

    public function setSize(int $size): bool
    {
        if ($size < 0) {
            throw new ValueError('SplFixedArray::setSize(): Argument #1 ($size) must be greater than or equal to 0');
        }
        if ($this->__mcbuf === 0) {
            $this->__mcbuf = __mc_nbuf_alloc(11, $size);
        } else {
            // Shrinking releases each dropped element; growing null-fills.
            $this->__mcbuf = __mc_nbuf_resize($this->__mcbuf, $size);
        }
        return true;
    }

    /** @return array<int, mixed> */
    public function toArray(): array
    {
        $out = [];
        $n = __mc_nbuf_len($this->__mcbuf);
        for ($i = 0; $i < $n; $i++) { $out[] = __mc_nbuf_get_c($this->__mcbuf, $i); }
        return $out;
    }

    public static function fromArray(array $array, bool $preserveKeys = true): SplFixedArray
    {
        if ($array === []) { return new SplFixedArray(0); }
        if ($preserveKeys) {
            $max = -1;
            foreach ($array as $k => $_) {
                if (!\is_int($k) || $k < 0) {
                    throw new InvalidArgumentException('array must contain only positive integer keys');
                }
                if ($k > $max) { $max = $k; }
            }
            $out = new SplFixedArray($max + 1);
            foreach ($array as $k => $v) { __mc_nbuf_set_c($out->__mcbuf, (int)$k, $v); }
            return $out;
        }
        $out = new SplFixedArray(\count($array));
        $i = 0;
        foreach ($array as $v) { __mc_nbuf_set_c($out->__mcbuf, $i, $v); $i = $i + 1; }
        return $out;
    }

    // Each accessor tries an in-range INT index first: the guard reads an
    // unboxed copy of it (NarrowScalarGuards), so the common `$tokens[$i]`
    // is two int compares and a load instead of the full offset rule in
    // __index. Anything else takes the rule.

    public function offsetExists(mixed $index): bool
    {
        if (\is_int($index)) {
            if ($index >= 0 && $index < __mc_nbuf_len($this->__mcbuf)) {
                return __mc_nbuf_get_c($this->__mcbuf, $index) !== null;
            }
        }
        $i = $this->__index($index, false);
        return $i >= 0 && __mc_nbuf_get_c($this->__mcbuf, $i) !== null;
    }

    /** @return T */
    public function offsetGet(mixed $index): mixed
    {
        if (\is_int($index)) {
            if ($index >= 0 && $index < __mc_nbuf_len($this->__mcbuf)) {
                return __mc_nbuf_get_c($this->__mcbuf, $index);
            }
        }
        return __mc_nbuf_get_c($this->__mcbuf, $this->__index($index, true));
    }

    public function offsetSet(mixed $index, mixed $value): void
    {
        if (\is_int($index)) {
            if ($index >= 0 && $index < __mc_nbuf_len($this->__mcbuf)) {
                __mc_nbuf_set_c($this->__mcbuf, $index, $value);
                return;
            }
        }
        if ($index === null) {
            throw new Error('[] operator not supported for SplFixedArray');
        }
        __mc_nbuf_set_c($this->__mcbuf, $this->__index($index, true), $value);
    }

    public function offsetUnset(mixed $index): void
    {
        __mc_nbuf_set_c($this->__mcbuf, $this->__index($index, true), null);
    }

    public function getIterator(): Iterator
    {
        return new __McFixedArrayIterator($this);
    }

    /** @return array<int, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /** @return array<int, mixed> */
    public function __serialize(): array
    {
        return $this->toArray();
    }

    /** @param array<int|string, mixed> $data */
    public function __unserialize(array $data): void
    {
        // The elements, in order (the keys are their positions).
        $this->setSize(0);
        $this->setSize(\count($data));
        $i = 0;
        foreach ($data as $v) { __mc_nbuf_set_c($this->__mcbuf, $i, $v); $i = $i + 1; }
    }

    /** @return array<int, mixed> */
    public function __debugInfo(): array
    {
        return $this->toArray();
    }

    /** The stored element at the valid position `$i`, past any override of
     *  offsetGet — what php's own iterator reads. */
    final public function __mcAt(int $i): mixed
    {
        return __mc_nbuf_get_c($this->__mcbuf, $i);
    }

    /** The stored size, past any override of getSize. */
    final public function __mcLen(): int
    {
        return __mc_nbuf_len($this->__mcbuf);
    }

    /** php's offset rule: an int, or a string/float/bool that reads as one; in range. */
    private function __index(mixed $index, bool $strict): int
    {
        if (\is_int($index)) {
            $i = $index;
        } elseif (\is_string($index) && \is_numeric($index) && (string)(int)$index === $index) {
            $i = (int)$index;
        } elseif (\is_float($index) || \is_bool($index)) {
            $i = (int)$index;
        } else {
            throw new TypeError('Cannot access offset of type ' . \get_debug_type($index) . ' on SplFixedArray');
        }
        if ($i < 0 || $i >= __mc_nbuf_len($this->__mcbuf)) {
            if (!$strict) { return -1; }
            throw new OutOfBoundsException('Index invalid or out of range');
        }
        return $i;
    }
}

/** SplFixedArray::getIterator() — reads the array live, like php's. */
final class __McFixedArrayIterator implements Iterator
{
    private int $__pos = 0;

    public function __construct(private SplFixedArray $array) {}

    public function current(): mixed { return $this->array->__mcAt($this->__pos); }
    public function key(): mixed { return $this->__pos; }
    public function next(): void { $this->__pos = $this->__pos + 1; }
    public function rewind(): void { $this->__pos = 0; }
    public function valid(): bool { return $this->__pos < $this->array->__mcLen(); }
}

class SplDoublyLinkedList implements Iterator, Countable, ArrayAccess
{
    public const IT_MODE_LIFO = 2;
    public const IT_MODE_FIFO = 0;
    public const IT_MODE_DELETE = 1;
    public const IT_MODE_KEEP = 0;

    private int $flags = 0;
    /** @var array<int, mixed> head to tail; the live elements start at `$__off` */
    private array $dllist = [];
    /** Slots at the front already shifted out: a shift is O(1), the list is compacted when half of it is dead. */
    private int $__off = 0;
    private int $__pos = 0;
    /** The cursor points at an element (php's traverse pointer is not NULL). */
    private bool $__live = false;

    public function push(mixed $value): void { $this->dllist[] = $value; }

    public function unshift(mixed $value): void
    {
        if ($this->__off > 0) {
            $this->__off = $this->__off - 1;
            $this->dllist[$this->__off] = $value;
            return;
        }
        \array_unshift($this->dllist, $value);
    }

    public function pop(): mixed
    {
        if (\count($this->dllist) === $this->__off) { throw new RuntimeException("Can't pop from an empty datastructure"); }
        $v = \array_pop($this->dllist);
        if (\count($this->dllist) === $this->__off) { $this->dllist = []; $this->__off = 0; }
        return $v;
    }

    public function shift(): mixed
    {
        $n = \count($this->dllist);
        if ($n === $this->__off) { throw new RuntimeException("Can't shift from an empty datastructure"); }
        $v = $this->dllist[$this->__off];
        $this->dllist[$this->__off] = null;
        $this->__off = $this->__off + 1;
        if ($this->__off === $n) {
            $this->dllist = [];
            $this->__off = 0;
        } elseif ($this->__off >= 16 && $this->__off * 2 >= $n) {
            $this->dllist = \array_slice($this->dllist, $this->__off);
            $this->__off = 0;
        }
        return $v;
    }

    public function top(): mixed
    {
        $n = \count($this->dllist);
        if ($n === $this->__off) { throw new RuntimeException("Can't peek at an empty datastructure"); }
        return $this->dllist[$n - 1];
    }

    public function bottom(): mixed
    {
        if (\count($this->dllist) === $this->__off) { throw new RuntimeException("Can't peek at an empty datastructure"); }
        return $this->dllist[$this->__off];
    }

    public function isEmpty(): bool { return \count($this->dllist) === $this->__off; }

    public function count(): int { return \count($this->dllist) - $this->__off; }

    public function setIteratorMode(int $mode): int
    {
        $fixed = $this->__mcFixed();
        if ($fixed !== 0 && ($mode & 2) !== ($fixed & 2)) {
            throw new RuntimeException("Iterators' LIFO/FIFO modes for SplStack/SplQueue objects are frozen");
        }
        $this->flags = $mode & 3;
        return $this->flags | $fixed;
    }

    public function getIteratorMode(): int { return $this->flags | $this->__mcFixed(); }

    public function offsetExists(mixed $index): bool
    {
        $k = $this->__mcIndex($index, 'offsetExists');
        return $k >= 0 && $k < \count($this->dllist) - $this->__off;
    }

    public function offsetGet(mixed $index): mixed
    {
        return $this->dllist[$this->__mcSlot($this->__mcIndex($index, 'offsetGet'), 'offsetGet')];
    }

    public function offsetSet(mixed $index, mixed $value): void
    {
        if ($index === null) { $this->dllist[] = $value; return; }
        $this->dllist[$this->__mcSlot($this->__mcIndex($index, 'offsetSet'), 'offsetSet')] = $value;
    }

    public function offsetUnset(mixed $index): void
    {
        \array_splice($this->dllist, $this->__mcSlot($this->__mcIndex($index, 'offsetUnset'), 'offsetUnset'), 1);
    }

    /** Inserts `$value` so that it becomes element `$index` (counted from the top in LIFO mode). */
    public function add(int $index, mixed $value): void
    {
        $n = \count($this->dllist) - $this->__off;
        if ($index < 0 || $index > $n) { throw new OutOfRangeException('SplDoublyLinkedList::add(): Argument #1 ($index) is out of range'); }
        if ($index === $n) { $this->dllist[] = $value; return; }
        \array_splice($this->dllist, $this->__mcSlot($index, 'add'), 0, [$value]);
    }

    public function rewind(): void
    {
        $n = \count($this->dllist) - $this->__off;
        $this->__pos = (($this->flags | $this->__mcFixed()) & 2) !== 0 ? $n - 1 : 0;
        $this->__live = $n > 0;
    }

    public function valid(): bool { return $this->__live; }

    public function current(): mixed
    {
        // A cursor whose element was removed under it reads null, as php's does.
        if (!$this->__live || $this->__pos < 0 || $this->__pos >= \count($this->dllist) - $this->__off) { return null; }
        return $this->dllist[$this->__off + $this->__pos];
    }

    public function key(): int { return $this->__pos; }

    public function next(): void { $this->__mcStep((($this->flags | $this->__mcFixed()) & 2) !== 0); }

    public function prev(): void { $this->__mcStep((($this->flags | $this->__mcFixed()) & 2) === 0); }

    /** @return array<int, mixed> */
    public function __serialize(): array
    {
        return [$this->flags | $this->__mcFixed(), \array_slice($this->dllist, $this->__off), []];
    }

    /** @param array<int, mixed> $data */
    public function __unserialize(array $data): void
    {
        $this->flags = ((int)$data[0]) & 3;
        $this->dllist = \array_values($data[1]);
        $this->__off = 0;
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [
            "\0SplDoublyLinkedList\0flags" => $this->flags | $this->__mcFixed(),
            "\0SplDoublyLinkedList\0dllist" => \array_slice($this->dllist, $this->__off),
        ];
    }

    /** One cursor step toward the head (`$back`) or the tail, deleting the end it leaves in DELETE mode. */
    private function __mcStep(bool $back): void
    {
        if (!$this->__live) { return; }
        $delete = ($this->flags & 1) !== 0;
        if ($back) {
            $this->__pos = $this->__pos - 1;
            if ($delete) { $this->pop(); }
        } elseif ($delete) {
            $this->shift();
        } else {
            $this->__pos = $this->__pos + 1;
        }
        $this->__live = $this->__pos >= 0 && $this->__pos < \count($this->dllist) - $this->__off;
    }

    /** The mode bits an SplStack / SplQueue cannot change. */
    private function __mcFixed(): int
    {
        if ($this instanceof SplStack) { return 6; }
        if ($this instanceof SplQueue) { return 4; }
        return 0;
    }

    /** php's `int $index` coercion. */
    private function __mcIndex(mixed $index, string $fn): int
    {
        if (\is_int($index)) { return $index; }
        if (\is_bool($index) || \is_float($index) || $index === null || (\is_string($index) && \is_numeric($index))) { return (int)$index; }
        throw new TypeError('SplDoublyLinkedList::' . $fn . '(): Argument #1 ($index) must be of type int, ' . \get_debug_type($index) . ' given');
    }

    /** The storage slot of element `$k`, counted from the top in LIFO mode. */
    private function __mcSlot(int $k, string $fn): int
    {
        $n = \count($this->dllist) - $this->__off;
        if ($k < 0 || $k >= $n) { throw new OutOfRangeException('SplDoublyLinkedList::' . $fn . '(): Argument #1 ($index) is out of range'); }
        return $this->__off + ((($this->flags | $this->__mcFixed()) & 2) !== 0 ? $n - 1 - $k : $k);
    }
}

class SplQueue extends SplDoublyLinkedList
{
    public function enqueue(mixed $value): void { $this->push($value); }

    public function dequeue(): mixed { return $this->shift(); }
}

class SplStack extends SplDoublyLinkedList
{
}

class SplObjectStorage implements Countable, SeekableIterator, ArrayAccess
{
    /** @var array<int, object> */
    private array $__objects = [];
    /** @var array<int, mixed> */
    private array $__infos = [];
    /** @var int[] */
    private array $__order = [];
    private int $__pos = 0;

    public function attach(object $object, mixed $info = null): void
    {
        $id = \spl_object_id($object);
        if (!isset($this->__objects[$id])) { $this->__order[] = $id; }
        $this->__objects[$id] = $object;
        $this->__infos[$id] = $info;
    }

    public function detach(object $object): void
    {
        $id = \spl_object_id($object);
        if (!isset($this->__objects[$id])) { return; }
        unset($this->__objects[$id], $this->__infos[$id]);
        $this->__order = \array_values(\array_filter($this->__order, static fn (int $x): bool => $x !== $id));
    }

    public function contains(object $object): bool
    {
        return isset($this->__objects[\spl_object_id($object)]);
    }

    public function addAll(SplObjectStorage $storage): int
    {
        foreach ($storage->__order as $id) {
            $this->attach($storage->__objects[$id], $storage->__infos[$id]);
        }
        return \count($this->__order);
    }

    public function removeAll(SplObjectStorage $storage): int
    {
        foreach ($storage->__order as $id) { $this->detach($storage->__objects[$id]); }
        return \count($this->__order);
    }

    public function removeAllExcept(SplObjectStorage $storage): int
    {
        foreach ($this->__order as $id) {
            if (!isset($storage->__objects[$id])) { $this->detach($this->__objects[$id]); }
        }
        return \count($this->__order);
    }

    public function getInfo(): mixed
    {
        $id = $this->__order[$this->__pos] ?? null;
        return $id === null ? null : $this->__infos[$id];
    }

    public function setInfo(mixed $info): void
    {
        $id = $this->__order[$this->__pos] ?? null;
        if ($id !== null) { $this->__infos[$id] = $info; }
    }

    public function getHash(object $object): string
    {
        return \spl_object_hash($object);
    }

    public function count(int $mode = 0): int
    {
        return \count($this->__order);
    }

    public function rewind(): void { $this->__pos = 0; }

    public function valid(): bool { return $this->__pos < \count($this->__order); }

    public function key(): mixed { return $this->__pos; }

    public function current(): mixed
    {
        $id = $this->__order[$this->__pos] ?? null;
        if ($id === null) {
            throw new RuntimeException('Called current() on invalid iterator');
        }
        return $this->__objects[$id];
    }

    public function next(): void { $this->__pos = $this->__pos + 1; }

    public function seek(int $offset): void
    {
        if ($offset < 0 || $offset >= \count($this->__order)) {
            throw new OutOfBoundsException('Seek position ' . $offset . ' is out of range');
        }
        $this->__pos = $offset;
    }

    public function offsetExists(mixed $object): bool
    {
        return \is_object($object) && $this->contains($object);
    }

    public function offsetGet(mixed $object): mixed
    {
        $id = \spl_object_id($object);
        if (!isset($this->__objects[$id])) {
            throw new UnexpectedValueException('Object not found');
        }
        return $this->__infos[$id];
    }

    public function offsetSet(mixed $object, mixed $info = null): void
    {
        $this->attach($object, $info);
    }

    public function offsetUnset(mixed $object): void
    {
        $this->detach($object);
    }
}

/**
 * `yield from $src` normalised to ONE generator, whatever `$src` is: the same
 * generator when it is one, else a wrapper walking the array / Iterator /
 * IteratorAggregate. The desugared `foreach` over the result is then statically
 * a Generator loop, so its yielding body is emitted once — an erased source
 * (a closure call, an `iterable`) otherwise took the array-only walk and a
 * generator behind it yielded nothing (symfony Finder's LazyIterator).
 */
function __mc_yf_gen(mixed $src): \Generator
{
    if ($src instanceof \Generator) { return $src; }
    return __mc_yf_wrap($src);
}

function __mc_yf_wrap(mixed $src): \Generator
{
    if (\is_array($src)) {
        foreach ($src as $k => $v) { yield $k => $v; }
        return;
    }
    if ($src instanceof \IteratorAggregate) {
        foreach (__mc_yf_gen($src->getIterator()) as $k => $v) { yield $k => $v; }
        return;
    }
    if ($src instanceof \Iterator) {
        $src->rewind();
        while ($src->valid()) {
            yield $src->key() => $src->current();
            $src->next();
        }
    }
}

/**
 * php's binary heap, with its exact sift order (equal elements come out in
 * the order Zend gives them). The root is the element `compare()` ranks
 * highest. A `compare()` that throws leaves the heap corrupted: every later
 * read or write throws until recoverFromCorruption().
 *
 * SplMinHeap and SplMaxHeap themselves (no user `compare()`) take a path with
 * no virtual call and no try/catch per comparison: a `try` costs a setjmp.
 */
abstract class SplHeap implements Iterator, Countable
{
    private int $flags = 0;
    private bool $isCorrupted = false;
    /** @var array<int, mixed> */
    private array $heap = [];
    abstract protected function compare(mixed $value1, mixed $value2): int;

    /** 1 = SplMaxHeap itself, -1 = SplMinHeap itself, 0 = a user compare() (no property: var_dump shows the real ones) */
    private function __mcDir(): int
    {
        $c = \get_class($this);
        return $c === 'SplMaxHeap' ? 1 : ($c === 'SplMinHeap' ? -1 : 0);
    }

    public function insert(mixed $value): true
    {
        if ($this->isCorrupted) { throw new RuntimeException('Heap is corrupted, heap properties are no longer ensured.'); }
        $dir = $this->__mcDir();
        $i = \count($this->heap);
        $this->heap[] = $value;
        if ($dir !== 0) {
            $max = $dir > 0;
            while ($i > 0) {
                $p = ($i - 1) >> 1;
                $pv = $this->heap[$p];
                if ($max ? ($pv <=> $value) >= 0 : ($value <=> $pv) >= 0) { break; }
                $this->heap[$i] = $pv;
                $i = $p;
            }
            $this->heap[$i] = $value;
            return true;
        }
        $err = null;
        while ($i > 0) {
            $p = \intdiv($i - 1, 2);
            if ($this->__mcCmp($this->heap[$p], $value, $err) >= 0) { break; }
            $this->heap[$i] = $this->heap[$p];
            $i = $p;
        }
        $this->heap[$i] = $value;
        if ($err !== null) { $this->isCorrupted = true; throw $err; }
        return true;
    }

    public function extract(): mixed
    {
        if ($this->isCorrupted) { throw new RuntimeException('Heap is corrupted, heap properties are no longer ensured.'); }
        if ($this->heap === []) { throw new RuntimeException("Can't extract from an empty heap"); }
        $err = null;
        $top = $this->__mcDeleteTop($err);
        if ($err !== null) { $this->isCorrupted = true; throw $err; }
        return $top;
    }

    public function top(): mixed
    {
        if ($this->isCorrupted) { throw new RuntimeException('Heap is corrupted, heap properties are no longer ensured.'); }
        if ($this->heap === []) { throw new RuntimeException("Can't peek at an empty heap"); }
        return $this->heap[0];
    }

    public function isEmpty(): bool { return $this->heap === []; }

    public function count(): int { return \count($this->heap); }

    public function rewind(): void {}

    public function valid(): bool { return $this->heap !== []; }

    public function current(): mixed { return $this->heap === [] ? null : $this->heap[0]; }

    public function key(): int { return \count($this->heap) - 1; }

    public function next(): void
    {
        if ($this->isCorrupted) { throw new RuntimeException('Heap is corrupted, heap properties are no longer ensured.'); }
        if ($this->heap === []) { return; }
        $err = null;
        $this->__mcDeleteTop($err);
        if ($err !== null) { $this->isCorrupted = true; throw $err; }
    }

    public function recoverFromCorruption(): true
    {
        $this->isCorrupted = false;
        return true;
    }

    public function isCorrupted(): bool { return $this->isCorrupted; }

    /** @return array<int, mixed> */
    public function __serialize(): array
    {
        return [[], ['flags' => $this->flags, 'heap_elements' => $this->heap]];
    }

    /** @param array<int, mixed> $data */
    public function __unserialize(array $data): void
    {
        $this->flags = (int)$data[1]['flags'];
        $this->heap = \array_values($data[1]['heap_elements']);
    }

    private function __mcCmp(mixed $a, mixed $b, ?Throwable &$err): int
    {
        if ($err !== null) { return 0; }
        try {
            return $this->compare($a, $b);
        } catch (Throwable $e) {
            $err = $e;
            return 0;
        }
    }

    private function __mcDeleteTop(?Throwable &$err): mixed
    {
        $top = $this->heap[0];
        $bottom = \array_pop($this->heap);
        $n = \count($this->heap);
        if ($n === 0) { return $top; }
        $limit = $n >> 1;
        $i = 0;
        $dir = $this->__mcDir();
        if ($dir !== 0) {
            $max = $dir > 0;
            while ($i < $limit) {
                $j = 2 * $i + 1;
                $cj = $this->heap[$j];
                if ($j + 1 !== $n) {
                    $r = $this->heap[$j + 1];
                    if ($max ? ($r <=> $cj) > 0 : ($cj <=> $r) > 0) { $j = $j + 1; $cj = $r; }
                } else {
                    if ($max ? ($bottom <=> $cj) > 0 : ($cj <=> $bottom) > 0) { $j = $j + 1; $cj = $bottom; }
                }
                if ($max ? ($bottom <=> $cj) >= 0 : ($cj <=> $bottom) >= 0) { break; }
                $this->heap[$i] = $cj;
                $i = $j;
            }
            $this->heap[$i] = $bottom;
            return $top;
        }
        while ($i < $limit) {
            $j = 2 * $i + 1;
            if ($j !== $n && $this->__mcCmp($this->__mcAt($j + 1, $n, $bottom), $this->heap[$j], $err) > 0) { $j = $j + 1; }
            if ($this->__mcCmp($bottom, $this->__mcAt($j, $n, $bottom), $err) >= 0) { break; }
            $this->heap[$i] = $this->__mcAt($j, $n, $bottom);
            $i = $j;
        }
        $this->heap[$i] = $bottom;
        return $top;
    }

    /** Element `$j` as Zend sees it mid-removal: slot `$n` still holds the old last element. */
    private function __mcAt(int $j, int $n, mixed $bottom): mixed
    {
        return $j === $n ? $bottom : $this->heap[$j];
    }
}

class SplMinHeap extends SplHeap
{
    protected function compare(mixed $value1, mixed $value2): int { return $value2 <=> $value1; }
}

class SplMaxHeap extends SplHeap
{
    protected function compare(mixed $value1, mixed $value2): int { return $value1 <=> $value2; }
}

/**
 * A heap of data/priority pairs ranked by `compare()` over the priorities. The two
 * halves live in parallel arrays (a pair array per element cost an allocation and
 * two string-key lookups per comparison); the pair is built only for
 * EXTR_BOTH and serialization. SplPriorityQueue itself takes the path with no
 * virtual call and no try/catch per comparison.
 */
class SplPriorityQueue implements Iterator, Countable
{
    public const EXTR_BOTH = 3;
    public const EXTR_PRIORITY = 2;
    public const EXTR_DATA = 1;

    private int $flags = 1;
    private bool $isCorrupted = false;
    /** @var array<int, mixed> */
    private array $hd = [];
    /** @var array<int, mixed> */
    private array $hp = [];
    public function compare(mixed $priority1, mixed $priority2): int { return $priority1 <=> $priority2; }

    /** SplPriorityQueue itself (no user compare()); no property: it would show in var_dump */
    private function __mcPlain(): bool
    {
        return \get_class($this) === 'SplPriorityQueue';
    }

    /** @return array<string, mixed> php's debug view: the pairs rebuilt, private names mangled */
    public function __debugInfo(): array
    {
        $els = [];
        $n = \count($this->hp);
        for ($i = 0; $i < $n; $i = $i + 1) { $els[] = ['data' => $this->hd[$i], 'priority' => $this->hp[$i]]; }
        return ["\0SplPriorityQueue\0flags" => $this->flags, "\0SplPriorityQueue\0isCorrupted" => $this->isCorrupted, "\0SplPriorityQueue\0heap" => $els];
    }

    public function insert(mixed $value, mixed $priority): true
    {
        if ($this->isCorrupted) { throw new RuntimeException('Heap is corrupted, heap properties are no longer ensured.'); }
        $i = \count($this->hp);
        $this->hd[] = $value;
        $this->hp[] = $priority;
        if ($this->__mcPlain()) {
            while ($i > 0) {
                $p = ($i - 1) >> 1;
                $pp = $this->hp[$p];
                if (($pp <=> $priority) >= 0) { break; }
                $this->hd[$i] = $this->hd[$p];
                $this->hp[$i] = $pp;
                $i = $p;
            }
            $this->hd[$i] = $value;
            $this->hp[$i] = $priority;
            return true;
        }
        $err = null;
        while ($i > 0) {
            $p = \intdiv($i - 1, 2);
            if ($this->__mcCmp($this->hp[$p], $priority, $err) >= 0) { break; }
            $this->hd[$i] = $this->hd[$p];
            $this->hp[$i] = $this->hp[$p];
            $i = $p;
        }
        $this->hd[$i] = $value;
        $this->hp[$i] = $priority;
        if ($err !== null) { $this->isCorrupted = true; throw $err; }
        return true;
    }

    public function setExtractFlags(int $flags): int
    {
        if (($flags & 3) === 0) { throw new RuntimeException('Must specify at least one extract flag'); }
        $this->flags = $flags & 3;
        return $this->flags;
    }

    public function getExtractFlags(): int { return $this->flags; }

    public function extract(): mixed
    {
        if ($this->isCorrupted) { throw new RuntimeException('Heap is corrupted, heap properties are no longer ensured.'); }
        if ($this->hp === []) { throw new RuntimeException("Can't extract from an empty heap"); }
        $err = null;
        $topD = $this->hd[0];
        $topP = $this->hp[0];
        $this->__mcDeleteTop($err);
        if ($err !== null) { $this->isCorrupted = true; throw $err; }
        return $this->__mcView($topD, $topP);
    }

    public function top(): mixed
    {
        if ($this->isCorrupted) { throw new RuntimeException('Heap is corrupted, heap properties are no longer ensured.'); }
        if ($this->hp === []) { throw new RuntimeException("Can't peek at an empty heap"); }
        return $this->__mcView($this->hd[0], $this->hp[0]);
    }

    public function isEmpty(): bool { return $this->hp === []; }

    public function count(): int { return \count($this->hp); }

    public function rewind(): void {}

    public function valid(): bool { return $this->hp !== []; }

    public function current(): mixed { return $this->hp === [] ? null : $this->__mcView($this->hd[0], $this->hp[0]); }

    public function key(): int { return \count($this->hp) - 1; }

    public function next(): void
    {
        if ($this->isCorrupted) { throw new RuntimeException('Heap is corrupted, heap properties are no longer ensured.'); }
        if ($this->hp === []) { return; }
        $err = null;
        $this->__mcDeleteTop($err);
        if ($err !== null) { $this->isCorrupted = true; throw $err; }
    }

    public function recoverFromCorruption(): true
    {
        $this->isCorrupted = false;
        return true;
    }

    public function isCorrupted(): bool { return $this->isCorrupted; }

    /** @return array<int, mixed> */
    public function __serialize(): array
    {
        $els = [];
        $n = \count($this->hp);
        for ($i = 0; $i < $n; $i = $i + 1) { $els[] = ['data' => $this->hd[$i], 'priority' => $this->hp[$i]]; }
        return [[], ['flags' => $this->flags, 'heap_elements' => $els]];
    }

    /** @param array<int, mixed> $data */
    public function __unserialize(array $data): void
    {
        $this->flags = (int)$data[1]['flags'];
        $this->hd = [];
        $this->hp = [];
        foreach ($data[1]['heap_elements'] as $el) {
            $this->hd[] = $el['data'];
            $this->hp[] = $el['priority'];
        }
    }

    private function __mcView(mixed $data, mixed $priority): mixed
    {
        if ($this->flags === 1) { return $data; }
        if ($this->flags === 2) { return $priority; }
        return ['data' => $data, 'priority' => $priority];
    }

    private function __mcCmp(mixed $a, mixed $b, ?Throwable &$err): int
    {
        if ($err !== null) { return 0; }
        try {
            return $this->compare($a, $b);
        } catch (Throwable $e) {
            $err = $e;
            return 0;
        }
    }

    /** Removes the root; the caller has already read it. */
    private function __mcDeleteTop(?Throwable &$err): void
    {
        $bottomD = \array_pop($this->hd);
        $bottomP = \array_pop($this->hp);
        $n = \count($this->hp);
        if ($n === 0) { return; }
        $limit = $n >> 1;
        $i = 0;
        $plain = $this->__mcPlain();
        while ($i < $limit) {
            $j = 2 * $i + 1;
            if ($j !== $n) {
                $r = $j + 1 === $n ? $bottomP : $this->hp[$j + 1];
                if ($plain ? ($r <=> $this->hp[$j]) > 0 : $this->__mcCmp($r, $this->hp[$j], $err) > 0) { $j = $j + 1; }
            }
            $cp = $j === $n ? $bottomP : $this->hp[$j];
            if ($plain ? ($bottomP <=> $cp) >= 0 : $this->__mcCmp($bottomP, $cp, $err) >= 0) { break; }
            $this->hd[$i] = $j === $n ? $bottomD : $this->hd[$j];
            $this->hp[$i] = $cp;
            $i = $j;
        }
        $this->hd[$i] = $bottomD;
        $this->hp[$i] = $bottomP;
    }
}
