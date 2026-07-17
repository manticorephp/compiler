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
 * Class, enum, trait and interface declarations → ClassDef: the layout, the
 * inherited members, the property hooks.
 *
 * A trait on the one {@see LowerFromAst} host — split by concern so a reader opens
 * the file for the thing they are looking at. State stays on the host.
 */
trait LowerClasses
{
    /**
     * Name of a class decl read through a ClassDecl-typed param. The
     * pre-pass holds the decl as a statically-unknown `$stmt->decl`;
     * reading `->name` off that erases to the wrong field offset under
     * self-host. Threading it through this typed param fixes the offset.
     */
    private function classDeclName(\Parser\Ast\ClassDecl $decl): string
    {
        return $decl->name;
    }

    /**
     * The declared shape of one method, straight off the AST. Type hints are kept
     * as the SOURCE STRING, not a lowered Type — see {@see \Compile\Mir\ParamMeta}.
     */
    private function buildMethodMeta(string $declaringClass, \Parser\Ast\MethodDecl $m): \Compile\Mir\MethodMeta
    {
        $params = [];
        foreach ($m->params as $p) {
            $params[] = new \Compile\Mir\ParamMeta(
                $p->name,
                $p->typeHint === null ? '' : $p->typeHint,
                $p->default !== null,
                $p->byRef,
                $p->variadic,
                $p->promoted,
                $this->attrNames($p->attributes),
            );
        }
        return new \Compile\Mir\MethodMeta(
            $m->name,
            $m->visibility === '' ? 'public' : $m->visibility,
            $m->isStatic,
            $m->isAbstract,
            $m->isFinal,
            $m->returnType === null ? '' : $m->returnType,
            $params,
            $this->attrNames($m->attributes),
            $declaringClass,
        );
    }

    /**
     * `use T { m as x; }` — the MethodDecl the alias renames. `$a->trait` is
     * optional in the syntax (`m as x;`), so an empty trait searches every used
     * trait, first match wins, which is what PHP's own resolution does when the
     * name is unambiguous.
     */
    private function traitAliasSource(\Parser\Ast\ClassDecl $decl, \Parser\Ast\TraitAdaptation $a): ?\Parser\Ast\MethodDecl
    {
        foreach ($decl->uses as $traitName) {
            $tn = \ltrim($traitName, '\\');
            if ($a->trait !== '' && $tn !== \ltrim($a->trait, '\\')) { continue; }
            $td = $this->traitTable[$tn] ?? null;
            if ($td === null) { continue; }
            foreach ($td->methods as $tm) {
                if ($tm->name === $a->method) { return $tm; }
            }
        }
        return null;
    }

    /** Attribute NAMES as written. No namespace resolution — the compiler's own
     *  attribute lookups are raw string matches, and this inherits that.
     *  @param \Parser\Ast\AttributeNode[] $attrs
     *  @return string[] */
    private function attrNames(array $attrs): array
    {
        $out = [];
        foreach ($attrs as $at) { $out[] = $at->name; }
        return $out;
    }

