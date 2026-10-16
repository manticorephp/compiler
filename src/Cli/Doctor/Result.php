<?php

namespace Cli\Doctor;

/** One doctor check outcome. */
final class Result
{
    public const OK = 'ok';
    public const WARN = 'warn';
    public const FAIL = 'fail';

    public function __construct(
        public string $status,
        public string $name,
        public string $detail,
        public string $hint = '',
    ) {
    }
}
