# HookPool

HookPool is an open source, self-hostable webhook inbox for developers, makers, side projects, automations, and personal infrastructure.

The project has a very explicit design preference: webhook URLs should stay as short as possible while still being safe, unique, and practical to manage.

## Project Identity

HookPool is built to run in two equally valid ways:

- personal or self-hosted single-user mode
- public multi-user mode with GitHub login

By default, the project is optimized for simple self-hosting on a personal server.

## Authentication Modes

- `HOOKPOOL_AUTH=no` (default): single-user local mode. The app auto-logs into a local `local` user and does not expose multi-user login.
- `HOOKPOOL_AUTH=yes`: GitHub OAuth mode. Login is required and the application works in multi-user mode.

Set the mode in `.env`, Docker environment variables, or `env.php`.

## URL Design

HookPool prefers short public webhook URLs.

The target format is:

```text
https://hookpool.com/<project-slug>/<hook-code>
```

Example:

```text
https://hookpool.com/test/a7da8d
```

This structure has a precise meaning:

- `<project-slug>` identifies the project
- `<hook-code>` identifies the specific hook inside that project

### Project Slug Rules

The first path segment is a project identifier generated from the project name.

Example:

- project name: `Chess`
- preferred slug: `/chess/`

If the slug is already taken, HookPool must automatically resolve collisions:

- `/chess/`
- `/chess-1/`
- `/chess-2/`

The project slug should also be editable by the user, but always validated by the system.

The system should enforce:

- uniqueness
- reserved-word blacklists
- invalid-pattern blacklists
- collision-safe auto-generation

This means some path words must never be assignable to projects because they are reserved by the application or the hosting environment.

Examples of reserved values:

- `api`
- `auth`
- `dashboard`
- `settings`
- `hook`
- `public`
- `admin`

## Hook Code Rules

The second path segment is the short random code for a specific hook inside the project.

Design goal:

- as short as possible
- random enough to prevent easy abuse or blind enumeration
- URL-safe

Preferred character set:

```text
a-z0-9
```

Current product direction:

- keep this code minimal
- prefer short codes over long opaque tokens
- increase length only if operational evidence shows abuse or collision risk

As a practical baseline, `6` base36 characters is a reasonable starting point for short hook codes, but the project should keep the implementation flexible so the length can be tuned later if needed.

## Product Direction

HookPool is not trying to produce long, ugly, over-engineered webhook endpoints.

The design preference is:

- short URLs
- readable project-level paths
- compact per-hook identifiers
- predictable self-hosted deployment
- open source ownership of your infrastructure

This preference should inform routing, slug generation, validation rules, and UI copy throughout the project.

## Self-Hosting

HookPool is intended to be installable on personal servers and small VPS environments without heavy infrastructure requirements.

Configuration is environment-based and works with Docker or plain PHP deployment.
