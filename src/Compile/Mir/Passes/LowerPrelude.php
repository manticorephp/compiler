<?php

namespace Compile\Mir\Passes;

use Compile\Mir\Add;
use Compile\Mir\BitOp;
use Compile\Mir\BitNot_;
use Compile\Mir\ArrayAccess_;
use Compile\Mir\ArrayElement_;
use Compile\Mir\ArrayLit;
use Compile\Mir\Spread_;
use Compile\Mir\Block;
use Compile\Mir\ClassDef;
use Compile\Mir\EnumDef;
use Compile\Mir\BoolConst;
use Compile\Mir\Break_;
use Compile\Mir\Call;
use Compile\Mir\Walk;
use Compile\Mir\Closure_;
use Compile\Mir\Invoke_;
use Compile\Mir\NullCoalesce_;
use Compile\Mir\Instanceof_;
use Compile\Mir\Cast;
use Compile\Mir\Cmp;
use Compile\Mir\Concat;
use Compile\Mir\Continue_;
use Compile\Mir\Goto_;
use Compile\Mir\Label_;
use Compile\Mir\Div;
use Compile\Mir\Echo_;
use Compile\Mir\FloatConst;
use Compile\Mir\FunctionDef;
use Compile\Mir\Foreach_;
use Compile\Mir\For_;
use Compile\Mir\DoWhile_;
use Compile\Mir\IncDec;
use Compile\Mir\StaticProp_;
use Compile\Mir\StoreStaticProp_;
use Compile\Mir\StaticLocalDecl_;
use Compile\Mir\Isset_;
use Compile\Mir\Unset_;
use Compile\Mir\ClassName_;
use Compile\Mir\RefAlias_;
use Compile\Mir\RefBind_;
use Compile\Mir\RefAddr_;
use Compile\Mir\Throw_;
use Compile\Mir\Yield_;
use Compile\Mir\TryCatch_;
use Compile\Mir\MirCatch;
use Compile\Mir\Ternary;
use Compile\Mir\Switch_;
use Compile\Mir\SwitchArm_;
use Compile\Mir\Match_;
use Compile\Mir\MatchArm_;
use Compile\Mir\If_;
use Compile\Mir\IntConst;
use Compile\Mir\LoadLocal;
use Compile\Mir\MethodCall_;
use Compile\Mir\Mod;
use Compile\Mir\Module;
use Compile\Mir\Mul;
use Compile\Mir\Neg;
use Compile\Mir\NewObj;
use Compile\Mir\Node;
use Compile\Mir\Not_;
use Compile\Mir\NullConst;
use Compile\Mir\Param;
use Compile\Mir\Pass;
use Compile\Mir\PropertyAccess_;
use Compile\Mir\Return_;
use Compile\Mir\StaticCall_;
use Compile\Mir\StoreElement;
use Compile\Mir\StoreLocal;
use Compile\Mir\StoreProperty;
use Compile\Mir\DynProp_;
use Compile\Mir\StoreDynProp_;
use Compile\Mir\StringConst;
use Compile\Mir\Sub;
use Compile\Mir\Type;
use Compile\Mir\While_;
use Parser\Ast\Program;

/**
 * The synthesized prelude: PHP source the compiler builds for itself
 * (Throwable, the array classes, predefined constants, the CLI superglobals).
 *
 * A trait on the one {@see LowerFromAst} host — split by concern so a reader opens
 * the file for the thing they are looking at. State stays on the host.
 */
trait LowerPrelude
{
    /**
     * The prelude as PHP source, assembled from `prelude/*.php` and parsed once.
     *
     * Nothing here is a string literal in the compiler any more: the Throwable
     * hierarchy, the backtrace-frame builder, var_dump's recursive backend, the
     * SPL array classes, the array functions, the CLI superglobals and print_r
     * are ordinary PHP files, read by Main (see {@see \Manticore\find_prelude_src})
     * and gated by what the program actually demands ({@see \Compile\Mir\PreludeDemand}).
     *
     * `exceptions.php` is unconditional — every program can `throw`. It calls
     * `__mir_bt_frames`, which arrives from `backtrace.php` (a program that
     * queries a trace) or `backtrace_stub.php` (one that does not); Main picks
     * one, so the name is always defined exactly once.
     *
     * The one thing still GENERATED is `__mir_dump_object` — it is written from
     * the finished class table, so it cannot be a file. See {@see dumpObjectSrc}.
     *
     * @return \Parser\Ast\Stmt[]
     */
    private function preludeStatements(): array
    {
        $src = "<?php\n" . $this->exceptionsSrc . $this->resourceSrc . $this->fiberSrc . $this->backtraceSrc;
        if ($this->includeVarDump) {
            $src = $src . $this->varDumpSrc;
        }
        if ($this->includeArrayClasses) {
            $src = $src . $this->arrayClassesSrc;
        }
        if ($this->includeArrayFns) {
            $src = $src . $this->arrayFnsSrc;
        }
        if ($this->includeArrayFnsExt) {
            $src = $src . $this->arrayFnsExtSrc;
        }
        if ($this->includeCli) {
            $src = $src . $this->cliSrc;
        }
        if ($this->includeVarExport) {
            $src = $src . $this->varExportSrc;
        }
        if ($this->includePrintR) {
            $src = $src . $this->printRSrc;
        }
        if ($this->includeSerialize) {
            $src = $src . $this->serializeSrc;
        }
        if ($this->includeUnserialize) {
            $src = $src . $this->unserializeSrc;
        }
        if ($this->includeReflection) {
            // After exceptions.php: ReflectionException extends Exception, and
            // the sources are concatenated then parsed as one unit.
            $src = $src . $this->reflectionSrc;
        }
        if ($this->includeAttributes) {
            // Depends on nothing (and nothing depends on it) — placed beside
            // reflection because that is what gates it.
            $src = $src . $this->attributesSrc;
        }
        if ($this->includeDateTime) {
            // After exceptions.php (DateMalformedStringException extends
            // Exception, DateError extends Error) and after spl_arrays.php,
            // whose interfaces DatePeriod implements.
            $src = $src . $this->dateTimeSrc;
        }
        if ($this->binarySrc !== '') {
            $src = $src . $this->binarySrc;
        }
        if ($this->errorsSrc !== '') {
            // After exceptions.php — __mc_dispatch_uncaught takes a Throwable.
            $src = $src . $this->errorsSrc;
        }
        if ($this->obSrc !== '') {
            // After errors.php: both are drained from the same atexit family,
            // and __mc_ob_shutdown must run after the shutdown queue it may be
            // buffering the output of.
            $src = $src . $this->obSrc;
        }
        if ($this->sapiSrc !== '') {
            // After exceptions.php (setcookie throws ValueError) and after
            // cli.php, which Main forces on: the request context seeds $_SERVER.
            $src = $src . $this->sapiSrc;
        }
        if ($this->sessionSrc !== '') {
            // LAST: it names __McSapi (sapi.php), __McUnSt (unserialize.php) and
            // the Throwable hierarchy, and Main forces every one of those on.
            $src = $src . $this->sessionSrc;
        }
        if ($this->xmlSrc !== '') {
            // ext/simplexml — global namespace, so it belongs to this blob and
            // not to the braced tier below. After exceptions.php: the
            // SimpleXMLElement constructor throws on malformed input.
            //
            // ⚠ ORDER IS LOAD-BEARING. Classes are built in SOURCE order, so a
            // subclass parsed ahead of its parent inherits ZERO slots and its
            // objects walk off their own layout: xml.php defines
            // SimpleXMLElement before SimpleXMLIterator extends it, and
            // xml_dom.php defines DOMNode before every DOM subclass.
            $src = $src . $this->xmlSrc . $this->xmlXpathSrc . $this->xmlDomSrc;
        }
        $program = \Parser\Parser::parseSource($src);
        $stmts = $program->statements;
        // Io\Poll is a NAMESPACED class tree (braced `namespace Io\Poll {}`).
        // PHP forbids mixing bracketed + unbracketed namespaces in one unit, so
        // it is parsed on its OWN (all-braced, self-contained) and its statements
        // are appended — the namespaces resolve to their real FQNs that way.
        if ($this->ioPollSrc !== '') {
            $ip = \Parser\Parser::parseSource("<?php\n" . $this->ioPollSrc);
            foreach ($ip->statements as $s) { $stmts[] = $s; }
        }
        // ext/pcntl — braced namespaces too, and BEFORE async: the scheduler
        // calls pcntl_signal_dispatch().
        if ($this->pcntlSrc !== '') {
            $pc = \Parser\Parser::parseSource("<?php\n" . $this->pcntlSrc);
            foreach ($pc->statements as $s) { $stmts[] = $s; }
        }
        // Async\ — same deal (braced `namespace Async {}`), and AFTER io_poll:
        // the Scheduler holds an \Io\Poll\Context and a \StreamPollHandle.
        if ($this->asyncSrc !== '') {
            $as = \Parser\Parser::parseSource("<?php\n" . $this->asyncSrc);
            foreach ($as->statements as $s) { $stmts[] = $s; }
        }
        // Buffer\ — braced namespace, no dependencies of its own; parsed before
        // Http\, which names Buffer\ByteBuffer in its signatures.
        if ($this->bufferSrc !== '') {
            $bf = \Parser\Parser::parseSource("<?php\n" . $this->bufferSrc);
            foreach ($bf->statements as $s) { $stmts[] = $s; }
        }
        // Http\ — LAST of the braced tier. Its Server rides Async\ (Semaphore,
        // TaskGroup, shutdownOn) and its parser rides Buffer\ByteBuffer, so both
        // must already be registered when its signatures are lowered.
        if ($this->httpSrc !== '') {
            $ht = \Parser\Parser::parseSource("<?php\n" . $this->httpSrc);
            foreach ($ht->statements as $s) { $stmts[] = $s; }
        }
        return $stmts;
    }

