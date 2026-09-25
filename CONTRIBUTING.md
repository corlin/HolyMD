# Contributing to HolyMD

Thanks for your interest in HolyMD. Issues and pull requests are welcome in English or Chinese (欢迎使用中文或英文提交 Issue 与 PR).

## Reporting issues

- **Bugs** — include your PHP version, MySQL/MariaDB version, web server, whether you use `HOLYMD_SYNC_PUBLISH`, and the steps to reproduce.
- **Security vulnerabilities** — do not open a public issue. Report them privately through [GitHub Security Advisories](https://github.com/corlin/HolyMD/security/advisories/new).
- **Feature ideas** — explain the problem first. HolyMD's focus is GEO for independent websites, so proposals that improve how sites are discovered, read, and cited by AI systems are especially welcome.

## Development setup

Follow the [Quick start](README.md#quick-start), then make sure the checks pass before opening a pull request:

```bash
composer validate --strict
composer analyse
composer test
```

## Conventions

- **Tests are required** for behavior changes. Tests use `sqlite::memory:` with inline schemas, so SQL must run on both MySQL 8 (production) and SQLite (tests). `pdo_sqlite` silently ignores bound parameters inside `CASE` expressions in `UPDATE`; split conditional updates into separate statements.
- **Timestamps** are stored in UTC. Compare them as fixed-width `gmdate('Y-m-d H:i:s.u')` strings.
- **CLI exit codes** are `0` (success), `64` (usage error), and `1` (failure).
- **The AI reviewer never edits article bodies.** GEO suggestions only touch metadata, and every suggestion needs an explicit human decision.
- **Some tests assert documentation strings** (for example, the dev server command in `README.md` and phrases in `docs/operations/`). If you edit documentation, run the full test suite.
- Match the style of the surrounding code. Keep pull requests focused on one change.

## Commit messages

Use [Conventional Commits](https://www.conventionalcommits.org/): `feat:`, `fix:`, `docs:`, `test:`, `refactor:`, `chore:`.

## License

By contributing, you agree that your contributions are licensed under the [MIT License](LICENSE).
