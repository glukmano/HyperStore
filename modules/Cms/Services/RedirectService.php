<?php

declare(strict_types=1);

namespace Modules\Cms\Services;

use Modules\Cms\Exceptions\InvalidRedirectException;
use Modules\Cms\Models\Redirect;

/**
 * Redirect targets must be relative platform paths unless explicitly
 * flagged external (never an unvalidated arbitrary target from user input —
 * no open redirects). Loop/excessive-chain prevention is checked at write
 * time by walking the target chain, bounded, never at request time.
 */
final class RedirectService
{
    private const int MAX_CHAIN_DEPTH = 5;

    public function create(int $tenantId, string $fromPath, string $toPath, int $statusCode = 301, ?string $locale = null, bool $isExternal = false): Redirect
    {
        $fromPath = $this->normalize($fromPath);

        if (! $isExternal && $this->looksAbsolute($toPath)) {
            throw new InvalidRedirectException('Redirect targets must be relative platform paths unless explicitly marked external.');
        }

        $toPath = $isExternal ? $toPath : $this->normalize($toPath);

        if ($fromPath === $toPath) {
            throw new InvalidRedirectException('A redirect cannot target itself.');
        }

        $this->assertNoLoop($tenantId, $fromPath, $toPath, $isExternal);

        return Redirect::query()->create([
            'tenant_id' => $tenantId,
            'from_path' => $fromPath,
            'to_path' => $toPath,
            'status_code' => $statusCode,
            'locale' => $locale,
            'is_active' => true,
            'is_external' => $isExternal,
        ]);
    }

    private function assertNoLoop(int $tenantId, string $fromPath, string $toPath, bool $isExternal): void
    {
        if ($isExternal) {
            return;
        }

        // The new link's full chain is: (whatever already redirects into
        // fromPath) -> fromPath -> toPath -> (whatever toPath already
        // redirects to). Both legs are walked so the *total* resulting
        // chain length (in hops) is what's bounded, not just the forward
        // leg from this one new link.
        [$backwardNodes, $backwardHops] = $this->walkBackward($tenantId, $fromPath);
        [$forwardNodes, $forwardHops] = $this->walkForward($tenantId, $toPath);

        $totalHops = $backwardHops + 1 + $forwardHops;

        if ($totalHops > self::MAX_CHAIN_DEPTH) {
            throw new InvalidRedirectException('Redirect chain exceeds the maximum allowed depth.');
        }

        $fullChain = [...$backwardNodes, $fromPath, $toPath, ...$forwardNodes];
        if (count($fullChain) !== count(array_unique($fullChain))) {
            throw new InvalidRedirectException('Redirect chain would loop back to an earlier path in the chain.');
        }
    }

    /**
     * @return array{0: list<string>, 1: int} nodes visited (oldest first) and hop count
     */
    private function walkBackward(int $tenantId, string $toPath): array
    {
        $chain = [];
        $current = $toPath;
        $hops = 0;

        for ($depth = 0; $depth < self::MAX_CHAIN_DEPTH + 1; $depth++) {
            $incoming = Redirect::query()->where('tenant_id', $tenantId)->where('to_path', $current)->where('is_active', true)->where('is_external', false)->first();

            if ($incoming === null || in_array($incoming->from_path, $chain, true)) {
                break;
            }

            $chain[] = $incoming->from_path;
            $current = $incoming->from_path;
            $hops++;
        }

        return [array_reverse($chain), $hops];
    }

    /**
     * @return array{0: list<string>, 1: int} nodes visited (nearest first) and hop count
     */
    private function walkForward(int $tenantId, string $fromPath): array
    {
        $chain = [];
        $current = $fromPath;
        $hops = 0;

        for ($depth = 0; $depth < self::MAX_CHAIN_DEPTH + 1; $depth++) {
            $next = Redirect::query()->where('tenant_id', $tenantId)->where('from_path', $current)->where('is_active', true)->first();

            if ($next === null || $next->is_external || in_array($next->to_path, $chain, true)) {
                break;
            }

            $chain[] = $next->to_path;
            $current = $next->to_path;
            $hops++;
        }

        return [$chain, $hops];
    }

    private function normalize(string $path): string
    {
        $path = '/'.ltrim($path, '/');

        return rtrim($path, '/') ?: '/';
    }

    /**
     * A scheme-qualified URL (http://, https://) or a protocol-relative
     * URL (//host/...) both escape the current site — only these count as
     * "absolute" for open-redirect purposes; a plain leading slash is a
     * normal, safe relative platform path.
     */
    private function looksAbsolute(string $path): bool
    {
        return (bool) preg_match('#^(https?:)?//#i', trim($path));
    }
}
