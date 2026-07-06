<?php

declare(strict_types=1);

/**
 * Convert PHP SDK
 * Version 1.0.0
 * Copyright (c) 2020 Convert Insights, Inc
 * License Apache-2.0
 */

namespace ConvertSdk\Preview;

/**
 * qs-02 capability (B) preview input — pure static parser for the canonical
 * `convert_preview={experienceId}.{variationId}` link param (contract §2),
 * mirroring the web force-param format `_conv_eforce={expId}.{varId}`.
 *
 * The application is responsible for extracting the raw query-string value
 * from the request URL and handing it to {@see parse()}; the SDK never reads
 * request superglobals or query strings directly.
 */
final class PreviewParam
{
    /**
     * Parse a `convert_preview` link-param value into its experience/variation
     * id pair. Both ids must be non-negative integer strings, dot-separated,
     * with no surrounding whitespace or trailing garbage.
     *
     * @param string $value The raw `convert_preview` query-string value
     * @return array{experienceId: string, variationId: string}|null The parsed
     *     pair, or null when the value is malformed.
     */
    public static function parse(string $value): ?array
    {
        if (preg_match('/^(\d+)\.(\d+)$/', $value, $matches) !== 1) {
            return null;
        }

        return [
            'experienceId' => $matches[1],
            'variationId' => $matches[2],
        ];
    }
}