    /** Whether any class in the finished table resolves a `__toString` — the
     *  gate for generating {@see objToStrSrc}. */
    private function anyToStringClass(): bool
    {
        foreach ($this->walkableClassesDerivedFirst() as $cname) {
            if ($this->declaresMethod($cname, '__toString')) { return true; }
        }
        return false;
    }

    /**
     * PHP source for `__mir_obj_to_str` — `(string)` on a value whose STATIC
     * type is a cell but whose runtime tag says object.
     *
     * Why this exists at all: `@__manticore_tagged_to_str` is a single external
     * body in the central core (it is `T` in `lib/manticore_stdlib.o`), so it
     * can never be told what classes a user module holds — and it consequently
     * had no object arm, rendering the tagged word itself. `(string)$sxe['id']`
     * printed `38503727592` where php prints the attribute's text.
     *
     * Generated from the finished class table exactly like {@see dumpObjectSrc}:
     * most-derived first, so a subclass is matched before its base. A class with
     * no `__toString` falls through to '' — php fatals there, and the sentinel
     * → exception conversion is its own owed epic.
     */
    private function objToStrSrc(): string
    {
        $body = "function __mir_obj_to_str(mixed \$v): string {\n";
        foreach ($this->walkableClassesDerivedFirst() as $cname) {
            if (!$this->declaresMethod($cname, '__toString')) { continue; }
            $body = $body . "  if (\$v instanceof \\" . $cname . ") { return \$v->__toString(); }\n";
        }
        return $body . "  return '';\n}\n";
    }

    /**
     * PHP source for `__mir_dump_object` — a class-aware var_dump for typed
     * objects, generated from the complete class table. Each known class gets
     * an `instanceof` branch (most-derived first, so a subclass is matched
     * before its base) that prints `object(Class)#1 (N) { ["prop"]=> ... }` over
     * its declared properties via the recursive `__mir_var_dump`. A dynamic
     * (stdClass / bag) object falls through to a bag walk. Clarity over strict
     * PHP parity: public-style keys (no visibility annotation), a fixed `#1` id.
     */
    private function dumpObjectSrc(): string
    {
        $names = $this->walkableClassesDerivedFirst();
        $n = \count($names);
        $sawDebugInfo = false;
        $body = "function __mir_dump_object(mixed \$v, int \$indent): void {\n"
            . "  \$pad = ''; \$jj = 0; while (\$jj < \$indent) { \$pad = \$pad . '  '; \$jj = \$jj + 1; }\n"
            // An enum-case singleton renders `enum(Enum::Case)` — detected via its
            // class descriptor before the instanceof walk (enums aren't classes here).
            . "  \$en = __mir_enum_name(\$v); if (\$en !== '') { echo 'enum(', \$en, \")\\n\"; return; }\n";
        $ci = 0;
        while ($ci < $n) {
            $cname = $names[$ci];
            $cd = $this->classTable[$cname];
            $props = $cd->propertyNames;
            $pc = (string)\count($props);
            // A reified specialization reports its ORIGIN's name (`Box`, not
            // `Box__of__float`) — but is still matched by its OWN name, so the
            // props read at the specialized (concrete) types. Depth-sorting puts
            // it before its origin, which is what makes the narrowing land here.
            $body = $body . "  if (\$v instanceof \\" . $cname . ") {\n";
            // __debugInfo REPLACES the walk — both the declared slots and the
            // bag, exactly as php does. The count is the returned array's, and
            // its keys are printed as ARRAY keys (an int key is bare), because
            // that array is what php shows, not a property list.
            if ($this->declaresMethod($cname, '__debugInfo')) {
                $sawDebugInfo = true;
                $body = $body . "    \$d = \$v->__debugInfo();\n"
                    . "    echo 'object(" . $cd->display() . ")#1 (', (string)count(\$d), \") {\\n\";\n"
                    . "    __mir_dump_debug_body(\$d, \$indent);\n"
                    . "    echo \$pad, \"}\\n\"; return;\n  }\n";
                $ci = $ci + 1;
                continue;
            }
            if ($cd->usesBag()) {
                // #[AllowDynamicProperties]: the count is not static, and the bag
                // entries print after the declared slots. Without this the arm
                // claimed the object and printed `(0) {}` — the bag walk below is
                // only reached by a class with NO arm (stdClass).
                $body = $body . "    \$bag = \\__mir_obj_bag(\$v);\n"
                    . "    echo 'object(" . $cd->display() . ")#1 (', (string)(" . $pc . " + count(\$bag)), \") {\\n\";\n";
            } else {
                $body = $body . "    echo 'object(" . $cd->display() . ")#1 (" . $pc . ") {' . \"\\n\";\n";
            }
            foreach ($props as $p) {
                $body = $body . "    echo \$pad, '  [\"" . $p . "\"]=>', \"\\n\", \$pad, '  '; __mir_var_dump(\$v->" . $p . ", \$indent + 1);\n";
            }
            if ($cd->usesBag()) {
                $body = $body . "    foreach (\$bag as \$bk => \$bv) {\n"
                    . "      echo \$pad, '  [\"', \$bk, \"\\\"]=>\\n\", \$pad, '  ';\n"
                    . "      __mir_var_dump(\$bv, \$indent + 1);\n"
                    . "    }\n";
            }
            $body = $body . "    echo \$pad, \"}\\n\"; return;\n  }\n";
            $ci = $ci + 1;
        }
        $body = $body
            . "  \$arr = \\__mir_obj_bag(\$v);\n"
            . "  echo 'object(stdClass)#1 (', (string)count(\$arr), \") {\\n\";\n"
            . "  foreach (\$arr as \$k => \$val) {\n"
            . "    echo \$pad, '  [\"', \$k, \"\\\"]=>\\n\", \$pad, '  ';\n"
            . "    __mir_var_dump(\$val, \$indent + 1);\n"
            . "  }\n"
            . "  echo \$pad, \"}\\n\";\n}\n";
        // The __debugInfo body is a helper, not an arm, and only a program that
        // declares the method pays for it. It takes the array as `mixed` ON
        // PURPOSE: `__debugInfo(): array` is a bare `array` hint, which erases
        // its element to unknown, so a `$k => $v` foreach in the arm above would
        // hand __mir_var_dump the raw word. Crossing a `mixed` parameter makes
        // it a cell array by construction — the one shape the walk can read.
        if ($sawDebugInfo) {
            $body = $body . "function __mir_dump_debug_body(mixed \$d, int \$indent): void {\n"
                . "  \$pad = ''; \$jj = 0; while (\$jj < \$indent) { \$pad = \$pad . '  '; \$jj = \$jj + 1; }\n"
                . "  foreach (\$d as \$k => \$val) {\n"
                . "    if (is_int(\$k)) { echo \$pad, '  [', (string)\$k, \"]=>\\n\", \$pad, '  '; }\n"
                . "    else { echo \$pad, '  [\"', \$k, \"\\\"]=>\\n\", \$pad, '  '; }\n"
                . "    __mir_var_dump(\$val, \$indent + 1);\n"
                . "  }\n}\n";
        }
        return $body;
    }

    /**
     * The class table sorted most-derived-first, with the classes that have no
     * object to walk removed (stdClass — the dynamic-bag fallback handles it;
     * a `#[Struct]`, which has no header; a `#[TypeDef]`, whose class does not
     * exist at runtime, so an `instanceof` arm for one would make CheckTypeDefs
     * reject the compiler's OWN generated code and blame the user's program).
     *
     * Depth-DESC matters: a subclass must be matched before its base, and a
     * reified specialization before its origin.
     *
     * @return string[]
     */
    private function walkableClassesDerivedFirst(): array
    {
        $names = [];
        $depths = [];
        foreach ($this->classTable as $cname => $cd) {
            if ($cname === 'stdClass') { continue; }
            if ($cd->isStruct) { continue; }
            if ($this->isTypeDef($cname)) { continue; }
            $names[] = $cname;
            $depths[] = $this->classDepth($cname);
        }
        $n = \count($names);
        $i = 0;
        while ($i < $n) {
            $max = $i;
            $j = $i + 1;
            while ($j < $n) {
                if ($depths[$j] > $depths[$max]) { $max = $j; }
                $j = $j + 1;
            }
            if ($max !== $i) {
                $tn = $names[$i]; $names[$i] = $names[$max]; $names[$max] = $tn;
                $td = $depths[$i]; $depths[$i] = $depths[$max]; $depths[$max] = $td;
            }
            $i = $i + 1;
        }
        return $names;
    }

