<?php

namespace Manticore\Attr;

/**
 * Mark the properties that ARE an object's value for `==` / `<=>`.
 *
 *   final class DateTime {
 *       #[\Manticore\Attr\CompareKey] private int $ts = 0;
 *       #[\Manticore\Attr\CompareKey] private int $us = 0;
 *       private string $zname = 'UTC';
 *   }
 *
 * Without it two objects of one class compare EVERY property in declaration
 * order (php's default handler). With it they compare only the marked ones,
 * in declaration order — and objects of DIFFERENT classes that both mark keys
 * compare too, which is how php's custom handlers that compare across classes
 * (a DateTime against a DateTimeImmutable, by instant) are expressed. See
 * {@see \Compile\MemoryAbi::CMP_GROUP_KEYED}.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class CompareKey
{
}
