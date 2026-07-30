<?php
// @epic: reflection-tier4
// @why: AttributeAutoconfigurationPass and any #[Route] subclassing pattern call
//       getAttributes(Base::class, ReflectionAttribute::IS_INSTANCEOF). The
//       constant exists in prelude/reflection.php but the filter is exact-name
//       match only, so a subclassed attribute is invisible.

#[Attribute]
class CapBaseAttr {}

#[Attribute]
class CapDerivedAttr extends CapBaseAttr {}

#[CapDerivedAttr]
final class CapTarget {}

$rc = new ReflectionClass(CapTarget::class);
var_dump(count($rc->getAttributes(CapDerivedAttr::class)));
var_dump(count($rc->getAttributes(CapBaseAttr::class)));
var_dump(count($rc->getAttributes(CapBaseAttr::class, ReflectionAttribute::IS_INSTANCEOF)));
