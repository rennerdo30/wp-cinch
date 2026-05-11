<?php

declare(strict_types=1);

// Lightweight harness — no PHPUnit dep; just asserts + a one-line summary.

require __DIR__ . '/../src/Optimizer/RegexMinifier.php';
require __DIR__ . '/../src/Optimizer/CssMinifier.php';
require __DIR__ . '/../src/Optimizer/JsMinifier.php';

use Cinch\Optimizer\CssMinifier;
use Cinch\Optimizer\JsMinifier;

$pass = 0;
$fail = 0;

function check(string $label, bool $cond): void
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "  OK  $label\n";
    } else {
        $fail++;
        echo "  FAIL $label\n";
    }
}

echo "CssMinifier:\n";
check('strips block comments',
    !str_contains(CssMinifier::minify("/* hi */ a{color:red}"), 'hi'));

check('keeps /*! banner */',
    str_contains(CssMinifier::minify("/*! keep */ a{color:red}"), 'keep'));

check('collapses whitespace',
    CssMinifier::minify("a   {  color:   red  ;  }") === 'a{color:red}');

check('drops trailing semicolon before }',
    CssMinifier::minify("a{color:red;}") === 'a{color:red}');

check('protects strings',
    str_contains(CssMinifier::minify('a{content:"  spaced  "}'), '  spaced  '));

check('handles empty input',
    CssMinifier::minify('') === '');

echo "\nJsMinifier:\n";

check('strips line comments',
    !str_contains(JsMinifier::minify("var x = 1; // bye\nvar y = 2;"), 'bye'));

check('strips block comments',
    !str_contains(JsMinifier::minify("/* bye */ var x = 1;"), 'bye'));

check('keeps /*! banner */',
    str_contains(JsMinifier::minify("/*! keep */ var x = 1;"), 'keep'));

check('preserves strings with // inside',
    str_contains(JsMinifier::minify("var u = 'https://example.com';"), 'https://example.com'));

check('preserves regex literals',
    str_contains(JsMinifier::minify("var r = /a\\/b/g;"), '/a\\/b/'));

check('preserves template literals',
    str_contains(JsMinifier::minify("var t = `line1\nline2`;"), 'line1'));

check('division is NOT treated as regex',
    str_contains(JsMinifier::minify("var z = a / b / c;"), '/ b /'));

check('collapses blank lines',
    !str_contains(JsMinifier::minify("var a = 1;\n\n\n\nvar b = 2;"), "\n\n\n"));

echo "\n";
echo "passed: $pass, failed: $fail\n";
exit($fail === 0 ? 0 : 1);
