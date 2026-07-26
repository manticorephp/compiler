<?php

/**
 * HTML entities: htmlspecialchars_decode, htmlentities, html_entity_decode and
 * strip_tags. Byte-oriented, no FFI, like the rest of the string stdlib.
 *
 * The named-entity table is the HTML 4.01 set php uses by default
 * (get_html_translation_table(HTML_ENTITIES, ENT_QUOTES|ENT_HTML401, "UTF-8")),
 * generated from php itself so the mapping is exact by construction rather than
 * transcribed. 252 named entities plus the numeric forms; the HTML5-only names
 * are deliberately out (php only decodes those under ENT_HTML5).
 *
 * Flags follow php: bit 1 = double quotes, bit 2 = single quotes
 * (ENT_COMPAT = 2, ENT_QUOTES = 3, ENT_NOQUOTES = 0).
 */

/** @return array<string,string> UTF-8 char => entity NAME (no & or ;) */
function __mc_html_entities_enc(): array
{
    return [
        "\x22" => "quot",
        "\x26" => "amp",
        "\x3C" => "lt",
        "\x3E" => "gt",
        "\xC2\xA0" => "nbsp",
        "\xC2\xA1" => "iexcl",
        "\xC2\xA2" => "cent",
        "\xC2\xA3" => "pound",
        "\xC2\xA4" => "curren",
        "\xC2\xA5" => "yen",
        "\xC2\xA6" => "brvbar",
        "\xC2\xA7" => "sect",
        "\xC2\xA8" => "uml",
        "\xC2\xA9" => "copy",
        "\xC2\xAA" => "ordf",
        "\xC2\xAB" => "laquo",
        "\xC2\xAC" => "not",
        "\xC2\xAD" => "shy",
        "\xC2\xAE" => "reg",
        "\xC2\xAF" => "macr",
        "\xC2\xB0" => "deg",
        "\xC2\xB1" => "plusmn",
        "\xC2\xB2" => "sup2",
        "\xC2\xB3" => "sup3",
        "\xC2\xB4" => "acute",
        "\xC2\xB5" => "micro",
        "\xC2\xB6" => "para",
        "\xC2\xB7" => "middot",
        "\xC2\xB8" => "cedil",
        "\xC2\xB9" => "sup1",
        "\xC2\xBA" => "ordm",
        "\xC2\xBB" => "raquo",
        "\xC2\xBC" => "frac14",
        "\xC2\xBD" => "frac12",
        "\xC2\xBE" => "frac34",
        "\xC2\xBF" => "iquest",
        "\xC3\x80" => "Agrave",
        "\xC3\x81" => "Aacute",
        "\xC3\x82" => "Acirc",
        "\xC3\x83" => "Atilde",
        "\xC3\x84" => "Auml",
        "\xC3\x85" => "Aring",
        "\xC3\x86" => "AElig",
        "\xC3\x87" => "Ccedil",
        "\xC3\x88" => "Egrave",
        "\xC3\x89" => "Eacute",
        "\xC3\x8A" => "Ecirc",
        "\xC3\x8B" => "Euml",
        "\xC3\x8C" => "Igrave",
        "\xC3\x8D" => "Iacute",
        "\xC3\x8E" => "Icirc",
        "\xC3\x8F" => "Iuml",
        "\xC3\x90" => "ETH",
        "\xC3\x91" => "Ntilde",
        "\xC3\x92" => "Ograve",
        "\xC3\x93" => "Oacute",
        "\xC3\x94" => "Ocirc",
        "\xC3\x95" => "Otilde",
        "\xC3\x96" => "Ouml",
        "\xC3\x97" => "times",
        "\xC3\x98" => "Oslash",
        "\xC3\x99" => "Ugrave",
        "\xC3\x9A" => "Uacute",
        "\xC3\x9B" => "Ucirc",
        "\xC3\x9C" => "Uuml",
        "\xC3\x9D" => "Yacute",
        "\xC3\x9E" => "THORN",
        "\xC3\x9F" => "szlig",
        "\xC3\xA0" => "agrave",
        "\xC3\xA1" => "aacute",
        "\xC3\xA2" => "acirc",
        "\xC3\xA3" => "atilde",
        "\xC3\xA4" => "auml",
        "\xC3\xA5" => "aring",
        "\xC3\xA6" => "aelig",
        "\xC3\xA7" => "ccedil",
        "\xC3\xA8" => "egrave",
        "\xC3\xA9" => "eacute",
        "\xC3\xAA" => "ecirc",
        "\xC3\xAB" => "euml",
        "\xC3\xAC" => "igrave",
        "\xC3\xAD" => "iacute",
        "\xC3\xAE" => "icirc",
        "\xC3\xAF" => "iuml",
        "\xC3\xB0" => "eth",
        "\xC3\xB1" => "ntilde",
        "\xC3\xB2" => "ograve",
        "\xC3\xB3" => "oacute",
        "\xC3\xB4" => "ocirc",
        "\xC3\xB5" => "otilde",
        "\xC3\xB6" => "ouml",
        "\xC3\xB7" => "divide",
        "\xC3\xB8" => "oslash",
        "\xC3\xB9" => "ugrave",
        "\xC3\xBA" => "uacute",
        "\xC3\xBB" => "ucirc",
        "\xC3\xBC" => "uuml",
        "\xC3\xBD" => "yacute",
        "\xC3\xBE" => "thorn",
        "\xC3\xBF" => "yuml",
        "\xC5\x92" => "OElig",
        "\xC5\x93" => "oelig",
        "\xC5\xA0" => "Scaron",
        "\xC5\xA1" => "scaron",
        "\xC5\xB8" => "Yuml",
        "\xC6\x92" => "fnof",
        "\xCB\x86" => "circ",
        "\xCB\x9C" => "tilde",
        "\xCE\x91" => "Alpha",
        "\xCE\x92" => "Beta",
        "\xCE\x93" => "Gamma",
        "\xCE\x94" => "Delta",
        "\xCE\x95" => "Epsilon",
        "\xCE\x96" => "Zeta",
        "\xCE\x97" => "Eta",
        "\xCE\x98" => "Theta",
        "\xCE\x99" => "Iota",
        "\xCE\x9A" => "Kappa",
        "\xCE\x9B" => "Lambda",
        "\xCE\x9C" => "Mu",
        "\xCE\x9D" => "Nu",
        "\xCE\x9E" => "Xi",
        "\xCE\x9F" => "Omicron",
        "\xCE\xA0" => "Pi",
        "\xCE\xA1" => "Rho",
        "\xCE\xA3" => "Sigma",
        "\xCE\xA4" => "Tau",
        "\xCE\xA5" => "Upsilon",
        "\xCE\xA6" => "Phi",
        "\xCE\xA7" => "Chi",
        "\xCE\xA8" => "Psi",
        "\xCE\xA9" => "Omega",
        "\xCE\xB1" => "alpha",
        "\xCE\xB2" => "beta",
        "\xCE\xB3" => "gamma",
        "\xCE\xB4" => "delta",
        "\xCE\xB5" => "epsilon",
        "\xCE\xB6" => "zeta",
        "\xCE\xB7" => "eta",
        "\xCE\xB8" => "theta",
        "\xCE\xB9" => "iota",
        "\xCE\xBA" => "kappa",
        "\xCE\xBB" => "lambda",
        "\xCE\xBC" => "mu",
        "\xCE\xBD" => "nu",
        "\xCE\xBE" => "xi",
        "\xCE\xBF" => "omicron",
        "\xCF\x80" => "pi",
        "\xCF\x81" => "rho",
        "\xCF\x82" => "sigmaf",
        "\xCF\x83" => "sigma",
        "\xCF\x84" => "tau",
        "\xCF\x85" => "upsilon",
        "\xCF\x86" => "phi",
        "\xCF\x87" => "chi",
        "\xCF\x88" => "psi",
        "\xCF\x89" => "omega",
        "\xCF\x91" => "thetasym",
        "\xCF\x92" => "upsih",
        "\xCF\x96" => "piv",
        "\xE2\x80\x82" => "ensp",
        "\xE2\x80\x83" => "emsp",
        "\xE2\x80\x89" => "thinsp",
        "\xE2\x80\x8C" => "zwnj",
        "\xE2\x80\x8D" => "zwj",
        "\xE2\x80\x8E" => "lrm",
        "\xE2\x80\x8F" => "rlm",
        "\xE2\x80\x93" => "ndash",
        "\xE2\x80\x94" => "mdash",
        "\xE2\x80\x98" => "lsquo",
        "\xE2\x80\x99" => "rsquo",
        "\xE2\x80\x9A" => "sbquo",
        "\xE2\x80\x9C" => "ldquo",
        "\xE2\x80\x9D" => "rdquo",
        "\xE2\x80\x9E" => "bdquo",
        "\xE2\x80\xA0" => "dagger",
        "\xE2\x80\xA1" => "Dagger",
        "\xE2\x80\xA2" => "bull",
        "\xE2\x80\xA6" => "hellip",
        "\xE2\x80\xB0" => "permil",
        "\xE2\x80\xB2" => "prime",
        "\xE2\x80\xB3" => "Prime",
        "\xE2\x80\xB9" => "lsaquo",
        "\xE2\x80\xBA" => "rsaquo",
        "\xE2\x80\xBE" => "oline",
        "\xE2\x81\x84" => "frasl",
        "\xE2\x82\xAC" => "euro",
        "\xE2\x84\x91" => "image",
        "\xE2\x84\x98" => "weierp",
        "\xE2\x84\x9C" => "real",
        "\xE2\x84\xA2" => "trade",
        "\xE2\x84\xB5" => "alefsym",
        "\xE2\x86\x90" => "larr",
        "\xE2\x86\x91" => "uarr",
        "\xE2\x86\x92" => "rarr",
        "\xE2\x86\x93" => "darr",
        "\xE2\x86\x94" => "harr",
        "\xE2\x86\xB5" => "crarr",
        "\xE2\x87\x90" => "lArr",
        "\xE2\x87\x91" => "uArr",
        "\xE2\x87\x92" => "rArr",
        "\xE2\x87\x93" => "dArr",
        "\xE2\x87\x94" => "hArr",
        "\xE2\x88\x80" => "forall",
        "\xE2\x88\x82" => "part",
        "\xE2\x88\x83" => "exist",
        "\xE2\x88\x85" => "empty",
        "\xE2\x88\x87" => "nabla",
        "\xE2\x88\x88" => "isin",
        "\xE2\x88\x89" => "notin",
        "\xE2\x88\x8B" => "ni",
        "\xE2\x88\x8F" => "prod",
        "\xE2\x88\x91" => "sum",
        "\xE2\x88\x92" => "minus",
        "\xE2\x88\x97" => "lowast",
        "\xE2\x88\x9A" => "radic",
        "\xE2\x88\x9D" => "prop",
        "\xE2\x88\x9E" => "infin",
        "\xE2\x88\xA0" => "ang",
        "\xE2\x88\xA7" => "and",
        "\xE2\x88\xA8" => "or",
        "\xE2\x88\xA9" => "cap",
        "\xE2\x88\xAA" => "cup",
        "\xE2\x88\xAB" => "int",
        "\xE2\x88\xB4" => "there4",
        "\xE2\x88\xBC" => "sim",
        "\xE2\x89\x85" => "cong",
        "\xE2\x89\x88" => "asymp",
        "\xE2\x89\xA0" => "ne",
        "\xE2\x89\xA1" => "equiv",
        "\xE2\x89\xA4" => "le",
        "\xE2\x89\xA5" => "ge",
        "\xE2\x8A\x82" => "sub",
        "\xE2\x8A\x83" => "sup",
        "\xE2\x8A\x84" => "nsub",
        "\xE2\x8A\x86" => "sube",
        "\xE2\x8A\x87" => "supe",
        "\xE2\x8A\x95" => "oplus",
        "\xE2\x8A\x97" => "otimes",
        "\xE2\x8A\xA5" => "perp",
        "\xE2\x8B\x85" => "sdot",
        "\xE2\x8C\x88" => "lceil",
        "\xE2\x8C\x89" => "rceil",
        "\xE2\x8C\x8A" => "lfloor",
        "\xE2\x8C\x8B" => "rfloor",
        "\xE2\x8C\xA9" => "lang",
        "\xE2\x8C\xAA" => "rang",
        "\xE2\x97\x8A" => "loz",
        "\xE2\x99\xA0" => "spades",
        "\xE2\x99\xA3" => "clubs",
        "\xE2\x99\xA5" => "hearts",
        "\xE2\x99\xA6" => "diams",
    ];
}