    private function buildClassDef(\Parser\Ast\ClassDecl $decl, int $classId): ClassDef
    {
        $this->currentDeclNamespace = $this->nsOf($decl->name);
        // Set the class context so `self` / `static` / `parent` in a
        // property or promoted-ctor-param type hint resolves to obj<Class>
        // (else lowerTypeHint erases it to unknown → method dispatch on the
        // property can't find a return flavor). Restored before return.
        $savedLowerClass = $this->currentLowerClass;
        $this->currentLowerClass = $decl->name;
        // `@template T` — in scope for every property / param / return hint of
        // this class, so `T` and `T[]` lower to a typevar rather than erasing.
        $savedTypeParams = $this->currentTypeParams;
        $savedTypeBounds = $this->currentTypeBounds;
        $savedTypeSubst = $this->currentTypeSubst;
        // A generic TRAIT's parameter is bound HERE, at the copy — see
        // {@see traitTypeSubst}. A REIFIED class's own parameter is bound the
        // same way ({@see LowerReify}): the copy IS the binding, so `T` lowers
        // straight to the concrete type and never becomes a type variable.
        $this->currentTypeSubst = $this->memberTypeSubst($decl);
        $this->pendingTypeBounds = [];
        $this->pendingTypeDefaults = [];
        $this->currentTypeParams = $this->docTemplates($decl->docComment);
        // Lower the bounds FIRST: `lowerTypeHint` consults them when it turns a
        // `T` into a typevar, and the typevar's erasure depends on them.
        $this->currentTypeBounds = [];
        foreach ($this->pendingTypeBounds as $tp => $hint) {
            $this->currentTypeBounds[$tp] = $this->lowerTypeHint($hint);
        }
        $names = [];
        $types = [];
        $arrHinted = [];
        $roProps = [];
        // Single inheritance: prepend the parent's properties so the
        // subclass instance shares the parent's field offsets, then
        // append the subclass's own. Parent is assumed already built
        // (declared earlier in source — the common case).
        $parent = '';
        if ($decl->extends !== []) {
            $parent = \ltrim($decl->extends[0], '\\');
        }
        if ($parent !== '' && isset($this->classTable[$parent])) {
            $pcd = $this->classTable[$parent];
            foreach ($pcd->propertyNames as $pn) {
                $names[] = $pn;
                $types[$pn] = $pcd->propertyTypes[$pn] ?? Type::unknown();
                $arrHinted[$pn] = $pcd->propertyArrayHinted[$pn] ?? false;
                // readonly is NOT propagated: `readonlyDeclClass` walks the parent
                // chain so the ORIGINAL declaring class drives the scope check
                // (only it may write the slot).
            }
        }
        foreach ($decl->methods as $m) {
            if ($m->name === '__construct') {
                foreach ($m->params as $p) {
                    if ($p->promoted !== '') {
                        $names[] = $p->name;
                        $pdoc = $this->docTagType($m->docComment, '@param', $p->name);
                        $peff = $this->effectiveHint($p->typeHint, $pdoc);
                        $types[$p->name] = $this->lowerTypeHint($peff);
                        $arrHinted[$p->name] = $this->isBareArrayHint($peff) || $types[$p->name]->isArray();
                        if (($p->promotedReadonly ?? false) || $decl->isReadonly) { $roProps[$p->name] = true; }
                    }
                }
            }
        }
        $spNames = [];
        $spTypes = [];
        foreach ($decl->properties as $prop) {
            $vdoc = $this->docTagType($prop->docComment, '@var', '');
            $veff = $this->effectiveHint($prop->typeHint, $vdoc);
            // `@var T[]` erases in the shared body (as it did before generics);
            // the binding lives at the use site, not in the class's layout.
            $pt = $this->lowerTypeHint($veff)->eraseTypeVars();
            // Bare `array` with no docblock: recover the element type from a
            // homogeneous list-literal default, else from how the class's methods
            // push into it (usage inference) — both keep reads typed instead of
            // erased. Static props keep the default-only recovery (no $this stores).
            if ($this->isBareArrayHint($veff)) {
                $this->propStoreStrKey = false;
                $elem = $prop->default !== null ? $this->inferBareArrayPropElem($prop->default) : null;
                if ($elem === null && !$prop->isStatic) {
                    $elem = $this->inferPropElemFromStores($decl, $prop->name);
                }
                if ($elem !== null) {
                    $pt = $this->propStoreStrKey ? Type::assoc(Type::string_(), $elem) : Type::vec($elem);
                }
            }
            if ($prop->isStatic) {
                // A REIFIED specialization declares NO static of its own: PHP has
                // ONE static slot per class, shared by every binding of it, and a
                // spec that registered its own would silently give each binding a
                // private counter. Registering nothing makes the declaring-class
                // walk (staticPropDeclClass) climb straight to the origin, which
                // owns the one slot. {@see LowerReify}
                if ($this->isReifiedDecl($decl)) { continue; }
                $spNames[] = $prop->name;
                $spTypes[] = $pt;
                $this->staticProps[$decl->name . '::' . $prop->name] = true;
                $this->staticPropTypes[$decl->name . '::' . $prop->name] = $pt;
                continue;
            }
            $names[] = $prop->name;
            $types[$prop->name] = $pt;
            $arrHinted[$prop->name] = $this->isBareArrayHint($veff) || $pt->isArray();
            if ($prop->isReadonly || $decl->isReadonly) { $roProps[$prop->name] = true; }
        }
        // Mixed-in trait properties extend the class's layout (PHP appends
        // them after the class's own fields). Without this they get no slot,
        // so `$this->traitProp` inside a trait method reads a wrong offset →
        // heap corruption (e.g. a string slot that lands on an obj/vec tag →
        // strcmp-on-RC_TAG_MAGIC abort). The class's own property wins on
        // conflict. Static trait props are not yet mixed in (rare).
        foreach ($decl->uses as $traitName) {
            $tn = \ltrim($traitName, '\\');
            $td = $this->traitTable[$tn] ?? null;
            if ($td === null) { continue; }
            foreach ($td->properties as $tprop) {
                if ($tprop->isStatic) { continue; }
                if (isset($types[$tprop->name])) { continue; }
                $tvdoc = $this->docTagType($tprop->docComment, '@var', '');
                $tveff = $this->effectiveHint($tprop->typeHint, $tvdoc);
                $names[] = $tprop->name;
                $types[$tprop->name] = $this->lowerTypeHint($tveff);
            }
        }
        // PHP 8.4 property hooks: inherit the parent's map, then record each
        // hooked property's get/set accessor symbol. The property keeps its
        // storage slot (allocated above) — a virtual hook just never uses it.
        $propHooks = [];
        if ($parent !== '' && isset($this->classTable[$parent])) {
            foreach ($this->classTable[$parent]->propHooks as $pn => $ph) {
                $propHooks[$pn] = $ph;
            }
        }
        $methodNames = [];
        foreach ($decl->properties as $prop) {
            if ($prop->isStatic || $prop->hooks === []) { continue; }
            // Empty-string = absent hook (never null — a null assoc value trips
            // the self-host missing-key-reads-false hazard downstream).
            $get = '';
            $set = '';
            foreach ($prop->hooks as $hook) {
                $sym = $decl->name . '____hook_' . $prop->name . '_' . $hook->kind;
                if ($hook->kind === 'get') { $get = $sym; $methodNames['__hook_' . $prop->name . '_get'] = true; }
                if ($hook->kind === 'set') { $set = $sym; $methodNames['__hook_' . $prop->name . '_set'] = true; }
            }
            $propHooks[$prop->name] = ['get' => $get, 'set' => $set];
        }
        $methodMeta = [];
        $ownMethods = [];
        foreach ($decl->methods as $m) {
            $methodNames[$m->name] = true;
            $ownMethods[$m->name] = true;
            $methodMeta[$m->name] = $this->buildMethodMeta($decl->name, $m);
        }
        // Mixed-in trait methods count as the class's own (for dispatch
        // + ctor resolution); the class's own method wins on conflict.
        // `use … { A::m insteadof B; }` excludes the loser; `m as x;` adds an alias.
        $excluded = $this->traitExclusions($decl);
        foreach ($decl->uses as $traitName) {
            $tn = \ltrim($traitName, '\\');
            $td = $this->traitTable[$tn] ?? null;
            if ($td === null) { continue; }
            foreach ($td->methods as $tm) {
                if (isset($excluded[$tn . '::' . $tm->name])) { continue; }
                if (!isset($methodNames[$tm->name])) { $methodNames[$tm->name] = true; }
                // A trait method mixed in is reported by PHP as declared on the
                // USING class, not the trait — unlike an inherited one. The
                // class's own declaration always wins; a trait's beats a parent's.
                if (!isset($ownMethods[$tm->name])) {
                    $methodMeta[$tm->name] = $this->buildMethodMeta($decl->name, $tm);
                }
            }
        }
        foreach ($decl->traitAdaptations as $a) {
            if ($a->kind === 'as' && $a->alias !== '') {
                $methodNames[$a->alias] = true;
                // The alias is the same declaration under a second name.
                $src = $this->traitAliasSource($decl, $a);
                if ($src !== null) {
                    $am = $this->buildMethodMeta($decl->name, $src);
                    $am->name = $a->alias;
                    if ($a->visibility !== '') { $am->visibility = $a->visibility; }
                    $methodMeta[$a->alias] = $am;
                }
            }
        }
        // Inherited methods LAST, and only where this class did not redeclare
        // them: PHP's getMethods() reports own-then-trait-then-inherited, and
        // that order is observable. A non-overridden method keeps its ancestor
        // as declaringClass; an override was already recorded above with this
        // class as the declarer.
        if ($parent !== '' && isset($this->classTable[$parent])) {
            foreach ($this->classTable[$parent]->methodMeta as $mn => $mm) {
                if (isset($methodMeta[$mn])) { continue; }
                $methodMeta[$mn] = $mm;
            }
        }
        // A class with defaulted properties but no user ctor gets a
        // synthesised one (see lowerClassMethods) — flag it so NewObj
        // calls it.
        foreach ($decl->properties as $prop) {
            if ($prop->isStatic) { continue; }
            if ($prop->default !== null) { $methodNames['__construct'] = true; break; }
        }
        // A defaulted trait property also needs the synthesised ctor to run.
        if (!isset($methodNames['__construct'])) {
            foreach ($decl->uses as $traitName) {
                $td = $this->traitTable[\ltrim($traitName, '\\')] ?? null;
                if ($td === null) { continue; }
                foreach ($td->properties as $tprop) {
                    if ($this->traitPropHasDefault($tprop)) { $methodNames['__construct'] = true; break; }
                }
            }
        }
        $ifaces = [];
        foreach ($decl->implements as $i) { $ifaces[] = \ltrim($i, '\\'); }
        // Register a module global cell per static property (initialised
        // to its literal default, or 0). Set the class context so a
        // default like `self::CONST` resolves against this class.
        $prevLowerClass = $this->currentLowerClass;
        $this->currentLowerClass = $decl->name;
        foreach ($decl->properties as $prop) {
            if (!$prop->isStatic) { continue; }
            // The origin already owns the one slot (see above) — a second global
            // per specialization is exactly the bug.
            if ($this->isReifiedDecl($decl)) { continue; }
            $def = $prop->default !== null ? $this->lowerExpr($prop->default) : new IntConst(0, Type::int_());
            // A PRELUDE class's cell must coalesce, not collide: the prelude is
            // compiled into every module, so stdlib.o and the user's .o both
            // define this symbol ({@see Module::$globalIsPrelude}).
            $this->module->addGlobalCell(
                '@' . $this->sanitizeSym($decl->name . '__sp_' . $prop->name),
                $def,
                $this->inPreludeClass,
            );
        }
        $this->currentLowerClass = $prevLowerClass;
        $isStruct = $this->hasStructAttr($decl->attributes);
        $hasBag = $this->hasDynamicPropsAttr($decl->attributes);
        $this->currentLowerClass = $savedLowerClass;
        $cd = new ClassDef($decl->name, $classId, $names, $types, $methodNames, $parent, $ifaces, $spNames, $spTypes, $isStruct, $hasBag, $propHooks);
        $cd->propertyArrayHinted = $arrHinted;
        $cd->propertyReadonly = $roProps;
        $cd->methodMeta = $methodMeta;
        $cd->attributes = $this->attrNames($decl->attributes);
        $cd->isFinal = $decl->isFinal;
        $cd->isAbstract = $decl->isAbstract;
        // Narrow slots: a property declared as a `#[TypeDef(repr: 'u8')]` occupies
        // ONE byte, not a word. This is where `repr` stops being a validated label
        // and starts costing what it says. In a register a narrow type buys nothing
        // — a register is 64 bits either way — so a slot is the only place the
        // promise can be kept.
        foreach ($names as $pname) {
            $pt = $types[$pname] ?? null;
            if ($pt === null) { continue; }
            $tdCls = $pt->typeDefClass();
            if ($tdCls === null) { continue; }
            $repr = $this->typeDefReprs[$tdCls] ?? '';
            $w = $this->reprWidth($repr);
            if ($w === 8) { continue; }
            $cd->propertyWidths[$pname] = $w;
            $cd->propertySigned[$pname] = $this->reprSigned($repr);
            $cd->propertyFloat32[$pname] = $repr === 'f32';
        }
        $cd->typeParams = $this->currentTypeParams;
        $cd->typeParamBounds = $this->currentTypeBounds;
        foreach ($this->pendingTypeDefaults as $tp => $hint) {
            $cd->typeParamDefaults[$tp] = $this->lowerTypeHint($hint);
        }
        // `@extends Base<T>` — how this class binds its generic parent. Read while
        // its own `@template` params are still in scope, so a `T` here resolves to
        // a typevar that climbing the chain can substitute.
        $ext = $this->docTagType($decl->docComment, '@extends', '');
        if ($ext !== null && $ext !== '') {
            $et = $this->lowerTypeHint($ext);
            if ($et->kind === Type::KIND_OBJ) { $cd->parentTypeArgs = $et->typeArgs; }
        }
        $this->currentTypeParams = $savedTypeParams;
        $this->currentTypeBounds = $savedTypeBounds;
        $this->currentTypeSubst = $savedTypeSubst;
        return $cd;
    }

