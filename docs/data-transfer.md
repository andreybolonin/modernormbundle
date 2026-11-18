# Plan a MySQL to PostgreSQL data transfer

`modernorm:data:transfer` currently implements **planning only**. `--dry-run` is
required. Running without it fails before opening either connection. No loader,
DDL generation, row copying, sequence resets or resume mechanism is implemented.

```bash
php bin/console modernorm:data:transfer \
    --source-url-env=MYSQL_DATABASE_URL \
    --target-url-env=POSTGRES_DATABASE_URL \
    --batch-size=1000 \
    --dry-run
```

Both URL options name environment variables, not literal URLs. Symfony Dotenv
values and process environment variables are supported. The source must use
MySQL/PDO (`pdo_mysql` extension), and target PostgreSQL/PDO (`pdo_pgsql`). MySQL
8.4 is integration-tested; MariaDB and other transfer directions are unsupported.
Credentials are not printed in the report. No application connection is used.

First generate and review attribute entities using `modernorm:mapping:import`.
Configure their mapping paths in the bundle, adapt MySQL-specific types/options,
and set their Table schema to the intended PostgreSQL schema (`public` if absent).
Create and review the PostgreSQL schema before planning a transfer. The command
does not create target tables or modify the generated entities.

## What the plan checks

- All ordinary MySQL tables in the source URL's database are inventoried. Views
  are ignored. Each table must have a single corresponding concrete entity;
  skipped/unmapped tables are errors rather than silent data omissions.
- Source and target table/column names must match the entity's physical names.
  Explicit table/column renaming, table filters and multiple entities with the
  same table name in different target schemas are not supported yet.
- Source, mapping and target need the same single-column primary key. Composite
  IDs and ManyToMany join-table transfer are unsupported.
- Target tables must exist and appear empty to the target connection. Required
  target columns need a mapped source value, a default or server generation.
  Mapped target generated columns cannot receive copied values.
- Physical source/target types and mapped field types are checked for the initial
  supported conversion families. Unsupported conversions are errors.
- Source and target FKs determine parent-before-child order. Self-references,
  cycles and references outside the plan are blockers in this first version.
- Owned PostgreSQL sequences are identified for a future post-load reset. The
  sequence is not advanced or reset by planning, including identity sequences.

The batch size is a planned row limit, a positive integer (default 1000). Each
table's effective size is capped against PostgreSQL's 65535 bind-parameter limit.
Without `--validate-data`, no source batches are read. No rows are ever written
by dry run.

Both connections are placed in read-only REPEATABLE READ transactions and rolled back/closed
after planning. Metadata uses an in-memory cache. Planning reads catalog metadata
and checks target emptiness with `SELECT 1 ... LIMIT 1`; it does not scan or count
source rows unless `--validate-data` is supplied. Changing schemas/data after
planning invalidates the plan.

## Scan all source rows

```bash
php bin/console modernorm:data:transfer \
    --source-url-env=MYSQL_DATABASE_URL \
    --target-url-env=POSTGRES_DATABASE_URL \
    --batch-size=1000 --dry-run --validate-data \
    --max-error-examples=100 --format=json
```

`--validate-data` first runs the structural checks. If they find blockers, scanning
is skipped (`validation.status: skipped_structural_errors`, `data_scanned: false`).
Otherwise every row of every planned source table is read using bounded keyset
batches ordered by its PK, without OFFSET. Numeric key comparisons use explicit
SIGNED/UNSIGNED casts to preserve integer precision above PHP_INT_MAX. InnoDB
source tables and a UTF8 PostgreSQL target are required. PostgreSQL 17+ provides
the `pg_input_is_valid` function used for batched read-only type-input checks.

Checks include integer ranges, NOT NULL, UTF-8/NUL and character-length limits,
boolean 0/1 values, ENUM/UUID input, JSON/JSONB input limits, decimal range/precision,
zero/invalid dates, finite float ranges, TIME duration rejection, and fractional
seconds. Values that need decimal or temporal rounding are rejected. Binary values
are checked as bytes, not UTF-8 text. No temporary inserts or sequence updates
are used. Source data values are not included in error examples.

The report includes per-table/total scanned rows, invalid rows, invalid-value
counts and examples identifying table, PK, column and reason. PK displays are
bounded; long or invalid UTF-8 keys use a truncated base64 representation. The
default example limit is 100. `--max-error-examples=0` keeps counts only. The limit
never stops scanning or error counting. Memory is bounded by row batches, the
small target-validation batches and retained examples; an individual large row
can still require significant memory. Long scans hold database snapshots and
take time proportional to the data size.

`data_scanned: true` is set only after all planned rows have been visited, including
when invalid values were found. Runtime errors or interruption fail the command;
they do not produce a successful complete scan. `validation.status: complete`
describes coverage, not whether values passed: inspect `error_count` and the exit
code. The source and target session timezones are included for review; validation
does not infer timezone conversion or business rules.

## Reports and exit codes

Text reports list tables, column conversions, effective batch sizes, sequence
actions, dependency order, warnings and blockers. For machine-readable output:

```bash
php bin/console modernorm:data:transfer \
    --source-url-env=MYSQL_DATABASE_URL \
    --target-url-env=POSTGRES_DATABASE_URL \
    --dry-run --format=json
```

Exit code 0 means a structural plan was produced without blockers and any requested
value scan found no errors. Exit code 1 means the report contains structural
blockers or invalid values. Invalid options, connection failures,
unsupported introspection types and invalid ORM metadata can fail earlier with
a Console error. JSON reports include `data_scanned` and prerequisites
for any future loader. Success is **not** a promise that all rows can be copied.

## Checks still required for actual loading

Without `--validate-data`, all per-value checks remain outstanding. Even after a
successful full scan, UNIQUE/FK/CHECK constraints, collation-dependent collisions,
triggers, permissions, RLS visibility, timezone conversion policy, SET business
semantics and other server behavior need review. `constraints_checked` remains
false. The scan is not a trial load or a complete migration audit.

A future loader must stop source writes, recheck an empty target, preserve IDs
(including PostgreSQL GENERATED ALWAYS identity handling), copy bounded batches,
reset sequences, and verify counts and referential integrity. It must validate
values again because they may have changed since dry run. Restarting after a
partial transfer must fail until an explicit recovery strategy is implemented.