/** @return array<string,string> entity NAME => UTF-8 char */
function __mc_html_entities_dec(): array
{
    return [
        "quot" => "\x22",
        "amp" => "\x26",
        "lt" => "\x3C",
        "gt" => "\x3E",
        "nbsp" => "\xC2\xA0",
        "iexcl" => "\xC2\xA1",
        "cent" => "\xC2\xA2",
        "pound" => "\xC2\xA3",
        "curren" => "\xC2\xA4",
        "yen" => "\xC2\xA5",
        "brvbar" => "\xC2\xA6",
        "sect" => "\xC2\xA7",
        "uml" => "\xC2\xA8",
        "copy" => "\xC2\xA9",
        "ordf" => "\xC2\xAA",
        "laquo" => "\xC2\xAB",
        "not" => "\xC2\xAC",
        "shy" => "\xC2\xAD",
        "reg" => "\xC2\xAE",
        "macr" => "\xC2\xAF",
        "deg" => "\xC2\xB0",
        "plusmn" => "\xC2\xB1",
        "sup2" => "\xC2\xB2",
        "sup3" => "\xC2\xB3",
        "acute" => "\xC2\xB4",
        "micro" => "\xC2\xB5",
        "para" => "\xC2\xB6",
        "middot" => "\xC2\xB7",
        "cedil" => "\xC2\xB8",
        "sup1" => "\xC2\xB9",
        "ordm" => "\xC2\xBA",
        "raquo" => "\xC2\xBB",
        "frac14" => "\xC2\xBC",
        "frac12" => "\xC2\xBD",
        "frac34" => "\xC2\xBE",
        "iquest" => "\xC2\xBF",
        "Agrave" => "\xC3\x80",
        "Aacute" => "\xC3\x81",
        "Acirc" => "\xC3\x82",
        "Atilde" => "\xC3\x83",
        "Auml" => "\xC3\x84",
        "Aring" => "\xC3\x85",
        "AElig" => "\xC3\x86",
        "Ccedil" => "\xC3\x87",
        "Egrave" => "\xC3\x88",
        "Eacute" => "\xC3\x89",
        "Ecirc" => "\xC3\x8A",
        "Euml" => "\xC3\x8B",
        "Igrave" => "\xC3\x8C",
        "Iacute" => "\xC3\x8D",
        "Icirc" => "\xC3\x8E",
        "Iuml" => "\xC3\x8F",
        "ETH" => "\xC3\x90",
        "Ntilde" => "\xC3\x91",
        "Ograve" => "\xC3\x92",
        "Oacute" => "\xC3\x93",
        "Ocirc" => "\xC3\x94",
        "Otilde" => "\xC3\x95",
        "Ouml" => "\xC3\x96",
        "times" => "\xC3\x97",
        "Oslash" => "\xC3\x98",
        "Ugrave" => "\xC3\x99",
        "Uacute" => "\xC3\x9A",
        "Ucirc" => "\xC3\x9B",
        "Uuml" => "\xC3\x9C",
        "Yacute" => "\xC3\x9D",
        "THORN" => "\xC3\x9E",
        "szlig" => "\xC3\x9F",
        "agrave" => "\xC3\xA0",
        "aacute" => "\xC3\xA1",
        "acirc" => "\xC3\xA2",
        "atilde" => "\xC3\xA3",
        "auml" => "\xC3\xA4",
        "aring" => "\xC3\xA5",
        "aelig" => "\xC3\xA6",
        "ccedil" => "\xC3\xA7",
        "egrave" => "\xC3\xA8",
        "eacute" => "\xC3\xA9",
        "ecirc" => "\xC3\xAA",
        "euml" => "\xC3\xAB",
        "igrave" => "\xC3\xAC",
        "iacute" => "\xC3\xAD",
        "icirc" => "\xC3\xAE",
        "iuml" => "\xC3\xAF",
        "eth" => "\xC3\xB0",
        "ntilde" => "\xC3\xB1",
        "ograve" => "\xC3\xB2",
        "oacute" => "\xC3\xB3",
        "ocirc" => "\xC3\xB4",
        "otilde" => "\xC3\xB5",
        "ouml" => "\xC3\xB6",
        "divide" => "\xC3\xB7",
        "oslash" => "\xC3\xB8",
        "ugrave" => "\xC3\xB9",
        "uacute" => "\xC3\xBA",
        "ucirc" => "\xC3\xBB",
        "uuml" => "\xC3\xBC",
        "yacute" => "\xC3\xBD",
        "thorn" => "\xC3\xBE",
        "yuml" => "\xC3\xBF",
        "OElig" => "\xC5\x92",
        "oelig" => "\xC5\x93",
        "Scaron" => "\xC5\xA0",
        "scaron" => "\xC5\xA1",
        "Yuml" => "\xC5\xB8",
        "fnof" => "\xC6\x92",
        "circ" => "\xCB\x86",
        "tilde" => "\xCB\x9C",
        "Alpha" => "\xCE\x91",
        "Beta" => "\xCE\x92",
        "Gamma" => "\xCE\x93",
        "Delta" => "\xCE\x94",
        "Epsilon" => "\xCE\x95",
        "Zeta" => "\xCE\x96",
        "Eta" => "\xCE\x97",
        "Theta" => "\xCE\x98",
        "Iota" => "\xCE\x99",
        "Kappa" => "\xCE\x9A",
        "Lambda" => "\xCE\x9B",
        "Mu" => "\xCE\x9C",
        "Nu" => "\xCE\x9D",
        "Xi" => "\xCE\x9E",
        "Omicron" => "\xCE\x9F",
        "Pi" => "\xCE\xA0",
        "Rho" => "\xCE\xA1",
        "Sigma" => "\xCE\xA3",
        "Tau" => "\xCE\xA4",
        "Upsilon" => "\xCE\xA5",
        "Phi" => "\xCE\xA6",
        "Chi" => "\xCE\xA7",
        "Psi" => "\xCE\xA8",
        "Omega" => "\xCE\xA9",
        "alpha" => "\xCE\xB1",
        "beta" => "\xCE\xB2",
        "gamma" => "\xCE\xB3",
        "delta" => "\xCE\xB4",
        "epsilon" => "\xCE\xB5",
        "zeta" => "\xCE\xB6",
        "eta" => "\xCE\xB7",
        "theta" => "\xCE\xB8",
        "iota" => "\xCE\xB9",
        "kappa" => "\xCE\xBA",
        "lambda" => "\xCE\xBB",
        "mu" => "\xCE\xBC",
        "nu" => "\xCE\xBD",
        "xi" => "\xCE\xBE",
        "omicron" => "\xCE\xBF",
        "pi" => "\xCF\x80",
        "rho" => "\xCF\x81",
        "sigmaf" => "\xCF\x82",
        "sigma" => "\xCF\x83",
        "tau" => "\xCF\x84",
        "upsilon" => "\xCF\x85",
        "phi" => "\xCF\x86",
        "chi" => "\xCF\x87",
        "psi" => "\xCF\x88",
        "omega" => "\xCF\x89",
        "thetasym" => "\xCF\x91",
        "upsih" => "\xCF\x92",
        "piv" => "\xCF\x96",
        "ensp" => "\xE2\x80\x82",
        "emsp" => "\xE2\x80\x83",
        "thinsp" => "\xE2\x80\x89",
        "zwnj" => "\xE2\x80\x8C",
        "zwj" => "\xE2\x80\x8D",
        "lrm" => "\xE2\x80\x8E",
        "rlm" => "\xE2\x80\x8F",
        "ndash" => "\xE2\x80\x93",
        "mdash" => "\xE2\x80\x94",
        "lsquo" => "\xE2\x80\x98",
        "rsquo" => "\xE2\x80\x99",
        "sbquo" => "\xE2\x80\x9A",
        "ldquo" => "\xE2\x80\x9C",
        "rdquo" => "\xE2\x80\x9D",
        "bdquo" => "\xE2\x80\x9E",
        "dagger" => "\xE2\x80\xA0",
        "Dagger" => "\xE2\x80\xA1",
        "bull" => "\xE2\x80\xA2",
        "hellip" => "\xE2\x80\xA6",
        "permil" => "\xE2\x80\xB0",
        "prime" => "\xE2\x80\xB2",
        "Prime" => "\xE2\x80\xB3",
        "lsaquo" => "\xE2\x80\xB9",
        "rsaquo" => "\xE2\x80\xBA",
        "oline" => "\xE2\x80\xBE",
        "frasl" => "\xE2\x81\x84",
        "euro" => "\xE2\x82\xAC",
        "image" => "\xE2\x84\x91",
        "weierp" => "\xE2\x84\x98",
        "real" => "\xE2\x84\x9C",
        "trade" => "\xE2\x84\xA2",
        "alefsym" => "\xE2\x84\xB5",
        "larr" => "\xE2\x86\x90",
        "uarr" => "\xE2\x86\x91",
        "rarr" => "\xE2\x86\x92",
        "darr" => "\xE2\x86\x93",
        "harr" => "\xE2\x86\x94",
        "crarr" => "\xE2\x86\xB5",
        "lArr" => "\xE2\x87\x90",
        "uArr" => "\xE2\x87\x91",
        "rArr" => "\xE2\x87\x92",
        "dArr" => "\xE2\x87\x93",
        "hArr" => "\xE2\x87\x94",
        "forall" => "\xE2\x88\x80",
        "part" => "\xE2\x88\x82",
        "exist" => "\xE2\x88\x83",
        "empty" => "\xE2\x88\x85",
        "nabla" => "\xE2\x88\x87",
        "isin" => "\xE2\x88\x88",
        "notin" => "\xE2\x88\x89",
        "ni" => "\xE2\x88\x8B",
        "prod" => "\xE2\x88\x8F",
        "sum" => "\xE2\x88\x91",
        "minus" => "\xE2\x88\x92",
        "lowast" => "\xE2\x88\x97",
        "radic" => "\xE2\x88\x9A",
        "prop" => "\xE2\x88\x9D",
        "infin" => "\xE2\x88\x9E",
        "ang" => "\xE2\x88\xA0",
        "and" => "\xE2\x88\xA7",
        "or" => "\xE2\x88\xA8",
        "cap" => "\xE2\x88\xA9",
        "cup" => "\xE2\x88\xAA",
        "int" => "\xE2\x88\xAB",
        "there4" => "\xE2\x88\xB4",
        "sim" => "\xE2\x88\xBC",
        "cong" => "\xE2\x89\x85",
        "asymp" => "\xE2\x89\x88",
        "ne" => "\xE2\x89\xA0",
        "equiv" => "\xE2\x89\xA1",
        "le" => "\xE2\x89\xA4",
        "ge" => "\xE2\x89\xA5",
        "sub" => "\xE2\x8A\x82",
        "sup" => "\xE2\x8A\x83",
        "nsub" => "\xE2\x8A\x84",
        "sube" => "\xE2\x8A\x86",
        "supe" => "\xE2\x8A\x87",
        "oplus" => "\xE2\x8A\x95",
        "otimes" => "\xE2\x8A\x97",
        "perp" => "\xE2\x8A\xA5",
        "sdot" => "\xE2\x8B\x85",
        "lceil" => "\xE2\x8C\x88",
        "rceil" => "\xE2\x8C\x89",
        "lfloor" => "\xE2\x8C\x8A",
        "rfloor" => "\xE2\x8C\x8B",
        "lang" => "\xE2\x8C\xA9",
        "rang" => "\xE2\x8C\xAA",
        "loz" => "\xE2\x97\x8A",
        "spades" => "\xE2\x99\xA0",
        "clubs" => "\xE2\x99\xA3",
        "hearts" => "\xE2\x99\xA5",
        "diams" => "\xE2\x99\xA6",
    ];
}