    /**
     * Whether a (mixed-in trait) property carries a non-static default.
     * Reads through a typed PropertyDecl param so `->default` resolves the
     * right offset under self-host (T5 pattern — an untyped foreach element
     * mis-reads the nullable-Expr field).
     */
    private function traitPropHasDefault(\Parser\Ast\PropertyDecl $tp): bool
    {
        return !$tp->isStatic && $tp->default !== null;
    }

    /** Default-init store for one inherited (parent-declared) property (typed-param T5). */
    private function inheritedPropDefaultStore(\Parser\Ast\PropertyDecl $prop, string $className, Type $pt): StoreProperty
    {
        return new StoreProperty(
            new LoadLocal('this', Type::obj($className)),
            $prop->name,
            $this->lowerExpr($prop->default),
            $pt,
            $prop->hooks !== [],
        );
    }

    /** Default-init store for one mixed-in trait property (typed-param T5). */
    private function traitPropDefaultStore(\Parser\Ast\PropertyDecl $tp, string $className, Type $pt): StoreProperty
    {
        return new StoreProperty(
            new LoadLocal('this', Type::obj($className)),
            $tp->name,
            $this->lowerExpr($tp->default),
            $pt,
        );
    }

    /**
     * Lower each instance method into a `Class__method` MIR
     * function with `$this` as the implicit first param. The
     * constructor additionally gets synthetic `$this->p = $p`
     * stores for every promoted parameter, prepended to the body.
     */
    private function lowerClassMethods(\Parser\Ast\ClassDecl $decl, Module $module): void
    {
        $cd = $this->classTable[$decl->name];
        $this->currentLowerClass = $decl->name;
        // An ENUM reaches this path but has no ClassDef in the class table, so
        // guard on the table itself — reading `$cd->typeParams` unconditionally
        // dereferences null (a warning under Zend, a SIGSEGV once self-built).
        $this->currentTypeParams = [];
        $this->currentTypeBounds = [];
        $this->currentTypeSubst = $this->memberTypeSubst($decl);
        if (isset($this->classTable[$decl->name])) {
            $this->currentTypeParams = $this->classTable[$decl->name]->typeParams;
            $this->currentTypeBounds = $this->classTable[$decl->name]->typeParamBounds;
        }
        $this->currentDeclNamespace = $this->nsOf($decl->name);
        // Property-default stores (`public int $c = 7` → `$this->c = 7`),
        // applied at construction before the ctor body. Lowered into a
        // real function body so they pass through InferTypes like any
        // other node.
        $defaultStores = [];
        foreach ($decl->properties as $prop) {
            if ($prop->isStatic) { continue; }
            if ($prop->default === null) { continue; }
            $ptype = $cd->propertyTypes[$prop->name] ?? Type::unknown();
            // A hooked property's default initialises the backing slot DIRECTLY —
            // PHP does not route the default through the set hook.
            $defaultStores[] = new StoreProperty(
                new LoadLocal('this', Type::obj($decl->name)),
                $prop->name,
                $this->lowerExpr($prop->default),
                $ptype,
                $prop->hooks !== [],
            );
        }
        // Inherited property defaults: PHP applies EVERY declared default at
        // instantiation, not just the leaf class's. A subclass ctor (synthesized
        // or explicit) must therefore also initialise the parent chain's
        // defaulted properties — else an inherited `public string $k = 'x'` reads
        // back as 0/null on a subclass instance. Nearer class wins on name
        // conflict (a redeclaration, even without a default, shadows the parent).
        $seen = [];
        foreach ($decl->properties as $prop) { $seen[$prop->name] = true; }
        $pname = $decl->extends !== [] ? \ltrim($decl->extends[0], '\\') : '';
        $guard = 0;
        while ($pname !== '' && isset($this->classDecls[$pname]) && $guard < 256) {
            $pdecl = $this->classDecls[$pname];
            foreach ($pdecl->properties as $pprop) {
                if (isset($seen[$pprop->name])) { continue; }
                $seen[$pprop->name] = true;
                if ($pprop->isStatic) { continue; }
                if ($pprop->default === null) { continue; }
                $pptype = $cd->propertyTypes[$pprop->name] ?? Type::unknown();
                $defaultStores[] = $this->inheritedPropDefaultStore($pprop, $decl->name, $pptype);
            }
            $pname = $pdecl->extends !== [] ? \ltrim($pdecl->extends[0], '\\') : '';
            $guard = $guard + 1;
        }
        // Mixed-in trait property defaults too — the layout merge in
        // buildClassDef gives them a slot, but without these stores a
        // defaulted trait property is never initialized (uninitialized read,
        // e.g. a string slot → SIGSEGV). Field access goes through typed
        // helpers (T5). Class's own property wins.
        $ownPropNames = [];
        foreach ($decl->properties as $prop) { $ownPropNames[$prop->name] = true; }
        foreach ($decl->uses as $traitName) {
            $tn = \ltrim($traitName, '\\');
            $td = $this->traitTable[$tn] ?? null;
            if ($td === null) { continue; }
            foreach ($td->properties as $tprop) {
                if (isset($ownPropNames[$tprop->name])) { continue; }
                if (!$this->traitPropHasDefault($tprop)) { continue; }
                $tptype = $cd->propertyTypes[$tprop->name] ?? Type::unknown();
                $defaultStores[] = $this->traitPropDefaultStore($tprop, $decl->name, $tptype);
            }
        }
        // Augment with mixed-in trait methods (the class's own method
        // wins on conflict — trait_override). Each trait method is
        // lowered as `Class__method` with $this typed as the class, so
        // `$this->prop` resolves against the class layout.
        // Copy into a fresh vec — `$methods = $decl->methods` would ALIAS
        // the ClassDecl's own methods vec (no COW self-host); the trait
        // append below reallocs an empty vec, freeing the buffer that
        // `$this->classDecls[$class]->methods` still points to → UAF.
        $methods = [];
        foreach ($decl->methods as $m) { $methods[] = $m; }
        // Synthesize a method per property hook: `<prop>` get/set hooks become
        // `__hook_<prop>_get` / `__hook_<prop>_set`, lowered like any method so
        // `$this` / `$value` and the body pass through InferTypes. A get arrow
        // returns the expr; a set arrow stores the expr to the backing slot.
        // Both bodies reference `$this-><prop>` DIRECTLY (EmitLlvm's hook
        // dispatch suppresses re-entry while emitting the property's own hook).
        foreach ($decl->properties as $prop) {
            if ($prop->isStatic || $prop->hooks === []) { continue; }
            foreach ($prop->hooks as $hook) {
                $sp = $prop->span;
                if ($hook->kind === 'get') {
                    $body = $hook->blockBody
                        ?? new \Parser\Ast\Block([\Parser\Ast\Stmt::return_($hook->exprBody, $sp)]);
                    $methods[] = new \Parser\Ast\MethodDecl(
                        '__hook_' . $prop->name . '_get',
                        'public', false, false, false, [],
                        $prop->typeHint, $body, [], $sp,
                    );
                } else {
                    $pname = $hook->paramName ?? 'value';
                    $ptypeHint = $hook->paramType ?? $prop->typeHint;
                    $param = new \Parser\Ast\Param($pname, $ptypeHint, null, false, false, '', false, [], $sp);
                    $body = $hook->blockBody
                        ?? new \Parser\Ast\Block([\Parser\Ast\Stmt::expression(
                            \Parser\Ast\Expr::assign(
                                \Parser\Ast\Expr::propertyAccess(
                                    \Parser\Ast\Expr::variable('this', $sp), $prop->name, false, $sp),
                                $hook->exprBody, $sp),
                            $sp)]);
                    $methods[] = new \Parser\Ast\MethodDecl(
                        '__hook_' . $prop->name . '_set',
                        'public', false, false, false, [$param],
                        null, $body, [], $sp,
                    );
                }
            }
        }
        $ownNames = [];
        foreach ($decl->methods as $m) { $ownNames[$m->name] = true; }
        $excluded = $this->traitExclusions($decl);
        foreach ($decl->uses as $traitName) {
            $tn = \ltrim($traitName, '\\');
            $td = $this->traitTable[$tn] ?? null;
            if ($td === null) { continue; }
            foreach ($td->methods as $tm) {
                if (isset($excluded[$tn . '::' . $tm->name])) { continue; }
                if (!isset($ownNames[$tm->name])) { $methods[] = $tm; }
            }
        }
        // `m as alias` / `A::m as alias`: emit a renamed copy of the source
        // trait method (a visibility change without an alias is not enforced).
        foreach ($decl->traitAdaptations as $a) {
            if ($a->kind !== 'as' || $a->alias === '') { continue; }
            $src = $this->findTraitMethod($decl, \ltrim($a->trait, '\\'), $a->method);
            if ($src === null || isset($ownNames[$a->alias])) { continue; }
            $methods[] = new \Parser\Ast\MethodDecl(
                $a->alias, $src->visibility, $src->isStatic, $src->isFinal, $src->isAbstract,
                $src->params, $src->returnType, $src->body, $src->attributes, $src->span,
                $src->returnsByRef, $src->docComment,
            );
        }
        $sawCtor = false;
        $isTypeDefDecl = $this->isTypeDef($decl->name);
        foreach ($methods as $m) {
            if ($m->body === null) { continue; }
            // A `#[TypeDef]`'s constructor is Zend-only glue: it exists so `new C($x)`
            // builds a real object when `php` runs the same source. The compiler never
            // needs it — `new` lowers to the normaliser (`__invoke`) or, for the bare
            // promoted-property shape, to the argument itself.
            if ($isTypeDefDecl && $m->name === '__construct') { continue; }
            if ($m->name === '__construct') { $sawCtor = true; }
            // Normal copy: late-static scope == the lexical class.
            $mfn = $this->lowerMethodFn(
                $decl, $m, $cd, $defaultStores,
                $decl->name, $decl->name . '__' . $m->name,
            );
            $module->addFunction($mfn);
            $sep = $m->isStatic ? '::' : '->';
            $module->methodDisplay[$decl->name . '__' . $m->name] =
                $decl->name . $sep . $m->name;
            // Methods that reference `static` (`static::class`, `new static`,
            // `static::method()`) bind late: a subclass that inherits this
            // body must see itself as `static`. Queue per-descendant copies.
            if ($this->sawStaticUse) {
                $this->lsbPending[] = new LsbPending($decl, $m, $cd, $defaultStores);
            }
        }
        // A `#[TypeDef]` is constructed through exactly ONE function — its
        // normaliser `C____invoke(carrier): carrier`. The promoted-property shape
        // declares none, because it needs none, so synthesise the identity. Making
        // the two shapes uniform is not tidiness: `new U8(5)` must produce a value
        // TAGGED `U8`, and lowering it to the bare argument node would hand back a
        // plain `int` — the tag lost, and `$byte->raw()` then resolving against no
        // class at all (`@manticore___raw`). A call carries the callee's declared
        // return type, and that type is the tag. LLVM inlines `ret i64 %value` away.
        if ($isTypeDefDecl && !isset($cd->methodNames['__invoke'])) {
            $carrier = $this->typeDefCarrier($decl->name);
            $module->addFunction(new FunctionDef(
                name: $decl->name . '____invoke',
                params: [new Param(
                    name: 'raw',
                    type: $carrier->stripTypeDef(),
                    byRef: false,
                    variadic: false,
                )],
                returnType: $carrier,
                body: new Block(
                    [new Return_(new LoadLocal('raw', $carrier->stripTypeDef()), $carrier)],
                    Type::void(),
                ),
            ));
        }
        // No user ctor but defaulted properties → synthesise a ctor
        // (`Class____construct($this)`) holding just the default stores.
        if (!$sawCtor && $defaultStores !== []) {
            $module->addFunction(new FunctionDef(
                name: $decl->name . '____construct',
                params: [new Param(
                    name: 'this',
                    type: Type::obj($decl->name),
                    byRef: false,
                    variadic: false,
                )],
                returnType: Type::void(),
                body: new Block($defaultStores, Type::void()),
            ));
        }
    }

