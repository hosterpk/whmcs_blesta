# AGENTS.md instructions for /Users/macbook/repos/whmcs_blesta

- Use `/opt/homebrew/opt/python@3.12/bin/python3.12` when a task needs Python 3.12 or `tomllib`.
- For tasks that need Python, use Python `3.11`; if Python 3.11 is not available, fall back to Python `3.6.8`.
- Exception: tasks that need stdlib `tomllib` (e.g. `_bmad/scripts/resolve_config.py`, `_bmad/scripts/resolve_customization.py`, the `bmad-customize` scripts) require Python `3.11+` and cannot use the `3.6.8` fallback, since `tomllib` was added in 3.11.
- Commit messages must follow: `<type>(<scope>): <summary>`.
- Allowed types: `feat`, `fix`, `docs`, `test`, `refactor`, `chore`.
- Keep summaries imperative, lowercase, and under 72 characters.
- KuickPay/Blesta money trap: Blesta `transactions.amount` and `transaction_applied.amount` are `decimal(12,4)`, so the DB returns 4-decimal strings (`"1000.0000"`) while the plugin stores 2dp `varchar` (`"1000.00"`). Any amount comparison reading a raw Blesta amount must tolerate trailing-zero decimals beyond paisa using string/integer minor-unit math (no floats, per NFR13/AC11) and treat an unparseable `null` as a definitive mismatch (never `null === null`). Test fakes must return 4-decimal strings so the parser is actually exercised. Relevant to 3-6/3-7 amount work.