/** The 5 chars htmlspecialchars covers, honouring the quote flags. */
function __mc_hs_entity(string $ch, int $flags): string
{
    if ($ch === "&") { return "&amp;"; }
    if ($ch === "<") { return "&lt;"; }
    if ($ch === ">") { return "&gt;"; }
    if ($ch === "\"") { return ($flags & 2) !== 0 ? "&quot;" : "\""; }
    if ($ch === "'") { return ($flags & 1) !== 0 ? "&#039;" : "'"; }
    return $ch;
}

/**
 * Reverse of htmlspecialchars — ONLY the five special entities, never the
 * named/numeric set (that is html_entity_decode's job).
 */
function htmlspecialchars_decode(string $string, int $flags = 3): string
{
    $out = "";
    $n = \strlen($string);
    $i = 0;
    while ($i < $n) {
        if ($string[$i] !== "&") {
            $out = $out . $string[$i];
            $i = $i + 1;
            continue;
        }
        $semi = \strpos($string, ";", $i);
        if ($semi === false || $semi - $i > 8) {
            $out = $out . "&";
            $i = $i + 1;
            continue;
        }
        $name = \substr($string, $i + 1, $semi - $i - 1);
        $rep = "";
        if ($name === "amp") { $rep = "&"; }
        elseif ($name === "lt") { $rep = "<"; }
        elseif ($name === "gt") { $rep = ">"; }
        elseif ($name === "quot" && ($flags & 2) !== 0) { $rep = "\""; }
        elseif (($name === "#039" || $name === "#39" || $name === "apos") && ($flags & 1) !== 0) { $rep = "'"; }
        if ($rep === "") {
            $out = $out . "&";
            $i = $i + 1;
            continue;
        }
        $out = $out . $rep;
        $i = $semi + 1;
    }
    return $out;
}

