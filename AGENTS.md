# AGENTS.md instructions for /Users/macbook/repos/whmcs_blesta

- Use `/opt/homebrew/opt/python@3.12/bin/python3.12` when a task needs Python 3.12 or `tomllib`.
- For tasks that need Python, use Python `3.11`; if Python 3.11 is not available, fall back to Python `3.6.8`.
- Exception: tasks that need stdlib `tomllib` (e.g. `_bmad/scripts/resolve_config.py`, `_bmad/scripts/resolve_customization.py`, the `bmad-customize` scripts) require Python `3.11+` and cannot use the `3.6.8` fallback, since `tomllib` was added in 3.11.
- Commit messages must follow: `<type>(<scope>): <summary>`.
- Allowed types: `feat`, `fix`, `docs`, `test`, `refactor`, `chore`.
- Keep summaries imperative, lowercase, and under 72 characters.
