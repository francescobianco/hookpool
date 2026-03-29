Perfetto, ti riassumo tutto in una **specifica coerente, pulita e copiabile** della tua DSL nella versione finale che hai definito.

---

# 🧠 DSL LOG SEQUENZIALI – SPECIFICA V1

## 🎯 Obiettivo

Definire un linguaggio **semplice, sicuro e dichiarativo** per analizzare sequenze temporali (log ON/OFF, eventi, ecc.), basato su:

* **metriche implicite** (COUNT, DAYS, ecc.)
* **navigazione temporale** (BEFORE, AFTER, FIRST, LAST)
* **filtri dinamici** (`WITH`)
* **nessuna sintassi complessa (no parentesi, no THIS)**

---

# 🧩 STRUTTURA GENERALE

```text
<METRIC PHRASE> [WITH <BOOLEAN_EXPR>]
```

### Esempio

```text
DAYS BEFORE LAST WITH {{pluto}} > 10
```

---

# 🧱 COMPONENTI DEL LINGUAGGIO

## 1. 📊 METRIC PHRASE (obbligatoria)

### Conteggi

```text
COUNT BEFORE
COUNT AFTER
COUNT RUN BEFORE
COUNT RUN AFTER
```

### Metriche temporali

```text
SECONDS BEFORE LAST
SECONDS AFTER FIRST

MINUTES BEFORE LAST
MINUTES AFTER FIRST

HOURS BEFORE LAST
HOURS AFTER FIRST

DAYS BEFORE LAST
DAYS AFTER FIRST
```

---

## 2. 🔍 WITH CLAUSE (opzionale)

```text
WITH <BOOLEAN_EXPR>
```

Serve a **filtrare i record candidati**.

👉 Se presente, la metrica opera solo sui record che soddisfano la condizione.

---

## 3. ⚖️ BOOLEAN_EXPR (dentro WITH)

Espressione booleana valutata su ogni record candidato.

### Supporta:

#### Confronti

```text
=  !=  >  >=  <  <=
```

#### Operatori logici

```text
AND  OR  NOT
```

#### Operatori aritmetici

```text
+  -  *  /
```

#### Operandi

* Campi del record

```text
VALUE
STATUS
TS
```

* Placeholder (espressioni riusabili)

```text
{{pluto}}
{{delta}}
```

* Costanti

```text
10
"ON"
NULL
```

---

# 🧠 SEMANTICA

## 📍 Record corrente

* Sempre implicito
* Non esiste `THIS`

---

## ⏪ BEFORE / AFTER

* `BEFORE` → record precedenti
* `AFTER` → record successivi

---

## 🎯 FIRST / LAST

* `LAST` → record più vicino nel passato
* `FIRST` → record più vicino nel futuro

---

## 🔁 RUN

* `COUNT RUN BEFORE` → conteggio consecutivo all’indietro
* `COUNT RUN AFTER` → conteggio consecutivo in avanti

---

## 🧮 Valutazione WITH

⚠️ **IMPORTANTE**

La clausola `WITH` è una **espressione interna**, non un confronto esterno.

```text
DAYS BEFORE LAST WITH {{pluto}} > 10
```

viene interpretato come:

```text
DAYS BEFORE LAST WITH ({{pluto}} > 10)
```

👉 NON come:

```text
(DAYS BEFORE LAST WITH {{pluto}}) > 10
```

---

## 🧭 Semantica operativa

### Esempio:

```text
DAYS BEFORE LAST WITH VALUE > 10
```

1. guarda i record precedenti
2. filtra quelli con `VALUE > 10`
3. prende l’ultimo tra questi
4. calcola la differenza in giorni col record corrente

---

### Esempio:

```text
COUNT AFTER WITH {{pluto}} >= 5
```

1. guarda i record successivi
2. valuta `{{pluto}}` su ciascuno
3. conta quelli con valore ≥ 5

---

### Esempio:

```text
COUNT RUN AFTER WITH VALUE = 0
```

1. guarda i record successivi in ordine
2. conta finché `VALUE = 0` resta vero consecutivamente