    /** Whether `$cls` or an ancestor declares method `$m`. `ClassDef::$methodNames`
     *  carries declared + trait-mixed methods ONLY, so an inherited magic method
     *  needs the parent walk (the same one {@see InferCalls::classDefinesMagic}
     *  does on the infer side). */
    private function declaresMethod(string $cls, string $m): bool
    {
        $c = $cls;
        while ($c !== '' && isset($this->classTable[$c])) {
            if (isset($this->classTable[$c]->methodNames[$m])) { return true; }
            $c = $this->classTable[$c]->parent;
        }
        return false;
    }

    /** `$s` as the BODY of a double-quoted PHP literal (no surrounding quotes).
     *  Every byte the lexer could reinterpret is escaped: a NUL becomes the
     *  three-digit octal `\000` (the parser's octal escape spans 1-3 digits, and
     *  neither a property name nor a class name can start with a digit, so the
     *  run is unambiguous). */
    private function dqBody(string $s): string
    {
        $out = '';
        $n = \strlen($s);
        $i = 0;
        while ($i < $n) {
            $c = \substr($s, $i, 1);
            $o = \ord($c);
            if ($o === 0) { $out = $out . '\\000'; }
            else if ($c === '\\') { $out = $out . '\\\\'; }
            else if ($c === '"') { $out = $out . '\\"'; }
            else if ($c === '$') { $out = $out . '\\$'; }
            else { $out = $out . $c; }
            $i = $i + 1;
        }
        return $out;
    }

    /**
     * php's serialized property key for `$prop` of class `$cname`: `prop` when
     * public, `"\0*\0prop"` when protected, `"\0<DeclaringClass>\0prop"` when
     * private — and the DECLARING class, so a private inherited by a subclass
     * still names its origin (php: `O:1:"Q":4:{…s:4:"\0P\0c";…}`).
     */
    private function serPropKey(string $cname, string $prop): string
    {
        $cd = $this->classTable[$cname];
        $meta = $cd->propertyMeta[$prop] ?? null;
        if ($meta === null) { return $prop; }
        if ($meta->visibility === 'protected') { return "\0" . '*' . "\0" . $prop; }
        if ($meta->visibility === 'private') {
            $decl = $meta->declaringClass !== '' ? $meta->declaringClass : $cname;
            return "\0" . $decl . "\0" . $prop;
        }
        return $prop;
    }

    /**
     * PHP source for `__mc_ser_object` — serialize()'s object arm, generated from
     * the complete class table for the same reason `__mir_dump_object` is: it
     * needs one `instanceof` arm per class, and the class table only exists after
     * every user class has registered. The arm is ordinary PHP, so it gets
     * instanceof narrowing, typed property reads at the right offset/repr, and
     * boxing on the recursive `mixed` parameter — and, because Manticore enforces
     * no property visibility, a free function may read a private slot directly.
     * Mangling is a FORMAT concern only.
     *
     * Called only from `__mc_ser_val`, which has already counted this value, so
     * `$st->n` IS the slot php would record for a back-reference.
     */
    private function serObjectSrc(): string
    {
        $names = $this->walkableClassesDerivedFirst();
        $body = "function __mc_ser_object(mixed \$v, __McSerSt \$st): string {\n"
            // BEFORE the id map: a \Resource IS an object to us (php says it is
            // not) and php serializes any resource as the integer 0. It CONSUMES
            // a slot — `serialize([$f,$f,$o,$o])` is `…i:0;…i:0;…O:…r:4;` — but
            // it is never RECORDED, so a repeat is `i:0;` again, not `r:`.
            . "  if (\$v instanceof \\Resource) { return 'i:0;'; }\n"
            // An object occupies ONE slot however many times it appears; every
            // later occurrence is `r:<that slot>`. php numbers references and
            // objects out of the same counter, and an enum case participates too
            // (`a:2:{i:0;E:11:"Suit:Hearts";i:1;r:2;}`). `R:` is php's REFERENCE
            // marker — Manticore arrays carry no is_ref bit, so there is no
            // runtime fact to emit it from.
            . "  \$id = spl_object_id(\$v);\n"
            . "  if (isset(\$st->seen[\$id])) { return 'r:' . (string)\$st->seen[\$id] . ';'; }\n"
            . "  \$st->seen[\$id] = \$st->n;\n"
            // An enum-case singleton is `E:len:"Enum:Case";` — the same
            // descriptor probe var_dump uses, with php's single-colon spelling.
            . "  \$en = __mir_enum_name(\$v);\n"
            . "  if (\$en !== '') {\n"
            . "    \$nm = str_replace('::', ':', \$en);\n"
            . "    return 'E:' . (string)strlen(\$nm) . ':\"' . \$nm . '\";';\n"
            . "  }\n";
        foreach ($names as $cname) {
            $cd = $this->classTable[$cname];
            $props = $cd->propertyNames;
            $disp = $cd->display();
            $head = 'O:' . (string)\strlen($disp) . ':"' . $this->dqBody($disp) . '":';
            $entries = '';
            foreach ($props as $p) {
                $key = $this->serPropKey($cname, $p);
                $entries = $entries . "      . \"s:" . (string)\strlen($key) . ":\\\"" . $this->dqBody($key)
                    . "\\\";\" . __mc_ser_val(\$v->" . $p . ", \$st)\n";
            }
            $body = $body . "  if (\$v instanceof \\" . $cname . ") {\n";
            if ($this->declaresMethod($cname, '__serialize')) {
                // php 7.4+: __serialize REPLACES the property walk entirely. Its
                // keys go out verbatim — no visibility mangling — and may be int
                // or string. The class name is still the object's own.
                $body = $body . "    \$d = \$v->__serialize();\n"
                    . "    \$b = '';\n"
                    . "    foreach (\$d as \$k => \$val) {\n"
                    . "      if (is_int(\$k)) { \$b = \$b . 'i:' . (string)\$k . ';'; }\n"
                    . "      else { \$b = \$b . __mc_ser_str((string)\$k); }\n"
                    . "      \$b = \$b . __mc_ser_val(\$val, \$st);\n"
                    . "    }\n"
                    . "    return \"" . $this->dqBody($head) . "\" . (string)count(\$d) . ':{' . \$b . '}';\n  }\n";
                continue;
            }
            if ($cd->usesBag()) {
                // #[AllowDynamicProperties]: the declared slots, then the bag.
                // `__mir_obj_bag($v)` reads the BAG ONLY, which is exactly what is left
                // to append — and the count cannot be baked in.
                $body = $body . "    \$b = ''\n" . $entries . "      ;\n"
                    . "    \$cnt = " . (string)\count($props) . ";\n"
                    . "    foreach (\\__mir_obj_bag(\$v) as \$k => \$val) {\n"
                    . "      if (is_int(\$k)) { \$b = \$b . 'i:' . (string)\$k . ';'; }\n"
                    . "      else { \$b = \$b . __mc_ser_str((string)\$k); }\n"
                    . "      \$b = \$b . __mc_ser_val(\$val, \$st);\n"
                    . "      \$cnt = \$cnt + 1;\n"
                    . "    }\n"
                    . "    return \"" . $this->dqBody($head) . "\" . (string)\$cnt . ':{' . \$b . '}';\n  }\n";
            } else {
                $body = $body . "    return \"" . $this->dqBody($head . (string)\count($props) . ':{') . "\"\n"
                    . $entries . "      . '}';\n  }\n";
            }
        }
        // stdClass: nothing is declared, so the whole body comes from the bag.
        // An EXPLICIT arm, not the fallthrough — see the throw below.
        $body = $body
            . "  if (\$v instanceof \\stdClass) {\n"
            . "    \$arr = \\__mir_obj_bag(\$v);\n"
            . "    \$out = 'O:8:\"stdClass\":' . (string)count(\$arr) . ':{';\n"
            . "    foreach (\$arr as \$k => \$val) {\n"
            . "      if (is_int(\$k)) { \$out = \$out . 'i:' . (string)\$k . ';'; }\n"
            . "      else { \$out = \$out . __mc_ser_str((string)\$k); }\n"
            . "      \$out = \$out . __mc_ser_val(\$val, \$st);\n"
            . "    }\n"
            . "    return \$out . '}';\n"
            . "  }\n"
            // Every class in the table has an arm above, so what is left is a
            // CLOSURE: `[fn_ptr, capture…]` from `__mir_alloc`, with no rc tag
            // and no class descriptor (slot 0 is the function pointer). Walking
            // it as an object reads a code address as a descriptor. php's answer
            // is this exact exception, so throw rather than deref.
            . "  throw new \\Exception(\"Serialization of 'Closure' is not allowed\");\n}\n";
        return $body;
    }

