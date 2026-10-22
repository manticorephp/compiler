<?php

namespace Compile\Mir\Passes;

use Compile\Mir\Call;
use Compile\Mir\FunctionDef;
use Compile\Mir\MethodCall_;
use Compile\Mir\Module;
use Compile\Mir\NewObj;
use Compile\Mir\Node;
use Compile\Mir\Return_;
use Compile\Mir\StaticCall_;
use Compile\Mir\Type;
use Compile\Mir\Walk;

/**
 * Compile-time type checker, ON by default (`MANTICORE_TYPECHECK=0` turns it
 * off; `reprOnly` is what the flag actually drives). Reports
 * GENUINELY-incompatible type uses — the kind PHP rejects even with
 * strict_types off — at two boundaries:
 *   - a call argument vs the callee parameter type, and
 *   - a `return` value vs the declared return type.
 *
 * Conservative on purpose (no false positives on a loosely-typed corpus or the
 * self-host build): a check fires only when BOTH sides are a concrete kind
 * (int/float/string/bool/array/obj) and they disagree on array-ness or
 * object-ness. Anything `unknown` / `cell` (mixed) / `null` / `void` / closure
 * on either side is skipped, as is obj↔obj (subtyping not modelled) and
 * scalar↔scalar (PHP coerces). Off by default — the driver runs it only when
 * the env flag is set and decides fatality from {@see $errors}.
 */
final class TypeCheck
{
    /** @var string[] collected, human-readable type errors */
    public array $errors = [];

    /** @var string[] findings that do not stop the build */
    public array $warnings = [];

    /**
     * Turn a statically-provable array-ness argument mismatch into a RUN-TIME
     * `TypeError` at the call site instead of a build error. Set by the driver
     * for a user program; `analyze` (collect mode) leaves it off, so the
     * compiler's own tree still reports every such site as an error.
     */
    public bool $demoteArgMismatch = false;

    /** A warning {@see shapeConflict} found while answering, taken by its caller. */
    private string $shapeWarn = '';

    /**
     * Run ONLY the array-representation conflict check (see
     * {@see arrayReprConflict}) and skip every other rule.
     *
     * That check is exempt from the "off by default" reasoning that gates the
     * rest of this pass: it mirrors the codegen's own key-reader choice, so a
     * hit is not a style opinion but a buffer read at the wrong type — a silent
     * SIGSEGV. The driver runs the pass in this mode UNCONDITIONALLY and treats
     * a hit as fatal; the self-host source and all 573 AOT cases are clean.
     */
    public bool $reprOnly = false;

    /**
     * Run ONLY the weak-mode `__toString` cast of an object bound to a `string`
     * param (set together with {@see $reprOnly}). The driver runs it again after
     * NarrowReturns: that pass sharpens a foreach variable over a call result from
     * erased to `obj`, after the full check already looked at the argument.
     */
    public bool $castOnly = false;

    /** @var array<string, \Compile\Mir\Param[]> fn / `Class__method` name → params */
    private array $paramsByFn = [];

    /** @var array<string, bool> reified bodies: their params are a docblock CLAIM, the cell stays a cell */
    private array $claimFns = [];

    /** @var array<string, bool> FFI-bound callees: their `string` param takes a raw address, never a rendered object */
    private array $ffiFns = [];

    /** @var array<string, Type> fn name → declared return type */
    private array $returnTypes = [];

    public function run(Module $module): Module
    {
        foreach ($module->functions as $fn) {
            $this->paramsByFn[$fn->name] = $fn->params;
            if ($fn->ffiSymbol !== null) { $this->ffiFns[$fn->name] = true; }
            if ($fn->claimOrigin !== '') { $this->claimFns[$fn->name] = true; }
            $this->returnTypes[$fn->name] = $module->declaredReturnTypes[$fn->name] ?? $fn->returnType;
        }
        foreach ($module->functions as $fn) {
            if ($fn->isExtern) { continue; }
            if (!$this->reprOnly) { $this->checkReturns($fn); }
            $this->checkNode($fn->body, $fn->name);
        }
        return $module;
    }

