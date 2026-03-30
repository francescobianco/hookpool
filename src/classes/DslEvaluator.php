<?php

/**
 * DSL Evaluator for sequential log analytics.
 *
 * Formulas are arithmetic expressions over metric phrases:
 *   COUNT BEFORE + COUNT AFTER
 *   DAYS BEFORE LAST - DAYS AFTER FIRST
 *   (COUNT STREAK BEFORE + COUNT STREAK AFTER) * 2
 *
 * Each metric phrase may include an optional WITH clause:
 *   COUNT BEFORE WITH {{status}} = 1
 *   HOURS AFTER FIRST WITH {{method}} = "POST"
 *
 * Metric phrases:
 *   COUNT BEFORE
 *   COUNT AFTER
 *   COUNT STREAK BEFORE
 *   COUNT STREAK AFTER
 *   DAYS|HOURS|MINUTES|SECONDS BEFORE LAST|FIRST
 *   DAYS|HOURS|MINUTES|SECONDS AFTER  LAST|FIRST
 *
 * Built-in placeholders (resolved from the candidate row):
 *   {{body}}     — raw body string
 *   {{status}}   — validated flag (1 or 0)
 *   {{ts}}       — received_at as Unix integer timestamp
 *   {{method}}   — HTTP method string
 *   {{ip}}       — sender IP address
 *   {{known_ip}} — known-IP label, or raw IP if no label defined
 *   {{path}}     — request path
 *
 * Custom field placeholders reference previously-computed fields: {{field_name}}
 */
class DslEvaluator
{
    private const BUILTINS = ['body', 'status', 'ts', 'method', 'ip', 'known_ip', 'path'];

    // ---- Public API ----

    /**
     * Evaluate a DSL formula for a specific row in an ordered sequence.
     *
     * @param string $formula      The DSL formula string
     * @param array  $rows         All rows ordered by received_at ASC
     * @param int    $idx          Zero-based index of the current row
     * @param array  $placeholders Map of custom_field_name => value for current row
     * @return mixed Numeric result, or null if undefined/error
     */
    public static function evaluate(string $formula, array $rows, int $idx, array $placeholders = []): mixed
    {
        $formula = trim($formula);
        if ($formula === '') return null;

        $tokens = self::tokenize($formula);
        $pos    = 0;
        return self::parseFormulaArith($tokens, $pos, $rows, $idx, $placeholders);
    }

