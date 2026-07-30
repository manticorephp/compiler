<?php
// @epic: reflection-tier4
// @why: doctrine maps backed enums to columns and symfony/form builds choices
//       from them, both through ReflectionEnum. It was built once and reverted
//       (docs/ROADMAP.md), so it is absent today.

enum CapStatus: string
{
    case Draft = 'draft';
    case Live = 'live';
}

var_dump(class_exists('ReflectionEnum'));
var_dump(enum_exists(CapStatus::class));
var_dump(CapStatus::from('live')->name);
var_dump(array_map(fn ($c) => $c->value, CapStatus::cases()));

$re = new ReflectionEnum(CapStatus::class);
var_dump($re->isBacked());
var_dump((string)$re->getBackingType());
var_dump(count($re->getCases()));
