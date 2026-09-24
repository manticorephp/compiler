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

interface OuterIterator extends Iterator
{
    public function getInnerIterator(): ?Iterator;
}

interface RecursiveIterator extends Iterator
{
    public function hasChildren(): bool;

    public function getChildren(): ?RecursiveIterator;
}

interface SeekableIterator extends Iterator
{
    public function seek(int $offset): void;
}

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
        while (($e = \readdir($h)) !== false) { $this->__entries[] = $e; }
        \closedir($h);
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

class SplFixedArray implements IteratorAggregate, ArrayAccess, Countable
{
    /** @var array<int, mixed> */
    private array $__data = [];
    private int $__size = 0;

    public function __construct(int $size = 0)
    {
        if ($size < 0) {
            throw new ValueError('SplFixedArray::__construct(): Argument #1 ($size) must be greater than or equal to 0');
        }
        $this->__size = $size;
        for ($i = 0; $i < $size; $i++) { $this->__data[] = null; }
    }

    public function count(): int
    {
        return $this->__size;
    }

    public function getSize(): int
    {
        return $this->__size;
    }

    public function setSize(int $size): bool
    {
        if ($size < 0) {
            throw new ValueError('SplFixedArray::setSize(): Argument #1 ($size) must be greater than or equal to 0');
        }
        if ($size < $this->__size) {
            $this->__data = \array_slice($this->__data, 0, $size);
        } else {
            for ($i = $this->__size; $i < $size; $i++) { $this->__data[] = null; }
        }
        $this->__size = $size;
        return true;
    }

    /** @return array<int, mixed> */
    public function toArray(): array
    {
        return $this->__data;
    }

    public static function fromArray(array $array, bool $preserveKeys = true): SplFixedArray
    {
        if ($array === []) { return new SplFixedArray(0); }
        if ($preserveKeys) {
            $max = -1;
            foreach ($array as $k => $_) {
                if (!\is_int($k) || $k < 0) {
                    throw new ValueError('array must contain only positive integer keys');
                }
                if ($k > $max) { $max = $k; }
            }
            $out = new SplFixedArray($max + 1);
            foreach ($array as $k => $v) { $out->__data[$k] = $v; }
            return $out;
        }
        $out = new SplFixedArray(\count($array));
        $i = 0;
        foreach ($array as $v) { $out->__data[$i] = $v; $i = $i + 1; }
        return $out;
    }

    public function offsetExists(mixed $index): bool
    {
        $i = $this->__index($index, false);
        return $i >= 0 && $this->__data[$i] !== null;
    }

    public function offsetGet(mixed $index): mixed
    {
        return $this->__data[$this->__index($index, true)];
    }

    public function offsetSet(mixed $index, mixed $value): void
    {
        if ($index === null) {
            throw new RuntimeException('[] operator not supported for SplFixedArray');
        }
        $this->__data[$this->__index($index, true)] = $value;
    }

    public function offsetUnset(mixed $index): void
    {
        $this->__data[$this->__index($index, true)] = null;
    }

    public function getIterator(): Iterator
    {
        return new __McFixedArrayIterator($this);
    }

    /** @return array<int, mixed> */
    public function __serialize(): array
    {
        return $this->__data;
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
            if (!$strict && \is_string($index)) { return -1; }
            throw new TypeError('Cannot access offset of type ' . \get_debug_type($index) . ' on SplFixedArray');
        }
        if ($i < 0 || $i >= $this->__size) {
            if (!$strict) { return -1; }
            throw new RuntimeException('Index invalid or out of range');
        }
        return $i;
    }
}

/** SplFixedArray::getIterator() — reads the array live, like php's. */
final class __McFixedArrayIterator implements Iterator
{
    private int $__pos = 0;

    public function __construct(private SplFixedArray $array) {}

    public function current(): mixed { return $this->array[$this->__pos]; }
    public function key(): mixed { return $this->__pos; }
    public function next(): void { $this->__pos = $this->__pos + 1; }
    public function rewind(): void { $this->__pos = 0; }
    public function valid(): bool { return $this->__pos < $this->array->getSize(); }
}

