<?php

declare(strict_types=1);

// Minifier chain harness — verifies the strategy plumbing works
// without WP / composer present. The Regex strategy is the only one
// we can rely on in CI, so we mostly assert chain semantics + that the
// regex fallback still emits the expected shape.

require __DIR__ . '/../src/Optimizer/MinifierInterface.php';
require __DIR__ . '/../src/Optimizer/RegexMinifier.php';
require __DIR__ . '/../src/Optimizer/EsbuildMinifier.php';
require __DIR__ . '/../src/Optimizer/MatthiasmullieMinifier.php';
require __DIR__ . '/../src/Optimizer/MinifierChain.php';

use Cinch\Optimizer\EsbuildMinifier;
use Cinch\Optimizer\MatthiasmullieMinifier;
use Cinch\Optimizer\MinifierChain;
use Cinch\Optimizer\MinifierInterface;
use Cinch\Optimizer\RegexMinifier;

$pass = 0;
$fail = 0;

function chain_check(string $label, bool $cond): void
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

echo "MinifierChain (defaults):\n";

$chain = MinifierChain::build_default();
chain_check('default chain has 3 strategies', count($chain->strategies()) === 3);

$ids = array_map(static fn (MinifierInterface $s): string => $s->id(), $chain->strategies());
chain_check('strategies in priority order',
    $ids === ['esbuild', 'matthiasmullie', 'regex']);

$regex = new RegexMinifier();
chain_check('regex always available', $regex->available());
chain_check('regex supports css', $regex->supports('css'));
chain_check('regex supports js', $regex->supports('js'));

chain_check('chain.active_strategy returns a real id',
    in_array($chain->active_strategy('css'), ['esbuild', 'matthiasmullie', 'regex'], true));

// With only regex registered, every call should land on regex.
$only_regex = new MinifierChain([new RegexMinifier()]);
chain_check('only-regex chain reports regex active',
    $only_regex->active_strategy('css') === 'regex' && $only_regex->active_strategy('js') === 'regex');

$css_in  = "a   {  color:   red  ;  }";
$css_out = $only_regex->minify($css_in, 'css');
chain_check('only-regex chain minifies css',
    $css_out === 'a{color:red}');

$js_in  = "var x = 1; // bye\nvar y = 2;";
$js_out = $only_regex->minify($js_in, 'js');
chain_check('only-regex chain strips js line comment',
    !str_contains($js_out, 'bye'));

// Always-failing strategy must fall through to regex.
$failing = new class implements MinifierInterface {
    public function id(): string { return 'failing'; }
    public function available(): bool { return true; }
    public function supports(string $type): bool { return true; }
    public function minify(string $code, string $type): string
    {
        throw new \RuntimeException('boom');
    }
};
$fallthrough = new MinifierChain([$failing, new RegexMinifier()]);
$out = $fallthrough->minify("a { color: red; }", 'css');
chain_check('fall-through on exception', $out === 'a{color:red}');

// Strategy reporting unavailable must be skipped.
$unavailable = new class implements MinifierInterface {
    public function id(): string { return 'unavail'; }
    public function available(): bool { return false; }
    public function supports(string $type): bool { return true; }
    public function minify(string $code, string $type): string
    {
        return 'never-called';
    }
};
$skip = new MinifierChain([$unavailable, new RegexMinifier()]);
chain_check('skip when available() === false',
    $skip->minify("a { color: red; }", 'css') === 'a{color:red}');

// Empty input is a fast path.
chain_check('empty input returns empty', $chain->minify('', 'css') === '');

// Esbuild + matthiasmullie probes shouldn't fatal even when missing.
$esb = new EsbuildMinifier();
$mm  = new MatthiasmullieMinifier();
chain_check('EsbuildMinifier::available() safe to call',
    is_bool($esb->available()));
chain_check('MatthiasmullieMinifier::available() safe to call',
    is_bool($mm->available()));

echo "\n";
echo "passed: $pass, failed: $fail\n";
exit($fail === 0 ? 0 : 1);
