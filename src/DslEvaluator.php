<?php

/**
 * DSL Evaluator for sequential log analytics.
 *
 * Metric phrases:
 *   COUNT BEFORE
 *   COUNT AFTER
 *   COUNT STREAK BEFORE
 *   COUNT STREAK AFTER
 *   DAYS|HOURS|MINUTES|SECONDS BEFORE LAST
 *   DAYS|HOURS|MINUTES|SECONDS AFTER FIRST
 *   Any of the above followed by: WITH <boolean_expression>
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
    /** Built-in placeholder names (lowercase). */
    private const BUILTINS = ['body', 'status', 'ts', 'method', 'ip', 'known_ip', 'path'];

    // ---- Public API ----

    /**
     * Evaluate a DSL formula for a specific row in an ordered sequence.
     *
     * @param string $formula      The DSL formula string
     * @param array  $rows         All rows (events) ordered by received_at ASC
     * @param int    $idx          Zero-based index of the current row in $rows
     * @param array  $placeholders Map of custom_field_name => value for current row
     * @return mixed Numeric result, or null if undefined/error
     */
    public static function evaluate(string $formula, array $rows, int $idx, array $placeholders = []): mixed
    {
        $formula = trim($formula);
        if ($formula === '') return null;

        $withPos = self::findWith($formula);
        if ($withPos !== false) {
            $metricStr = trim(substr($formula, 0, $withPos));
            $withStr   = trim(substr($formula, $withPos + 5));
        } else {
            $metricStr = $formula;
            $withStr   = null;
        }

        return self::evalMetric($metricStr, $withStr, $rows, $idx, $placeholders);
    }

    /**
     * Validate a formula string without evaluating it.
     * Returns null if valid, or an error message string if invalid.
     */
    public static function validate(string $formula): ?string
    {
        $formula = trim($formula);
        if ($formula === '') return 'Formula cannot be empty.';

        $withPos = self::findWith($formula);
        if ($withPos !== false) {
            $metricStr = trim(substr($formula, 0, $withPos));
            $withStr   = trim(substr($formula, $withPos + 5));
            if ($withStr === '') return 'WITH clause cannot be empty.';
        } else {
            $metricStr = $formula;
            $withStr   = null;
        }

        // Validate metric phrase
        $metric = strtoupper(preg_replace('/\s+/', ' ', trim($metricStr)));
        $knownMetrics = [
            'COUNT BEFORE', 'COUNT AFTER',
            'COUNT STREAK BEFORE', 'COUNT STREAK AFTER',
        ];
        $timePattern = '/^(SECONDS|MINUTES|HOURS|DAYS) (BEFORE|AFTER) (LAST|FIRST)$/';

        if (!in_array($metric, $knownMetrics, true) && !preg_match($timePattern, $metric)) {
            return 'Unknown metric "' . $metricStr . '". '
                 . 'Valid metrics: COUNT BEFORE, COUNT AFTER, COUNT STREAK BEFORE, COUNT STREAK AFTER, '
                 . 'SECONDS|MINUTES|HOURS|DAYS BEFORE|AFTER LAST|FIRST.';
        }

        // Validate WITH expression
        if ($withStr !== null) {
            $tokens = self::tokenize($withStr);
            $pos    = 0;
            try {
                self::parseOr($tokens, $pos, [], []);
            } catch (\Throwable $e) {
                return 'Syntax error in WITH clause: ' . $e->getMessage();
            }
            if ($pos < count($tokens)) {
                $unexpected = $tokens[$pos]['value'] ?? $tokens[$pos]['type'];
                return 'Unexpected token in WITH clause: "' . $unexpected . '"';
            }
        }

        return null;
    }

    /**
     * Normalize a formula: uppercase all keywords, trim whitespace.
     * Built-in placeholder names are lowercased; custom placeholder names are preserved.
     */
    public static function normalize(string $formula): string
    {
        $formula = trim($formula);
        if ($formula === '') return '';

        // Uppercase metric + WITH keyword
        $withPos = self::findWith($formula);
        if ($withPos !== false) {
            $metricPart = trim(substr($formula, 0, $withPos));
            $withPart   = trim(substr($formula, $withPos + 5));
        } else {
            $metricPart = $formula;
            $withPart   = null;
        }

        // Normalize metric: collapse whitespace + uppercase
        $metricNorm = strtoupper(preg_replace('/\s+/', ' ', $metricPart));

        if ($withPart === null) return $metricNorm;

        // Normalize WITH expression: uppercase logical/comparison keywords,
        // lowercase built-in placeholder names, preserve strings and custom placeholders
        $withNorm = self::normalizeWithExpr($withPart);

        return $metricNorm . ' WITH ' . $withNorm;
    }

    // ---- Private helpers ----

    private static function normalizeWithExpr(string $expr): string
    {
        $tokens = self::tokenize($expr);
        $parts  = [];
        foreach ($tokens as $tok) {
            $parts[] = match($tok['type']) {
                'and'         => 'AND',
                'or'          => 'OR',
                'not'         => 'NOT',
                'null'        => 'NULL',
                'op'          => $tok['value'],
                'lparen'      => '(',
                'rparen'      => ')',
                'number'      => (string)$tok['value'],
                'string'      => '"' . $tok['value'] . '"',
                'field'       => strtoupper($tok['value']),
                'placeholder' => '{{' . (in_array(strtolower($tok['value']), self::BUILTINS, true)
                                    ? strtolower($tok['value'])
                                    : $tok['value']) . '}}',
                default       => $tok['value'] ?? '',
            };
        }
        return implode(' ', $parts);
    }

    private static function findWith(string $formula): int|false
    {
        $upper = strtoupper($formula);
        $pos   = strpos($upper, ' WITH ');
        return $pos !== false ? $pos : false;
    }

    private static function evalMetric(
        string $metric,
        ?string $withStr,
        array $rows,
        int $idx,
        array $placeholders
    ): mixed {
        $metric = strtoupper(preg_replace('/\s+/', ' ', trim($metric)));

        switch ($metric) {
            case 'COUNT BEFORE':
                $cands = array_slice($rows, 0, $idx);
                if ($withStr !== null) $cands = self::filterWith($cands, $withStr, $placeholders);
                return count($cands);

            case 'COUNT AFTER':
                $cands = array_slice($rows, $idx + 1);
                if ($withStr !== null) $cands = self::filterWith($cands, $withStr, $placeholders);
                return count($cands);

            case 'COUNT STREAK BEFORE':
                $count = 0;
                for ($i = $idx - 1; $i >= 0; $i--) {
                    if ($withStr !== null && !self::evalBool($withStr, $rows[$i], $placeholders)) break;
                    $count++;
                }
                return $count;

            case 'COUNT STREAK AFTER':
                $count = 0;
                $n = count($rows);
                for ($i = $idx + 1; $i < $n; $i++) {
                    if ($withStr !== null && !self::evalBool($withStr, $rows[$i], $placeholders)) break;
                    $count++;
                }
                return $count;

            default:
                if (preg_match(
                    '/^(SECONDS|MINUTES|HOURS|DAYS) (BEFORE|AFTER) (LAST|FIRST)$/',
                    $metric, $m
                )) {
                    [, $unit, $dir, $anchor] = $m;

                    $cands = $dir === 'BEFORE'
                        ? array_slice($rows, 0, $idx)
                        : array_slice($rows, $idx + 1);

                    if ($withStr !== null) $cands = self::filterWith($cands, $withStr, $placeholders);
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

    private static function filterWith(array $cands, string $withStr, array $placeholders): array
    {
        return array_values(array_filter(
            $cands,
            static fn($row) => self::evalBool($withStr, $row, $placeholders)
        ));
    }

    private static function evalBool(string $expr, array $row, array $placeholders): bool
    {
        return (bool)self::evalExpr($expr, $row, $placeholders);
    }

    private static function evalExpr(string $expr, array $row, array $placeholders): mixed
    {
        $tokens = self::tokenize($expr);
        $pos    = 0;
        return self::parseOr($tokens, $pos, $row, $placeholders);
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
                    'AND'  => ['type' => 'and'],
                    'OR'   => ['type' => 'or'],
                    'NOT'  => ['type' => 'not'],
                    'NULL' => ['type' => 'null'],
                    default => ['type' => 'field', 'value' => $upper],
                };
                continue;
            }

            $i++;
        }

        return $tokens;
    }

    // ---- Recursive-descent parser ----

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
            $r = self::parseAdd($t, $p, $row, $ph);
            $v = match($op) {
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
            $r = self::parseMul($t, $p, $row, $ph);
            $v = $op === '+' ? (float)$v + (float)$r : (float)$v - (float)$r;
        }
        return $v;
    }

    private static function parseMul(array $t, int &$p, array $row, array $ph): mixed
    {
        $v = self::parseUnary($t, $p, $row, $ph);
        while ($p < count($t) && $t[$p]['type'] === 'op' && in_array($t[$p]['value'], ['*', '/'], true)) {
            $op = $t[$p]['value'];
            $p++;
            $r = self::parseUnary($t, $p, $row, $ph);
            $v = $op === '*' ? (float)$v * (float)$r : ($r != 0 ? (float)$v / (float)$r : null);
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

        if ($tok['type'] === 'number')  { $p++; return $tok['value']; }
        if ($tok['type'] === 'string')  { $p++; return $tok['value']; }
        if ($tok['type'] === 'null')    { $p++; return null; }

        if ($tok['type'] === 'placeholder') {
            $p++;
            $name  = $tok['value'];
            $lower = strtolower($name);
            // Built-in variables resolved from the candidate row
            return match($lower) {
                'body'     => $row['body'] ?? '',
                'status'   => (int)($row['validated'] ?? 0),
                'ts'       => (int)strtotime($row['received_at'] ?? 'now'),
                'method'   => $row['method'] ?? '',
                'ip'       => $row['ip'] ?? '',
                'known_ip' => $row['known_ip'] ?? $row['ip'] ?? '',
                'path'     => $row['path'] ?? '',
                // Custom field reference (case-sensitive, uses the name as defined)
                default    => $ph[$name] ?? null,
            };
        }

        // Bare identifiers (e.g. leftover words) — treat as null
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