    private function checkNode(Node $n, string $inFn): void
    {
        // $recv = 1 when the receiver (`this`) is IMPLICIT (not in args) so arg
        // index i maps to param i+1. A free function has no receiver; a static
        // call (incl. `parent::__construct`) already passes `this` explicitly in
        // args, so both align at offset 0.
        if ($n->kind === Node::KIND_ADD || $n->kind === Node::KIND_SUB || $n->kind === Node::KIND_MUL
            || $n->kind === Node::KIND_DIV || $n->kind === Node::KIND_MOD) {
            if (!$this->reprOnly) { $this->checkArith($n, $inFn); }
        }
        if ($n->kind === Node::KIND_CALL) {
            $this->nullArgsToScalar($n->function, $n, 0);
            $this->checkArgs($n->function, $n->function, $n->args, $inFn, 0, $n);
        } elseif ($n->kind === Node::KIND_NEW_OBJ) {
            $this->nullArgsToScalar($n->class . '____construct', $n, 1);
            $this->checkArgs($n->class . '____construct', 'new ' . $n->class, $n->args, $inFn, 1, $n);
        } elseif ($n->kind === Node::KIND_STATIC_CALL) {
            $this->nullArgsToScalar($n->class . '__' . $n->method, $n, 0);
            $this->checkArgs($n->class . '__' . $n->method, $n->class . '::' . $n->method, $n->args, $inFn, 0, $n);
        } elseif ($n->kind === Node::KIND_METHOD_CALL) {
            $cls = $n->object->type->class ?? '';
            if ($cls !== '') {
                $this->nullArgsToScalar($cls . '__' . $n->method, $n, 1);
                $this->checkArgs($cls . '__' . $n->method, $cls . '->' . $n->method, $n->args, $inFn, 1, $n);
            }
        }
        $this->checkShapeNode($n, $inFn);
        // An arm its own guard rules out never runs: `is_array($v) ? f($v) : g($v)`
        // over a `string` $v. Checking it reported the dead arm's argument as the
        // program's error.
        if ($n instanceof \Compile\Mir\Ternary) {
            $this->checkGuarded($n->cond, $n->then, $n->else_, $inFn);
            return;
        }
        if ($n instanceof \Compile\Mir\If_) {
            $this->checkGuarded($n->cond, $n->then, $n->else, $inFn);
            return;
        }
        foreach (Walk::children($n) as $c) { $this->checkNode($c, $inFn); }
    }

    /**
     * A literal `null` handed to a non-nullable int/float/bool parameter is
     * php's TypeError. The argument becomes a throwing expression, so the call
     * only fails when it is reached, as in php. (A nullable parameter is a
     * cell, and a default of null makes the hint implicitly nullable.)
     */
    private function nullArgsToScalar(string $fnName, Node $call, int $recv): void
    {
        $params = $this->paramsByFn[$fnName] ?? null;
        if ($params === null) { return; }
        $offset = ($recv === 1 && \count($params) > 0 && $params[0]->name === 'this') ? 1 : 0;
        foreach ($call->args as $ai => $arg) {
            if ($arg->kind === Node::KIND_SPREAD) { return; }
            $p = $params[$ai + $offset] ?? null;
            if ($p === null || $p->variadic || $p->byRef || $p->default !== null) { continue; }
            if ($arg->type->kind !== Type::KIND_NULL) { continue; }
            $k = $p->type->kind;
            if ($k !== Type::KIND_INT && $k !== Type::KIND_FLOAT && $k !== Type::KIND_BOOL) { continue; }
            $call->args[$ai] = new Call('__mir_throw_type_error', [
                new \Compile\Mir\StringConst($fnName . '(): Argument #' . (string)($ai + 1) . ' ($' . $p->name
                    . ') must be of type ' . $p->type->toString() . ', null given', Type::string_()),
            ], $p->type);
        }
    }

    private function checkGuarded(Node $cond, ?Node $then, ?Node $else, string $inFn): void
    {
        $truth = $this->staticTruth($cond);
        $this->checkNode($cond, $inFn);
        if ($then !== null && $truth !== 0) { $this->checkNode($then, $inFn); }
        if ($else !== null && $truth !== 1) { $this->checkNode($else, $inFn); }
    }

    /**
     * 1 / 0 when a type guard's answer follows from its operand's CONCRETE static
     * type (`is_array` of a string is 0), -1 when it does not.
     */
    private function staticTruth(Node $cond): int
    {
        if ($cond->kind === Node::KIND_NOT) {
            $inner = $cond instanceof \Compile\Mir\Not_ ? $this->staticTruth($cond->operand) : -1;
            return $inner < 0 ? -1 : 1 - $inner;
        }
        if (!($cond instanceof Call) || \count($cond->args) !== 1) { return -1; }
        $fn = \strtolower(\ltrim($cond->function, '\\'));
        $bs = \strrpos($fn, '\\');
        if ($bs !== false) { $fn = \substr($fn, $bs + 1); }
        $t = $cond->args[0]->type;
        if (!$this->concrete($t) || $t->kind === Type::KIND_NULL) { return -1; }
        $k = $t->kind;
        if ($fn === 'is_array')  { return $k === Type::KIND_ARRAY ? 1 : 0; }
        if ($fn === 'is_string') { return $k === Type::KIND_STRING ? 1 : 0; }
        if ($fn === 'is_int')    { return $k === Type::KIND_INT ? 1 : 0; }
        if ($fn === 'is_float')  { return $k === Type::KIND_FLOAT ? 1 : 0; }
        if ($fn === 'is_bool')   { return $k === Type::KIND_BOOL ? 1 : 0; }
        return -1;
    }