/**
 * Encode every character that has an HTML 4.01 named entity. Input is assumed
 * UTF-8 (php's default since 5.4); a byte that starts no known sequence passes
 * through unchanged, which is also php's ENT_SUBSTITUTE-free behaviour for
 * ASCII text.
 */
function htmlentities(string $string, int $flags = 3, string $encoding = "UTF-8", bool $double_encode = true): string
{
    $tab = __mc_html_entities_enc();
    $out = "";
    $n = \strlen($string);
    $i = 0;
    while ($i < $n) {
        $b = \ord($string[$i]);
        // UTF-8 lead byte → take the whole sequence as the lookup key.
        $len = 1;
        if ($b >= 240) { $len = 4; }
        elseif ($b >= 224) { $len = 3; }
        elseif ($b >= 192) { $len = 2; }
        if ($i + $len > $n) { $len = 1; }
        $ch = \substr($string, $i, $len);
        if (!$double_encode && $ch === "&") {
            // Leave an existing entity alone: copy through to its `;`.
            $semi = \strpos($string, ";", $i);
            if ($semi !== false && $semi - $i <= 10) {
                $out = $out . \substr($string, $i, $semi - $i + 1);
                $i = $semi + 1;
                continue;
            }
        }
        if ($ch === "\"" || $ch === "'") {
            $out = $out . __mc_hs_entity($ch, $flags);
            $i = $i + $len;
            continue;
        }
        if (isset($tab[$ch])) {
            $out = $out . "&" . $tab[$ch] . ";";
        } else {
            $out = $out . $ch;
        }
        $i = $i + $len;
    }
    return $out;
}