    /**
     * Validate a formula string without evaluating it.
     * Returns null if valid, or an error message string if invalid.
     */
    public static function validate(string $formula): ?string
    {
        $formula = trim($formula);
        if ($formula === '') return 'Formula cannot be empty.';

        try {
            $tokens = self::tokenize($formula);
            $pos    = 0;
            self::validateFormulaArith($tokens, $pos);
            if ($pos < count($tokens)) {
                return 'Unexpected token: "' . self::tokenDisplay($tokens[$pos]) . '"';
            }
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
        return null;
    }

    /**
     * Normalize a formula: uppercase all keywords, lowercase built-in placeholder names.
     */
    public static function normalize(string $formula): string
    {
        $formula = trim($formula);
        if ($formula === '') return '';

        $tokens = self::tokenize($formula);
        $pos    = 0;
        return self::normalizeFormulaArith($tokens, $pos);
    }

    public static function evaluateCondition(string $condition, array $rows, int $idx, array $placeholders = []): ?bool
    {
        $condition = trim($condition);
        if ($condition === '') return null;

        $tokens = self::tokenize($condition);
        $pos    = 0;
        $value  = self::parseConditionOr($tokens, $pos, $rows, $idx, $placeholders);
        if ($pos < count($tokens)) return null;
        return (bool)$value;
    }

    public static function validateCondition(string $condition): ?string
    {
        $condition = trim($condition);
        if ($condition === '') return 'Expression cannot be empty.';

        try {
            $tokens = self::tokenize($condition);
            $pos    = 0;
            self::validateConditionOr($tokens, $pos);
            if ($pos < count($tokens)) {
                return 'Unexpected token: "' . self::tokenDisplay($tokens[$pos]) . '"';
            }
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
        return null;
    }

    public static function normalizeCondition(string $condition): string
    {
        $condition = trim($condition);
        if ($condition === '') return '';

        $tokens = self::tokenize($condition);
        $pos    = 0;
        return self::normalizeConditionOr($tokens, $pos);
    }

    // ---- Formula-level evaluation parser ----

    private static function parseFormulaArith(array $t, int &$p, array $rows, int $idx, array $ph): mixed
    {
        $v = self::parseFormulaTerm($t, $p, $rows, $idx, $ph);
        while ($p < count($t) && $t[$p]['type'] === 'op' && in_array($t[$p]['value'], ['+', '-'], true)) {
            $op = $t[$p]['value'];
            $p++;
            $r = self::parseFormulaTerm($t, $p, $rows, $idx, $ph);
            if ($v === null || $r === null) { $v = null; continue; }
            $v = $op === '+' ? (float)$v + (float)$r : (float)$v - (float)$r;
        }
        return $v;
    }

    private static function parseFormulaTerm(array $t, int &$p, array $rows, int $idx, array $ph): mixed
    {
        $v = self::parseFormulaFactor($t, $p, $rows, $idx, $ph);
        while ($p < count($t) && $t[$p]['type'] === 'op' && in_array($t[$p]['value'], ['*', '/'], true)) {
            $op = $t[$p]['value'];
            $p++;
            $r = self::parseFormulaFactor($t, $p, $rows, $idx, $ph);
            if ($v === null || $r === null) { $v = null; continue; }
            $v = $op === '*' ? (float)$v * (float)$r : ((float)$r != 0 ? (float)$v / (float)$r : null);
        }
        return $v;
    }

    private static function parseFormulaFactor(array $t, int &$p, array $rows, int $idx, array $ph): mixed
    {
        if ($p >= count($t)) return null;

        // Unary minus
        if ($t[$p]['type'] === 'op' && $t[$p]['value'] === '-') {
            $p++;
            $v = self::parseFormulaFactor($t, $p, $rows, $idx, $ph);
            return $v !== null ? -(float)$v : null;
        }

        // Parenthesized expression
        if ($t[$p]['type'] === 'lparen') {
            $p++;
            $v = self::parseFormulaArith($t, $p, $rows, $idx, $ph);
            if ($p < count($t) && $t[$p]['type'] === 'rparen') $p++;
            return $v;
        }

        // Number literal
        if ($t[$p]['type'] === 'number') {
            return $t[$p++]['value'];
        }

        // Metric phrase
        if (self::isMetricStart($t, $p)) {
            return self::parseMetricNode($t, $p, $rows, $idx, $ph);
        }

        return null;
    }

    private static function isMetricStart(array $t, int $p): bool
    {
        if ($p >= count($t)) return false;
        return in_array($t[$p]['type'], ['kw_count', 'kw_seconds', 'kw_minutes', 'kw_hours', 'kw_days'], true);
    }

    /**
     * Parse and evaluate one metric phrase (possibly with a WITH clause).
     *
     * WITH clause disambiguation: parseOr() naturally stops when it encounters
     * a formula-level + or - that is not inside a comparison's arithmetic sub-expression,
     * because AND/OR/comparison chains don't allow bare + at the boolean top level.
     */
    private static function parseMetricNode(array $t, int &$p, array $rows, int $idx, array $ph): mixed
    {
        $metric = self::consumeMetricKeywords($t, $p);
        if ($metric === null) return null;

        // Collect WITH clause tokens by running the boolean parser (advances $p to where
        // the WITH expression ends, which is before any formula-level operator)
        $withTokens = null;
        if ($p < count($t) && $t[$p]['type'] === 'kw_with') {
            $p++; // consume WITH
            $withStart = $p;
            self::parseOr($t, $p, [], []); // advance $p past the WITH expression
            $withTokens = array_slice($t, $withStart, $p - $withStart);
        }

        return self::evalMetric($metric, $withTokens, $rows, $idx, $ph);
    }

    /**
     * Greedily consume metric keyword tokens (kw_* except kw_with) from the token stream.
     * Returns the metric phrase string (e.g. "COUNT BEFORE") or null if not a valid phrase.
     */
    private static function consumeMetricKeywords(array $t, int &$p, bool $strict = false): ?string
    {
        $startP    = $p;
        $keywords  = [];

        while ($p < count($t)) {
            $type = $t[$p]['type'];
            if (!str_starts_with($type, 'kw_') || $type === 'kw_with') break;
            $keywords[] = strtoupper(substr($type, 3)); // strip 'kw_' prefix
            $p++;
        }

        $metric      = implode(' ', $keywords);
        $knownMetrics = ['COUNT BEFORE', 'COUNT AFTER', 'COUNT STREAK BEFORE', 'COUNT STREAK AFTER'];
        $timePattern  = '/^(SECONDS|MINUTES|HOURS|DAYS) (BEFORE|AFTER) (LAST|FIRST)$/';

        if (in_array($metric, $knownMetrics, true) || preg_match($timePattern, $metric)) {
            return $metric;
        }

        if ($strict) {
            throw new \RuntimeException(
                'Unknown metric phrase: "' . $metric . '". '
                . 'Valid metrics: COUNT BEFORE, COUNT AFTER, COUNT STREAK BEFORE, COUNT STREAK AFTER, '
                . 'SECONDS|MINUTES|HOURS|DAYS BEFORE|AFTER LAST|FIRST.'
            );
        }

        $p = $startP;
        return null;
    }

    /**
     * Evaluate a parsed metric phrase against the event sequence.
     *
     * @param string     $metric     Normalized metric phrase (e.g. "COUNT BEFORE")
     * @param array|null $withTokens Pre-tokenized WITH expression, or null
     */
    private static function evalMetric(string $metric, ?array $withTokens, array $rows, int $idx, array $ph): mixed
    {
        switch ($metric) {
            case 'COUNT BEFORE':
                $cands = array_slice($rows, 0, $idx);
                if ($withTokens !== null) $cands = self::filterWithTokens($cands, $withTokens, $ph);
                return count($cands);

            case 'COUNT AFTER':
                $cands = array_slice($rows, $idx + 1);
                if ($withTokens !== null) $cands = self::filterWithTokens($cands, $withTokens, $ph);
                return count($cands);

            case 'COUNT STREAK BEFORE':
                $count = 0;
                for ($i = $idx - 1; $i >= 0; $i--) {
                    if ($withTokens !== null && !self::evalBoolTokens($withTokens, $rows[$i], $ph)) break;
                    $count++;
                }
                return $count;

            case 'COUNT STREAK AFTER':
                $count = 0;
                $n     = count($rows);
                for ($i = $idx + 1; $i < $n; $i++) {
                    if ($withTokens !== null && !self::evalBoolTokens($withTokens, $rows[$i], $ph)) break;
                    $count++;
                }
                return $count;

            default:
                if (preg_match('/^(SECONDS|MINUTES|HOURS|DAYS) (BEFORE|AFTER) (LAST|FIRST)$/', $metric, $m)) {
                    [, $unit, $dir, $anchor] = $m;

                    $cands = $dir === 'BEFORE'
                        ? array_slice($rows, 0, $idx)
                        : array_slice($rows, $idx + 1);

                    if ($withTokens !== null) $cands = self::filterWithTokens($cands, $withTokens, $ph);
                    if (empty($cands)) return null;

                    $target = ($anchor === 'LAST')
                        ? ($dir === 'BEFORE' ? end($cands)   : reset($cands))
                        : ($dir === 'BEFORE' ? reset($cands) : end($cands));

                    if (!$target) return null;

                    $diffSec = abs(
                        (int)strtotime($rows[$idx]['received_at']) -
                        (int)strtotime($target['received_at'])
                    );

                    return match($unit) {
                        'SECONDS' => $diffSec,
                        'MINUTES' => round($diffSec / 60, 2),
                        'HOURS'   => round($diffSec / 3600, 2),
                        'DAYS'    => round($diffSec / 86400, 2),
                    };
                }
                return null;
        }
    }

    private static function filterWithTokens(array $cands, array $withTokens, array $ph): array
    {
        return array_values(array_filter(
            $cands,
            static fn($row) => self::evalBoolTokens($withTokens, $row, $ph)
        ));
    }

    private static function evalBoolTokens(array $tokens, array $row, array $ph): bool
    {
        $pos = 0;
        return (bool)self::parseOr($tokens, $pos, $row, $ph);
    }

    private static function parseConditionOr(array $t, int &$p, array $rows, int $idx, array $ph): mixed
    {
        $v = self::parseConditionAnd($t, $p, $rows, $idx, $ph);
        while ($p < count($t) && $t[$p]['type'] === 'or') {
            $p++;
            $r = self::parseConditionAnd($t, $p, $rows, $idx, $ph);
            $v = (bool)$v || (bool)$r;
        }
        return $v;
    }

    private static function parseConditionAnd(array $t, int &$p, array $rows, int $idx, array $ph): mixed
    {
        $v = self::parseConditionCmp($t, $p, $rows, $idx, $ph);
        while ($p < count($t) && $t[$p]['type'] === 'and') {
            $p++;
            $r = self::parseConditionCmp($t, $p, $rows, $idx, $ph);
            $v = (bool)$v && (bool)$r;
        }
        return $v;
    }

    private static function parseConditionCmp(array $t, int &$p, array $rows, int $idx, array $ph): mixed
    {
        $left   = self::parseFormulaArith($t, $p, $rows, $idx, $ph);
        $cmpOps = ['=', '!=', '>', '>=', '<', '<='];
        if ($p < count($t) && $t[$p]['type'] === 'op' && in_array($t[$p]['value'], $cmpOps, true)) {
            $op = $t[$p]['value'];
            $p++;
            $right = self::parseFormulaArith($t, $p, $rows, $idx, $ph);
            return match($op) {
                '='  => $left == $right,
                '!=' => $left != $right,
                '>'  => $left > $right,
                '>=' => $left >= $right,
                '<'  => $left < $right,
                '<=' => $left <= $right,
            };
        }
        return (bool)$left;
    }

    // ---- Formula-level validation parser ----

    private static function validateConditionOr(array $t, int &$p): void
    {
        self::validateConditionAnd($t, $p);
        while ($p < count($t) && $t[$p]['type'] === 'or') {
            $p++;
            self::validateConditionAnd($t, $p);
        }
    }

    private static function validateConditionAnd(array $t, int &$p): void
    {
        self::validateConditionCmp($t, $p);
        while ($p < count($t) && $t[$p]['type'] === 'and') {
            $p++;
            self::validateConditionCmp($t, $p);
        }
    }

    private static function validateConditionCmp(array $t, int &$p): void
    {
        self::validateFormulaArith($t, $p);
        $cmpOps = ['=', '!=', '>', '>=', '<', '<='];
        if ($p < count($t) && $t[$p]['type'] === 'op' && in_array($t[$p]['value'], $cmpOps, true)) {
            $p++;
            self::validateFormulaArith($t, $p);
        }
    }

    private static function validateFormulaArith(array $t, int &$p): void
    {
        self::validateFormulaTerm($t, $p);
        while ($p < count($t) && $t[$p]['type'] === 'op' && in_array($t[$p]['value'], ['+', '-'], true)) {
            $p++;
            self::validateFormulaTerm($t, $p);
        }
    }

    private static function validateFormulaTerm(array $t, int &$p): void
    {
        self::validateFormulaFactor($t, $p);
        while ($p < count($t) && $t[$p]['type'] === 'op' && in_array($t[$p]['value'], ['*', '/'], true)) {
            $p++;
            self::validateFormulaFactor($t, $p);
        }
    }

    private static function validateFormulaFactor(array $t, int &$p): void
    {
        if ($p >= count($t)) throw new \RuntimeException('Unexpected end of formula.');

        if ($t[$p]['type'] === 'op' && $t[$p]['value'] === '-') {
            $p++;
            self::validateFormulaFactor($t, $p);
            return;
        }
        if ($t[$p]['type'] === 'lparen') {
            $p++;
            self::validateFormulaArith($t, $p);
            if ($p >= count($t) || $t[$p]['type'] !== 'rparen') {
                throw new \RuntimeException('Missing closing parenthesis.');
            }
            $p++;
            return;
        }
        if ($t[$p]['type'] === 'number') { $p++; return; }
        if (self::isMetricStart($t, $p)) {
            self::validateMetricNode($t, $p);
            return;
        }
        throw new \RuntimeException('Expected metric phrase or number, got "' . self::tokenDisplay($t[$p]) . '".');
    }

    private static function validateMetricNode(array $t, int &$p): void
    {
        self::consumeMetricKeywords($t, $p, strict: true); // throws on invalid phrase

        if ($p < count($t) && $t[$p]['type'] === 'kw_with') {
            $p++; // consume WITH
            if ($p >= count($t)) throw new \RuntimeException('WITH clause cannot be empty.');
            $withStart = $p;
            // Validate the boolean expression by parsing it with the real validator
            self::validateBoolExpr($t, $p);
            if ($p === $withStart) throw new \RuntimeException('WITH clause cannot be empty.');
        }
    }

    private static function validateBoolExpr(array $t, int &$p): void
    {
        // Run parseOr to check the expression is well-formed; it stops at formula-level operators
        // Re-use the evaluator with empty data — the return value is irrelevant here
        self::parseOr($t, $p, [], []);
    }

    // ---- Formula-level normalization ----

    private static function normalizeConditionOr(array $t, int &$p): string
    {
        $parts = [self::normalizeConditionAnd($t, $p)];
        while ($p < count($t) && $t[$p]['type'] === 'or') {
            $parts[] = 'OR';
            $p++;
            $parts[] = self::normalizeConditionAnd($t, $p);
        }
        return implode(' ', array_filter($parts, static fn($part) => $part !== ''));
    }

    private static function normalizeConditionAnd(array $t, int &$p): string
    {
        $parts = [self::normalizeConditionCmp($t, $p)];
        while ($p < count($t) && $t[$p]['type'] === 'and') {
            $parts[] = 'AND';
            $p++;
            $parts[] = self::normalizeConditionCmp($t, $p);
        }
        return implode(' ', array_filter($parts, static fn($part) => $part !== ''));
    }

    private static function normalizeConditionCmp(array $t, int &$p): string
    {
        $left = self::normalizeFormulaArith($t, $p);
        $cmpOps = ['=', '!=', '>', '>=', '<', '<='];
        if ($p < count($t) && $t[$p]['type'] === 'op' && in_array($t[$p]['value'], $cmpOps, true)) {
            $op = $t[$p]['value'];
            $p++;
            $right = self::normalizeFormulaArith($t, $p);
            return trim($left . ' ' . $op . ' ' . $right);
        }
        return $left;
    }

    private static function normalizeFormulaArith(array $t, int &$p): string
    {
        $parts = [self::normalizeFormulaTerm($t, $p)];
        while ($p < count($t) && $t[$p]['type'] === 'op' && in_array($t[$p]['value'], ['+', '-'], true)) {
            $parts[] = $t[$p]['value'];
            $p++;
            $parts[] = self::normalizeFormulaTerm($t, $p);
        }
        return implode(' ', $parts);
    }

    private static function normalizeFormulaTerm(array $t, int &$p): string
    {
        $parts = [self::normalizeFormulaFactor($t, $p)];
        while ($p < count($t) && $t[$p]['type'] === 'op' && in_array($t[$p]['value'], ['*', '/'], true)) {
            $parts[] = $t[$p]['value'];
            $p++;
            $parts[] = self::normalizeFormulaFactor($t, $p);
        }
        return implode(' ', $parts);
    }

    private static function normalizeFormulaFactor(array $t, int &$p): string
    {
        if ($p >= count($t)) return '';

        if ($t[$p]['type'] === 'op' && $t[$p]['value'] === '-') {
            $p++;
            return '- ' . self::normalizeFormulaFactor($t, $p);
        }
        if ($t[$p]['type'] === 'lparen') {
            $p++;
            $inner = self::normalizeFormulaArith($t, $p);
            if ($p < count($t) && $t[$p]['type'] === 'rparen') $p++;
            return '(' . $inner . ')';
        }
        if ($t[$p]['type'] === 'number') {
            $v = $t[$p++]['value'];
            return (floor($v) == $v) ? (string)(int)$v : (string)$v;
        }
        if (self::isMetricStart($t, $p)) {
            return self::normalizeMetricNode($t, $p);
        }
        $p++;
        return '';
    }

    private static function normalizeMetricNode(array $t, int &$p): string
    {
        $metric = self::consumeMetricKeywords($t, $p);
        if ($metric === null) return '';

        $parts = [$metric];
        if ($p < count($t) && $t[$p]['type'] === 'kw_with') {
            $p++; // consume WITH
            $withStart = $p;
            self::parseOr($t, $p, [], []); // advance $p past the WITH expression
            $withTokens = array_slice($t, $withStart, $p - $withStart);
            if (!empty($withTokens)) {
                $parts[] = 'WITH';
                $parts[] = self::normalizeTokenList($withTokens);
            }
        }
        return implode(' ', $parts);
    }

    private static function normalizeTokenList(array $tokens): string
    {
        $parts = [];
        foreach ($tokens as $tok) {
            $parts[] = match(true) {
                $tok['type'] === 'and'         => 'AND',
                $tok['type'] === 'or'          => 'OR',
                $tok['type'] === 'not'         => 'NOT',
                $tok['type'] === 'null'        => 'NULL',
                $tok['type'] === 'op'          => $tok['value'],
                $tok['type'] === 'lparen'      => '(',
                $tok['type'] === 'rparen'      => ')',
                $tok['type'] === 'number'      => (string)$tok['value'],
                $tok['type'] === 'string'      => '"' . $tok['value'] . '"',
                $tok['type'] === 'field'       => strtoupper($tok['value'] ?? ''),
                $tok['type'] === 'placeholder' => '{{' . (in_array(strtolower($tok['value']), self::BUILTINS, true)
                                                    ? strtolower($tok['value'])
                                                    : $tok['value']) . '}}',
                str_starts_with($tok['type'], 'kw_') => strtoupper(substr($tok['type'], 3)),
                default => $tok['value'] ?? '',
            };
        }
        return implode(' ', $parts);
    }

    private static function tokenDisplay(array $tok): string
    {
        if (str_starts_with($tok['type'], 'kw_')) return strtoupper(substr($tok['type'], 3));
        return $tok['value'] ?? $tok['type'];
    }

    // ---- Tokenizer ----

    private static function tokenize(string $expr): array
    {
        $tokens = [];
        $i      = 0;
        $len    = strlen($expr);

        while ($i < $len) {
            $ch = $expr[$i];

            if (ctype_space($ch)) { $i++; continue; }

            // Placeholder: {{name}}
            if (substr($expr, $i, 2) === '{{') {
                $i += 2;
                $name = '';
                while ($i < $len && substr($expr, $i, 2) !== '}}') {
                    $name .= $expr[$i++];
                }
                $i += 2;
                $tokens[] = ['type' => 'placeholder', 'value' => trim($name)];
                continue;
            }

            // String literal
            if ($ch === '"' || $ch === "'") {
                $q = $ch; $i++;
                $str = '';
                while ($i < $len && $expr[$i] !== $q) $str .= $expr[$i++];
                $i++;
                $tokens[] = ['type' => 'string', 'value' => $str];
                continue;
            }

            // Number
            if (ctype_digit($ch) || ($ch === '.' && $i + 1 < $len && ctype_digit($expr[$i + 1]))) {
                $num = '';
                while ($i < $len && (ctype_digit($expr[$i]) || $expr[$i] === '.')) {
                    $num .= $expr[$i++];
                }
                $tokens[] = ['type' => 'number', 'value' => (float)$num];
                continue;
            }

            // Two-char operators
            $two = substr($expr, $i, 2);
            if (in_array($two, ['!=', '>=', '<='], true)) {
                $tokens[] = ['type' => 'op', 'value' => $two];
                $i += 2;
                continue;
            }

            // Single-char operators / parens
            if (in_array($ch, ['=', '>', '<', '+', '-', '*', '/'], true)) {
                $tokens[] = ['type' => 'op', 'value' => $ch];
                $i++;
                continue;
            }
            if ($ch === '(') { $tokens[] = ['type' => 'lparen']; $i++; continue; }
            if ($ch === ')') { $tokens[] = ['type' => 'rparen']; $i++; continue; }

            // Identifier / keyword
            if (ctype_alpha($ch) || $ch === '_') {
                $word = '';
                while ($i < $len && (ctype_alnum($expr[$i]) || $expr[$i] === '_')) {
                    $word .= $expr[$i++];
                }
                $upper    = strtoupper($word);
                $tokens[] = match($upper) {
                    'AND'     => ['type' => 'and'],
                    'OR'      => ['type' => 'or'],
                    'NOT'     => ['type' => 'not'],
                    'NULL'    => ['type' => 'null'],
                    // DSL metric keywords — given special types so formula-level
                    // and boolean parsers can distinguish them
                    'WITH'    => ['type' => 'kw_with'],
                    'COUNT'   => ['type' => 'kw_count'],
                    'BEFORE'  => ['type' => 'kw_before'],
                    'AFTER'   => ['type' => 'kw_after'],
                    'STREAK'  => ['type' => 'kw_streak'],
                    'LAST'    => ['type' => 'kw_last'],
                    'FIRST'   => ['type' => 'kw_first'],
                    'SECONDS' => ['type' => 'kw_seconds'],
                    'MINUTES' => ['type' => 'kw_minutes'],
                    'HOURS'   => ['type' => 'kw_hours'],
                    'DAYS'    => ['type' => 'kw_days'],
                    default   => ['type' => 'field', 'value' => $upper],
                };
                continue;
            }

            $i++;
        }

        return $tokens;
    }

    // ---- Recursive-descent boolean expression parser ----

    private static function parseOr(array $t, int &$p, array $row, array $ph): mixed
    {
        $v = self::parseAnd($t, $p, $row, $ph);
        while ($p < count($t) && $t[$p]['type'] === 'or') {
            $p++;
            $r = self::parseAnd($t, $p, $row, $ph);
            $v = $v || $r;
        }
        return $v;
    }

    private static function parseAnd(array $t, int &$p, array $row, array $ph): mixed
    {
        $v = self::parseCmp($t, $p, $row, $ph);
        while ($p < count($t) && $t[$p]['type'] === 'and') {
            $p++;
            $r = self::parseCmp($t, $p, $row, $ph);
            $v = $v && $r;
        }
        return $v;
    }

    private static function parseCmp(array $t, int &$p, array $row, array $ph): mixed
    {
        $v      = self::parseAdd($t, $p, $row, $ph);
        $cmpOps = ['=', '!=', '>', '>=', '<', '<='];
        if ($p < count($t) && $t[$p]['type'] === 'op' && in_array($t[$p]['value'], $cmpOps, true)) {
            $op = $t[$p]['value'];
            $p++;
            $r  = self::parseAdd($t, $p, $row, $ph);
            $v  = match($op) {
                '='  => $v == $r,
                '!=' => $v != $r,
                '>'  => $v > $r,
                '>=' => $v >= $r,
                '<'  => $v < $r,
                '<=' => $v <= $r,
            };
        }
        return $v;
    }

    private static function parseAdd(array $t, int &$p, array $row, array $ph): mixed
    {
        $v = self::parseMul($t, $p, $row, $ph);
        while ($p < count($t) && $t[$p]['type'] === 'op' && in_array($t[$p]['value'], ['+', '-'], true)) {
            $op = $t[$p]['value'];
            $p++;
            $r  = self::parseMul($t, $p, $row, $ph);
            $v  = $op === '+' ? (float)$v + (float)$r : (float)$v - (float)$r;
        }
        return $v;
    }

    private static function parseMul(array $t, int &$p, array $row, array $ph): mixed
    {
        $v = self::parseUnary($t, $p, $row, $ph);
        while ($p < count($t) && $t[$p]['type'] === 'op' && in_array($t[$p]['value'], ['*', '/'], true)) {
            $op = $t[$p]['value'];
            $p++;
            $r  = self::parseUnary($t, $p, $row, $ph);
            $v  = $op === '*' ? (float)$v * (float)$r : ($r != 0 ? (float)$v / (float)$r : null);
        }
        return $v;
    }

    private static function parseUnary(array $t, int &$p, array $row, array $ph): mixed
    {
        if ($p < count($t)) {
            if ($t[$p]['type'] === 'not') { $p++; return !self::parseUnary($t, $p, $row, $ph); }
            if ($t[$p]['type'] === 'op' && $t[$p]['value'] === '-') {
                $p++;
                return -(float)self::parsePrimary($t, $p, $row, $ph);
            }
        }
        return self::parsePrimary($t, $p, $row, $ph);
    }

    private static function parsePrimary(array $t, int &$p, array $row, array $ph): mixed
    {
        if ($p >= count($t)) return null;
        $tok = $t[$p];

        // DSL metric keywords — do NOT consume; formula-level parser handles them.
        // Returning null without $p++ is the key disambiguation mechanism: when the
        // boolean parser (parseOr/parseAdd etc.) encounters a metric keyword after a
        // complete expression, it sees null and stops, leaving the keyword for the
        // formula-level parser.
        if (str_starts_with($tok['type'], 'kw_')) return null;

        if ($tok['type'] === 'number')  { $p++; return $tok['value']; }
        if ($tok['type'] === 'string')  { $p++; return $tok['value']; }
        if ($tok['type'] === 'null')    { $p++; return null; }

        if ($tok['type'] === 'placeholder') {
            $p++;
            $name  = $tok['value'];
            $lower = strtolower($name);
            return match($lower) {
                'body'     => $row['body'] ?? '',
                'status'   => (int)($row['validated'] ?? 0),
                'ts'       => (int)strtotime($row['received_at'] ?? 'now'),
                'method'   => $row['method'] ?? '',
                'ip'       => $row['ip'] ?? '',
                'known_ip' => $row['known_ip'] ?? $row['ip'] ?? '',
                'path'     => $row['path'] ?? '',
                default    => $ph[$name] ?? null,
            };
        }

        // Bare identifiers — treat as null
        if ($tok['type'] === 'field') { $p++; return null; }

        if ($tok['type'] === 'lparen') {
            $p++;
            $v = self::parseOr($t, $p, $row, $ph);
            if ($p < count($t) && $t[$p]['type'] === 'rparen') $p++;
            return $v;
        }

        $p++;
        return null;
    }
}