    /**
     * Codegen builtins have no declared signature, so the string cast {@see checkArgs} inserts for a
     * `string` parameter never reached them: `strlen($stringable)` counted the object's bytes and
     * `strtoupper($stringable)` upper-cased its pointer. These take their text through `__toString`
     * in php; an object bound to one of their string positions is rendered here. Positions only —
     * `get_class`, `spl_object_id`, `method_exists`, `__mc_refl_of` take the object itself.
     *
     * @var array<string, int[]>
     */
    private const STRING_ARG_BUILTINS = [
        'strlen' => [0], 'strtolower' => [0], 'strtoupper' => [0], 'ucfirst' => [0], 'lcfirst' => [0],
        'ucwords' => [0], 'trim' => [0], 'ltrim' => [0], 'rtrim' => [0], 'chop' => [0], 'strrev' => [0],
        'str_repeat' => [0], 'substr' => [0], 'nl2br' => [0], 'addslashes' => [0], 'md5' => [0],
        'sha1' => [0], 'crc32' => [0], 'bin2hex' => [0], 'base64_encode' => [0], 'urlencode' => [0],
        'rawurlencode' => [0], 'htmlspecialchars' => [0], 'htmlentities' => [0], 'strip_tags' => [0],
        'wordwrap' => [0], 'str_word_count' => [0], 'ctype_alpha' => [0], 'is_numeric' => [],
        'strpos' => [0, 1], 'stripos' => [0, 1], 'strrpos' => [0, 1], 'strripos' => [0, 1],
        'strstr' => [0, 1], 'stristr' => [0, 1], 'strrchr' => [0, 1], 'str_contains' => [0, 1],
        'str_starts_with' => [0, 1], 'str_ends_with' => [0, 1], 'strcmp' => [0, 1], 'strcasecmp' => [0, 1],
        'strncmp' => [0, 1], 'strncasecmp' => [0, 1], 'strnatcmp' => [0, 1], 'strnatcasecmp' => [0, 1],
        'substr_count' => [0, 1], 'strspn' => [0, 1], 'strcspn' => [0, 1], 'similar_text' => [0, 1],
        'levenshtein' => [0, 1], 'str_pad' => [0, 2], 'explode' => [0, 1],
    ];

    private function renderStringableBuiltinArgs(string $fnName, Node $site): void
    {
        $key = \strtolower(\ltrim($fnName, '\\'));
        $pos = self::STRING_ARG_BUILTINS[$key] ?? null;
        if ($pos === null || isset($this->ffiFns[$fnName])) { return; }
        foreach ($pos as $ai) {
            if (!isset($site->args[$ai])) { continue; }
            $arg = $site->args[$ai];
            if ($arg->kind === Node::KIND_SPREAD || $arg->type->kind !== Type::KIND_OBJ) { continue; }
            $cast = new \Compile\Mir\Cast('string', $arg, Type::string_());
            $cast->line = $arg->line;
            $site->args[$ai] = $cast;
        }
    }
    /**
     * @param Node[] $args
     *
     * Map each positional arg to its declared param. An implicit leading
     * `this` param (instance methods / ctors) is skipped via an offset so the
     * arg/param indices line up. An unresolved callee (builtin, dynamic,
     * inherited method on a parent class) is skipped — no callee, no check.
     */
    private function checkArgs(string $fnName, string $label, array $args, string $inFn, int $recv, Node $site): void
    {
        $params = $this->paramsByFn[$fnName] ?? null;
        if ($params === null) { $this->renderStringableBuiltinArgs($fnName, $site); return; }
        // A Monomorphize clone is named `first$mono$p0_vec_cell`; the message names the source function.
        $m = \strpos($label, '$mono$');
        if ($m !== false) { $label = \substr($label, 0, $m); }
        // Apply the implicit-receiver offset only when the callee actually has a
        // leading `this` param (guards a malformed resolution).
        $offset = 0;
        if ($recv === 1 && \count($params) > 0 && $params[0]->name === 'this') { $offset = 1; }
        foreach ($args as $ai => $arg) {
            // `f(...$arr)` is ONE argument node supplying N of them, so from here
            // on the positional mapping is a run-time fact: the array's length
            // decides which param each element lands in, and an assoc spread
            // names them. Checking the ARRAY against the next param's type read
            // `join2(...["hi", "yo"])` as "vec[string] given, string expected"
            // and would have failed three green cases the moment this checker
            // became fatal. Stop, exactly as a variadic param does.
            if ($arg->kind === Node::KIND_SPREAD) { break; }
            $pi = $ai + $offset;
            if (!isset($params[$pi])) { break; }
            $p = $params[$pi];
            if ($p->variadic) { break; }
            // Coercive mode: an object bound to a `string` param is rendered by its
            // `__toString`, a scalar by its text. Left alone, the callee read the
            // object POINTER, or the raw int (`$this->escape(404)`), as string bytes.
            $ak = $arg->type->kind;
            if ($p->type->kind === Type::KIND_STRING && !$p->byRef
                && ($ak === Type::KIND_OBJ || $ak === Type::KIND_CELL || $ak === Type::KIND_INT
                    || $ak === Type::KIND_FLOAT || $ak === Type::KIND_BOOL)
                && !isset($this->ffiFns[$fnName]) && !isset($this->claimFns[$fnName])
                && $site->args[$ai] === $arg) {
                $cast = new \Compile\Mir\Cast('string', $arg, Type::string_());
                $cast->keepNull = $arg->type->kind === Type::KIND_CELL;
                $cast->line = $arg->line;
                $site->args[$ai] = $cast;
                continue;
            }
            // Coercive mode: a float bound to an `int` param is converted at the
            // call (an integral float is the int, a fractional one truncates).
            // Left alone, the callee read the double's IEEE bits as the int.
            if ($p->type->kind === Type::KIND_INT && !$p->byRef && $ak === Type::KIND_FLOAT
                && !isset($this->ffiFns[$fnName]) && !isset($this->claimFns[$fnName])
                && $site->args[$ai] === $arg) {
                $cast = new \Compile\Mir\Cast('int', $arg, Type::int_());
                $cast->line = $arg->line;
                $site->args[$ai] = $cast;
                continue;
            }
            if ($this->castOnly) { continue; }
            if (!$this->reprOnly &&$this->incompatible($this->provenType($arg), $p->type)) {
                if ($this->demoteArgMismatch) {
                    $this->demoteToTypeError($site, $arg, $p, $label, $ai, $inFn);
                    return;
                }
                $this->errors[] = $this->at($arg) . $inFn . '(): argument ' . (string)($ai + 1) . ' to '
                    . $label . '() — ' . $arg->type->toString()
                    . ' given, ' . $p->type->toString() . ' expected';
                continue;
            }
            $why = $this->arrayReprConflict($arg->type, $p->type);
            if ($why !== null) {
                $this->errors[] = $this->at($arg) . $inFn . '(): argument ' . (string)($ai + 1) . ' to '
                    . $label . '() — ' . $why . ' (' . $arg->type->toString()
                    . ' given, ' . $p->type->toString() . ' expected)';
            }
            $this->shapeWarn = '';
            $sw = $this->shapeConflict(self::givenShape($arg), $p->type);
            if ($sw !== null) {
                $this->errors[] = $this->at($arg) . $inFn . '(): argument ' . (string)($ai + 1) . ' to '
                    . $label . '() — ' . $sw;
            }
            $this->takeShapeWarn($arg, $inFn . '(): argument ' . (string)($ai + 1) . ' to ' . $label . '() — ');
        }
    }