/** The UTF-8 encoding of one code point. */
function __mc_utf8_chr(int $cp): string
{
    if ($cp < 0x80) { return \chr($cp); }
    if ($cp < 0x800) {
        return \chr(0xC0 | ($cp >> 6)) . \chr(0x80 | ($cp & 0x3F));
    }
    if ($cp < 0x10000) {
        return \chr(0xE0 | ($cp >> 12)) . \chr(0x80 | (($cp >> 6) & 0x3F)) . \chr(0x80 | ($cp & 0x3F));
    }
    return \chr(0xF0 | ($cp >> 18)) . \chr(0x80 | (($cp >> 12) & 0x3F))
        . \chr(0x80 | (($cp >> 6) & 0x3F)) . \chr(0x80 | ($cp & 0x3F));
}

/**
 * Decode named and numeric entities. An unknown name is left verbatim, as php
 * does — `&foo;` stays `&foo;`.
 */
function html_entity_decode(string $string, int $flags = 3, string $encoding = "UTF-8"): string
{
    $tab = __mc_html_entities_dec();
    $out = "";
    $n = \strlen($string);
    $i = 0;
    while ($i < $n) {
        if ($string[$i] !== "&") {
            $out = $out . $string[$i];
            $i = $i + 1;
            continue;
        }
        $semi = \strpos($string, ";", $i);
        if ($semi === false || $semi - $i > 32) {
            $out = $out . "&";
            $i = $i + 1;
            continue;
        }
        $name = \substr($string, $i + 1, $semi - $i - 1);
        if ($name === "") {
            $out = $out . "&";
            $i = $i + 1;
            continue;
        }
        if ($name[0] === "#") {
            $digits = \substr($name, 1);
            $cp = -1;
            if ($digits !== "" && ($digits[0] === "x" || $digits[0] === "X")) {
                $hex = \substr($digits, 1);
                if ($hex !== "" && \ctype_xdigit($hex)) { $cp = \hexdec($hex); }
            } elseif ($digits !== "" && \ctype_digit($digits)) {
                $cp = (int)$digits;
            }
            if ($cp < 0 || $cp > 0x10FFFF) {
                $out = $out . "&";
                $i = $i + 1;
                continue;
            }
            // The quote flags gate the quote characters here too.
            if ($cp === 34 && ($flags & 2) === 0) { $out = $out . "&" . $name . ";"; $i = $semi + 1; continue; }
            if ($cp === 39 && ($flags & 1) === 0) { $out = $out . "&" . $name . ";"; $i = $semi + 1; continue; }
            $out = $out . __mc_utf8_chr($cp);
            $i = $semi + 1;
            continue;
        }
        if ($name === "quot" && ($flags & 2) === 0) { $out = $out . "&quot;"; $i = $semi + 1; continue; }
        if ($name === "apos" && ($flags & 1) === 0) { $out = $out . "&apos;"; $i = $semi + 1; continue; }
        if ($name === "apos") { $out = $out . "'"; $i = $semi + 1; continue; }
        if (isset($tab[$name])) {
            $out = $out . $tab[$name];
            $i = $semi + 1;
            continue;
        }
        $out = $out . "&";
        $i = $i + 1;
    }
    return $out;
}

