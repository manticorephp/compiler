<?php
// symfony/routing's AttributeFileLoader::findClass() — the exact token walk that
// recovers the class declared in a controller file so its #[Route] attributes
// can be loaded. It is the reason ext/tokenizer exists in this tree, and it
// exercises T_INLINE_HTML / T_NS_SEPARATOR / T_STRING / T_NAME_QUALIFIED /
// T_CLASS / T_DOUBLE_COLON / T_NEW / T_WHITESPACE / T_COMMENT / T_DOC_COMMENT /
// T_NAMESPACE together.
function findClass(string $source): string|false
{
    $class = false;
    $namespace = false;
    $tokens = token_get_all($source);

    if (1 === count($tokens) && T_INLINE_HTML === $tokens[0][0]) {
        return false;
    }

    $nsTokens = [T_NS_SEPARATOR => true, T_STRING => true, T_NAME_QUALIFIED => true];

    for ($i = 0; isset($tokens[$i]); ++$i) {
        $token = $tokens[$i];

        if (!isset($token[1])) {
            continue;
        }

        if (true === $class && T_STRING === $token[0]) {
            return $namespace . '\\' . $token[1];
        }

        if (true === $namespace && isset($nsTokens[$token[0]])) {
            $namespace = $token[1];
            while (isset($tokens[++$i][1]) && isset($nsTokens[$tokens[$i][0]])) {
                $namespace .= $tokens[$i][1];
            }
            $token = $tokens[$i];
        }

        if (T_CLASS === $token[0]) {
            // Skip usage of ::class constant and anonymous classes
            $skipClassToken = false;
            for ($j = $i - 1; $j > 0; --$j) {
                if (!isset($tokens[$j][1])) {
                    if ('(' === $tokens[$j] || ',' === $tokens[$j]) {
                        $skipClassToken = true;
                    }
                    break;
                }
                if (T_DOUBLE_COLON === $tokens[$j][0] || T_NEW === $tokens[$j][0]) {
                    $skipClassToken = true;
                    break;
                }
                if (!in_array($tokens[$j][0], [T_WHITESPACE, T_DOC_COMMENT, T_COMMENT], true)) {
                    break;
                }
            }

            if (!$skipClassToken) {
                $class = true;
            }
        }

        if (T_NAMESPACE === $token[0]) {
            $namespace = true;
        }
    }

    return false;
}

$plain = "<?php\nnamespace App\\Controller;\n\nuse Symfony\\Component\\Routing\\Attribute\\Route;\n\n"
       . "/** doc */\n#[Route('/blog')]\nclass BlogController extends AbstractController\n{\n}\n";
var_dump(findClass($plain));

$viaClassConst = "<?php\nnamespace App;\n\$x = Foo::class;\n\$y = new class {};\nclass Real {}\n";
var_dump(findClass($viaClassConst));

$htmlOnly = "just html, no php at all\n";
var_dump(findClass($htmlOnly));

$noNs = "<?php\nclass Bare {}\n";
var_dump(findClass($noNs));
