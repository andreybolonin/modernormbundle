# Transfer composite entity keys with `--schema-rekey`

`modernorm:data:transfer --schema-rekey` is an independent transfer path inside the
existing command. It copies directly from MySQL into temporary PostgreSQL staging,
assigns surrogate IDs, remaps declared FKs and ManyToMany links, loads prepared
final tables and commits only after full verification. It does not post-process a
previous `data:transfer` run. Composite IDs remain unsupported by runtime entities.

The strategy flag preserves the existing transfer options. Saved plans are
optional: without `--plan` the command builds and verifies an in-memory plan on
each run. `--plan-output` optionally saves that plan to a new file in any mode.

## Prepare and review

Prepare an empty PostgreSQL schema and adapt the application's entity mappings
before running. Tables and original columns keep their physical names; use
`--target-schema` when planning if the destination is not `public`. The command
never creates the final schema or switches application configuration.

For each composite entity PK, the target needs:

- A new single BIGINT identity PK, with owned BIGINT sequence, START/MINVALUE 1,
  INCREMENT 1 and NO CYCLE.
- All original columns, including NOT NULL source PK columns and a UNIQUE on the
  full original PK in its original order.
- A new BIGINT FK for each reference to a transformed parent; original FK columns
  remain present. The new FK must be nullable if any source FK component is
  nullable, and NOT NULL otherwise. Target FK actions are captured in the reviewed plan.

The planner infers mappings from the two physical schemas without entity metadata.
Each source FK must have exactly one matching target FK to the same parent. An
ambiguous match blocks the whole transfer. Ordinary single-PK entities retain IDs.
Pure join tables are recognized as tables consisting entirely of their PK columns,
with two non-overlapping FKs covering that PK and no incoming references. Their
resulting target PK consists of the resulting FK columns; original columns remain.

```bash
php bin/console modernorm:data:transfer \
  --schema-rekey \
  --source-url-env=MYSQL_DATABASE_URL \
  --target-url-env=POSTGRES_DATABASE_URL \
  --dry-run --plan-output=rekey-plan.yaml
```

`--dry-run` without `--validate-data` reads catalogs and checks target emptiness,
not row values. `--plan-output` exclusively creates a new local YAML file. A blocked
plan cannot be saved for execution. Unknown YAML fields, duplicate keys, aliases,
tags, wrong version or a changed plan hash are rejected. Saved plans bind endpoint
identities, schema fingerprints, execution policy and rules hash, not credentials
or source row contents. They contain environment variable names, not URLs.

## Share the existing transformation rules

The `trim`, `replace`, `null_to` engine, interactive wizard and JSONL journal are
shared with [ordinary data transfer](data-transfer.md). They do not assign IDs or
rewrite keys. In rekey mode, all PK/FK and UNIQUE business-key columns are protected,
including original keys, referenced columns and new surrogate/FK columns. A UNIQUE
expression index conservatively protects every source field of its table, since
its dependencies cannot be inferred safely from simple column names.

```bash
php bin/console modernorm:data:transfer \
  --schema-rekey \
  --source-url-env=MYSQL_DATABASE_URL \
  --target-url-env=POSTGRES_DATABASE_URL \
  --rules=rules.json --dry-run --validate-data \
  --change-log=rekey-validation.jsonl --plan-output=rekey-plan.yaml

php bin/console modernorm:data:transfer \
  --schema-rekey \
  --source-url-env=MYSQL_DATABASE_URL \
  --target-url-env=POSTGRES_DATABASE_URL \
  --dry-run --suggest-rules --rules-output=rules.proposed.json \
  --plan-output=rekey-plan.yaml
```

Suggestions require interactive text dry-run without `--change-log`. A saved
`--plan` may be used as a checked starting point; selected rules produce a new plan
hash, and `--plan-output` can save the updated plan to a new file.
They scan every source row, keep the default Skip choices, save only after review,
and revalidate in the same read-only snapshots. `--rules` can supply existing rules
for the wizard to extend. If value errors remain, no executable plan is saved.
Planning without a value scan can still save a structural plan; write modes always
scan again. Suggestions never start loading or rehearsal.

Supply the matching `--rules` file when using a saved plan. Its content hash must
match; modifying the file requires a new plan. The path itself is not persisted.
A plan created without rules requires the empty rule set. Source/raw staging values
remain untouched by rules; typed staging and final values are checked against
reapplying the same immutable rules to the source snapshot.

`--max-error-examples` and `--max-change-examples` bound retained examples, not
coverage or counting. Zero means counts only. `--change-log` creates a new JSONL
file, with separate validation/load phases, original full PKs and no value payloads.
Load events record attempted changes even if a later failure rolls back. End events
report `validated`, `committed`, `failed` or `commit_uncertain`; missing end events
mean interruption. Diagnostic journals are not checkpoints.

## Rehearse and execute

Exactly one of `--dry-run`, `--rehearsal`, `--execute` is required. `--plan` and
`--plan-output` are optional. When `--plan` is supplied, its endpoint/schema and
rules bindings must match. Existing source/target/schema options may also be
supplied when they match the saved plan; conflicting values are rejected. Without
it, explicit source/target environment names are required and planning is repeated
under the same guards before loading. Rehearsal requires an
isolated non-system database with a different name from the configured application
DB. When using saved plans, generate a separate plan for each target, including
the final target.