---

# 🧪 ESEMPI

```text
DAYS BEFORE LAST WITH {{pluto}} > 10

COUNT AFTER WITH VALUE = 0

COUNT RUN AFTER WITH VALUE = 0

SECONDS AFTER FIRST WITH STATUS = "ON"

HOURS BEFORE LAST WITH {{delta}} >= 5

COUNT BEFORE WITH {{pluto}} != 0

DAYS AFTER FIRST WITH VALUE >= 100
```

---

# 🧬 PLACEHOLDER `{{...}}`

## Definizione

```text
{{nome}}
```

Rappresenta:

* un’espressione salvata
* oppure una metrica calcolata

## Tipi

### Predicate (BOOL)

```text
{{is_null}}
{{pump_on}}
```

### Metric (NUMBER)

```text
{{delta}}
{{pluto}}
```

---

# 🏗️ MODELLO DI ESECUZIONE

Per ogni record:

1. si identifica il dominio (`BEFORE` / `AFTER`)
2. si scorrono i record candidati
3. si valuta `WITH` su ciascun record candidato
4. si seleziona:

    * `LAST` → primo match all’indietro
    * `FIRST` → primo match in avanti
    * `COUNT` → tutti
    * `RUN` → fino a rottura
5. si calcola la metrica

---

# 🔒 SICUREZZA

Il DSL è sicuro perché:

* nessun codice eseguibile
* niente accesso a funzioni PHP
* niente loop arbitrari
* solo funzioni whitelist
* espressioni limitate e controllate
* AST validabile

---

# 🧩 GRAMMATICA (SEMPLIFICATA)

```ebnf
EXPR            := METRIC_EXPR ( WITH_CLAUSE )? ;

METRIC_EXPR     :=
      "COUNT BEFORE"
    | "COUNT AFTER"
    | "COUNT RUN BEFORE"
    | "COUNT RUN AFTER"
    | TIME_UNIT RELATION POSITION
    ;

TIME_UNIT       := "SECONDS" | "MINUTES" | "HOURS" | "DAYS" ;
RELATION        := "BEFORE" | "AFTER" ;
POSITION        := "LAST" | "FIRST" ;

WITH_CLAUSE     := "WITH" BOOL_EXPR ;

BOOL_EXPR       := OR_EXPR ;
OR_EXPR         := AND_EXPR ( "OR" AND_EXPR )* ;
AND_EXPR        := CMP_EXPR ( "AND" CMP_EXPR )* ;
CMP_EXPR        := ADD_EXPR ( COMP_OP ADD_EXPR )? ;
ADD_EXPR        := MUL_EXPR ( ("+"|"-") MUL_EXPR )* ;
MUL_EXPR        := UNARY_EXPR ( ("*"|"/") UNARY_EXPR )* ;
UNARY_EXPR      := ("NOT"|"-") UNARY_EXPR | PRIMARY ;
PRIMARY         := NUMBER | STRING | FIELD | PLACEHOLDER | "(" BOOL_EXPR ")" ;

COMP_OP         := "=" | "!=" | ">" | ">=" | "<" | "<=" ;
PLACEHOLDER     := "{{" IDENT "}}" ;
FIELD           := IDENT ;
```

---

# ✅ PRINCIPI CHIAVE

* ❌ niente `THIS`
* ❌ niente parentesi nella metrica
* ✅ linguaggio naturale controllato
* ✅ metriche implicite
* ✅ filtri espressivi con `WITH`
* ✅ sintassi leggibile per utenti non tecnici
* ✅ ottimizzabile lato DB

---

# 🚀 RISULTATO

Hai un DSL:

* leggibile → “quasi linguaggio naturale”
* potente → esprime pattern temporali complessi
* sicuro → niente esecuzione arbitraria
* estendibile → puoi aggiungere metriche senza rompere la sintassi

---

Se vuoi, passo successivo posso darti direttamente:
👉 tokenizer PHP
👉 AST classes
👉 evaluator base su array / DB

così lo porti in produzione.
