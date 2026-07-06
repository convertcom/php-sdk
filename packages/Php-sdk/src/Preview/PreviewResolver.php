<?php

declare(strict_types=1);

/**
 * Convert PHP SDK
 * Version 1.0.0
 * Copyright (c) 2020 Convert Insights, Inc
 * License Apache-2.0
 */

namespace ConvertSdk\Preview;

use ConvertSdk\Enums\Messages;
use ConvertSdk\Interfaces\ApiManagerInterface;
use ConvertSdk\Interfaces\DataManagerInterface;
use ConvertSdk\Interfaces\LogManagerInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * qs-02 capability (B) preview input — resolves the experience data for a
 * preview target (contract §2 "Resolution"):
 *
 * 1. If the experience is already present in the current config, use it directly
 *    — no fetch needed.
 * 2. Otherwise, memoize per-experienceId for 60s under a preview-scoped cache
 *    key (`preview_{experienceId}` — NEVER the normal config cache key) and,
 *    on a cache miss, fetch via `ApiManagerInterface::getConfigForExperience()`
 *    (which forces `exp={id}` + `_conv_low_cache=1`, plus `debug_token` when
 *    configured).
 *
 * Never mutates the shared DataManager entity list — the resolved experience
 * data is handed back to the caller (Context) for per-context use only, so a
 * preview target absent from the shared config never leaks into other
 * contexts sharing the same DataManager/ApiManager singletons.
 */
final class PreviewResolver
{
    private const CACHE_TTL_SECONDS = 60;
    private const CACHE_KEY_PREFIX = 'preview_';

    public function __construct(
        private readonly DataManagerInterface $dataManager,
        private readonly ApiManagerInterface $apiManager,
        private readonly ?CacheInterface $cache,
        private readonly ?LogManagerInterface $loggerManager = null,
    ) {
    }

    /**
     * Resolve the raw experience data array for the given experience id.
     *
     * @param string $experienceId
     * @return array<string, mixed>|null The experience data, or null when it
     *     cannot be resolved from the current config nor via the `?exp=` fetch.
     */
    public function resolveExperience(string $experienceId): ?array
    {
        $existing = $this->dataManager->getEntityById($experienceId, 'experiences');
        if ($existing !== null) {
            return $existing;
        }

        $cacheKey = self::CACHE_KEY_PREFIX . $experienceId;
        if ($this->cache !== null) {
            $cached = $this->cache->get($cacheKey);
            if (is_array($cached)) {
                return $cached;
            }
        }

        try {
            $response = $this->apiManager->getConfigForExperience($experienceId);
        } catch (\Throwable $e) {
            $this->loggerManager?->warn(
                'PreviewResolver.resolveExperience()',
                Messages::PREVIEW_EXPERIENCE_NOT_FOUND,
                ['experienceId' => $experienceId, 'error' => $e->getMessage()]
            );
            return null;
        }

        foreach ($response->getExperiences() ?? [] as $candidate) {
            if (is_array($candidate) && (string)($candidate['id'] ?? '') === $experienceId) {
                $this->cache?->set($cacheKey, $candidate, self::CACHE_TTL_SECONDS);
                return $candidate;
            }
        }

        $this->loggerManager?->warn(
            'PreviewResolver.resolveExperience()',
            Messages::PREVIEW_EXPERIENCE_NOT_FOUND,
            ['experienceId' => $experienceId]
        );

        return null;
    }
}
