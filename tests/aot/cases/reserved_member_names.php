<?php
// @epic: parser-gaps
// @why: php lets a RESERVED WORD name a class constant or an enum case.
//       doctrine/dbal spells `case ARRAY = 'array';` in ArrayParameterType and
//       `const ARRAY = 'array';` in Types; nette/utils does the same. The lexer
//       classifies those as Keyword, not Identifier, so the parser rejected code
//       the interpreter accepts — six sites in symfony-demo alone.

enum ParamType: string
{
    case ARRAY = 'array';
    case STRING = 'string';
    case LIST = 'list';
    case MATCH = 'match';
    case CLASS_ = 'class_';
}

final class Types
{
    const ARRAY = 'array';
    const LIST = 'list';
    const PRINT = 'print';
    const DEFAULT = 'default';
    const PLAIN = 'plain';
}

echo ParamType::ARRAY->value, "\n";
echo ParamType::LIST->value, "\n";
echo ParamType::MATCH->value, "\n";
echo ParamType::from('string')->name, "\n";
var_dump(count(ParamType::cases()));

echo Types::ARRAY, "\n";
echo Types::LIST, "\n";
echo Types::PRINT, "\n";
echo Types::DEFAULT, "\n";

// A reserved word still cannot name a CLASS — that stays a php syntax error,
// and this case does not assert otherwise.
var_dump(Types::PLAIN);
