# For AI sessions

Start by reading `docs/SESSION-CONTINUITY.md`; it links to the PRD, design, decisions and the
TDD playbook and says what to do next. The short version of the rules:

- Rony commits; never run `git commit` or `git push`. 
- Stop at the end of each stage and wait for approval.
- Every PHP, Composer and npm command runs inside Docker (`docker compose exec web ...`).
- Test first, per `docs/TDD-SPEC.md`. Tests, Pint and PHPStan must be green before a commit point.
- The OpenAI key lives only in `.env`.
