# Plan a MySQL to PostgreSQL data transfer

`modernorm:data:transfer` supports read-only planning (`--dry-run`) and real loading
into a separate rehearsal database (`--rehearsal`). Choose exactly one mode.
Loading into the configured application database, automatic DDL and resume are
not supported.

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
Credentials are not printed in the report. The application connection is not
opened; its configured database name is used to guard rehearsal isolation.

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

Dry-run connections are placed in read-only REPEATABLE READ transactions and rolled back/closed
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

## Rehearse a real load

Create a disposable PostgreSQL database and apply the **same migrations,
extensions, constraints and triggers** intended for the final target. Entity
schema generation alone may omit CHECK constraints, functions or triggers. The
command neither creates nor drops databases and never truncates target tables.

```bash
php bin/console modernorm:data:transfer \
    --source-url-env=MYSQL_DATABASE_URL \
    --target-url-env=REHEARSAL_POSTGRES_DATABASE_URL \
    --batch-size=1000 --rehearsal --format=json
```

The target database name must differ from the explicitly configured application
database name, even on a different host. `postgres` and template databases are
rejected. A different schema inside the application database is insufficient.
Keep the application's connection configured normally; only the explicit target
URL points to rehearsal. The bundle supplies the application connection parameters
for this check without connecting to it.

**Rehearsal writes and commits real data.** Use an isolated environment: triggers
are enabled and may call external services. Configure those external actions for
test services/isolation. Turning off triggers would invalidate the rehearsal;
disabled or replica-only triggers, a non-origin replication role and RLS-enabled
planned tables are rejected, including partition/inheritance descendants.

The source remains a read-only InnoDB REPEATABLE READ snapshot. The target uses a
single READ COMMITTED write transaction. Planned target tables are locked in
deterministic order against concurrent writers, then rechecked for emptiness.
Full value validation is mandatory and uses the same snapshot, batch reader,
target type descriptions and scalar input conversion as the loader. Structural
or value errors prevent any inserts. `--validate-data` is implied in this mode.

The shared loader inserts bounded batches in FK order using typed PostgreSQL
casts and bytea-safe hex decoding. Explicit IDs use `OVERRIDING SYSTEM VALUE`.
INSERT RETURNING verifies that triggers did not suppress rows or change PKs.
After loading it forces deferred constraints with `SET CONSTRAINTS ALL IMMEDIATE`,
compares row counts and checks every source PK still exists in the target. Trigger
transformations of other columns are allowed; this is not byte-for-byte content
verification. CHECK, UNIQUE, FK and trigger behavior is exercised by PostgreSQL.
Existing planner restrictions, including cycles and ManyToMany, still apply.

Owned PK sequences are reset after verification, including empty-table starts
and descending increments. Exhausted or incompatible sequence ranges fail the
rehearsal. Custom/unowned sequences need manual synchronization. Sequence capacity
is checked before resets; sequence updates are not transactional.

Success commits and leaves the rehearsal database for inspection. The report
includes `rehearsal.status: committed`, `target_data_committed: true`, per-table
counts, verified keys, sequence resets and `constraints_checked: true`. Retrying
against those nonempty tables fails; clean/recreate only the disposable database
using your normal environment workflow.

The first failing batch stops loading; no rows are silently skipped. The report
identifies stage, table, batch number, exception category and SQLSTATE without
including PostgreSQL's potentially sensitive row details. Deferred failures may
be reported at the constraints stage without an individual row. There is no
savepoint-based row isolation or resume yet. `rows_loaded` on failure counts work
inside the failed transaction, not committed target rows.

Failed table writes roll back on connection cleanup. **Sequence changes and
external trigger actions can survive rollback.** An uncertain COMMIT failure
reports `target_data_committed: null`; inspect the database before retrying.
The rehearsal covers the chosen snapshot and database setup, not future changes
or external production behavior. Stop writes for an eventual real migration.

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

In rehearsal mode, exit code 0 means the load committed after verification.
Structural, value, insert, deferred-constraint, verification or sequence failures
return a failing report and exit code 1. Isolation guard failures reject the
command before loading.

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