    /**
     * PHP source for `__mir_export_object` — var_export's object arm, written
     * from the complete class table, same point and pattern as
     * {@see dumpObjectSrc} and {@see serObjectSrc}.
     *
     * ⚠ php does NOT call `__set_state` from var_export. It only PRINTS a literal
     * naming the method — `eval()` of that literal is what would call it, and
     * there is no eval here. So the deliverable is the literal's exact TEXT, for
     * every class, whether or not it declares `__set_state`; php prints the same
     * thing either way. Nobody should later "fix" this into a call.
     *
     * Indent contract: see {@see __mir_var_export} in prelude/var_export.php.
     * Keys sit at `$indent + 3`, the close at `$indent`, values recurse at
     * `$indent + 2`, and a nested value breaks the line first.
     */
    private function exportObjectSrc(): string
    {
        $names = $this->walkableClassesDerivedFirst();
        $body = "function __mir_export_object(mixed \$v, int \$indent): string {\n"
            . "  \$pad = str_repeat(' ', \$indent);\n"
            . "  \$ipad = \$pad . '   ';\n"
            . "  \$lead = \$indent > 0 ? (\"\\n\" . \$pad) : '';\n"
            // An enum case is a bare `\Enum::Case` — no array literal, but it is
            // still a nested VALUE, so it takes the same line break.
            . "  \$en = __mir_enum_name(\$v);\n"
            . "  if (\$en !== '') { return \$lead . '\\\\' . \$en; }\n";
        foreach ($names as $cname) {
            $cd = $this->classTable[$cname];
            $disp = $cd->display();
            $body = $body . "  if (\$v instanceof \\" . $cname . ") {\n"
                . "    \$out = \$lead . \"\\\\" . $this->dqBody($disp) . "::__set_state(array(\\n\";\n";
            foreach ($cd->propertyNames as $p) {
                $body = $body . "    \$out = \$out . \$ipad . \"'" . $p . "' => \" . __mir_var_export(\$v->"
                    . $p . ", \$indent + 2) . \",\\n\";\n";
            }
            if ($cd->usesBag()) {
                // #[AllowDynamicProperties]: the declared slots, then the bag.
                // `__mir_obj_bag($v)` reads the BAG ONLY, which is what is left to add.
                $body = $body . "    foreach (\\__mir_obj_bag(\$v) as \$bk => \$bv) {\n"
                    . "      \$ks = is_int(\$bk) ? (string)\$bk : (\"'\" . __mc_var_export_qstr((string)\$bk) . \"'\");\n"
                    . "      \$out = \$out . \$ipad . \$ks . ' => ' . __mir_var_export(\$bv, \$indent + 2) . \",\\n\";\n"
                    . "    }\n";
            }
            $body = $body . "    return \$out . \$pad . '))';\n  }\n";
        }
        // stdClass prints `(object) array(…)` and closes with ONE paren. An
        // EXPLICIT arm, not the fallthrough — see the closure note below.
        $body = $body
            . "  if (\$v instanceof \\stdClass) {\n"
            . "    \$out = \$lead . \"(object) array(\\n\";\n"
            . "    foreach (\\__mir_obj_bag(\$v) as \$k => \$val) {\n"
            . "      \$ks = is_int(\$k) ? (string)\$k : (\"'\" . __mc_var_export_qstr((string)\$k) . \"'\");\n"
            . "      \$out = \$out . \$ipad . \$ks . ' => ' . __mir_var_export(\$val, \$indent + 2) . \",\\n\";\n"
            . "    }\n"
            . "    return \$out . \$pad . ')';\n"
            . "  }\n"
            // Every class in the table has an arm above, so what is left is a
            // CLOSURE: `[fn_ptr, capture…]` from `__mir_alloc`, with no class
            // descriptor. Walking it would read a code address as one. php
            // prints an empty __set_state literal for a Closure, so print that
            // rather than deref.
            . "  return \$lead . \"\\\\Closure::__set_state(array(\\n\" . \$pad . '))';\n}\n";
        return $body;
    }

    /**
     * PHP source for unserialize's three generated helpers, written from the
     * complete class table — the reader's half of {@see serObjectSrc}.
     *
     *  - `__mc_unser_alloc(cls, st)` — a `===` chain naming every known class,
     *    each arm allocating WITHOUT running __construct (`__mc_new_uninit`,
     *    desugared to a bare NewObj in LowerExprs). A name that falls out of the
     *    chain is unknown to the closed world, which is the same answer php
     *    gives for a class that does not exist.
     *  - `__mc_unser_fill(o, props, st)` — one `instanceof` arm per class,
     *    storing each declared property the stream carried. The keys arrive
     *    DEMANGLED (the parser strips the `\0…\0` prefix), so the arms compare
     *    plain names. A free function may write a private or readonly slot: no
     *    visibility is enforced, and the readonly guard exempts this frame.
     *  - `__mc_unser_enum(spec, st)` — `Enum:Case` back to the case singleton.
     */
    private function unserSrc(): string
    {
        $names = $this->walkableClassesDerivedFirst();

        $alloc = "function __mc_unser_alloc(string \$cls, \\__McUnSt \$st): mixed {\n"
            // allowed_classes is purely runtime, and the closed world is an ASSET
            // here: a disallowed name and an unknown one fall out of the same
            // chain onto the same __PHP_Incomplete_Class.
            . "  if (!\$st->allowAll && !isset(\$st->allowed[\$cls])) { return __mc_incomplete(\$cls); }\n"
            . "  if (\$cls === 'stdClass') { return new \\stdClass(); }\n";
        foreach ($names as $cname) {
            $alloc = $alloc . "  if (\$cls === \"" . $this->dqBody($cname) . "\") { return __mc_new_uninit(\""
                . $this->dqBody($cname) . "\"); }\n";
        }
        $alloc = $alloc . "  return __mc_unser_unknown(\$cls, \$st);\n}\n";

        // ONE KEY AT A TIME, with the value as a `mixed` PARAMETER — not a
        // `$props[...]` read out of a bare-`array` param. A bare `array` hint
        // erases its element, so every read would type UNKNOWN and the slot store
        // would take the raw word: an array property got the TAGGED bits and an
        // enum property got the singleton pointer instead of its ordinal. Crossing
        // a `mixed` parameter makes the value a CELL by construction, which is the
        // one shape the typed-slot store knows how to unbox.
        $fill = "function __mc_unser_set(mixed \$o, string \$k, mixed \$v): void {\n";
        foreach ($names as $cname) {
            $cd = $this->classTable[$cname];
            if ($this->declaresMethod($cname, '__unserialize')) { continue; }
            if ($cd->propertyNames === [] && !$cd->usesBag()) { continue; }
            $fill = $fill . "  if (\$o instanceof \\" . $cname . ") {\n";
            foreach ($cd->propertyNames as $p) {
                $fill = $fill . "    if (\$k === '" . $p . "') { \$o->" . $p . " = \$v; return; }\n";
            }
            if ($cd->usesBag()) {
                // #[AllowDynamicProperties]: whatever no declared slot claimed
                // lands in the bag, as php does.
                $fill = $fill . "    \$o->\$k = \$v;\n";
            }
            $fill = $fill . "    return;\n  }\n";
        }
        // stdClass and __PHP_Incomplete_Class: every key is dynamic.
        $fill = $fill . "  \$o->\$k = \$v;\n}\n";

        // php 7.4+: __unserialize REPLACES the slot fill and is handed the array
        // __serialize produced, keys verbatim. It takes the WHOLE array, so it
        // stays a separate entry point — and nothing is stored into a typed slot
        // here, so the erasure above does not apply.
        $magic = "function __mc_unser_has_magic(mixed \$o): bool {\n";
        $call = "function __mc_unser_magic(mixed \$o, array \$props): void {\n";
        foreach ($names as $cname) {
            if (!$this->declaresMethod($cname, '__unserialize')) { continue; }
            $magic = $magic . "  if (\$o instanceof \\" . $cname . ") { return true; }\n";
            $call = $call . "  if (\$o instanceof \\" . $cname . ") { \$o->__unserialize(\$props); return; }\n";
        }
        $magic = $magic . "  return false;\n}\n";
        $call = $call . "}\n";
        $fill = $fill . $magic . $call;

        $enum = "function __mc_unser_enum(string \$spec, \\__McUnSt \$st): mixed {\n";
        foreach ($this->enumTable as $ename => $ed) {
            foreach ($ed->caseNames as $case) {
                $enum = $enum . "  if (\$spec === \"" . $this->dqBody($ename . ':' . $case)
                    . "\") { return \\" . $ename . "::" . $case . "; }\n";
            }
        }
        $enum = $enum . "  \$st->ok = false;\n  return null;\n}\n";

        return $alloc . $fill . $enum;
    }

