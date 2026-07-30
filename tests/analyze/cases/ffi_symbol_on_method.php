<?php

// An FFI binding must be a FREE FUNCTION. The attributes used to declare
// TARGET_METHOD, which made class-based binding look supported when nothing
// lowered it — only the free-function path in LowerFns consumes #[Symbol].
//
// Wiring it was the alternative, and it does not survive contact with the rest
// of the compiler: a non-static method's MIR function carries a receiver
// parameter with no C counterpart, so only statics could ever bind — and a
// static method binding is a namespaced free function with worse ergonomics.
// Worse, Sig::emitModule exports only $module->functions, so a class-based
// binding could never be imported across a .o boundary, which is precisely the
// property that makes Runtime\Libc\* usable at all.
//
// Zend now rejects these at ReflectionAttribute::newInstance(); the compiler
// rejects them here, at the declaration.

#[\Ffi\Library('c')]
class BoundOnClass
{
    #[\Ffi\Library('c'), \Ffi\Symbol('getpid')]
    public function instanceBinding(): int { return 0; }

    #[\Ffi\Symbol('getppid')]
    public static function staticBinding(): int { return 0; }

    #[\Ffi\CType('int')]
    public function ctypeOnMethod(): int { return 0; }
}

// A free function is the supported shape.
#[\Ffi\Library('c'), \Ffi\Symbol('getpid'), \Ffi\CType('int')]
function ok_free_function(): int { return 0; }

echo "unreachable\n";
