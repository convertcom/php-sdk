<?php
/**
 * Convert PHP SDK
 * Version 1.0.0
 * Copyright(c) 2020 Convert Insights, Inc
 * License Apache-2.0
 */

namespace ConvertSdk;

use ConvertSdk\Interfaces\RuleManagerInterface;
use ConvertSdk\Enums\RuleError;
use ConvertSdk\Enums\Messages;
use ConvertSdk\Enums\ErrorMessages;
use ConvertSdk\Utils\ArrayUtils;
use ConvertSdk\Utils\ObjectUtils;
use ConvertSdk\Utils\Comparisons;
use ConvertSdk\Utils\StringUtils;
use ConvertSdk\Logger\LogManagerInterface;
use OpenAPI\Client\Model\RuleElement;
use OpenAPI\Client\Model\RuleObject;
use OpenAPI\Client\RuleAnd;
use OpenAPI\Client\RuleOrWhen;
use OpenAPI\Client\Config;

class RuleManager implements RuleManagerInterface
{
    private $_comparisonProcessor;
    private $_negation;
    private $_keys_case_sensitive;
    private $_loggerManager;
    private $_mapper;

    const DEFAULT_KEYS_CASE_SENSITIVE = true;
    const DEFAULT_NEGATION = '!';
    const DEFAULT_COMPARISON_PROCESSOR = Comparisons::class;

    /**
     * Constructor to initialize RuleManager with required dependencies.
     *
     * @param Config|null $config Optional configuration object.
     * @param array $dependencies
     */
    public function __construct(?Config $config = null, array $dependencies = [])
    {
        $this->_loggerManager = $dependencies['loggerManager'] ?? null;
        
        // Retrieve the 'rules' section from the Config instance using its getter.
        $rules = $config && method_exists($config, 'getRules') ? $config->getRules() : [];

        // Set the comparison processor. If not provided in the config, use the default.
        $this->_comparisonProcessor = isset($rules['comparisonProcessor']) 
            ? $rules['comparisonProcessor']
            : self::DEFAULT_COMPARISON_PROCESSOR;
        
        // Set negation and keys_case_sensitive from the rules section.
        $this->_negation = isset($rules['negation']) ? (string)$rules['negation'] : self::DEFAULT_NEGATION;
        $this->_keys_case_sensitive = isset($rules['keys_case_sensitive'])
            ? $rules['keys_case_sensitive']
            : self::DEFAULT_KEYS_CASE_SENSITIVE;

        // Set mapper from config if provided, otherwise use the identity function.
        $this->_mapper = ($config && method_exists($config, 'getMapper') && is_callable($config->getMapper()))
            ? $config->getMapper()
            : function ($value) { return $value; };

        if ($this->_loggerManager) {
            $this->_loggerManager->trace('RuleManager()', Messages::RULE_CONSTRUCTOR, $this);
        }
    }

    /**
     * Setter for comparison processor
     *
     * @param array $comparisonProcessor
     */
    public function setComparisonProcessor(array $comparisonProcessor): void
    {
        $this->_comparisonProcessor = $comparisonProcessor;
    }

    /**
     * Getter for comparison processor
     *
     * @return array
     */

    public function getComparisonProcessor(): array
{
    if (is_string($this->_comparisonProcessor)) {
        // If it's a class name, return its static method names
        return get_class_methods($this->_comparisonProcessor);
    }
    return $this->_comparisonProcessor;
}

    /**
     * Retrieve comparison methods from comparison processor
     *
     * @return array
     */
    // public function getComparisonProcessorMethods(): array
    // {
    //     return array_filter(array_keys($this->_comparisonProcessor), function ($name) {
    //         return is_callable($this->_comparisonProcessor[$name]);
    //     });
    // }

    public function getComparisonProcessorMethods(): array
    {
        if (is_string($this->_comparisonProcessor)) {
            // Return static method names if it's a class name
            return get_class_methods($this->_comparisonProcessor);
        } elseif (is_array($this->_comparisonProcessor)) {
            // Filter callable keys if it's an array
            return array_filter(array_keys($this->_comparisonProcessor), function ($name) {
                return is_callable($this->_comparisonProcessor[$name]);
            });
        }
        return [];
    }