/**
 * Strip HTML/PHP tags. Follows php's scanner: `<` opens a tag, quoted
 * attribute values may contain `>`, and `<!--` … `-->` is a comment. The
 * `$allowed_tags` parameter is accepted in php's string form ("<b><i>").
 */
function strip_tags(string $string, string $allowed_tags = ""): string
{
    $allowed = "";
    if ($allowed_tags !== "") { $allowed = \strtolower($allowed_tags); }
    $out = "";
    $n = \strlen($string);
    $i = 0;
    while ($i < $n) {
        $c = $string[$i];
        if ($c !== "<") {
            $out = $out . $c;
            $i = $i + 1;
            continue;
        }
        // A comment runs to `-->`.
        if (\substr($string, $i, 4) === "<!--") {
            $end = \strpos($string, "-->", $i + 4);
            $i = $end === false ? $n : $end + 3;
            continue;
        }
        // Scan to the closing `>`, skipping quoted regions.
        $j = $i + 1;
        $quote = "";
        while ($j < $n) {
            $d = $string[$j];
            if ($quote !== "") {
                if ($d === $quote) { $quote = ""; }
            } elseif ($d === "\"" || $d === "'") {
                $quote = $d;
            } elseif ($d === ">") {
                break;
            }
            $j = $j + 1;
        }
        if ($j >= $n) {
            // Unterminated tag: php drops the rest.
            break;
        }
        if ($allowed !== "") {
            $tag = \substr($string, $i + 1, $j - $i - 1);
            if ($tag !== "" && $tag[0] === "/") { $tag = \substr($tag, 1); }
            $sp = \strcspn($tag, " \t\n\r\x0B\x0C/");
            $name = \strtolower(\substr($tag, 0, $sp));
            // str_contains, not strpos: the stdlib strpos reports "not found"
            // with a sentinel, and a sentinel test here is one more place to get
            // the polarity wrong.
            if ($name !== "" && \str_contains($allowed, "<" . $name . ">")) {
                $out = $out . \substr($string, $i, $j - $i + 1);
            }
        }
        $i = $j + 1;
    }
    return $out;
}
