# Data fixtures

[Documentation](README.md) · [Migrations](migrations.md) · [Runnable example](../examples/migrations-fixtures/README.md)

Install `doctrine/data-fixtures:^2.2` as an application development dependency and enable:

```yaml
modernorm:
    managed_schema_filter: '~^modernorm_~'
    fixtures:
        enabled: true
```

Extend `ModernORM\Bundle\Fixtures\Fixture` and implement `load(Doctrine\Persistence\ObjectManager $manager)`. Autoconfigured services receive the `modernorm.fixture` tag. Existing Doctrine fixtures are not automatically selected. You may explicitly tag other `doctrine/data-fixtures` FixtureInterface implementations if they accept the ModernORM manager.

Use upstream `DependentFixtureInterface` **or** `OrderedFixtureInterface` for ordering, and `addReference()` / `getReference($name, Entity::class)` for relationships. Generated identifiers are captured after flush before clear; references added both before and after flush work. Dependencies must be registered services, so constructor injection is preserved. Implement `ModernORM\Bundle\Fixtures\FixtureGroupInterface::getGroups(): array` for groups; class names and short class names can also select fixtures. Selecting a group includes its dependency closure.

```sh
bin/console modernorm:fixtures:load --group=demo
bin/console modernorm:fixtures:load --append --group=demo
```

A load uses one transaction and a separate ModernORM EntityManager with L2 disabled, so cacheable entity inserts work without weakening the normal external-transaction contract. Each fixture is flushed automatically and followed by clear; the caller's UnitOfWork is cleared as well. Fixtures may call flush, but must not commit/roll back or replace the transaction boundary. Keep external effects (HTTP calls, files, emails) out of fixture execution: they cannot be rolled back with PostgreSQL.

Unless `--append` is supplied, all mapped ModernORM tables are truncated together with `RESTART IDENTITY`, without CASCADE. This includes association tables but excludes migration history. External FK dependencies cause an error rather than cascading into other applications' tables. Group selection controls fixtures, not the purge scope. Purge and all fixture writes roll back together on failure. L2 classes are invalidated before and after the load; no uncommitted fixture state is published to L2. `FixtureLoadException::databaseCommitted` distinguishes rollback (`false`), committed cleanup failure (`true`) and an unknown outcome (`null`). Do not blindly retry after a committed/unknown failure.

CLI purge asks for confirmation in interactive mode. `--no-interaction` is suitable for explicitly scripted test setup, and `--force` is required in production. Keep fixture services and optional packages registered only in development/test environments if installing them as dev dependencies. Production initial/reference data should generally use versioned data migrations.

A runnable [empty-schema example](../examples/migrations-fixtures/README.md) demonstrates the full migration + fixtures cycle against PostgreSQL.
