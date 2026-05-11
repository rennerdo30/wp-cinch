<?php

declare(strict_types=1);

namespace Cinch\Optimizer;

/**
 * Wraps the `matthiasmullie/minify` composer library.
 *
 * Detection is by class_exists() rather than direct require so the
 * plugin still works on hosts where composer was never run (the chain
 * falls through to {@see RegexMinifier}).
 */
final class MatthiasmullieMinifier implements MinifierInterface
{
    public function id(): string
    {
        return 'matthiasmullie';
    }

    public function available(): bool
    {
        return class_exists(\MatthiasMullie\Minify\CSS::class)
            && class_exists(\MatthiasMullie\Minify\JS::class);
    }

    public function supports(string $type): bool
    {
        return $type === 'css' || $type === 'js';
    }

    public function minify(string $code, string $type): string
    {
        if ($code === '') {
            return '';
        }
        if (!$this->available()) {
            throw new \RuntimeException('matthiasmullie/minify not installed');
        }
        if ($type === 'css') {
            $m = new \MatthiasMullie\Minify\CSS();
        } elseif ($type === 'js') {
            $m = new \MatthiasMullie\Minify\JS();
        } else {
            throw new \RuntimeException('unsupported type ' . $type);
        }
        $m->add($code);
        $out = (string) $m->minify();
        if ($out === '') {
            throw new \RuntimeException('matthiasmullie returned empty output');
        }
        return $out;
    }
}
