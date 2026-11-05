# Migrations and fixtures from an empty PostgreSQL schema

Install the bundle's development dependencies, then run:

```sh
MODERNORM_TEST_URL='postgresql://user:password@127.0.0.1/modernorm_test' php examples/migrations-fixtures/run.php
```

The example creates a random, isolated schema and migration directory, generates and applies a Doctrine migration from ModernORM attributes, checks that the next diff is empty, and loads an Immutable, L2-cacheable Country and its City through dependent fixture services. It prints `Paris / France` and removes its own schema and generated files on completion. Existing application tables are not changed.

Use a dedicated local PostgreSQL test database. This example is executable integration code; a Symfony application registers the same adapters through `modernorm.migrations` and `modernorm.fixtures` instead of constructing them directly. See the bundle README for configuration and commands.
