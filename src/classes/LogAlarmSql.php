<?php

require_once __DIR__ . '/../config.php';

final class LogAlarmSql
{
    public static function validateAggregateCondition(string $expression): ?string
    {
        $expression = trim($expression);
        if ($expression === '') return 'Expression cannot be empty.';

        try {
            $tokens = self::tokenizeAggregate($expression);
            $pos    = 0;
            self::validateAggConditionOr($tokens, $pos);
            if ($pos < count($tokens)) {
                return 'Unexpected token: "' . self::tokenDisplay($tokens[$pos]) . '"';
            }
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
        return null;
    }

    public static function normalizeAggregateCondition(string $expression): string
    {
        $expression = trim($expression);
        if ($expression === '') return '';

        $tokens = self::tokenizeAggregate($expression);
        $pos    = 0;
        return self::normalizeAggConditionOr($tokens, $pos);
    }

    public static function findUngroupedMatches(PDO $db, int $webhookId, string $condition): array
    {
        $compiler = new self();
        $conditionSql = $compiler->compileDslCondition($condition, 'b');

        $sql = "
            WITH base AS (
                SELECT
                    e.id,
                    e.method,
                    e.received_at,
                    e.path,
                    e.query_string,
                    e.body,
                    e.validated,
                    e.ip,
                    COALESCE(k.label, e.ip) AS known_ip,
                    ROW_NUMBER() OVER (ORDER BY e.received_at, e.id) AS seq
                FROM events e
                JOIN webhooks w ON w.id = e.webhook_id
                JOIN projects p ON p.id = w.project_id
                LEFT JOIN known_ips k ON k.user_id = p.user_id AND k.ip = e.ip
                WHERE e.webhook_id = :webhook_id
                  AND e.method != 'ALARM'
                  AND e.validated = 1
            )
            SELECT b.id, b.path, b.received_at
            FROM base b
            WHERE {$conditionSql}
            ORDER BY b.received_at ASC, b.id ASC
        ";

        $stmt = $db->prepare($sql);
        $stmt->execute(['webhook_id' => $webhookId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function findGroupedMatches(PDO $db, int $webhookId, string $groupBy, string $metricFormula, string $aggregateCondition): array
    {
        $compiler = new self();
        $metricSql = $compiler->compileDslFormula($metricFormula, 'b');
        $groupKeySql = $compiler->groupKeyExpr('b.received_at', $groupBy);
        $aggregateSql = $compiler->compileAggregateCondition($aggregateCondition, [
            'SUM' => 'g.sum_value',
            'MAX' => 'g.max_value',
            'MIN' => 'g.min_value',
            'AVG' => 'g.avg_value',
        ]);

        $sql = "
            WITH base AS (
                SELECT
                    e.id,
                    e.method,
                    e.received_at,
                    e.path,
                    e.query_string,
                    e.body,
                    e.validated,
                    e.ip,
                    COALESCE(k.label, e.ip) AS known_ip,
                    ROW_NUMBER() OVER (ORDER BY e.received_at, e.id) AS seq
                FROM events e
                JOIN webhooks w ON w.id = e.webhook_id
                JOIN projects p ON p.id = w.project_id
                LEFT JOIN known_ips k ON k.user_id = p.user_id AND k.ip = e.ip
                WHERE e.webhook_id = :webhook_id
                  AND e.method != 'ALARM'
                  AND e.validated = 1
            ),
            metric_rows AS (
                SELECT
                    b.*,
                    {$groupKeySql} AS group_key,
                    {$metricSql} AS metric_value
                FROM base b
            ),
            grouped AS (
                SELECT
                    mr.group_key,
                    MIN(mr.received_at) AS first_received_at,
                    SUM(mr.metric_value) AS sum_value,
                    MAX(mr.metric_value) AS max_value,
                    MIN(mr.metric_value) AS min_value,
                    AVG(mr.metric_value) AS avg_value
                FROM metric_rows mr
                GROUP BY mr.group_key
            )
            SELECT g.group_key, g.first_received_at
            FROM grouped g
            WHERE {$aggregateSql}
            ORDER BY g.first_received_at ASC, g.group_key ASC
        ";

        $stmt = $db->prepare($sql);
        $stmt->execute(['webhook_id' => $webhookId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function compileDslCondition(string $condition, string $rowAlias): string
    {
        $tokens = self::tokenizeDsl($condition);
        $pos    = 0;
        $sql    = $this->compileConditionOr($tokens, $pos, $rowAlias);
        if ($pos < count($tokens)) {
            throw new RuntimeException('Unexpected token: "' . self::tokenDisplay($tokens[$pos]) . '"');
        }
        return $sql;
    }

    private function compileDslFormula(string $formula, string $rowAlias): string
    {
        $tokens = self::tokenizeDsl($formula);
        $pos    = 0;
        $sql    = $this->compileFormulaArith($tokens, $pos, $rowAlias);
        if ($pos < count($tokens)) {
            throw new RuntimeException('Unexpected token: "' . self::tokenDisplay($tokens[$pos]) . '"');
        }
        return $sql;
    }

    private function compileFormulaArith(array $t, int &$p, string $rowAlias): string
    {
        $sql = $this->compileFormulaTerm($t, $p, $rowAlias);
        while ($p < count($t) && $t[$p]['type'] === 'op' && in_array($t[$p]['value'], ['+', '-'], true)) {
            $op = $t[$p]['value'];
            $p++;
            $right = $this->compileFormulaTerm($t, $p, $rowAlias);
            $sql = '(' . $sql . ' ' . $op . ' ' . $right . ')';
        }
        return $sql;
    }

    private function compileFormulaTerm(array $t, int &$p, string $rowAlias): string
    {
        $sql = $this->compileFormulaFactor($t, $p, $rowAlias);
        while ($p < count($t) && $t[$p]['type'] === 'op' && in_array($t[$p]['value'], ['*', '/'], true)) {
            $op = $t[$p]['value'];
            $p++;
            $right = $this->compileFormulaFactor($t, $p, $rowAlias);
            $sql = '(' . $sql . ' ' . $op . ' ' . $right . ')';
        }
        return $sql;
    }

    private function compileFormulaFactor(array $t, int &$p, string $rowAlias): string
    {
        if ($p >= count($t)) throw new RuntimeException('Unexpected end of formula.');

        if ($t[$p]['type'] === 'op' && $t[$p]['value'] === '-') {
            $p++;
            return '(-' . $this->compileFormulaFactor($t, $p, $rowAlias) . ')';
        }

        if ($t[$p]['type'] === 'lparen') {
            $p++;
            $inner = $this->compileFormulaArith($t, $p, $rowAlias);
            if ($p >= count($t) || $t[$p]['type'] !== 'rparen') {
                throw new RuntimeException('Missing closing parenthesis.');
            }
            $p++;
            return '(' . $inner . ')';
        }

        if ($t[$p]['type'] === 'number') {
            $v = $t[$p++]['value'];
            return (string)(floor($v) == $v ? (int)$v : $v);
        }

        if ($this->isMetricStart($t, $p)) {
            return $this->compileMetricNode($t, $p, $rowAlias);
        }

        throw new RuntimeException('Expected metric phrase or number, got "' . self::tokenDisplay($t[$p]) . '".');
    }

    private function compileMetricNode(array $t, int &$p, string $rowAlias): string
    {
        $metric = $this->consumeMetricKeywords($t, $p, true);
        $withSql = null;
        if ($p < count($t) && $t[$p]['type'] === 'kw_with') {
            $p++;
            $withStart = $p;
            $this->compileBoolOr($t, $p, 'c');
            $withTokens = array_slice($t, $withStart, $p - $withStart);
            if (empty($withTokens)) throw new RuntimeException('WITH clause cannot be empty.');
            $withSql = $this->compileBoolFromTokens($withTokens, 'c');
        }

        $maxSeqSql = '(SELECT MAX(seq) FROM base)';
        return match ($metric) {
            'COUNT BEFORE' => $this->countMetricSql($rowAlias, '<', $withSql),
            'COUNT AFTER' => $this->countMetricSql($rowAlias, '>', $withSql),
            'COUNT STREAK BEFORE' => $this->countStreakBeforeSql($rowAlias, $withSql),
            'COUNT STREAK AFTER' => $this->countStreakAfterSql($rowAlias, $withSql, $maxSeqSql),
            default => $this->timeMetricSql($metric, $rowAlias, $withSql),
        };
    }

    private function countMetricSql(string $rowAlias, string $directionOp, ?string $withSql): string
    {
        $where = "c.seq {$directionOp} {$rowAlias}.seq";
        if ($withSql !== null) $where .= " AND ({$withSql})";
        return "(SELECT COUNT(*) FROM base c WHERE {$where})";
    }

    private function countStreakBeforeSql(string $rowAlias, ?string $withSql): string
    {
        if ($withSql === null) {
            return "({$rowAlias}.seq - 1)";
        }
        return "(SELECT COUNT(*) FROM base c
            WHERE c.seq < {$rowAlias}.seq
              AND c.seq >= COALESCE(
                    (SELECT MAX(f.seq) + 1 FROM base f WHERE f.seq < {$rowAlias}.seq AND NOT ({$this->swapAlias($withSql, 'c', 'f')})),
                    1
                  )
              AND ({$withSql})
        )";
    }

    private function countStreakAfterSql(string $rowAlias, ?string $withSql, string $maxSeqSql): string
    {
        if ($withSql === null) {
            return "(({$maxSeqSql}) - {$rowAlias}.seq)";
        }
        return "(SELECT COUNT(*) FROM base c
            WHERE c.seq > {$rowAlias}.seq
              AND c.seq <= COALESCE(
                    (SELECT MIN(f.seq) - 1 FROM base f WHERE f.seq > {$rowAlias}.seq AND NOT ({$this->swapAlias($withSql, 'c', 'f')})),
                    {$maxSeqSql}
                  )
              AND ({$withSql})
        )";
    }

    private function timeMetricSql(string $metric, string $rowAlias, ?string $withSql): string
    {
        if (!preg_match('/^(SECONDS|MINUTES|HOURS|DAYS) (BEFORE|AFTER) (LAST|FIRST)$/', $metric, $m)) {
            throw new RuntimeException('Unknown metric phrase: "' . $metric . '".');
        }
        [, $unit, $dir, $anchor] = $m;
        $cmp = $dir === 'BEFORE' ? '<' : '>';
        $agg = $anchor === 'LAST'
            ? ($dir === 'BEFORE' ? 'MAX' : 'MIN')
            : ($dir === 'BEFORE' ? 'MIN' : 'MAX');
        $where = "c.seq {$cmp} {$rowAlias}.seq";
        if ($withSql !== null) $where .= " AND ({$withSql})";

        $targetTs = "(SELECT {$agg}(" . $this->epochExpr('c.received_at') . ") FROM base c WHERE {$where})";
        $diff = "(ABS(" . $this->epochExpr($rowAlias . '.received_at') . " - {$targetTs}))";
        return match ($unit) {
            'SECONDS' => $diff,
            'MINUTES' => "({$diff} / 60.0)",
            'HOURS'   => "({$diff} / 3600.0)",
            'DAYS'    => "({$diff} / 86400.0)",
        };
    }

    private function compileBoolFromTokens(array $tokens, string $rowAlias): string
    {
        $pos = 0;
        $sql = $this->compileBoolOr($tokens, $pos, $rowAlias);
        if ($pos < count($tokens)) {
            throw new RuntimeException('Unexpected token: "' . self::tokenDisplay($tokens[$pos]) . '"');
        }
        return $sql;
    }

    private function compileBoolOr(array $t, int &$p, string $rowAlias): string
    {
        $sql = $this->compileBoolAnd($t, $p, $rowAlias);
        while ($p < count($t) && $t[$p]['type'] === 'or') {
            $p++;
            $sql = '(' . $sql . ' OR ' . $this->compileBoolAnd($t, $p, $rowAlias) . ')';
        }
        return $sql;
    }

    private function compileBoolAnd(array $t, int &$p, string $rowAlias): string
    {
        $sql = $this->compileBoolCmp($t, $p, $rowAlias);
        while ($p < count($t) && $t[$p]['type'] === 'and') {
            $p++;
            $sql = '(' . $sql . ' AND ' . $this->compileBoolCmp($t, $p, $rowAlias) . ')';
        }
        return $sql;
    }

    private function compileBoolCmp(array $t, int &$p, string $rowAlias): string
    {
        $left = $this->compileBoolAdd($t, $p, $rowAlias);
        $cmpOps = ['=', '!=', '>', '>=', '<', '<='];
        if ($p < count($t) && $t[$p]['type'] === 'op' && in_array($t[$p]['value'], $cmpOps, true)) {
            $op = $t[$p]['value'] === '=' ? '=' : $t[$p]['value'];
            $p++;
            $right = $this->compileBoolAdd($t, $p, $rowAlias);
            return '(' . $left . ' ' . $op . ' ' . $right . ')';
        }
        return '(COALESCE(' . $left . ', 0) <> 0)';
    }

    private function compileBoolAdd(array $t, int &$p, string $rowAlias): string
    {
        $sql = $this->compileBoolMul($t, $p, $rowAlias);
        while ($p < count($t) && $t[$p]['type'] === 'op' && in_array($t[$p]['value'], ['+', '-'], true)) {
            $op = $t[$p]['value'];
            $p++;
            $sql = '(' . $sql . ' ' . $op . ' ' . $this->compileBoolMul($t, $p, $rowAlias) . ')';
        }
        return $sql;
    }

    private function compileBoolMul(array $t, int &$p, string $rowAlias): string
    {
        $sql = $this->compileBoolUnary($t, $p, $rowAlias);
        while ($p < count($t) && $t[$p]['type'] === 'op' && in_array($t[$p]['value'], ['*', '/'], true)) {
            $op = $t[$p]['value'];
            $p++;
            $sql = '(' . $sql . ' ' . $op . ' ' . $this->compileBoolUnary($t, $p, $rowAlias) . ')';
        }
        return $sql;
    }

    private function compileBoolUnary(array $t, int &$p, string $rowAlias): string
    {
        if ($p < count($t) && $t[$p]['type'] === 'not') {
            $p++;
            return '(NOT ' . $this->compileBoolUnary($t, $p, $rowAlias) . ')';
        }
        if ($p < count($t) && $t[$p]['type'] === 'op' && $t[$p]['value'] === '-') {
            $p++;
            return '(-' . $this->compileBoolPrimary($t, $p, $rowAlias) . ')';
        }
        return $this->compileBoolPrimary($t, $p, $rowAlias);
    }

    private function compileBoolPrimary(array $t, int &$p, string $rowAlias): string
    {
        if ($p >= count($t)) throw new RuntimeException('Unexpected end of expression.');
        $tok = $t[$p];

        if ($tok['type'] === 'number') {
            $p++;
            return (string)(floor($tok['value']) == $tok['value'] ? (int)$tok['value'] : $tok['value']);
        }
        if ($tok['type'] === 'string') {
            $p++;
            return $this->quoteSqlString($tok['value']);
        }
        if ($tok['type'] === 'null') {
            $p++;
            return 'NULL';
        }
        if ($tok['type'] === 'placeholder') {
            $p++;
            return $this->placeholderToSql($tok['value'], $rowAlias);
        }
        if ($tok['type'] === 'field') {
            $p++;
            return 'NULL';
        }
        if ($tok['type'] === 'lparen') {
            $p++;
            $inner = $this->compileBoolOr($t, $p, $rowAlias);
            if ($p >= count($t) || $t[$p]['type'] !== 'rparen') {
                throw new RuntimeException('Missing closing parenthesis.');
            }
            $p++;
            return '(' . $inner . ')';
        }

        throw new RuntimeException('Unexpected token: "' . self::tokenDisplay($tok) . '"');
    }

    private function compileConditionOr(array $t, int &$p, string $rowAlias): string
    {
        $sql = $this->compileConditionAnd($t, $p, $rowAlias);
        while ($p < count($t) && $t[$p]['type'] === 'or') {
            $p++;
            $sql = '(' . $sql . ' OR ' . $this->compileConditionAnd($t, $p, $rowAlias) . ')';
        }
        return $sql;
    }

    private function compileConditionAnd(array $t, int &$p, string $rowAlias): string
    {
        $sql = $this->compileConditionCmp($t, $p, $rowAlias);
        while ($p < count($t) && $t[$p]['type'] === 'and') {
            $p++;
            $sql = '(' . $sql . ' AND ' . $this->compileConditionCmp($t, $p, $rowAlias) . ')';
        }
        return $sql;
    }

    private function compileConditionCmp(array $t, int &$p, string $rowAlias): string
    {
        $left = $this->compileFormulaArith($t, $p, $rowAlias);
        $cmpOps = ['=', '!=', '>', '>=', '<', '<='];
        if ($p < count($t) && $t[$p]['type'] === 'op' && in_array($t[$p]['value'], $cmpOps, true)) {
            $op = $t[$p]['value'];
            $p++;
            $right = $this->compileFormulaArith($t, $p, $rowAlias);
            return '(' . $left . ' ' . $op . ' ' . $right . ')';
        }
        return '(COALESCE(' . $left . ', 0) <> 0)';
    }

    private function compileAggregateCondition(string $condition, array $mapping): string
    {
        $tokens = self::tokenizeAggregate($condition);
        $pos    = 0;
        $sql    = $this->compileAggConditionOr($tokens, $pos, $mapping);
        if ($pos < count($tokens)) {
            throw new RuntimeException('Unexpected token: "' . self::tokenDisplay($tokens[$pos]) . '"');
        }
        return $sql;
    }

    private function compileAggConditionOr(array $t, int &$p, array $mapping): string
    {
        $sql = $this->compileAggConditionAnd($t, $p, $mapping);
        while ($p < count($t) && $t[$p]['type'] === 'or') {
            $p++;
            $sql = '(' . $sql . ' OR ' . $this->compileAggConditionAnd($t, $p, $mapping) . ')';
        }
        return $sql;
    }

    private function compileAggConditionAnd(array $t, int &$p, array $mapping): string
    {
        $sql = $this->compileAggConditionCmp($t, $p, $mapping);
        while ($p < count($t) && $t[$p]['type'] === 'and') {
            $p++;
            $sql = '(' . $sql . ' AND ' . $this->compileAggConditionCmp($t, $p, $mapping) . ')';
        }
        return $sql;
    }

    private function compileAggConditionCmp(array $t, int &$p, array $mapping): string
    {
        $left = $this->compileAggExpr($t, $p, $mapping);
        $cmpOps = ['=', '!=', '>', '>=', '<', '<='];
        if ($p < count($t) && $t[$p]['type'] === 'op' && in_array($t[$p]['value'], $cmpOps, true)) {
            $op = $t[$p]['value'];
            $p++;
            $right = $this->compileAggExpr($t, $p, $mapping);
            return '(' . $left . ' ' . $op . ' ' . $right . ')';
        }
        return '(COALESCE(' . $left . ', 0) <> 0)';
    }

    private function compileAggExpr(array $t, int &$p, array $mapping): string
    {
        $sql = $this->compileAggTerm($t, $p, $mapping);
        while ($p < count($t) && $t[$p]['type'] === 'op' && in_array($t[$p]['value'], ['+', '-'], true)) {
            $op = $t[$p]['value'];
            $p++;
            $sql = '(' . $sql . ' ' . $op . ' ' . $this->compileAggTerm($t, $p, $mapping) . ')';
        }
        return $sql;
    }

    private function compileAggTerm(array $t, int &$p, array $mapping): string
    {
        $sql = $this->compileAggFactor($t, $p, $mapping);
        while ($p < count($t) && $t[$p]['type'] === 'op' && in_array($t[$p]['value'], ['*', '/'], true)) {
            $op = $t[$p]['value'];
            $p++;
            $sql = '(' . $sql . ' ' . $op . ' ' . $this->compileAggFactor($t, $p, $mapping) . ')';
        }
        return $sql;
    }

    private function compileAggFactor(array $t, int &$p, array $mapping): string
    {
        if ($p >= count($t)) throw new RuntimeException('Unexpected end of expression.');
        $tok = $t[$p];

        if ($tok['type'] === 'op' && $tok['value'] === '-') {
            $p++;
            return '(-' . $this->compileAggFactor($t, $p, $mapping) . ')';
        }
        if ($tok['type'] === 'number') {
            $p++;
            return (string)(floor($tok['value']) == $tok['value'] ? (int)$tok['value'] : $tok['value']);
        }
        if ($tok['type'] === 'agg') {
            $p++;
            $name = strtoupper($tok['value']);
            if (!isset($mapping[$name])) {
                throw new RuntimeException('Unsupported aggregate token: "' . $name . '"');
            }
            return $mapping[$name];
        }
        if ($tok['type'] === 'lparen') {
            $p++;
            $inner = $this->compileAggConditionOr($t, $p, $mapping);
            if ($p >= count($t) || $t[$p]['type'] !== 'rparen') {
                throw new RuntimeException('Missing closing parenthesis.');
            }
            $p++;
            return '(' . $inner . ')';
        }
        throw new RuntimeException('Unexpected token: "' . self::tokenDisplay($tok) . '"');
    }

    private static function validateAggConditionOr(array $t, int &$p): void
    {
        self::validateAggConditionAnd($t, $p);
        while ($p < count($t) && $t[$p]['type'] === 'or') {
            $p++;
            self::validateAggConditionAnd($t, $p);
        }
    }

    private static function validateAggConditionAnd(array $t, int &$p): void
    {
        self::validateAggConditionCmp($t, $p);
        while ($p < count($t) && $t[$p]['type'] === 'and') {
            $p++;
            self::validateAggConditionCmp($t, $p);
        }
    }

    private static function validateAggConditionCmp(array $t, int &$p): void
    {
        self::validateAggExpr($t, $p);
        $cmpOps = ['=', '!=', '>', '>=', '<', '<='];
        if ($p < count($t) && $t[$p]['type'] === 'op' && in_array($t[$p]['value'], $cmpOps, true)) {
            $p++;
            self::validateAggExpr($t, $p);
        }
    }

    private static function validateAggExpr(array $t, int &$p): void
    {
        self::validateAggTerm($t, $p);
        while ($p < count($t) && $t[$p]['type'] === 'op' && in_array($t[$p]['value'], ['+', '-'], true)) {
            $p++;
            self::validateAggTerm($t, $p);
        }
    }

    private static function validateAggTerm(array $t, int &$p): void
    {
        self::validateAggFactor($t, $p);
        while ($p < count($t) && $t[$p]['type'] === 'op' && in_array($t[$p]['value'], ['*', '/'], true)) {
            $p++;
            self::validateAggFactor($t, $p);
        }
    }

    private static function validateAggFactor(array $t, int &$p): void
    {
        if ($p >= count($t)) throw new RuntimeException('Unexpected end of expression.');

        if ($t[$p]['type'] === 'op' && $t[$p]['value'] === '-') {
            $p++;
            self::validateAggFactor($t, $p);
            return;
        }
        if ($t[$p]['type'] === 'number' || $t[$p]['type'] === 'agg') {
            $p++;
            return;
        }
        if ($t[$p]['type'] === 'lparen') {
            $p++;
            self::validateAggConditionOr($t, $p);
            if ($p >= count($t) || $t[$p]['type'] !== 'rparen') {
                throw new RuntimeException('Missing closing parenthesis.');
            }
            $p++;
            return;
        }
        throw new RuntimeException('Unexpected token: "' . self::tokenDisplay($t[$p]) . '"');
    }

    private static function normalizeAggConditionOr(array $t, int &$p): string
    {
        $parts = [self::normalizeAggConditionAnd($t, $p)];
        while ($p < count($t) && $t[$p]['type'] === 'or') {
            $parts[] = 'OR';
            $p++;
            $parts[] = self::normalizeAggConditionAnd($t, $p);
        }
        return implode(' ', $parts);
    }

    private static function normalizeAggConditionAnd(array $t, int &$p): string
    {
        $parts = [self::normalizeAggConditionCmp($t, $p)];
        while ($p < count($t) && $t[$p]['type'] === 'and') {
            $parts[] = 'AND';
            $p++;
            $parts[] = self::normalizeAggConditionCmp($t, $p);
        }
        return implode(' ', $parts);
    }

    private static function normalizeAggConditionCmp(array $t, int &$p): string
    {
        $left = self::normalizeAggExpr($t, $p);
        $cmpOps = ['=', '!=', '>', '>=', '<', '<='];
        if ($p < count($t) && $t[$p]['type'] === 'op' && in_array($t[$p]['value'], $cmpOps, true)) {
            $op = $t[$p]['value'];
            $p++;
            return trim($left . ' ' . $op . ' ' . self::normalizeAggExpr($t, $p));
        }
        return $left;
    }

    private static function normalizeAggExpr(array $t, int &$p): string
    {
        $parts = [self::normalizeAggTerm($t, $p)];
        while ($p < count($t) && $t[$p]['type'] === 'op' && in_array($t[$p]['value'], ['+', '-'], true)) {
            $parts[] = $t[$p]['value'];
            $p++;
            $parts[] = self::normalizeAggTerm($t, $p);
        }
        return implode(' ', $parts);
    }

    private static function normalizeAggTerm(array $t, int &$p): string
    {
        $parts = [self::normalizeAggFactor($t, $p)];
        while ($p < count($t) && $t[$p]['type'] === 'op' && in_array($t[$p]['value'], ['*', '/'], true)) {
            $parts[] = $t[$p]['value'];
            $p++;
            $parts[] = self::normalizeAggFactor($t, $p);
        }
        return implode(' ', $parts);
    }

    private static function normalizeAggFactor(array $t, int &$p): string
    {
        if ($p >= count($t)) return '';
        if ($t[$p]['type'] === 'op' && $t[$p]['value'] === '-') {
            $p++;
            return '- ' . self::normalizeAggFactor($t, $p);
        }
        if ($t[$p]['type'] === 'number') {
            $v = $t[$p++]['value'];
            return (string)(floor($v) == $v ? (int)$v : $v);
        }
        if ($t[$p]['type'] === 'agg') {
            return strtoupper($t[$p++]['value']);
        }
        if ($t[$p]['type'] === 'lparen') {
            $p++;
            $inner = self::normalizeAggConditionOr($t, $p);
            if ($p < count($t) && $t[$p]['type'] === 'rparen') $p++;
            return '(' . $inner . ')';
        }
        $p++;
        return '';
    }

    private function groupKeyExpr(string $col, string $groupBy): string
    {
        return match ($groupBy) {
            'day' => DB_TYPE === 'mysql'
                ? "DATE_FORMAT({$col}, '%Y-%m-%d')"
                : "strftime('%Y-%m-%d', {$col})",
            'week' => DB_TYPE === 'mysql'
                ? "DATE_FORMAT({$col}, '%x-W%v')"
                : "(strftime('%Y', {$col}) || '-W' || printf('%02d', CAST(strftime('%W', {$col}) AS INTEGER)))",
            'month' => DB_TYPE === 'mysql'
                ? "DATE_FORMAT({$col}, '%Y-%m')"
                : "strftime('%Y-%m', {$col})",
            default => throw new RuntimeException('Unsupported grouping: ' . $groupBy),
        };
    }

    private function epochExpr(string $col): string
    {
        return DB_TYPE === 'mysql'
            ? "UNIX_TIMESTAMP({$col})"
            : "CAST(strftime('%s', {$col}) AS INTEGER)";
    }

    private function placeholderToSql(string $name, string $rowAlias): string
    {
        $lower = strtolower(trim($name));
        if (str_starts_with($lower, 'params.')) {
            $paramName = substr($name, 7);
            return $this->queryParamExpr("{$rowAlias}.query_string", $paramName);
        }
        return match ($lower) {
            'body'     => "{$rowAlias}.body",
            'status'   => "{$rowAlias}.validated",
            'ts'       => $this->epochExpr("{$rowAlias}.received_at"),
            'method'   => "{$rowAlias}.method",
            'ip'       => "{$rowAlias}.ip",
            'known_ip' => "{$rowAlias}.known_ip",
            'path'     => "{$rowAlias}.path",
            default    => 'NULL',
        };
    }

    private function queryParamExpr(string $queryStringExpr, string $paramName): string
    {
        $paramName = trim($paramName);
        if ($paramName === '') {
            return 'NULL';
        }

        $needle = $this->quoteSqlString('&' . rawurlencode($paramName) . '=');

        if (DB_TYPE === 'mysql') {
            $wrapped = "CONCAT('&', COALESCE({$queryStringExpr}, ''), '&')";
            $start = "LOCATE({$needle}, {$wrapped})";
            $valueStart = "({$start} + CHAR_LENGTH({$needle}))";
            $tail = "SUBSTRING({$wrapped}, {$valueStart})";
            $end = "LOCATE('&', {$tail})";
            return "CASE
                WHEN {$start} = 0 THEN NULL
                WHEN {$end} = 0 THEN {$tail}
                ELSE SUBSTRING({$tail}, 1, {$end} - 1)
            END";
        }

        $wrapped = "('&' || COALESCE({$queryStringExpr}, '') || '&')";
        $start = "instr({$wrapped}, {$needle})";
        $valueStart = "({$start} + length({$needle}))";
        $tail = "substr({$wrapped}, {$valueStart})";
        $end = "instr({$tail}, '&')";
        return "CASE
            WHEN {$start} = 0 THEN NULL
            WHEN {$end} = 0 THEN {$tail}
            ELSE substr({$tail}, 1, {$end} - 1)
        END";
    }

    private function swapAlias(string $sql, string $fromAlias, string $toAlias): string
    {
        return str_replace($fromAlias . '.', $toAlias . '.', $sql);
    }

    private function quoteSqlString(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }

    private function isMetricStart(array $tokens, int $pos): bool
    {
        if ($pos >= count($tokens)) return false;
        return in_array($tokens[$pos]['type'], ['kw_count', 'kw_seconds', 'kw_minutes', 'kw_hours', 'kw_days'], true);
    }

    private function consumeMetricKeywords(array $tokens, int &$pos, bool $strict = false): ?string
    {
        $startPos = $pos;
        $keywords = [];
        while ($pos < count($tokens)) {
            $type = $tokens[$pos]['type'];
            if (!str_starts_with($type, 'kw_') || $type === 'kw_with') break;
            $keywords[] = strtoupper(substr($type, 3));
            $pos++;
        }

        $metric = implode(' ', $keywords);
        $knownMetrics = ['COUNT BEFORE', 'COUNT AFTER', 'COUNT STREAK BEFORE', 'COUNT STREAK AFTER'];
        $timePattern  = '/^(SECONDS|MINUTES|HOURS|DAYS) (BEFORE|AFTER) (LAST|FIRST)$/';
        if (in_array($metric, $knownMetrics, true) || preg_match($timePattern, $metric)) {
            return $metric;
        }
        if ($strict) {
            throw new RuntimeException('Unknown metric phrase: "' . $metric . '".');
        }
        $pos = $startPos;
        return null;
    }

    private static function tokenizeDsl(string $expr): array
    {
        $tokens = [];
        $i = 0;
        $len = strlen($expr);

        while ($i < $len) {
            $ch = $expr[$i];
            if (ctype_space($ch)) { $i++; continue; }

            if (substr($expr, $i, 2) === '{{') {
                $i += 2;
                $name = '';
                while ($i < $len && substr($expr, $i, 2) !== '}}') $name .= $expr[$i++];
                $i += 2;
                $tokens[] = ['type' => 'placeholder', 'value' => trim($name)];
                continue;
            }

            if ($ch === '"' || $ch === "'") {
                $quote = $ch;
                $i++;
                $str = '';
                while ($i < $len && $expr[$i] !== $quote) $str .= $expr[$i++];
                $i++;
                $tokens[] = ['type' => 'string', 'value' => $str];
                continue;
            }

            if (ctype_digit($ch) || ($ch === '.' && $i + 1 < $len && ctype_digit($expr[$i + 1]))) {
                $num = '';
                while ($i < $len && (ctype_digit($expr[$i]) || $expr[$i] === '.')) $num .= $expr[$i++];
                $tokens[] = ['type' => 'number', 'value' => (float)$num];
                continue;
            }

            $two = substr($expr, $i, 2);
            if (in_array($two, ['!=', '>=', '<='], true)) {
                $tokens[] = ['type' => 'op', 'value' => $two];
                $i += 2;
                continue;
            }

            if (in_array($ch, ['=', '>', '<', '+', '-', '*', '/'], true)) {
                $tokens[] = ['type' => 'op', 'value' => $ch];
                $i++;
                continue;
            }
            if ($ch === '(') { $tokens[] = ['type' => 'lparen']; $i++; continue; }
            if ($ch === ')') { $tokens[] = ['type' => 'rparen']; $i++; continue; }

            if (ctype_alpha($ch) || $ch === '_') {
                $word = '';
                while ($i < $len && (ctype_alnum($expr[$i]) || $expr[$i] === '_')) $word .= $expr[$i++];
                $upper = strtoupper($word);
                $tokens[] = match ($upper) {
                    'AND'     => ['type' => 'and'],
                    'OR'      => ['type' => 'or'],
                    'NOT'     => ['type' => 'not'],
                    'NULL'    => ['type' => 'null'],
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

    private static function tokenizeAggregate(string $expr): array
    {
        $tokens = [];
        $i = 0;
        $len = strlen($expr);

        while ($i < $len) {
            $ch = $expr[$i];
            if (ctype_space($ch)) { $i++; continue; }

            if (ctype_digit($ch) || ($ch === '.' && $i + 1 < $len && ctype_digit($expr[$i + 1]))) {
                $num = '';
                while ($i < $len && (ctype_digit($expr[$i]) || $expr[$i] === '.')) $num .= $expr[$i++];
                $tokens[] = ['type' => 'number', 'value' => (float)$num];
                continue;
            }

            $two = substr($expr, $i, 2);
            if (in_array($two, ['!=', '>=', '<='], true)) {
                $tokens[] = ['type' => 'op', 'value' => $two];
                $i += 2;
                continue;
            }

            if (in_array($ch, ['=', '>', '<', '+', '-', '*', '/'], true)) {
                $tokens[] = ['type' => 'op', 'value' => $ch];
                $i++;
                continue;
            }
            if ($ch === '(') { $tokens[] = ['type' => 'lparen']; $i++; continue; }
            if ($ch === ')') { $tokens[] = ['type' => 'rparen']; $i++; continue; }

            if (ctype_alpha($ch) || $ch === '_') {
                $word = '';
                while ($i < $len && (ctype_alnum($expr[$i]) || $expr[$i] === '_')) $word .= $expr[$i++];
                $upper = strtoupper($word);
                $tokens[] = match ($upper) {
                    'AND' => ['type' => 'and'],
                    'OR'  => ['type' => 'or'],
                    'SUM', 'MAX', 'MIN', 'AVG' => ['type' => 'agg', 'value' => $upper],
                    default => ['type' => 'field', 'value' => $upper],
                };
                continue;
            }

            $i++;
        }

        return $tokens;
    }

    private static function tokenDisplay(array $token): string
    {
        return $token['value'] ?? $token['type'];
    }
}