    /**
     * php checks an argument against the callee's parameter type when the call
     * is MADE, so a mismatch in code that never runs (an inherited `new static`
     * cloned per subclass, a branch behind a feature guard) is not an error
     * until it runs, and then it is a `TypeError`. The call's LAST argument
     * becomes `true ? throw new TypeError(…) : <arg>`: the earlier arguments are
     * evaluated first, as php does, then the throw. Keeps a warning at build time.
     */
    private function demoteToTypeError(Node $site, Node $arg, \Compile\Mir\Param $p, string $label, int $ai, string $inFn): void
    {
        $callee = \str_replace('->', '::', $label);
        if (\str_starts_with($callee, 'new ')) { $callee = \substr($callee, 4) . '::__construct'; }
        $msg = $callee . '(): Argument #' . (string)($ai + 1) . ' ($' . $p->name . ') must be of type '
            . $this->phpTypeName($p->type) . ', ' . $this->phpTypeName($this->provenType($arg)) . ' given';
        $line = $arg->line;
        $str = new \Compile\Mir\StringConst($msg, Type::string_());
        $zero = new \Compile\Mir\IntConst(0, Type::int_());
        $none = new \Compile\Mir\NullConst(Type::null_());
        $err = new NewObj('TypeError', [$str, $zero, $none], Type::obj('TypeError'));
        $throw = new \Compile\Mir\Throw_($err, Type::void());
        $args = $site->args;
        $last = \count($args) - 1;
        $orig = $args[$last];
        $guard = new \Compile\Mir\Ternary(new \Compile\Mir\BoolConst(true, Type::bool_()), $throw, $orig, $orig->type);
        $args[$last] = $guard;
        $site->args = $args;
        foreach ([$str, $zero, $none, $err, $throw, $guard] as $made) { $made->line = $line; }
        $this->warnings[] = ($arg->line > 0 ? 'line ' . (string)$arg->line . ': warning: ' : 'warning: ') . $inFn . '(): ' . $msg . ' — throws TypeError if reached';
    }

    private function phpTypeName(Type $t): string
    {
        $k = $t->kind;
        if ($k === Type::KIND_ARRAY) { return 'array'; }
        if ($k === Type::KIND_INT) { return 'int'; }
        if ($k === Type::KIND_FLOAT) { return 'float'; }
        if ($k === Type::KIND_STRING) { return 'string'; }
        if ($k === Type::KIND_BOOL) { return 'bool'; }
        if ($k === Type::KIND_OBJ && $t->class !== null) { return $t->class; }
        return 'mixed';
    }

