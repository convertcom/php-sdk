<?php

declare(strict_types=1);

namespace ConvertSdk\Config;

use ConvertSdk\Exception\ConfigValidationException;
use OpenAPI\Client\Model\ConfigResponseData;

final class ConfigValidator
{
    public function validate(ConfigResponseData $config): void
    {
        if ($config->getAccountId() === null || $config->getAccountId() === '') {
            throw new ConfigValidationException(
                "Config validation failed: missing 'account_id' field"
            );
        }

        $project = $config->getProject();

        if ($project === null) {
            throw new ConfigValidationException(
                "Config validation failed: missing 'project' field"
            );
        }

        $projectId = is_array($project) ? ($project['id'] ?? null) : ($project->getId() ?? null);

        if ($projectId === null || $projectId === '') {
            throw new ConfigValidationException(
                "Config validation failed: 'project' must contain an 'id' field"
            );
        }
    }
}
