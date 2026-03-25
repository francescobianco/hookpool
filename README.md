# hookpool

HookPool supports two auth modes:

- `HOOKPOOL_AUTH=no` (default): single-user local mode. The app auto-logs into a local `local` user and does not expose multi-user login.
- `HOOKPOOL_AUTH=yes`: GitHub OAuth mode. Login is required and the application works in multi-user mode.

Set the mode in `.env`, Docker environment variables, or `env.php`.
