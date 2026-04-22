<?php

declare(strict_types=1);

namespace ConvertSdk\Tests;

use ConvertSdk\Enums\LogLevel;
use ConvertSdk\LogManager;
use ConvertSdk\RuleManager;
use OpenAPI\Client\Model\RuleObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Stringable;

/**
 * Regression test for the OpenAPI enum log-serialization bug reported on PR #11 (comment
 * discussion_r3120320108). When a RuleObject containing a RuleElement with rule_type other
 * than 'js_condition' is logged via PSR-3, the PHP OpenAPI generator's ObjectSerializer
 * enum validation throws and LogManager substitutes "[log serialization error: …]" for
 * the real payload. This test pins the fix: RuleManager.isRuleMatched() must route its
 * log context through LogUtils::toLoggable() so the serializer never runs on the model.
 */
class RuleManagerLogSerializationTest extends TestCase
{
    public function testIsRuleMatchedDoesNotEmitLogSerializationError(): void
    {
        $captured = $this->makeCapturingLogger();
        $logManager = new LogManager($captured['logger'], LogLevel::Trace);

        $ruleManager = new RuleManager(logManager: $logManager);

        $ruleSet = new RuleObject([
            'OR' => [[
                'AND' => [[
                    'OR_WHEN' => [[
                        'rule_type' => 'generic_text_key_value',
                        'matching' => ['match_type' => 'matches', 'negated' => false],
                        'value' => 'events',
                        'key' => 'location',
                    ]],
                ]],
            ]],
        ]);

        $ruleManager->isRuleMatched(['location' => 'events'], $ruleSet, 'events-location');

        $allMessages = array_column($captured['messages'], 'message');
        $joined = implode("\n", $allMessages);

        $this->assertStringNotContainsString(
            'log serialization error',
            $joined,
            'Expected no "log serialization error" in captured logs. Full capture: ' . $joined,
        );

        $traceMessages = array_filter($allMessages, fn ($m) => str_contains($m, 'RuleManager.isRuleMatched()'));
        $this->assertNotEmpty($traceMessages, 'Expected at least one trace log from RuleManager.isRuleMatched()');

        $traceBlob = implode("\n", $traceMessages);
        $this->assertStringContainsString(
            'generic_text_key_value',
            $traceBlob,
            'Expected the captured trace to contain the real rule_type payload. Blob: ' . $traceBlob,
        );
    }

    /**
     * @return array{logger: LoggerInterface, messages: array<int, array{level: string, message: string}>}
     */
    private function makeCapturingLogger(): array
    {
        $messages = [];
        $logger = new class ($messages) implements LoggerInterface {
            /**
             * @param array<int, array{level: string, message: string}> $messages
             */
            public function __construct(private array &$messages)
            {
            }

            public function emergency(string|Stringable $message, array $context = []): void
            {
                $this->capture('emergency', $message);
            }

            public function alert(string|Stringable $message, array $context = []): void
            {
                $this->capture('alert', $message);
            }

            public function critical(string|Stringable $message, array $context = []): void
            {
                $this->capture('critical', $message);
            }

            public function error(string|Stringable $message, array $context = []): void
            {
                $this->capture('error', $message);
            }

            public function warning(string|Stringable $message, array $context = []): void
            {
                $this->capture('warning', $message);
            }

            public function notice(string|Stringable $message, array $context = []): void
            {
                $this->capture('notice', $message);
            }

            public function info(string|Stringable $message, array $context = []): void
            {
                $this->capture('info', $message);
            }

            public function debug(string|Stringable $message, array $context = []): void
            {
                $this->capture('debug', $message);
            }

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->capture((string)$level, $message);
            }

            private function capture(string $level, string|Stringable $message): void
            {
                $this->messages[] = ['level' => $level, 'message' => (string)$message];
            }
        };

        return ['logger' => $logger, 'messages' => &$messages];
    }
}