    /**
     * Arithmetic `+ - * / %` on a string operand the compiler can PROVE is not
     * numeric — `"abc" + 1`, which php 8 raises a TypeError for.
     *
     * ⚠ NOT every string operand. php coerces a NUMERIC string and so do we:
     * `"2026" + "06"` is 2032 and `$p = explode(",", …); $p[0] + $p[1]` sums,
     * both asserted by the corpus (`string_numeric_arith`, `explode_builtin`).
     * Whether a run-time string is numeric is not a static fact, so the only
     * sound fatal case is a CONSTANT that is provably not — anything else is a
     * style opinion and belongs to `analyze`, which still reports every string
     * operand as a non-fatal finding. This rule read every string operand as an
     * error while the runtime coerced correctly, which is why the checker could
     * not be turned on. The `.` concat operator is the string path, not checked.
     */
    // Add/Sub/Mul/Div/Mod are five distinct classes sharing the (left, right)
    // layout. The param was typed `Add` so the field reads resolve — the native
    // compiler accepts that (identical offsets), but ZEND enforces the
    // declaration and threw `Mul given` the moment this pass ran under the
    // fast loop (`tools/compile_user_mir.php`). Take a `Node` and pull the two
    // operands through {@see Walk::children}, which every arith node answers in
    // (left, right) order — one spelling both hosts agree on.
    private function checkArith(Node $n, string $inFn): void
    {
        $kids = \Compile\Mir\Walk::children($n);
        if (\count($kids) < 2) { return; }
        $left = $kids[0];
        $right = $kids[1];
        if ($this->provablyNonNumericString($left)
            || $this->provablyNonNumericString($right)) {
            $op = $this->arithOp($n->kind);
            $this->errors[] = $this->at($n) . 'arithmetic (`' . $op
                . '`) on a NON-NUMERIC string constant in ' . $inFn
                . '() — php raises a TypeError; cast explicitly ((int)/(float))';
        }
    }

    /** A string CONSTANT whose text php would refuse to compute on: not a
     *  numeric string by php's own rule (leading whitespace allowed, an
     *  optional sign, digits with an optional fraction / exponent). */
    private function provablyNonNumericString(Node $n): bool
    {
        if ($n->kind !== Node::KIND_STRING_CONST) { return false; }
        if ($n->type->kind !== Type::KIND_STRING) { return false; }
        return !\is_numeric(\ltrim($n->value));
    }

    private function arithOp(string $kind): string
    {
        if ($kind === Node::KIND_ADD) { return '+'; }
        if ($kind === Node::KIND_SUB) { return '-'; }
        if ($kind === Node::KIND_MUL) { return '*'; }
        if ($kind === Node::KIND_DIV) { return '/'; }
        return '%';
    }

    /** `line N: error: ` prefix from a node's stamped source line (0 → ''). */
    private function takeShapeWarn(Node $n, string $what): void
    {
        if ($this->shapeWarn === '') { return; }
        $this->warnings[] = ($n->line > 0 ? 'line ' . (string)$n->line . ': warning: ' : 'warning: ')
            . $what . $this->shapeWarn;
        $this->shapeWarn = '';
    }

    private function at(Node $n): string
    {
        return $n->line > 0 ? 'line ' . (string)$n->line . ': error: ' : 'error: ';
    }

    private function checkReturns(FunctionDef $fn): void
    {
        // A generator's `return X` sets the FINAL value (getReturn), not the
        // declared `Generator` return type — never a mismatch.
        if ($fn->isGenerator) { return; }
        $rt = $fn->returnType;
        if (!$this->concrete($rt)) { return; }
        $this->checkReturnNodes($fn->body, $rt, $fn->name);
    }

    private function checkReturnNodes(Node $n, Type $rt, string $fnName): void
    {
        if ($n->kind === Node::KIND_RETURN) {
            if ($n->value !== null && $this->incompatible($this->provenType($n->value), $rt)) {
                $this->errors[] = $this->at($n) . $fnName . '(): return ' . $n->value->type->toString()
                    . ' incompatible with declared ' . $rt->toString();
            }
        }
        foreach (Walk::children($n) as $c) { $this->checkReturnNodes($c, $rt, $fnName); }
    }

    /**
     * The type of `$n` as far as it is a PROOF. A method call on an erased
     * (`cell` / `unknown`) receiver is typed by {@see InferCalls}: when the
     * classes declaring the method agree, the call is given that return type so
     * the caller reads the result at the right width. With ONE declarer that is
     * a dispatch hint, not evidence about the receiver — `$cell->getMessages()`
     * is a `Generator` because some unrelated class declares a generator
     * `getMessages()`, while the receiver is a class of an uninstalled package.
     * Checking the hint against a declared `array` return made an unreachable
     * path a build error.
     */
    private function provenType(Node $n): Type
    {
        if ($n->kind === Node::KIND_METHOD_CALL && $n instanceof MethodCall_) {
            $rk = $n->object->type->kind;
            if ($rk === Type::KIND_CELL || $rk === Type::KIND_UNKNOWN) { return Type::unknown(); }
        }
        return $n->type;
    }