    /**
     * Check input data matching to rule set
     *
     * @param array $data Single value or key-value data set to compare
     * @param RuleObject $ruleSet
     * @param string|null $logEntry
     * @return bool|RuleError
     */
    public function isRuleMatched($data, RuleObject $ruleSet, ?string $logEntry = null)
    {
        if ($this->_loggerManager) {
            $this->_loggerManager->trace('RuleManager.isRuleMatched()', call_user_func($this->_mapper, [
                'data' => $data,
                'ruleSet' => $ruleSet
            ]));
        }
        if ($logEntry && $this->_loggerManager) {
            $this->_loggerManager->info('RuleManager.isRuleMatched()', str_replace('#', $logEntry, Messages::PROCESSING_ENTITY));
        }

        // Top OR level
        $match = false;
        if (isset($ruleSet['OR']) && ArrayUtils::arrayNotEmpty($ruleSet['OR'])) {
            foreach ($ruleSet['OR'] as $i => $rule) {

                $match = $this->_processAND($data, new RuleAnd($rule));
                if (in_array($match, RuleError::getConstants(), true)) {
                    if ($this->_loggerManager) {
                        $this->_loggerManager->info('RuleManager.isRuleMatched()', $logEntry ?? '', ErrorMessages::RULE_ERROR);
                    }
                } else {
                    if ($this->_loggerManager) {
                        $this->_loggerManager->info('RuleManager.isRuleMatched()', $logEntry ?? '', $match === false ? Messages::RULE_NOT_MATCH : str_replace('#', (string)$i, Messages::RULE_MATCH));
                    }
                }
                if ($match !== false) {
                    return $match;
                }
            }
        } else {
            if ($this->_loggerManager) {
                $this->_loggerManager->warn('RuleManager.isRuleMatched()', $logEntry ?? '', ErrorMessages::RULE_NOT_VALID);
            }
        }
        return false;
    }
    

    /**
     * Check if rule object is valid
     *
     * @param RuleElement $rule
     * @return bool
     */
    public function isValidRule(RuleElement $rule): bool
    {
        if ($this->_loggerManager) {
            $this->_loggerManager->trace('RuleManager.isValidRule()', call_user_func($this->_mapper, ['rule' => $rule]));
        }
        return isset($rule['matching']) && is_array($rule['matching']) &&
            isset($rule['matching']['match_type']) && is_string($rule['matching']['match_type']) &&
            isset($rule['matching']['negated']) && is_bool($rule['matching']['negated']) &&
            isset($rule['value']);
    }

    /**
     * Process AND block of rule set. Return first false if found
     *
     * @param array $data Single value or key-value data set to compare
     * @param RuleAnd $rulesSubset
     * @return bool|RuleError
     * @private
     */
    private function _processAND($data, RuleAnd $rulesSubset)
    {
        // Second AND level
        $match = false;
        if ($rulesSubset instanceof RuleAnd) {
            $andRules = $rulesSubset->getAnd(); // Get the AND rules
            if (ArrayUtils::arrayNotEmpty($andRules)) {
                foreach ($rulesSubset->getAnd() as $rule) {

                    $match = $this->_processORWHEN($data, new RuleOrWhen($rule));
                    if ($match === false) {
                        return false;
                    }
                }
                if ($match !== false && $this->_loggerManager) {
                    $this->_loggerManager->info('RuleManager._processAND()', Messages::RULE_MATCH_AND);
                }
                return $match;
            }
        } else {
            if ($this->_loggerManager) {
                $this->_loggerManager->warn('RuleManager._processAND()', ErrorMessages::RULE_NOT_VALID);
            }
        }
        return false;
    }

    /**
     * Process OR block of rule set. Return first true if found
     *
     * @param array $data Single value or key-value data set to compare
     * @param RuleOrWhen $rulesSubset
     * @return bool|RuleError
     * @private
     */
    private function _processORWHEN($data, RuleOrWhen $rulesSubset)
    {
        // Third OR level. Called OR_WHEN.
        $match = false;
        if ($rulesSubset instanceof RuleOrWhen) {
            $orWhenRules = $rulesSubset->getOrWhen(); // Get the OR_WHEN rules
            
            if (ArrayUtils::arrayNotEmpty($orWhenRules)) {
                foreach ($orWhenRules as $rule) {

                    $match = $this->_processRuleItem($data, new RuleElement($rule));
                    if ($match !== false) {
                        return $match;
                    }
                }
            }
        } else {
            if ($this->_loggerManager) {
                $this->_loggerManager->warn('RuleManager._processORWHEN()', ErrorMessages::RULE_NOT_VALID);
            }
        }
        return false;
    }

