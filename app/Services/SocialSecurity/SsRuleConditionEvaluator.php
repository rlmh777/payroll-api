<?php

namespace App\Services\SocialSecurity;

class SsRuleConditionEvaluator
{
    /**
     * @param array<string, mixed> $conditions
     * @param array<string, mixed> $context
     */
    public function matches(array $conditions, array $context): bool
    {
        if (isset($conditions['any']) && is_array($conditions['any'])) {
            foreach ($conditions['any'] as $child) {
                if ($this->matches($child, $context)) {
                    return true;
                }
            }

            return false;
        }

        if (isset($conditions['all']) && is_array($conditions['all'])) {
            foreach ($conditions['all'] as $child) {
                if (!$this->matches($child, $context)) {
                    return false;
                }
            }

            return true;
        }

        if (!isset($conditions['field'], $conditions['op'])) {
            return false;
        }

        $field = (string) $conditions['field'];
        $op = (string) $conditions['op'];
        $actual = $context[$field] ?? null;

        return match ($op) {
            '=' => $actual == ($conditions['value'] ?? null),
            '!=' => $actual != ($conditions['value'] ?? null),
            '>=' => is_numeric($actual) && $actual >= ($conditions['value'] ?? 0),
            '<=' => is_numeric($actual) && $actual <= ($conditions['value'] ?? 0),
            '>' => is_numeric($actual) && $actual > ($conditions['value'] ?? 0),
            '<' => is_numeric($actual) && $actual < ($conditions['value'] ?? 0),
            'between' => is_numeric($actual)
                && $actual >= ($conditions['min'] ?? 0)
                && $actual <= ($conditions['max'] ?? 0),
            default => false,
        };
    }
}