    /**
     * True when both types are concrete and disagree on ARRAY-ness (a compound
     * where a scalar/object is expected, or vice versa) — the reliably-inferred
     * incompatibility. Object↔scalar is intentionally NOT flagged yet: our
     * inference is too imprecise on polymorphic AST field reads (a node's
     * `->value` is int for one subclass, an Expr for another → spurious
     * `int given, obj expected`) and FFI deliberately passes `Ffi\Ptr` as a
     * string. Scalar↔scalar is always allowed (PHP coerces, non-strict).
     */
    private function incompatible(Type $a, Type $b): bool
    {
        if (!$this->concrete($a) || !$this->concrete($b)) { return false; }
        $aArr = $a->kind === Type::KIND_ARRAY;
        $bArr = $b->kind === Type::KIND_ARRAY;
        return $aArr !== $bArr;
    }

    /**
     * Shape rules — unconditional, like {@see arrayReprConflict}: a shape is a
     * repr claim every constant-key read now unboxes by, so a literal or a
     * store that contradicts it is a wrong read, not a style opinion. The
     * key-set rules (an unnamed key, a missing or extra key in a literal)
     * hold a DECLARED shape only ({@see Type::$declared}): a shape inferred
     * from a literal describes that literal, it does not seal the program.
     */
    private function checkShapeNode(Node $n, string $inFn): void
    {
        // A Monomorphize clone shares its generic's docblock shapes and body;
        // the generic stays in the module and its nodes keep their source
        // lines, so the clone would only repeat every finding without one.
        if ($this->castOnly || \str_contains($inFn, '$mono$')) { return; }
        if ($n->kind === Node::KIND_RETURN && $n->value !== null) {
            $rt = $this->returnTypes[$inFn] ?? null;
            if ($rt !== null && $rt->hasShape()) {
                $this->shapeWarn = '';
                $sw = $this->shapeConflict(self::givenShape($n->value), $rt);
                if ($sw !== null) { $this->errors[] = $this->at($n) . $inFn . '(): return — ' . $sw; }
                $this->takeShapeWarn($n, $inFn . '(): return — ');
            }
        } elseif ($n->kind === Node::KIND_STORE_ELEMENT) {
            $at = $n->array->type;
            $k = self::constKey($n->index);
            if ($k !== null && $at->isShape()) {
                $ft = $at->shapeField($k);
                if ($ft !== null && $this->storeIncompatible($n->value->type, $ft, $at->declared)) {
                    $this->errors[] = $this->at($n) . $inFn . '(): store to key ' . (string)$k . ' — '
                        . $n->value->type->toString() . ' given, ' . $ft->toString() . ' expected';
                }
            }
        } elseif ($n->kind === Node::KIND_ARRAY_ACCESS) {
            $at = $n->array->type;
            $k = self::constKey($n->index);
            if ($k !== null && !$n->probe && $at->isShape() && $at->declared && $at->shapeField($k) === null) {
                $this->errors[] = $this->at($n) . $inFn . '(): key ' . (string)$k . ' is not in ' . $at->shapeString();
            }
        } elseif ($n->kind === Node::KIND_STORE_LOCAL && $n->declaredType !== null
            && $n->declaredType->hasShape()) {
            $this->shapeWarn = '';
            $sw = $this->shapeConflict(self::givenShape($n->value), $n->declaredType);
            if ($sw !== null) { $this->errors[] = $this->at($n) . $inFn . '(): $' . $n->name . ' — ' . $sw; }
            $this->takeShapeWarn($n, $inFn . '(): $' . $n->name . ' — ');
        }
    }

    /** The literal key of an index node — an int or string constant — or null. */
    private static function constKey(Node $index): int|string|null
    {
        if ($index->kind === Node::KIND_INT_CONST) { return $index->value; }
        if ($index->kind === Node::KIND_STRING_CONST) { return $index->value; }
        return null;
    }

    /**
     * The shape a VALUE offers: an array literal's own per-element types (its
     * `type` may already have been retyped to the slot's shape by inference,
     * but its children keep theirs), else the node's static type. A literal
     * with a dynamic key or a spread offers nothing checkable.
     */
    public static function givenShape(Node $v): Type
    {
        if ($v->kind !== Node::KIND_ARRAY_LIT) { return $v->type; }
        $fields = [];
        $next = 0;
        foreach ($v->elements as $el) {
            if ($el->value->kind === Node::KIND_SPREAD) { return Type::unknown(); }
            if ($el->key === null) { $k = $next; }
            else {
                $k = self::constKey($el->key);
                if ($k === null) { return Type::unknown(); }
            }
            $ek = Type::shapeKey($k);
            if (Type::shapeKeyIsInt($ek)) { $next = (int)Type::shapeKeyLabel($ek) + 1; }
            // A sub-literal offers ITS elements too, so a `vec[array{…}]`
            // parameter sees `[[1, 'a']]` as the shape it spells, not as
            // the element inference already widened the outer literal to.
            // `shapeOf` then makes the element the sub-shape itself when every
            // sub-literal spells the same one, which is what the element arm of
            // {@see shapeConflict} compares.
            $fields[$ek] = self::givenShape($el->value);
        }
        return Type::shapeOf($fields, []);
    }

