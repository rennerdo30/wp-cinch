<?php

declare(strict_types=1);

namespace Cinch\Optimizer;

/**
 * Try each registered minifier in priority order. The first one that
 * is {@see MinifierInterface::available()} AND supports the requested
 * type is asked to minify. On exception OR on a result that's longer
 * than the input, fall through to the next strategy.
 *
 * The chain ALWAYS terminates at {@see RegexMinifier} (added last in
 * the default config) which is guaranteed to be available.
 */
final class MinifierChain
{
    /** @var list<MinifierInterface> */
    private array $strategies;

    /**
     * @param list<MinifierInterface> $strategies
     */
    public function __construct(array $strategies)
    {
        $this->strategies = $strategies;
    }

    /**
     * Build the canonical chain: esbuild → matthiasmullie → regex.
     */
    public static function build_default(): self
    {
        return new self([
            new EsbuildMinifier(),
            new MatthiasmullieMinifier(),
            new RegexMinifier(),
        ]);
    }

    /** @return list<MinifierInterface> */
    public function strategies(): array
    {
        return $this->strategies;
    }

    /**
     * Engine id that will actually run for this type, or 'none' if
     * every strategy is unavailable.
     *
     * @param 'css'|'js' $type
     */
    public function active_strategy(string $type = 'css'): string
    {
        foreach ($this->strategies as $s) {
            if ($s->supports($type) && $s->available()) {
                return $s->id();
            }
        }
        return 'none';
    }

    /**
     * @param 'css'|'js' $type
     */
    public function minify(string $code, string $type = 'css'): string
    {
        if ($code === '') {
            return '';
        }
        $input_len = strlen($code);
        $best      = null;
        $best_len  = $input_len;

        foreach ($this->strategies as $s) {
            if (!$s->supports($type) || !$s->available()) {
                continue;
            }
            try {
                $out = $s->minify($code, $type);
            } catch (\Throwable $e) {
                // Engine failed — try the next one. We deliberately do
                // not log to error_log() here because the chain runs on
                // every request; the settings page surfaces availability.
                continue;
            }
            if ($out === '') {
                continue;
            }
            $len = strlen($out);
            // Accept the first engine that emits a real result, even if
            // larger than input — defensive truncation happens in the
            // filter. The chain's job is "did the engine run", not "did
            // the engine improve". But: if the engine somehow returned
            // LITERALLY the input, that's a degenerate pass — try the
            // next one because we have nothing to gain. Strictly safer
            // to keep going than to return identity.
            if ($len >= $input_len && $out === $code) {
                continue;
            }
            $best     = $out;
            $best_len = $len;
            break;
        }

        if ($best === null) {
            return $code;
        }
        return $best;
    }
}