```bash
# Direct rehearsal: the plan is built and checked in memory.
php bin/console modernorm:data:transfer \
  --schema-rekey \
  --source-url-env=MYSQL_DATABASE_URL \
  --target-url-env=REHEARSAL_POSTGRES_DATABASE_URL \
  --rules=rules.proposed.json --rehearsal --batch-size=1000

# Optionally use a saved plan.
php bin/console modernorm:data:transfer \
  --schema-rekey --plan=rekey-plan.yaml \
  --rules=rules.proposed.json --rehearsal --batch-size=1000 \
  --change-log=rekey-rehearsal.jsonl --format=json

# Use a separately reviewed plan bound to the final target.
php bin/console modernorm:data:transfer \
  --schema-rekey --plan=rekey-production.yaml \
  --rules=rules.proposed.json --execute --batch-size=1000 \
  --change-log=rekey-production.jsonl --format=json
```

Stop source writes and target application/workers before execution. Both write
modes acquire `FLUSH TABLES WITH READ LOCK` **across the entire MySQL instance**
before opening a read-only InnoDB snapshot and hold it until the PostgreSQL
transaction outcome is known. This requires global SELECT for complete FK
inventory and FLUSH_TABLES/RELOAD for the fence. Lock acquisition times out after
30 seconds; missing rights or loss of the source session fail the operation.
No best-effort unlocked fallback exists. PostgreSQL inspection requires permission
to read `pg_control_system()` for endpoint identity, as well as target catalog,
temporary-table, table lock/insert/select and owned sequence access.

The target uses one READ COMMITTED transaction. Final tables are locked against
writers and rechecked for emptiness and catalog changes. Temporary raw/typed
staging and ID mapping are transactional and removed on commit or rollback.
`--batch-size` limits source reading and bounded parameter batches, not transaction
size. It is excluded from plan identity and does not change assigned IDs: each
surrogate table gets `1..N` in exact source PK order, including unsigned BIGINT.

Verification re-reads every source row, compares raw staging, recomputes typed
values with the planned rules, checks the ID bijection, every final key/value in
both directions, and every remapped edge against the original parent key. Full
ManyToMany pairs are checked. Counts or hashes alone do not suffice. Deferred
constraints are forced before sequence synchronization and commit.

`--format=json` reports `schema_rekey: true`, stage, plan hash, source fence outcome,
value validation, complete table/edge validation and `target_data_committed`:

- `true`: target commit succeeded; inspect `validation_complete` and blockers too.
- `false`: no successful commit; transactional target changes are rolled back.
- `null`: COMMIT outcome is uncertain; inspect the target before retrying.

Exit code 0 means an unblocked structural/validated dry-run or a verified confirmed
commit. Invalid options/plans throw Console errors; structural, scan, load or
verification failures return code 1 with blockers. Normal mode keeps its existing
CLI/report contract; `--plan`, `--plan-output`, `--target-schema` require rekey mode.

There is no resume. After rollback, retry the entire operation with an empty target.
Sequences may advance despite rollback. Once the fence is released, the operator
must keep source writes stopped through manual cutover. The command neither
maintains that fence after exit nor performs CDC or a final catch-up sync.

## Additional target columns

A target-only payload column can be nullable without a default, or have a literal
constant default. Prepare these columns in PostgreSQL as usual:

```sql
ALTER TABLE balances
    ADD migration_note TEXT NULL,
    ADD status VARCHAR(20) NOT NULL DEFAULT 'pending',
    ADD retries BIGINT NOT NULL DEFAULT 0,
    ADD settings JSONB DEFAULT '{}'::jsonb;
```

`migration_note` receives NULL; the other columns receive their checked constants
for every copied row. A nullable column with a non-NULL constant default uses the
default, not NULL. Original source columns continue to use copied/transformed
source values even if their target columns also have defaults.

The plan records target-only values explicitly, for example:

```yaml
target_projections:
  migration_note:
    operation: "null"
    target_type: "text"
    encoding: "text"
    value: null
  status:
    operation: "constant"
    target_type: "string"
    encoding: "text"
    value: "pending"
  retries:
    operation: "constant"
    target_type: "bigint"
    encoding: "text"
    value: "0"
```

Constants are recognized from PostgreSQL's deparsed default: one literal with an
optional built-in cast. Integer/decimal values stay exact strings; boolean,
text, bytea, JSON/JSONB, UUID and absolute date/time constants are supported for
compatible built-in target types. Binary values are stored as base64 in the plan.
Temporal special/relative inputs such as `now`, `today` or `infinity` are rejected.

No default function or arbitrary expression is evaluated. `now()`, `nextval()`,
UUID functions, arithmetic, concatenation, subqueries and custom-type casts are
blocked for target-only columns. Generated columns and extra identity columns
outside the planned PK remain unsupported. Required columns without a non-NULL
constant, incompatible types and implicit lossy casts block planning, even for
empty source tables. Explicit literal casts use their declared PostgreSQL value.

