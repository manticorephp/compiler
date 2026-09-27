<?php

declare(strict_types=1);

namespace Manticore\TestRunner;

final readonly class Text
{
    public const string ROW_RESET = "\033[0m";
    public const string COLOR_BOLD = "\033[1m";
    public const string COLOR_DIM = "\033[2m";
    public const string COLOR_GRAY = "\033[90m";
    public const string COLOR_GREEN = "\033[32m";
    public const string COLOR_RED = "\033[31m";
    public const string COLOR_YELLOW = "\033[33m";
    public const string COLOR_CYAN = "\033[36m";
    public const string COLOR_MAGENTA = "\033[35m";
    public const string BG_GREEN = "\033[42;30;1m";
    public const string BG_RED = "\033[41;37;1m";
}
