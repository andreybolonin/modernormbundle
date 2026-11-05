# Security policy

ModernORMBundle 0.1 is a beta integration package. No stable support window or response-time SLA is promised.

Report vulnerabilities privately through [GitHub vulnerability reporting](https://github.com/andreybolonin/modernormbundle/security/advisories/new). Include affected versions, a minimal reproducer and relevant PHP/Symfony/PostgreSQL versions. Never include live credentials or customer data. Private reporting is enabled; if it becomes unavailable, request a private contact route without publishing exploit details.

The Profiler and SQL history are development diagnostics. Bound values and exception messages are not collected by SQL history, but literals embedded in SQL remain visible. Configure access to Symfony's profiler and enable SQL collection only where appropriate.

Fixture loading can truncate managed tables; ownership filters constrain generated schema diffs and fixture purge, not arbitrary SQL in trusted migrations/fixtures. Review generated migrations and coordinate L2 namespaces and worker restarts during deployments.

Issues and PRs are reviewed against this package's own contract. There is no scheduled upstream patch-porting commitment. Report core-specific defects to [ModernORM](https://github.com/andreybolonin/modernorm/security/advisories/new).
