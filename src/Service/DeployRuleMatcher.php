<?php

declare(strict_types=1);

namespace Revolte\DeployTools\Service;

class DeployRuleMatcher
{
    public function match(string $path, array $profile): bool
    {
        return $this->evaluate($path, $profile)['allowed'];
    }

    /**
     * Returns the match result with a full trace for the explain command.
     *
     * @return array{allowed: bool, trace: list<array{rule: string, matched: bool, active: bool}>}
     */
    public function explain(string $path, array $profile): array
    {
        return $this->evaluate($path, $profile);
    }

    private function evaluate(string $path, array $profile): array
    {
        if (!str_starts_with($path, '/')) {
            $path = '/' . $path;
        }

        $defaultAllow = 'allow' === ($profile['default'] ?? 'disallow');
        $allowed = $defaultAllow;
        $lastMatchedIndex = -1;

        $trace = [['rule' => 'default: ' . ($defaultAllow ? 'allow' : 'disallow'), 'matched' => true, 'active' => true]];

        foreach ($profile['rules'] ?? [] as $i => $rule) {
            if (isset($rule['allow'])) {
                $pattern = (string) $rule['allow'];
                $ruleAllows = true;
            } elseif (isset($rule['disallow'])) {
                $pattern = (string) $rule['disallow'];
                $ruleAllows = false;
            } else {
                continue;
            }

            $matched = $this->matchesPattern($path, $pattern);

            $trace[] = [
                'rule' => sprintf('%s: %s', $ruleAllows ? 'allow' : 'disallow', $pattern),
                'matched' => $matched,
                'active' => false,
            ];

            if ($matched) {
                $allowed = $ruleAllows;
                $lastMatchedIndex = $i + 1; // +1 because index 0 is the default entry
            }
        }

        // Mark the last matched rule as active
        if ($lastMatchedIndex >= 0) {
            $trace[0]['active'] = false;
            $trace[$lastMatchedIndex]['active'] = true;
        }

        return ['allowed' => $allowed, 'trace' => $trace];
    }

    private function matchesPattern(string $path, string $pattern): bool
    {
        if (!str_starts_with($pattern, '/')) {
            $pattern = '/' . $pattern;
        }

        return (bool) preg_match($this->patternToRegex($pattern), $path);
    }



    private function patternToRegex(string $pattern): string
    {
        $result = '';
        $i = 0;
        $len = strlen($pattern);

        while ($i < $len) {
            if ('*' === $pattern[$i] && $i + 1 < $len && '*' === $pattern[$i + 1]) {
                $result .= '.*';
                $i += 2;
            } elseif ('*' === $pattern[$i]) {
                $result .= '[^/]*';
                $i++;
            } else {
                $result .= preg_quote($pattern[$i], '/');
                $i++;
            }
        }

        return '#^' . $result . '$#';
    }
}