    /**
     * Resolve `Class::CONST` to its initializer expression, walking the
     * class's own consts, its trait uses, then its parent chain. Null if
     * unknown.
     */
    private function findClassConst(string $className, string $name): ?\Parser\Ast\Expr
    {
        // Class or trait — a trait can declare consts the using class
        // inherits (`self::CONST` inside a mixed-in trait method).
        $decl = $this->classDecls[$className] ?? ($this->traitTable[$className] ?? null);
        if ($decl !== null) {
            foreach ($decl->consts as $const) {
                if ($const->name === $name) { return $const->value; }
            }
            foreach ($decl->uses as $traitName) {
                $tn = \ltrim($traitName, '\\');
                $found = $this->findClassConst($tn, $name);
                if ($found !== null) { return $found; }
            }
            if ($decl->extends !== []) {
                $found = $this->findClassConst(\ltrim($decl->extends[0], '\\'), $name);
                if ($found !== null) { return $found; }
            }
            // Implemented interfaces (and extended interfaces, via `implements`
            // populated by the parser for interface `extends`) carry consts too.
            foreach ($decl->implements as $ifaceName) {
                $found = $this->findClassConst(\ltrim($ifaceName, '\\'), $name);
                if ($found !== null) { return $found; }
            }
        }
        return null;
    }
}