    /**
     * Process single rule item
     *
     * @param array $data Single value or key-value data set to compare
     * @param RuleElement $rule A single rule to compare
     * @return bool|RuleError Comparison result
     * @private
     */
    private function _processRuleItem($data, RuleElement $rule)
    {
        if ($this->isValidRule($rule)) {
            try {
                $negation = $rule['matching']['negated'] ?? false;
                $matching = $rule['matching']['match_type'];
                if (in_array($matching, $this->getComparisonProcessorMethods(), true)) {
                    if (is_array($data)) {
                        if ($this->isUsingCustomInterface($data)) {
                            if (isset($rule['rule_type'])) {
                                if ($this->_loggerManager) {
                                    $this->_loggerManager->info('RuleManager._processRuleItem()', str_replace('#', $rule['rule_type'], Messages::RULE_MATCH_START));
                                }
                                foreach (get_class_methods($data) as $method) {
                                    if ($method === '__construct') continue;
                                    $rule_method = StringUtils::camelCase('get ' . str_replace('_', ' ', $rule['rule_type']));
                                    if ($method === $rule_method || ($data['mapper'] ?? null) === $rule_method) {
                                        $dataValue = $data[$method]($rule);
                                        if (in_array($dataValue, RuleError::getConstants(), true)) {
                                            return $dataValue;
                                        }
                                        if ($rule['rule_type'] === 'js_condition') return $dataValue;
                                        // Handle both string (class name) and array cases
                                        if (is_string($this->_comparisonProcessor)) {
                                            return call_user_func(
                                                [$this->_comparisonProcessor, $matching],
                                                $dataValue,
                                                $rule['value'],
                                                $negation
                                            );
                                        } else {
                                            return $this->_comparisonProcessor[$matching](
                                                $dataValue,
                                                $rule['value'],
                                                $negation
                                            );
                                        }
                                    }
                                }
                            }
                        } elseif (ObjectUtils::objectNotEmpty($data)) {
                            foreach ($data as $key => $value) {
                                $k = $this->_keys_case_sensitive ? $key : strtolower($key);
                                $rule_k = $this->_keys_case_sensitive ? $rule['key'] : strtolower($rule['key']);
                                if ($k === $rule_k) {
                                    // Handle both string (class name) and array cases
                                    if (is_string($this->_comparisonProcessor)) {
                                        return call_user_func(
                                            [$this->_comparisonProcessor, $matching],
                                            $value,
                                            $rule['value'],
                                            $negation
                                        );
                                    } else {
                                        return $this->_comparisonProcessor[$matching](
                                            $value,
                                            $rule['value'],
                                            $negation
                                        );
                                    }
                                }
                            }
                        } else {
                            if ($this->_loggerManager) {
                                $this->_loggerManager->trace('RuleManager._processRuleItem()', [
                                    'warn' => ErrorMessages::RULE_DATA_NOT_VALID,
                                    'data' => $data
                                ]);
                            }
                        }
                    } else {
                        if ($this->_loggerManager) {
                            $this->_loggerManager->trace('RuleManager._processRuleItem()', [
                                'warn' => ErrorMessages::RULE_NOT_VALID,
                                'data' => $data,
                                'rule' => $rule
                            ]);
                        }
                    }
                } else {
                    if ($this->_loggerManager) {
                        $this->_loggerManager->warn('RuleManager._processRuleItem()', str_replace('#', $matching, ErrorMessages::RULE_MATCH_TYPE_NOT_SUPPORTED));
                    }
                }
            } catch (\Throwable $error) {
                if ($this->_loggerManager) {
                    $this->_loggerManager->error('RuleManager._processRuleItem()', [
                        'error' => $error->getMessage()
                    ]);
                }
            }
        } else {
            if ($this->_loggerManager) {
                $this->_loggerManager->warn('RuleManager._processRuleItem()', ErrorMessages::RULE_NOT_VALID);
            }
        }
        return false;
    }

    /**
     * Check if rule data object is a custom interface instead of a literal object
     *
     * @param array $data Single value or key-value data set to compare
     * @return bool
     */
    private function isUsingCustomInterface(array $data): bool
    {
        return ObjectUtils::objectNotEmpty($data) &&
            isset($data['name']) &&
            $data['name'] === 'RuleData';
    }
}
