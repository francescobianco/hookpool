# Custom Fields — DSL Reference

Custom fields let you define computed columns for the analytics table.
Each field has a **name** and a **formula** written in a small, safe DSL designed for sequential log analysis.

---

## Formula syntax

```
METRIC_PHRASE [WITH BOOLEAN_EXPR]
```

The `WITH` clause is optional. When present, only rows that satisfy its condition are considered by the metric.

---

## Metric phrases

### Count metrics

| Formula | Description |
|---|---|
| `COUNT BEFORE` | Number of events that occurred before the current one |
| `COUNT AFTER` | Number of events that occurred after the current one |
| `COUNT STREAK BEFORE` | Consecutive count backwards (stops at first non-matching row) |
| `COUNT STREAK AFTER` | Consecutive count forwards (stops at first non-matching row) |

### Time-distance metrics

```
SECONDS | MINUTES | HOURS | DAYS   BEFORE LAST
SECONDS | MINUTES | HOURS | DAYS   AFTER  FIRST
```

These compute the time elapsed between the current event and a reference event:

| Position | Meaning |
|---|---|
| `BEFORE LAST` | The most recent matching event in the past |
| `BEFORE FIRST` | The oldest matching event in the past |
| `AFTER FIRST` | The nearest matching event in the future |
| `AFTER LAST` | The furthest matching event in the future |

Returns `null` when no matching event is found.

---

## WITH clause

```
WITH <boolean_expression>
```

Filters the candidate rows before the metric is applied. The expression is evaluated on each candidate row.

Examples:

```
COUNT BEFORE WITH STATUS = 1
DAYS BEFORE LAST WITH METHOD = "POST"
COUNT RUN AFTER WITH VALUE > 100
```

---

## Boolean expressions

Expressions support:

**Comparison operators:** `=` `!=` `>` `>=` `<` `<=`

**Logical operators:** `AND` `OR` `NOT`

**Arithmetic operators:** `+` `-` `*` `/`

**Grouping:** `( ... )`

---

## Built-in fields

These fields refer to the **candidate row** being evaluated inside `WITH`:

| Field | Type | Description |
|---|---|---|
| `VALUE` | number | Event body parsed as a float (0 if non-numeric) |
| `STATUS` | number | `1` if the event passed all guards, `0` otherwise |
| `TS` | number | Event timestamp as a Unix integer |
| `METHOD` | string | HTTP method (e.g. `"POST"`, `"GET"`) |

---

## Placeholders

You can reference other custom fields defined on the same view:

```
{{field_name}}
```

The placeholder is resolved to the value computed for that named field on the **same row**.
Fields are evaluated in the order they were added, so a placeholder can only reference a field defined **before** the current one.

---

## Execution model

For each row at index `i`:

1. Determine the candidate set:
   - `BEFORE` → rows `0 … i-1` (ordered chronologically)
   - `AFTER` → rows `i+1 … end`
2. If a `WITH` clause is present, keep only candidates where the expression is true.
3. Select the reference point:
   - `LAST` → nearest candidate (`end` of BEFORE set, `start` of AFTER set)
   - `FIRST` → furthest candidate (`start` of BEFORE set, `end` of AFTER set)
   - `COUNT` → all candidates (no selection)
   - `STREAK` → stop scanning at the first non-matching candidate
4. Compute the metric (count or time difference).

---

## Safety

The DSL is sandboxed:

- No arbitrary code execution
- No access to PHP functions
- No unbounded loops
- Only whitelisted operations
- The expression tree is fully validated before evaluation

---

## Examples

```
COUNT BEFORE
```
How many events came before this one.

```
COUNT AFTER WITH STATUS = 1
```
How many valid events follow this one.

```
COUNT STREAK AFTER WITH VALUE = 0
```
How many consecutive events after this one have a zero body value.

```
SECONDS AFTER FIRST WITH METHOD = "POST"
```
Seconds until the next POST event.

```
DAYS BEFORE LAST WITH STATUS = 1
```
Days since the most recent valid event.

```
HOURS BEFORE LAST WITH {{my_flag}} >= 5
```
Hours since the last event where the custom field `my_flag` was ≥ 5.

---

## Grammar (simplified EBNF)

```ebnf
EXPR        := METRIC ( "WITH" BOOL_EXPR )? ;

METRIC      := "COUNT BEFORE"
             | "COUNT AFTER"
             | "COUNT STREAK BEFORE"
             | "COUNT STREAK AFTER"
             | TIME_UNIT DIRECTION ANCHOR ;

TIME_UNIT   := "SECONDS" | "MINUTES" | "HOURS" | "DAYS" ;
DIRECTION   := "BEFORE" | "AFTER" ;
ANCHOR      := "LAST" | "FIRST" ;

BOOL_EXPR   := OR_EXPR ;
OR_EXPR     := AND_EXPR ( "OR" AND_EXPR )* ;
AND_EXPR    := CMP_EXPR ( "AND" CMP_EXPR )* ;
CMP_EXPR    := ADD_EXPR ( COMP_OP ADD_EXPR )? ;
ADD_EXPR    := MUL_EXPR ( ("+" | "-") MUL_EXPR )* ;
MUL_EXPR    := UNARY ( ("*" | "/") UNARY )* ;
UNARY       := ("NOT" | "-") UNARY | PRIMARY ;
PRIMARY     := NUMBER | STRING | FIELD | PLACEHOLDER | "(" BOOL_EXPR ")" ;

COMP_OP     := "=" | "!=" | ">" | ">=" | "<" | "<=" ;
FIELD       := "VALUE" | "STATUS" | "TS" | "METHOD" ;
PLACEHOLDER := "{{" name "}}" ;
```