class SplDoublyLinkedList implements Iterator, Countable, ArrayAccess
{
    public const IT_MODE_LIFO = 2;
    public const IT_MODE_FIFO = 0;
    public const IT_MODE_DELETE = 1;
    public const IT_MODE_KEEP = 0;

    /** @var array<int, mixed> */
    private array $__items = [];
    private int $__mode = 0;
    private int $__pos = 0;

    public function push(mixed $value): void { $this->__items[] = $value; }

    public function unshift(mixed $value): void { \array_unshift($this->__items, $value); }

    public function pop(): mixed
    {
        if ($this->__items === []) { throw new RuntimeException("Can't pop from an empty datastructure"); }
        return \array_pop($this->__items);
    }

    public function shift(): mixed
    {
        if ($this->__items === []) { throw new RuntimeException("Can't shift from an empty datastructure"); }
        return \array_shift($this->__items);
    }

    public function top(): mixed
    {
        if ($this->__items === []) { throw new RuntimeException("Can't peek at an empty datastructure"); }
        return $this->__items[\count($this->__items) - 1];
    }

    public function bottom(): mixed
    {
        if ($this->__items === []) { throw new RuntimeException("Can't peek at an empty datastructure"); }
        return $this->__items[0];
    }

    public function isEmpty(): bool { return $this->__items === []; }

    public function count(): int { return \count($this->__items); }

    /** @return array<int, mixed> */
    public function toArray(): array { return $this->__items; }

    public function setIteratorMode(int $mode): int
    {
        $this->__mode = $mode;
        return $mode;
    }

    public function getIteratorMode(): int { return $this->__mode; }

    public function offsetExists(mixed $index): bool
    {
        return \is_numeric($index) && (int)$index >= 0 && (int)$index < \count($this->__items);
    }

    public function offsetGet(mixed $index): mixed
    {
        if (!$this->offsetExists($index)) { throw new OutOfRangeException('SplDoublyLinkedList::offsetGet(): Argument #1 ($index) is out of range'); }
        return $this->__items[(int)$index];
    }

    public function offsetSet(mixed $index, mixed $value): void
    {
        if ($index === null) { $this->__items[] = $value; return; }
        if (!$this->offsetExists($index)) { throw new OutOfRangeException('SplDoublyLinkedList::offsetSet(): Argument #1 ($index) is out of range'); }
        $this->__items[(int)$index] = $value;
    }

    public function offsetUnset(mixed $index): void
    {
        if (!$this->offsetExists($index)) { throw new OutOfRangeException('SplDoublyLinkedList::offsetUnset(): Argument #1 ($index) is out of range'); }
        \array_splice($this->__items, (int)$index, 1);
    }

    public function rewind(): void
    {
        $this->__pos = ($this->__mode & self::IT_MODE_LIFO) !== 0 ? \count($this->__items) - 1 : 0;
    }

    public function valid(): bool
    {
        return $this->__pos >= 0 && $this->__pos < \count($this->__items);
    }

    public function current(): mixed
    {
        return $this->valid() ? $this->__items[$this->__pos] : null;
    }

    public function key(): mixed
    {
        return $this->__pos;
    }

    public function next(): void
    {
        $lifo = ($this->__mode & self::IT_MODE_LIFO) !== 0;
        if (($this->__mode & self::IT_MODE_DELETE) !== 0) {
            if ($lifo) {
                \array_pop($this->__items);
                $this->__pos = \count($this->__items) - 1;
            } else {
                \array_shift($this->__items);
            }
            return;
        }
        $this->__pos = $lifo ? $this->__pos - 1 : $this->__pos + 1;
    }

    public function prev(): void
    {
        $lifo = ($this->__mode & self::IT_MODE_LIFO) !== 0;
        $this->__pos = $lifo ? $this->__pos + 1 : $this->__pos - 1;
    }
}

class SplQueue extends SplDoublyLinkedList
{
    public function enqueue(mixed $value): void { $this->push($value); }

    public function dequeue(): mixed { return $this->shift(); }
}

class SplStack extends SplDoublyLinkedList
{
    public function __construct()
    {
        $this->setIteratorMode(self::IT_MODE_LIFO);
    }
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
