<?php

declare(strict_types=1);

namespace ConvertSdk\Tests\Config;

use ConvertSdk\Config\ConfigValidator;
use ConvertSdk\Exception\ConfigValidationException;
use OpenAPI\Client\Model\ConfigResponseData;
use PHPUnit\Framework\TestCase;

class ConfigValidatorTest extends TestCase
{
    private ConfigValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new ConfigValidator();
    }

    /** @test */
    public function validConfigPassesWithoutException(): void
    {
        $config = new ConfigResponseData([
            'account_id' => '12345',
            'project' => ['id' => '67890'],
        ]);

        $this->validator->validate($config);
        $this->assertTrue(true); // No exception thrown
    }

    /** @test */
    public function missingAccountIdThrowsConfigValidationException(): void
    {
        $config = new ConfigResponseData([
            'project' => ['id' => '67890'],
        ]);

        $this->expectException(ConfigValidationException::class);
        $this->expectExceptionMessage('account_id');

        $this->validator->validate($config);
    }

    /** @test */
    public function emptyAccountIdThrowsConfigValidationException(): void
    {
        $config = new ConfigResponseData([
            'account_id' => '',
            'project' => ['id' => '67890'],
        ]);

        $this->expectException(ConfigValidationException::class);
        $this->expectExceptionMessage('account_id');

        $this->validator->validate($config);
    }

    /** @test */
    public function missingProjectThrowsConfigValidationException(): void
    {
        $config = new ConfigResponseData([
            'account_id' => '12345',
        ]);

        $this->expectException(ConfigValidationException::class);
        $this->expectExceptionMessage('project');

        $this->validator->validate($config);
    }

    /** @test */
    public function projectWithoutIdThrowsConfigValidationException(): void
    {
        $config = new ConfigResponseData([
            'account_id' => '12345',
            'project' => ['name' => 'no-id-here'],
        ]);

        $this->expectException(ConfigValidationException::class);
        $this->expectExceptionMessage('id');

        $this->validator->validate($config);
    }

    /** @test */
    public function exceptionMessageContainsFieldName(): void
    {
        $config = new ConfigResponseData([
            'project' => ['id' => '67890'],
        ]);

        try {
            $this->validator->validate($config);
            $this->fail('Expected ConfigValidationException');
        } catch (ConfigValidationException $e) {
            $this->assertStringContainsString('account_id', $e->getMessage());
        }
    }
}
