<?php
// spl_autoload_register and friends.
// DEMAND-GATED (Main.php): only a program that calls one of these carries it.
//
// Autoloaders are CALLABLES, which is why this lives in the prelude and not in
// src/Runtime/Stdlib: a callable cannot cross the stdlib.o boundary.
//
// ── What an autoloader can and cannot do here ────────────────────────────────
//
// Ahead-of-time, every class the program can name is already compiled in:
// composer's PSR-4 map is resolved at BUILD time into a source glob
// ({@see \Manticore\composer_source_dirs}), and `require` lowers away. So a
// registered autoloader can never bring a new class into being — nothing it
// does at runtime can define one.
//
// That is not a reason to make these no-ops. Every composer application starts
// with `ClassLoader::register()`, which calls spl_autoload_register(); with the
// function missing that was a hard compile error under `manticore compile`, and
// a link-stub returning 0 under a manifest build. Registering the callback and
// reporting the queue honestly turns the bootstrap into something that runs.
//
// The queue is only ever DRAINED for a class that does not exist, which is the
// one case where php consults it too — and where the answer is false either
// way. The observable difference is the callback's side effects, which is what
// a `class_exists($n, true)` guard in the DI container is really after.

class __McAutoload
{
    /** @var array<int,mixed> registered autoloaders, in the order they run */
    public static array $fns = [];
}

/**
 * Queue an autoloader. `$prepend` puts it first, as php does.
 *
 * php's `$callback = null` means "register the default spl_autoload"; there is
 * no such default here, so it is accepted and does nothing — matching the
 * observable behaviour, since that loader could not define a class either.
 */
function spl_autoload_register(mixed $callback = null, bool $throw = true, bool $prepend = false): bool
{
    if ($callback === null) {
        return true;
    }
    if ($prepend) {
        /** @var array<int,mixed> $out */
        $out = [];
        $out[] = $callback;
        foreach (__McAutoload::$fns as $f) {
            $out[] = $f;
        }
        __McAutoload::$fns = $out;
        return true;
    }
    __McAutoload::$fns[] = $callback;
    return true;
}

/** Remove one autoloader. Identity comparison, as php's does. */
function spl_autoload_unregister(mixed $callback): bool
{
    /** @var array<int,mixed> $out */
    $out = [];
    $removed = false;
    foreach (__McAutoload::$fns as $f) {
        if (!$removed && $f === $callback) {
            $removed = true;
            continue;
        }
        $out[] = $f;
    }
    __McAutoload::$fns = $out;
    return true;
}

/** The queue, in call order. php returns false when nothing is registered. */
function spl_autoload_functions(): array
{
    return __McAutoload::$fns;
}

/**
 * Run the queue for one class name — the hook a `class_exists($n, true)` on an
 * unknown class calls. Stops at the first loader that makes the class appear,
 * which ahead-of-time is never, so in practice every loader runs once.
 */
function __mc_autoload_call(string $class): void
{
    foreach (__McAutoload::$fns as $cb) {
        if ($cb === null) {
            continue;
        }
        if (\is_array($cb)) {
            $o = $cb[0];
            $m = $cb[1];
            $o->$m($class);
            continue;
        }
        $cb($class);
    }
}

/** The default loader's extension list, as php's spl_autoload_extensions() holds it. */
class __McAutoloadExt
{
    public static string $ext = '.inc,.php';
}

function spl_autoload_extensions(?string $file_extensions = null): string
{
    if ($file_extensions !== null) {
        __McAutoloadExt::$ext = $file_extensions;
    }
    return __McAutoloadExt::$ext;
}

/**
 * php's default loader looks `strtolower($class)` up on the include path and
 * `require`s it. Nothing can be defined at run time here (see the header), so
 * there is never a file to load: the call is a faithful no-op.
 */
function spl_autoload(string $class, ?string $file_extensions = null): void
{
}

/** Run the registered loaders for `$class`, as `class_exists($class, true)` does. */
function spl_autoload_call(string $class): void
{
    __mc_autoload_call($class);
}

/** @return array<string,string> */
function spl_classes(): array
{
    $out = [];
    foreach (['AppendIterator', 'ArrayIterator', 'ArrayObject', 'BadFunctionCallException', 'BadMethodCallException',
        'CachingIterator', 'CallbackFilterIterator', 'DirectoryIterator', 'DomainException', 'EmptyIterator',
        'FilesystemIterator', 'FilterIterator', 'GlobIterator', 'InfiniteIterator', 'InvalidArgumentException',
        'IteratorIterator', 'LengthException', 'LimitIterator', 'LogicException', 'MultipleIterator',
        'NoRewindIterator', 'OuterIterator', 'OutOfBoundsException', 'OutOfRangeException', 'OverflowException',
        'ParentIterator', 'RangeException', 'RecursiveArrayIterator', 'RecursiveCachingIterator',
        'RecursiveCallbackFilterIterator', 'RecursiveDirectoryIterator', 'RecursiveFilterIterator',
        'RecursiveIterator', 'RecursiveIteratorIterator', 'RecursiveRegexIterator', 'RecursiveTreeIterator',
        'RegexIterator', 'RuntimeException', 'SeekableIterator', 'SplDoublyLinkedList', 'SplFileInfo',
        'SplFileObject', 'SplFixedArray', 'SplHeap', 'SplMinHeap', 'SplMaxHeap', 'SplObjectStorage',
        'SplObserver', 'SplPriorityQueue', 'SplQueue', 'SplStack', 'SplSubject', 'SplTempFileObject',
        'UnderflowException', 'UnexpectedValueException'] as $n) {
        $out[$n] = $n;
    }
    return $out;
}