    /**
     * Why `$given` cannot fill a slot of type `$want`, or null. A DECLARED
     * shape is compared field by field with a shaped `$given` (a literal, a
     * local that kept its record), and against the one element type of an
     * unshaped concrete array (`vec[int]` into `array{0:int,1:string}`: the
     * runtime guard refuses that read, so the call is refused here). Then
     * the elements, so a `vec[array{…}]` parameter checks each sub-literal.
     * Skips the erased cases (a non-array, a cell/unknown on either side)
     * and obj↔obj / scalar↔scalar, exactly as {@see fieldIncompatible}.
     */
    private function shapeConflict(Type $given, Type $want): ?string
    {
        if (!$given->isArray() || !$want->isArray()) { return null; }
        if ($want->isShape() && $want->declared) {
            $why = null;
            if ($given->isShape()) {
                foreach ($want->fields as $ek => $ft) {
                    $gt = $given->shapeFieldAt($ek);
                    if ($gt === null) {
                        if (isset($want->nullableFields[$ek])) { continue; }
                        $why = 'key ' . Type::shapeKeyLabel($ek) . ' is missing';
                        break;
                    }
                    if ($this->fieldIncompatible($gt, $ft)) {
                        $why = 'key ' . Type::shapeKeyLabel($ek) . ' is ' . $gt->toString() . ', ' . $ft->toString() . ' expected';
                        break;
                    }
                    $sub = $this->shapeConflict($gt, $ft);
                    if ($sub !== null) {
                        $why = 'key ' . Type::shapeKeyLabel($ek) . ': ' . $sub;
                        break;
                    }
                }
                if ($why === null) {
                    foreach ($given->fields as $ek => $gt) {
                        if ($want->shapeFieldAt($ek) !== null) { continue; }
                        // An OPTIONAL key the target does not name is a docblock
                        // disagreement, not a wrong read: nothing on the other side
                        // looks for it. php-cs-fixer declares `constant?:` where the
                        // method it passes to declares `const?:`. A warning, not a
                        // refusal to build.
                        if (isset($given->nullableFields[$ek])) {
                            if ($this->shapeWarn === '') {
                                $this->shapeWarn = 'optional key ' . Type::shapeKeyLabel($ek) . ' of '
                                    . $given->shapeString() . ' is not in ' . $want->shapeString();
                            }
                            continue;
                        }
                        $why = 'key ' . Type::shapeKeyLabel($ek) . ' is not in the shape';
                        break;
                    }
                }
            } elseif ($given->element !== null && $this->concrete($given->element)) {
                foreach ($want->fields as $ek => $ft) {
                    if ($this->fieldIncompatible($given->element, $ft)) {
                        $why = 'key ' . Type::shapeKeyLabel($ek) . ' is ' . $given->element->toString() . ', ' . $ft->toString() . ' expected';
                        break;
                    }
                }
            }
            if ($why !== null) {
                return $given->shapeString() . ' given, ' . $want->shapeString() . ' expected: ' . $why;
            }
        }
        if ($given->element === null || $want->element === null) { return null; }
        $sub = $this->shapeConflict($given->element, $want->element);
        return $sub === null ? null : 'element ' . $sub;
    }

    /**
     * Field-level disagreement: array-ness, object-ness or a pointer kind vs a
     * scalar kind. Scalar↔scalar (php coerces) and obj↔obj (subtyping not
     * modelled) are not conflicts; an erased side never is.
     */
    private function fieldIncompatible(Type $a, Type $b): bool
    {
        if (!$this->concrete($a) || !$this->concrete($b)) { return false; }
        if ($a->kind === $b->kind) { return false; }
        if ($this->isPtrKind($a) || $this->isPtrKind($b)) { return true; }
        return false;
    }

    /**
     * A constant-key STORE is held to the field's exact kind when the shape
     * is DECLARED: the read side unboxes by the docblock, so a scalar of
     * another kind is not php's coercion but the wrong bits (`$q[1] = 2.5`
     * into `array{0:int,1:int}` read back the double's bit pattern as an int).
     * An int into a `float` field is the one widening the store converts
     * ({@see \Compile\Mir\Passes\EmitLlvmArrays::emitStoreElemValue}); an
     * inferred shape keeps the pointer-vs-scalar rule of {@see fieldIncompatible}.
     */
    private function storeIncompatible(Type $given, Type $field, bool $declared): bool
    {
        if (!$declared) { return $this->fieldIncompatible($given, $field); }
        if (!$this->concrete($given) || !$this->concrete($field)) { return false; }
        if ($given->kind === $field->kind) { return false; }
        return !($given->kind === Type::KIND_INT && $field->kind === Type::KIND_FLOAT);
    }

    private function isPtrKind(Type $t): bool
    {
        return $t->kind === Type::KIND_ARRAY || $t->kind === Type::KIND_OBJ || $t->kind === Type::KIND_STRING;
    }