Staging does not inherit target defaults. Instead it receives explicit typed
NULL/constant values and final INSERT copies those values. Validation independently
checks each projection against the plan, then compares every final field against
staging. Corrupting both copies does not bypass the constant check. UNIQUE/CHECK/FK
constraints still run and can reject a constant for the actual rows; such failures
roll back table writes. A default change invalidates a saved plan.

Rules and suggestions continue to address source-backed fields only; a rule on a
target-only column is rejected. Projection filling is schema-driven and does not
produce transformation-rule journal events. Rekey protects keys and validates new
relationships as before; extra target-only FKs are not implicitly inferred.
Previously saved plans must be regenerated after this plan-format extension.

## Nullable composite foreign keys

Optional composite FK relationships between different tables use the same
MATCH SIMPLE policy as self-references. If **any** source component is NULL,
the new scalar FK is NULL. This includes an all-NULL FK, one populated component
plus NULL, and a non-null tenant component plus a nullable object component.
The original components remain unchanged, even when a populated component alone
does not identify an existing parent.

Prepare a nullable BIGINT target FK if any component in the source relationship
is nullable. A required relationship still needs a NOT NULL target FK. For example:

```text
Source: reservations(warehouse_id NULL, product_id NULL)
        → balances PK(warehouse_id, product_id)
Target: reservations.balance_id BIGINT NULL → balances.id
```

A fully populated tuple, including zero-valued components, must resolve to
exactly one parent; orphan references fail remap and roll back the transfer.
There is no fallback that turns an unresolved populated FK into NULL. The plan
records `nullable`, `null_policy: any_null_absent` and the source MATCH mode.
Parents still load before children. Source key/FK components remain protected
from transformation rules; normal payload rules and journals work as before.

Final validation checks absent relationships, missing relationships and wrong
existing parent IDs independently of staging/final agreement. Cross-table cycles,
references to alternate UNIQUE keys and rekey chains through a child identifier
or a referenced child key remain blocked.

## Self-references

Rekey supports declared self-references to the complete source PK. IDs for every
row are allocated in staging before any links are remapped; each final table is
inserted with one `INSERT ... SELECT`. Parents can occur after children in source
PK order even when `--batch-size=1`, and target FKs need not be deferrable. Cycles
between different tables are still blocked. Existing self-parent links and cycles
between rows of the same table are preserved; the command does not validate
application-specific tree invariants.

For a composite entity key, a common supported source shape is:

```sql
CREATE TABLE categories (
    tenant_id BIGINT NOT NULL,
    category_code BIGINT NOT NULL,
    parent_code BIGINT NULL,
    PRIMARY KEY (tenant_id, category_code),
    FOREIGN KEY (tenant_id, parent_code)
        REFERENCES categories (tenant_id, category_code)
);
```

Prepare the target with a new PK and a nullable scalar parent FK:

```sql
CREATE TABLE categories (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    tenant_id BIGINT NOT NULL,
    category_code BIGINT NOT NULL,
    parent_code BIGINT NULL,
    parent_id BIGINT NULL REFERENCES categories(id),
    UNIQUE (tenant_id, category_code)
);
```

Self-references use the source's MATCH SIMPLE semantics: **any NULL component**
means no relationship, including a populated tenant ID plus NULL parent code.
The new parent ID is NULL; retained source FK columns are not cleared or changed.
See [MySQL's documented FK semantics](https://dev.mysql.com/doc/refman/8.4/en/create-table.html).
A fully populated FK must resolve to exactly one original parent key, or the
operation fails and rolls back; it never falls back to NULL for an orphan.

The plan records `self_reference`, `nullable`, `null_policy` (`any_null_absent` or
`required`) and source/target MATCH modes. Final verification independently checks
that absent relationships stayed NULL and populated relationships point to the
correct mapped parent. Single-PK self-references can also be preserved without
rekeying their IDs. Incoming join-table references and ambiguous mappings to
multiple new FK columns remain unsupported. Regenerate previously saved plans after this structural change.

## MVP limits

- MySQL source and PostgreSQL target; source tables must be InnoDB. Type-input
  validation requires PostgreSQL 17+.
- Transformed PK components must be non-null integers; remapped FK components
  must be integers. Nullable references use MATCH SIMPLE semantics. Unsigned
  source keys may use explicit NUMERIC(...,0) target columns to retain their full
  range; the new ID always stays signed BIGINT.
- Every source table/column is covered; no skip, rename or partial transfer.
- References outside the source DB, alternate referenced UNIQUE keys, cross-table
  cycles, incoming join-table references and rekey chains through child
  identifiers/referenced keys are blocked globally.
- Additional target columns require planned IDs/FKs, NULL or supported constant
  projections. Generated columns, executable defaults, custom target-only types,
  RLS, inheritance/partitioning, user triggers, rewrite
  rules and invalid indexes/constraints are blocked.
- No schema DDL generation/application, UUID allocator, cleanup of original
  columns, application mapping/code rewrite or automatic cutover.