    private function injectCliSuperglobals(array $mainStmts): array
    {
        $readArgv = false; $readArgc = false;
        $setArgv = false; $setArgc = false;
        foreach ($mainStmts as $s) {
            if ($this->nodeReadsLocal($s, 'argv')) { $readArgv = true; }
            if ($this->nodeReadsLocal($s, 'argc')) { $readArgc = true; }
            if ($this->nodeWritesLocal($s, 'argv')) { $setArgv = true; }
            if ($this->nodeWritesLocal($s, 'argc')) { $setArgc = true; }
        }
        $pre = [];
        if ($readArgv && !$setArgv) {
            $pre[] = new StoreLocal(
                'argv',
                new Call('__mc_argv', [], Type::vec(Type::string_())),
                Type::vec(Type::string_()),
            );
        }
        if ($readArgc && !$setArgc) {
            $pre[] = new StoreLocal(
                'argc',
                new Call('__mir_argc', [], Type::int_()),
                Type::int_(),
            );
        }
        if ($pre === []) { return $mainStmts; }
        foreach ($mainStmts as $s) { $pre[] = $s; }
        return $pre;
    }

    /**
     * PHP predefined constants → a literal node, or null if `$name` is not a
     * known predefined. Covers the broadly-used core / math / flag families
     * (php.net/reserved.constants, math.constants, string.constants); values
     * are baked at compile time. INF/NAN ride a FloatConst (EmitLlvm emits the
     * exact bit pattern). User constants (define()) are handled separately.
     */
    private function predefinedConstant(string $name): ?Node
    {
        // PHP_INT_MAX/MIN are written out (too wide for some literal paths).
        if ($name === 'PHP_INT_MAX') { return new IntConst(9223372036854775807, Type::int_()); }
        if ($name === 'PHP_INT_MIN') { return new IntConst(-9223372036854775807 - 1, Type::int_()); }
        if ($name === 'INF') { return new FloatConst(\INF, Type::float_()); }
        if ($name === 'NAN') { return new FloatConst(\NAN, Type::float_()); }
        // Standard CLI stream resources. The `__mir_std*` builtins still load the
        // platform FILE* global (so fwrite(STDOUT, …) shares echo's buffer), but
        // the CONSTANT is a \Resource like every other handle — the f* family is
        // typed \Resource and would otherwise reject STDOUT. __mc_std_res caches
        // one per stream, so `STDOUT === STDOUT` and the ids stay stable.
        // The FILE* comes from the `__mir_std*` BUILTIN, emitted HERE at the
        // mention — it must not be called from the stdlib. Resolving those
        // globals needs host_os() (glibc `stdin` vs Apple `__stdinp`), and the
        // emitter only runs it when a program uses a stream; the compiler's own
        // src/ never does, which is what keeps the Zend cold-seed alive (see
        // EmitLlvmModule's needsStdStreams block). A stdlib fn that mentioned
        // them would make src/ itself use a stream and kill the bootstrap.
        if ($name === 'STDIN')  { return $this->stdStreamNode(0); }
        if ($name === 'STDOUT') { return $this->stdStreamNode(1); }
        if ($name === 'STDERR') { return $this->stdStreamNode(2); }

        return $this->preludeConstInt($name);
    }

    /**
     * The cached `\Resource` for standard stream `$which` (0 in, 1 out, 2 err).
     * The FILE* comes from the `__mir_std*` builtin emitted right here, at the
     * mention — see the note above on why it must not come from the stdlib.
     */
    private function stdStreamNode(int $which): Node
    {
        $builtin = '__mir_stderr';
        if ($which === 0) { $builtin = '__mir_stdin'; }
        elseif ($which === 1) { $builtin = '__mir_stdout'; }
        return new Call(
            '__mc_std_res',
            [
                new IntConst($which, Type::int_()),
                new Call($builtin, [], Type::obj('Ffi\\Ptr')),
            ],
            Type::obj('Resource'),
        );
    }

    /**
     * `php://stdout` / `php://output` / `php://stderr` / `php://stdin` (any
     * case) → the standard-stream resource, so `fopen()` on the literal every
     * console app uses answers the same handle the constant does. Null for any
     * other target, which leaves the stdlib's fopen to handle it.
     *
     * ⚠ `php://output` is NOT `php://stdout`, though this folded both to fd 1
     * until output buffering existed. php sends php://output through the output
     * layer — so ob_start() captures it — and php://stdout straight to fd 1,
     * where it is not captured. One node each.
     */
    private function stdStreamResource(string $target): ?Node
    {
        $t = \strtolower($target);
        if ($t === 'php://stdin')  { return $this->stdStreamNode(0); }
        if ($t === 'php://stdout') { return $this->stdStreamNode(1); }
        if ($t === 'php://stderr') { return $this->stdStreamNode(2); }
        if ($t === 'php://output') {
            return new Call('__mc_output_res', [], Type::obj('Resource'));
        }
        return null;
    }