    /**
     * Array-vs-array REPRESENTATION conflict at a call boundary, or null.
     *
     * The declared param type decides how the CALLEE reads the buffer, so a
     * disagreement is not a style nit — it is a wild read. A packed (int-keyed)
     * array handed to a param declared `array<string,V>` makes the callee walk
     * an int as a string pointer: a silent SIGSEGV, the exact trap that cost a
     * session on `http_build_query`. Same for the element repr.
     *
     * Deliberately narrow — it fires only when BOTH sides are informative:
     * a cell/unknown on either side is the erased case the call site already
     * coerces (`emitCellifyArrayRaw`) or is simply not known well enough here.
     */
    private function arrayReprConflict(Type $arg, Type $param): ?string
    {
        if ($arg->kind !== Type::KIND_ARRAY || $param->kind !== Type::KIND_ARRAY) { return null; }
        $ak = $this->keyKindOf($arg);
        $pk = $this->keyKindOf($param);
        if ($ak !== null && $pk !== null && $ak !== $pk) {
            return 'array KEY repr conflict — a ' . $this->kindName($ak)
                . '-keyed array read as ' . $this->kindName($pk) . '-keyed walks the key as the wrong type';
        }
        // `concrete()`, not `reprConcrete()`: a UNION element (`obj<A>|obj<B>`)
        // has its own kind but the same pointer repr as either arm, so it must
        // not read as a conflict — the self-host parser legitimately passes
        // `vec[obj<Ellipsis>|obj<Expr>]` to a `vec[obj<Expr>]` param. Same
        // reason obj↔obj is skipped by `incompatible()`: subtyping is not modelled.
        $ae = $arg->element;
        $pe = $param->element;
        if ($ae !== null && $pe !== null
            && $this->concrete($ae) && $this->concrete($pe)
            && $ae->kind !== $pe->kind) {
            return 'array ELEMENT repr conflict — ' . $this->kindName($ae->kind)
                . ' elements read as ' . $this->kindName($pe->kind);
        }
        return null;
    }

    /**
     * The key KIND an array is READ with, or null when the read is TAG-DISPATCHED
     * (and so accepts either kind).
     *
     * This must mirror the codegen's key-reader choice exactly — `emitForeach`
     * (EmitLlvmControl) picking `__mir_array_key_cell_at` over
     * `__mir_array_key_at`, and its inference twin in `InferNodes::inferForeach`.
     * That choice keys off the ELEMENT, not the declared key: a vec whose
     * element is cell/unknown reads keys tag-aware, which is precisely why
     * `array<mixed,mixed>` (which lowers to vec[cell], NOT a cell-keyed assoc —
     * `isAssoc()` means a STRING key) accepts an int-keyed array at all.
     *
     * So only two shapes are genuinely committed to one key repr: a vec with a
     * CONCRETE element (packed, implicit 0..n-1) and a string-keyed assoc.
     */
    private function keyKindOf(Type $t): ?string
    {
        if (!$t->isArray()) { return null; }
        $e = $t->element;
        if ($e === null || !$this->reprConcrete($e)) { return null; }
        // A CELL key is the tag-dispatched channel and must be tested BEFORE
        // isAssoc(), which is string-key-only and answers FALSE for it — a
        // cell-keyed assoc reports isVec(), so it would otherwise read as a
        // commitment to packed int keys, which is the opposite of what it is.
        // `emitForeach` picks `__mir_array_key_cell_at` off exactly this, so a
        // cell-keyed param accepts either key kind at runtime. This is what
        // {@see InferScans::scanDocListKeyPromote} promotes a `T[]` param to.
        $k = $t->key;
        if ($k !== null && $k->kind === Type::KIND_CELL) { return null; }
        if (!$t->isAssoc()) { return Type::KIND_INT; }
        if ($k === null || !$this->reprConcrete($k)) { return null; }
        return $k->kind;
    }

    /** A repr we can hold the call site to (cell/unknown are the coerced cases). */
    private function reprConcrete(Type $t): bool
    {
        return $t->kind !== Type::KIND_CELL && $t->kind !== Type::KIND_UNKNOWN
            && $t->kind !== Type::KIND_NULL;
    }

    private function kindName(string $k): string
    {
        if ($k === Type::KIND_INT) { return 'int'; }
        if ($k === Type::KIND_STRING) { return 'string'; }
        if ($k === Type::KIND_FLOAT) { return 'float'; }
        if ($k === Type::KIND_BOOL) { return 'bool'; }
        if ($k === Type::KIND_ARRAY) { return 'array'; }
        if ($k === Type::KIND_OBJ) { return 'object'; }
        return 'value';
    }

    /** A kind we can reason about (excludes unknown / cell / null / void / closure). */
    private function concrete(Type $t): bool
    {
        $k = $t->kind;
        return $k === Type::KIND_INT || $k === Type::KIND_FLOAT
            || $k === Type::KIND_STRING || $k === Type::KIND_BOOL
            || $k === Type::KIND_ARRAY || $k === Type::KIND_OBJ;
    }
}