    private function preludeConstInt(string $name): ?Node
    {
        $ints = [
            // string padding
            'STR_PAD_RIGHT' => 1, 'STR_PAD_LEFT' => 0, 'STR_PAD_BOTH' => 2,
            // sort flags
            'SORT_REGULAR' => 0, 'SORT_NUMERIC' => 1, 'SORT_STRING' => 2,
            'SORT_DESC' => 3, 'SORT_ASC' => 4, 'SORT_LOCALE_STRING' => 5,
            'SORT_NATURAL' => 6, 'SORT_FLAG_CASE' => 8,
            // count / array_filter
            'COUNT_NORMAL' => 0, 'COUNT_RECURSIVE' => 1,
            'ARRAY_FILTER_USE_KEY' => 2, 'ARRAY_FILTER_USE_BOTH' => 1,
            // round modes
            'PHP_ROUND_HALF_UP' => 1, 'PHP_ROUND_HALF_DOWN' => 2,
            'PHP_ROUND_HALF_EVEN' => 3, 'PHP_ROUND_HALF_ODD' => 4,
            // error reporting levels
            'E_ERROR' => 1, 'E_WARNING' => 2, 'E_PARSE' => 4, 'E_NOTICE' => 8,
            'E_CORE_ERROR' => 16, 'E_CORE_WARNING' => 32, 'E_COMPILE_ERROR' => 64,
            'E_COMPILE_WARNING' => 128, 'E_USER_ERROR' => 256, 'E_USER_WARNING' => 512,
            'E_USER_NOTICE' => 1024, 'E_STRICT' => 2048, 'E_RECOVERABLE_ERROR' => 4096,
            'E_DEPRECATED' => 8192, 'E_USER_DEPRECATED' => 16384, 'E_ALL' => 30719,
            // php core ints
            'PHP_INT_SIZE' => 8, 'PHP_VERSION_ID' => 80503, 'PHP_MAJOR_VERSION' => 8,
            'PHP_MINOR_VERSION' => 5, 'PHP_RELEASE_VERSION' => 3, 'PHP_FLOAT_DIG' => 15,
            'PHP_ZTS' => 0, 'PHP_DEBUG' => 0, 'PHP_MAXPATHLEN' => 1024,
            // json flags
            'JSON_HEX_TAG' => 1, 'JSON_HEX_AMP' => 2, 'JSON_HEX_APOS' => 4,
            'JSON_HEX_QUOT' => 8, 'JSON_FORCE_OBJECT' => 16, 'JSON_NUMERIC_CHECK' => 32,
            'JSON_UNESCAPED_SLASHES' => 64, 'JSON_PRETTY_PRINT' => 128,
            'JSON_UNESCAPED_UNICODE' => 256, 'JSON_PARTIAL_OUTPUT_ON_ERROR' => 512,
            'JSON_PRESERVE_ZERO_FRACTION' => 1024, 'JSON_INVALID_UTF8_IGNORE' => 1048576,
            'JSON_INVALID_UTF8_SUBSTITUTE' => 2097152, 'JSON_THROW_ON_ERROR' => 4194304,
            'JSON_OBJECT_AS_ARRAY' => 1, 'JSON_BIGINT_AS_STRING' => 2, 'JSON_ERROR_NONE' => 0,
            // preg flags
            'PREG_PATTERN_ORDER' => 1, 'PREG_SET_ORDER' => 2, 'PREG_OFFSET_CAPTURE' => 256,
            'PREG_UNMATCHED_AS_NULL' => 512, 'PREG_SPLIT_NO_EMPTY' => 1,
            'PREG_SPLIT_DELIM_CAPTURE' => 2, 'PREG_SPLIT_OFFSET_CAPTURE' => 4,
            'PREG_GREP_INVERT' => 1,
            // preg_last_error() codes
            'PREG_NO_ERROR' => 0, 'PREG_INTERNAL_ERROR' => 1,
            'PREG_BACKTRACK_LIMIT_ERROR' => 2, 'PREG_RECURSION_LIMIT_ERROR' => 3,
            'PREG_BAD_UTF8_ERROR' => 4, 'PREG_BAD_UTF8_OFFSET_ERROR' => 5,
            'PREG_JIT_STACKLIMIT_ERROR' => 6,
            // htmlspecialchars / entities (common subset)
            'ENT_NOQUOTES' => 0, 'ENT_COMPAT' => 2, 'ENT_QUOTES' => 3, 'ENT_HTML5' => 48,
            // filesystem: fseek whence + file_put_contents / flock flags
            'SEEK_SET' => 0, 'SEEK_CUR' => 1, 'SEEK_END' => 2,
            'FILE_USE_INCLUDE_PATH' => 1, 'FILE_APPEND' => 8,
            'FILE_IGNORE_NEW_LINES' => 2, 'FILE_SKIP_EMPTY_LINES' => 4, 'FILE_NO_DEFAULT_CONTEXT' => 16,
            // PHP's LOCK_* are PHP's own values, not the OS's — flock() translates.
            'LOCK_SH' => 1, 'LOCK_EX' => 2, 'LOCK_UN' => 3, 'LOCK_NB' => 4,
            'SCANDIR_SORT_ASCENDING' => 0, 'SCANDIR_SORT_DESCENDING' => 1,
            'SCANDIR_SORT_NONE' => 2,
            // ob_start() handler phases + flags. START is set on the FIRST
            // handler call for a buffer, so a first ob_end_flush() sees 9 and a
            // flush after one already happened sees 8 — {@see prelude/ob.php}.
            'PHP_OUTPUT_HANDLER_START' => 1, 'PHP_OUTPUT_HANDLER_WRITE' => 0,
            'PHP_OUTPUT_HANDLER_FLUSH' => 4, 'PHP_OUTPUT_HANDLER_CLEAN' => 2,
            'PHP_OUTPUT_HANDLER_FINAL' => 8, 'PHP_OUTPUT_HANDLER_CONT' => 0,
            'PHP_OUTPUT_HANDLER_END' => 8,
            'PHP_OUTPUT_HANDLER_CLEANABLE' => 16, 'PHP_OUTPUT_HANDLER_FLUSHABLE' => 32,
            'PHP_OUTPUT_HANDLER_REMOVABLE' => 64, 'PHP_OUTPUT_HANDLER_STDFLAGS' => 112,
            // parse_ini_* scanner modes
            'INI_SCANNER_NORMAL' => 0, 'INI_SCANNER_RAW' => 1, 'INI_SCANNER_TYPED' => 2,
            // stream_socket_server / _client flags — php's own values. A udp://
            // server passes STREAM_SERVER_BIND alone (listen is stream-only).
            'STREAM_SERVER_BIND' => 4, 'STREAM_SERVER_LISTEN' => 8,
            'STREAM_CLIENT_CONNECT' => 4, 'STREAM_CLIENT_ASYNC_CONNECT' => 2,
            'STREAM_CLIENT_PERSISTENT' => 1,
            // stream_socket_enable_crypto methods — php's values; bit 0 selects
            // CLIENT (1) vs SERVER (0). TLS_* is the version-agnostic combination.
            'STREAM_CRYPTO_METHOD_ANY_CLIENT' => 127, 'STREAM_CRYPTO_METHOD_ANY_SERVER' => 126,
            'STREAM_CRYPTO_METHOD_TLS_CLIENT' => 121, 'STREAM_CRYPTO_METHOD_TLS_SERVER' => 120,
            'STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT' => 33, 'STREAM_CRYPTO_METHOD_TLSv1_2_SERVER' => 32,
            'STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT' => 65, 'STREAM_CRYPTO_METHOD_TLSv1_3_SERVER' => 64,
            'STREAM_CRYPTO_PROTO_TLSv1_2' => 16, 'STREAM_CRYPTO_PROTO_TLSv1_3' => 32,
            // dns_get_record type bitmask — php's OWN values (not the wire QTYPEs;
            // the resolver maps them). Host-invariant.
            'DNS_A' => 1, 'DNS_NS' => 2, 'DNS_CNAME' => 16, 'DNS_SOA' => 32,
            'DNS_PTR' => 2048, 'DNS_HINFO' => 4096, 'DNS_CAA' => 8192, 'DNS_MX' => 16384,
            'DNS_TXT' => 32768, 'DNS_A6' => 16777216, 'DNS_SRV' => 33554432,
            'DNS_NAPTR' => 67108864, 'DNS_AAAA' => 134217728, 'DNS_ANY' => 268435456,
            'DNS_ALL' => 268435455,
            // stream_socket_pair domain/type/proto — host-INVARIANT AF_/SOCK_/IPPROTO_
            // values (STREAM_PF_INET6 is host-divergent, folded below with AF_INET6).
            'STREAM_PF_INET' => 2, 'STREAM_PF_UNIX' => 1,
            'STREAM_SOCK_STREAM' => 1, 'STREAM_SOCK_DGRAM' => 2, 'STREAM_SOCK_RAW' => 3,
            'STREAM_IPPROTO_IP' => 0, 'STREAM_IPPROTO_TCP' => 6, 'STREAM_IPPROTO_UDP' => 17,
            'STREAM_IPPROTO_ICMP' => 1, 'STREAM_IPPROTO_RAW' => 255,
            // glob: php's OWN values, not the host's (php has carried its own
            // glob since 8.3) — GLOB_NOESCAPE is 0x1000 where Darwin's header
            // says 0x2000, and no libc has GLOB_ONLYDIR = 0x40000000. Host
            // independent, hence a plain entry here and not a host_os() probe.
            'GLOB_ERR' => 0x0004, 'GLOB_MARK' => 0x0008,
            'GLOB_NOCHECK' => 0x0010, 'GLOB_NOSORT' => 0x0020,
            'GLOB_BRACE' => 0x0080, 'GLOB_NOESCAPE' => 0x1000,
            'GLOB_ONLYDIR' => 0x40000000, 'GLOB_AVAILABLE_FLAGS' => 0x400010bc,
            'PATHINFO_DIRNAME' => 1, 'PATHINFO_BASENAME' => 2,
            'PATHINFO_EXTENSION' => 4, 'PATHINFO_FILENAME' => 8, 'PATHINFO_ALL' => 15,
            // parse_url() component selectors + http_build_query() encodings.
            'PHP_URL_SCHEME' => 0, 'PHP_URL_HOST' => 1, 'PHP_URL_PORT' => 2,
            'PHP_URL_USER' => 3, 'PHP_URL_PASS' => 4, 'PHP_URL_PATH' => 5,
            'PHP_URL_QUERY' => 6, 'PHP_URL_FRAGMENT' => 7,
            'PHP_QUERY_RFC1738' => 1, 'PHP_QUERY_RFC3986' => 2,
            // ext/sockets — host-INVARIANT constants (MEASURED identical on Darwin
            // and Linux, tools/docker/PROBE_RESULTS.md). The host-DIVERGENT ones
            // (AF_INET6, SOL_SOCKET, SO_*, the split MSG_*, SOCKET_E*) resolve
            // against the build host in the fold below, like PHP_OS/FNM_*.
            'AF_UNSPEC' => 0, 'AF_INET' => 2, 'AF_UNIX' => 1, 'PF_INET' => 2,
            'PF_UNIX' => 1, 'PF_UNSPEC' => 0,
            'SOCK_STREAM' => 1, 'SOCK_DGRAM' => 2, 'SOCK_RAW' => 3,
            'SOCK_SEQPACKET' => 5, 'SOCK_RDM' => 4,
            'IPPROTO_IP' => 0, 'IPPROTO_ICMP' => 1, 'IPPROTO_TCP' => 6,
            'IPPROTO_UDP' => 17, 'IPPROTO_IPV6' => 41, 'IPPROTO_RAW' => 255,
            'SOL_TCP' => 6, 'SOL_UDP' => 17,
            'TCP_NODELAY' => 1, 'SOMAXCONN' => 128,
            'SHUT_RD' => 0, 'SHUT_WR' => 1, 'SHUT_RDWR' => 2,
            'SCM_RIGHTS' => 1,   // ancillary fd passing — 1 on both hosts
            'MSG_OOB' => 1, 'MSG_PEEK' => 2, 'MSG_DONTROUTE' => 4, 'MSG_EOR' => 8,
            'PHP_NORMAL_READ' => 1, 'PHP_BINARY_READ' => 2,
            'AI_PASSIVE' => 1, 'AI_CANONNAME' => 2, 'AI_NUMERICHOST' => 4,
            // ext-filter — php's OWN constant values (host-invariant). filter_var
            // uses the *_VALIDATE_* / *_DEFAULT filter ids + the NULL_ON_FAILURE
            // and numeric flags.
            'INPUT_POST' => 0, 'INPUT_GET' => 1, 'INPUT_COOKIE' => 2,
            'INPUT_SERVER' => 5, 'INPUT_ENV' => 4,
            'FILTER_DEFAULT' => 516, 'FILTER_UNSAFE_RAW' => 516,
            'FILTER_FLAG_NONE' => 0, 'FILTER_CALLBACK' => 1024,
            'FILTER_VALIDATE_INT' => 257, 'FILTER_VALIDATE_BOOLEAN' => 258,
            'FILTER_VALIDATE_BOOL' => 258, 'FILTER_VALIDATE_FLOAT' => 259,
            'FILTER_VALIDATE_REGEXP' => 272, 'FILTER_VALIDATE_DOMAIN' => 277,
            'FILTER_VALIDATE_URL' => 273, 'FILTER_VALIDATE_EMAIL' => 274,
            'FILTER_VALIDATE_IP' => 275, 'FILTER_VALIDATE_MAC' => 276,
            'FILTER_SANITIZE_ENCODED' => 514, 'FILTER_SANITIZE_SPECIAL_CHARS' => 515,
            'FILTER_SANITIZE_FULL_SPECIAL_CHARS' => 522, 'FILTER_SANITIZE_EMAIL' => 517,
            'FILTER_SANITIZE_URL' => 518, 'FILTER_SANITIZE_NUMBER_INT' => 519,
            'FILTER_SANITIZE_NUMBER_FLOAT' => 520, 'FILTER_SANITIZE_ADD_SLASHES' => 523,
            'FILTER_REQUIRE_SCALAR' => 33554432, 'FILTER_REQUIRE_ARRAY' => 16777216,
            'FILTER_FORCE_ARRAY' => 67108864, 'FILTER_NULL_ON_FAILURE' => 134217728,
            'FILTER_FLAG_ALLOW_OCTAL' => 1, 'FILTER_FLAG_ALLOW_HEX' => 2,
            'FILTER_FLAG_STRIP_LOW' => 4, 'FILTER_FLAG_STRIP_HIGH' => 8,
            'FILTER_FLAG_ENCODE_LOW' => 16, 'FILTER_FLAG_ENCODE_HIGH' => 32,
            'FILTER_FLAG_ENCODE_AMP' => 64, 'FILTER_FLAG_ALLOW_FRACTION' => 4096,
            'FILTER_FLAG_ALLOW_THOUSAND' => 8192, 'FILTER_FLAG_ALLOW_SCIENTIFIC' => 16384,
            'FILTER_FLAG_IPV4' => 1048576, 'FILTER_FLAG_IPV6' => 2097152,
            'FILTER_FLAG_EMAIL_UNICODE' => 1048576,
            // ext-intl grapheme_extract() type selector — php's own values
            // (host-invariant). The intl-grapheme polyfill defines these behind
            // `if (!defined(...))`, redundant once predefined here.
            'GRAPHEME_EXTR_COUNT' => 0, 'GRAPHEME_EXTR_MAXBYTES' => 1,
            'GRAPHEME_EXTR_MAXCHARS' => 2,
            // ext-mbstring case-conversion modes + the (deprecated) overload
            // selector — php's own values (host-invariant).
            'MB_CASE_UPPER' => 0, 'MB_CASE_LOWER' => 1, 'MB_CASE_TITLE' => 2,
            'MB_CASE_FOLD' => 3, 'MB_CASE_UPPER_SIMPLE' => 4,
            'MB_CASE_LOWER_SIMPLE' => 5, 'MB_CASE_TITLE_SIMPLE' => 6,
            'MB_CASE_FOLD_SIMPLE' => 7, 'MB_OVERLOAD_STRING' => 2,
            // Linked pcre2 version — taken from the build host's pcre2 (the
            // static lib manticore links). 10.44+ so intl-grapheme uses the `\X`
            // grapheme-cluster shorthand.
            'PCRE_VERSION_MAJOR' => 10, 'PCRE_VERSION_MINOR' => 47,
        ];
        if (isset($ints[$name])) { return new IntConst($ints[$name], Type::int_()); }

        $floats = [
            'M_PI' => 3.14159265358979323846, 'M_E' => 2.7182818284590452354,
            'M_SQRT2' => 1.41421356237309504880, 'M_SQRT1_2' => 0.70710678118654752440,
            'M_SQRT3' => 1.7320508075688772935, 'M_2_SQRTPI' => 1.12837916709551257390,
            'M_SQRTPI' => 1.77245385090551602729, 'M_1_PI' => 0.31830988618379067154,
            'M_2_PI' => 0.63661977236758134308, 'M_PI_2' => 1.57079632679489661923,
            'M_PI_4' => 0.78539816339744830962, 'M_LN2' => 0.69314718055994530942,
            'M_LN10' => 2.30258509299404568402, 'M_LOG2E' => 1.4426950408889634074,
            'M_LOG10E' => 0.43429448190325182765, 'M_EULER' => 0.57721566490153286061,
            'PHP_FLOAT_EPSILON' => 2.2204460492503131E-16,
            'PHP_FLOAT_MAX' => 1.7976931348623157E+308,
            'PHP_FLOAT_MIN' => 2.2250738585072014E-308,
        ];
        if (isset($floats[$name])) { return new FloatConst($floats[$name], Type::float_()); }

        $strs = [
            'PHP_EOL' => "\n", 'DIRECTORY_SEPARATOR' => '/', 'PATH_SEPARATOR' => ':',
            'PHP_VERSION' => '8.5.3', 'PHP_SAPI' => 'cli', 'PHP_EXTRA_VERSION' => '',
            'PCRE_VERSION' => '10.47 2025-10-21',
            // No PHP interpreter beside a compiled binary — the PhpExecutableFinder
            // path is unreachable in a manticore build. Empty keeps references
            // compiling; a program that actually spawns `php` would need a real value.
            'PHP_BINARY' => '', 'PHP_BINDIR' => '', 'PHP_PEAR_PHP_BIN' => '',
        ];
        if (isset($strs[$name])) { return new StringConst($strs[$name], Type::string_()); }

        // Host-target OS, detected at compile time via libc uname(2) — the
        // sysname ("Darwin" / "Linux") is both PHP_OS and PHP_OS_FAMILY for the
        // two supported targets, matching the interpreter on the build host.
        if ($name === 'PHP_OS' || $name === 'PHP_OS_FAMILY') {
            return new StringConst(\Manticore\target_os_family(), Type::string_());
        }

        // fnmatch(3) flags. Unlike LOCK_*, php does NOT invent its own values
        // here — it exposes whatever the host's <fnmatch.h> says, and Darwin and
        // glibc disagree: FNM_NOESCAPE is 1 / FNM_PATHNAME is 2 on Darwin, and
        // the two are swapped on glibc. PERIOD/LEADING_DIR/CASEFOLD agree.
        // So these resolve against the build host, like PHP_OS above, and the
        // stdlib's fnmatch() passes the flags straight through to libc.
        //
        // Resolved HERE rather than in a plain table because host_os() cannot be
        // called from a path the stdlib itself walks: under the Zend seed the
        // libc bindings are empty stubs, so a compile-time host probe would kill
        // the cold bootstrap. Like PHP_OS, this stays safe only as long as no
        // stdlib source mentions an FNM_* name.
        if (\substr($name, 0, 4) === 'FNM_') {
            $isDarwin = \Manticore\is_darwin();
            $fnm = [
                'FNM_NOESCAPE' => $isDarwin ? 1 : 2,
                'FNM_PATHNAME' => $isDarwin ? 2 : 1,
                'FNM_FILE_NAME' => $isDarwin ? 2 : 1,
                'FNM_PERIOD' => 4,
                'FNM_LEADING_DIR' => 8,
                'FNM_CASEFOLD' => 16,
                'FNM_NOMATCH' => 1,
            ];
            if (isset($fnm[$name])) { return new IntConst($fnm[$name], Type::int_()); }
        }

        // setlocale() category selectors — host-DIVERGENT: Darwin's <locale.h>
        // orders the categories differently from glibc. Resolved against the
        // build host like FNM_* / the socket constants below.
        if (\substr($name, 0, 3) === 'LC_') {
            $isDarwin = \Manticore\is_darwin();
            $lc = [
                'LC_ALL' => $isDarwin ? 0 : 6,
                'LC_COLLATE' => $isDarwin ? 1 : 3,
                'LC_CTYPE' => $isDarwin ? 2 : 0,
                'LC_MONETARY' => $isDarwin ? 3 : 4,
                'LC_NUMERIC' => $isDarwin ? 4 : 1,
                'LC_TIME' => $isDarwin ? 5 : 2,
                'LC_MESSAGES' => $isDarwin ? 6 : 5,
            ];
            if (isset($lc[$name])) { return new IntConst($lc[$name], Type::int_()); }
        }

        // ext/posix resource limits. Host-exposed like FNM_* above (php reads the
        // host <sys/resource.h>; it does not invent these the way it does LOCK_*),
        // so they resolve against the build host — and no stdlib source may name
        // one: Stdlib/Pcntl.php uses the numeric __mc_rlimit_const() selector, whose
        // $which ORDER is the order of this table.
        //
        // MEASURED against each host's own php: CPU/FSIZE/DATA/STACK/CORE agree at
        // 0..4 and everything above diverges. INFINITY is what php reports, not what
        // the header says — Linux's RLIM_INFINITY is ~0UL, which php surfaces as
        // PHP_INT_MAX, and __mc_rlimit_get translates.
        if (\substr($name, 0, 13) === 'POSIX_RLIMIT_') {
            $isDarwin = \Manticore\is_darwin();
            $rl = [
                'POSIX_RLIMIT_CPU' => 0,
                'POSIX_RLIMIT_FSIZE' => 1,
                'POSIX_RLIMIT_DATA' => 2,
                'POSIX_RLIMIT_STACK' => 3,
                'POSIX_RLIMIT_CORE' => 4,
                'POSIX_RLIMIT_RSS' => 5,
                'POSIX_RLIMIT_MEMLOCK' => $isDarwin ? 6 : 8,
                'POSIX_RLIMIT_NPROC' => $isDarwin ? 7 : 6,
                'POSIX_RLIMIT_NOFILE' => $isDarwin ? 8 : 7,
                'POSIX_RLIMIT_AS' => $isDarwin ? 5 : 9,
                // MEASURED, and the one value that cannot be guessed: php hands out
                // the host's raw RLIM_INFINITY, so this is PHP_INT_MAX on Darwin but
                // -1 (~0UL read as an i64) on glibc. Assuming PHP_INT_MAX everywhere
                // made the constant wrong on Linux while every test stayed green on
                // macOS.
                'POSIX_RLIMIT_INFINITY' => $isDarwin ? \PHP_INT_MAX : -1,
            ];
            if (isset($rl[$name])) { return new IntConst($rl[$name], Type::int_()); }
        }

        // ext/pcntl signal numbers + the wait/mask flags. Host-DIVERGENT, and
        // resolved here for the same reason as FNM_* / SO_* below: php exposes
        // the host's own <signal.h>, and a compile-time host probe must not be
        // reachable from a path the stdlib walks (the Zend seed has stub libc
        // bindings). So NO stdlib source may name these — Stdlib/Pcntl.php uses
        // the numeric __mc_sig_const() selector instead.
        //
        // MEASURED, not recalled: a C probe compiled and run on Darwin arm64, on
        // gcc:13 (glibc) and on alpine:3.20 (musl). The two Linux libcs agree on
        // every value; Darwin differs on eleven of them, and on the SIG_BLOCK
        // family — the classic silent-on-one-host bug if guessed.
        if (\substr($name, 0, 4) === 'SIG_' || \substr($name, 0, 3) === 'SIG'
            || $name === 'WNOHANG' || $name === 'WUNTRACED') {
            $isDarwin = \Manticore\is_darwin();
            $sig = [
                // php's own handler sentinels, not the host's.
                'SIG_DFL' => 0, 'SIG_IGN' => 1, 'SIG_ERR' => -1,
                // how-argument to sigprocmask — DIFFERENT on the two hosts.
                'SIG_BLOCK' => $isDarwin ? 1 : 0,
                'SIG_UNBLOCK' => $isDarwin ? 2 : 1,
                'SIG_SETMASK' => $isDarwin ? 3 : 2,
                // Agreed by every host measured.
                'SIGHUP' => 1, 'SIGINT' => 2, 'SIGQUIT' => 3, 'SIGILL' => 4,
                'SIGTRAP' => 5, 'SIGABRT' => 6, 'SIGIOT' => 6, 'SIGFPE' => 8,
                'SIGKILL' => 9, 'SIGSEGV' => 11, 'SIGPIPE' => 13, 'SIGALRM' => 14,
                'SIGTERM' => 15, 'SIGTTIN' => 21, 'SIGTTOU' => 22, 'SIGXCPU' => 24,
                'SIGXFSZ' => 25, 'SIGVTALRM' => 26, 'SIGPROF' => 27, 'SIGWINCH' => 28,
                // Darwin / Linux disagree.
                'SIGBUS' => $isDarwin ? 10 : 7,
                'SIGUSR1' => $isDarwin ? 30 : 10,
                'SIGUSR2' => $isDarwin ? 31 : 12,
                'SIGCHLD' => $isDarwin ? 20 : 17,
                'SIGCLD' => $isDarwin ? 20 : 17,
                'SIGCONT' => $isDarwin ? 19 : 18,
                'SIGSTOP' => $isDarwin ? 17 : 19,
                'SIGTSTP' => $isDarwin ? 18 : 20,
                'SIGURG' => $isDarwin ? 16 : 23,
                'SIGSYS' => $isDarwin ? 12 : 31,
                'SIGIO' => $isDarwin ? 23 : 29,
                'SIGPOLL' => $isDarwin ? 23 : 29,
                'WNOHANG' => 1, 'WUNTRACED' => 2,
            ];
            // Host-only signals: php does not define the other host's either.
            if (!$isDarwin) {
                $sig['SIGPWR'] = 30;
                $sig['SIGSTKFLT'] = 16;
            } else {
                $sig['SIGEMT'] = 7;
                $sig['SIGINFO'] = 29;
            }
            if (isset($sig[$name])) { return new IntConst($sig[$name], Type::int_()); }
        }

        // ext/sockets — host-DIVERGENT constants. php exposes the host's own
        // <sys/socket.h> / errno values, and Darwin and Linux disagree on nearly
        // every one. Resolved against the build host like PHP_OS / FNM_* above,
        // and kept OUT of the plain table for the same reason: host_os() must not
        // be reachable from a path the stdlib itself walks (under the Zend seed the
        // libc bindings are empty stubs, so a compile-time host probe would kill the
        // cold bootstrap). So NO stdlib source may name these — Stdlib/Sockets.php
        // uses the numeric __mc_sock_const() runtime selector instead.
        // Values MEASURED: Darwin arm64 <sys/socket.h>/<sys/errno.h> vs Linux
        // asm-generic (glibc/musl, x86_64 + arm64 agree).
        if ($name === 'AF_INET6' || $name === 'PF_INET6' || $name === 'STREAM_PF_INET6'
            || $name === 'SOL_SOCKET'
            || \substr($name, 0, 3) === 'SO_' || \substr($name, 0, 4) === 'MSG_'
            || \substr($name, 0, 8) === 'SOCKET_E') {
            $isDarwin = \Manticore\is_darwin();
            $sock = [
                'AF_INET6' => $isDarwin ? 30 : 10,
                'PF_INET6' => $isDarwin ? 30 : 10,
                'STREAM_PF_INET6' => $isDarwin ? 30 : 10,
                'SOL_SOCKET' => $isDarwin ? 65535 : 1,
                'SO_DEBUG' => 1,
                'SO_REUSEADDR' => $isDarwin ? 4 : 2,
                'SO_REUSEPORT' => $isDarwin ? 512 : 15,
                'SO_TYPE' => $isDarwin ? 4104 : 3,
                'SO_ERROR' => $isDarwin ? 4103 : 4,
                'SO_DONTROUTE' => $isDarwin ? 16 : 5,
                'SO_BROADCAST' => $isDarwin ? 32 : 6,
                'SO_SNDBUF' => $isDarwin ? 4097 : 7,
                'SO_RCVBUF' => $isDarwin ? 4098 : 8,
                'SO_KEEPALIVE' => $isDarwin ? 8 : 9,
                'SO_OOBINLINE' => $isDarwin ? 256 : 10,
                'SO_LINGER' => $isDarwin ? 128 : 13,
                'SO_RCVLOWAT' => $isDarwin ? 4100 : 18,
                'SO_SNDLOWAT' => $isDarwin ? 4099 : 19,
                'SO_RCVTIMEO' => $isDarwin ? 4102 : 20,
                'SO_SNDTIMEO' => $isDarwin ? 4101 : 21,
                'SO_ACCEPTCONN' => $isDarwin ? 2 : 30,
                // MSG_* that diverge (the invariant MSG_OOB/PEEK/DONTROUTE/EOR are
                // in the plain table above).
                'MSG_TRUNC' => $isDarwin ? 16 : 32,
                'MSG_CTRUNC' => $isDarwin ? 32 : 8,
                'MSG_WAITALL' => $isDarwin ? 64 : 256,
                'MSG_DONTWAIT' => $isDarwin ? 128 : 64,
                // errno names ext/sockets exposes with a SOCKET_ prefix.
                'SOCKET_EAGAIN' => $isDarwin ? 35 : 11,
                'SOCKET_EWOULDBLOCK' => $isDarwin ? 35 : 11,
                'SOCKET_EINPROGRESS' => $isDarwin ? 36 : 115,
                'SOCKET_EINTR' => 4,
                'SOCKET_EPIPE' => 32,
                'SOCKET_ECONNREFUSED' => $isDarwin ? 61 : 111,
                'SOCKET_ECONNRESET' => $isDarwin ? 54 : 104,
                'SOCKET_ECONNABORTED' => $isDarwin ? 53 : 103,
                'SOCKET_EADDRINUSE' => $isDarwin ? 48 : 98,
                'SOCKET_EADDRNOTAVAIL' => $isDarwin ? 49 : 99,
                'SOCKET_ETIMEDOUT' => $isDarwin ? 60 : 110,
                'SOCKET_EHOSTUNREACH' => $isDarwin ? 65 : 113,
                'SOCKET_ENOTCONN' => $isDarwin ? 57 : 107,
                'SOCKET_EBADF' => 9,
                'SOCKET_EINVAL' => $isDarwin ? 22 : 22,
                'SOCKET_EMFILE' => $isDarwin ? 24 : 24,
                'SOCKET_ENFILE' => $isDarwin ? 23 : 23,
                'SOCKET_ENOBUFS' => $isDarwin ? 55 : 105,
                'SOCKET_ENOMEM' => $isDarwin ? 12 : 12,
                'SOCKET_EPROTO' => $isDarwin ? 100 : 71,
                'SOCKET_EACCES' => $isDarwin ? 13 : 13,
            ];
            if (isset($sock[$name])) { return new IntConst($sock[$name], Type::int_()); }
        }

        return null;
    }
}
