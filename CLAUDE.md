# CLAUDE.md

Guidance for a session working in this repository.

## What this is

`saasumcp` is a Laravel Zero 13 CLI (PHP 8.3+) that runs a read-only MCP server over stdio. Its
tools look up records in Saasu accounting files through `hampel/saasu-api` and
`hampel/saasu-api-laravel`, so an operator's agent can answer questions about invoices and
payments without holding a Saasu login.

**`README.md` is the operator's document; this file is why the code is the way it is.**
`CLAUDE.local.md`, gitignored, records where it runs, which files it reads and what is undecided.
**Unreleased**; the structure follows `xfmcp`, and its *Releasing* procedure applies as written.

## Commands

```bash
php saasumcp app:config            # resolved configuration, and the .env actually read
php saasumcp app:validate          # inventory, state directory, and every permission, asked of Saasu
php saasumcp mcp:start saasu       # the server; speaks JSON-RPC on stdin and stdout

composer check                     # phpstan, then pest
composer build                     # release phar into builds/, refusing dev dependencies
```

## The budget is the one feature that must not break

**Saasu blocks a file's whole API for 24 hours once its daily quota is exceeded**, every
integration on it included — another system books sales into the same files every morning. So
every request goes through `App\Saasu\Gateway::call()`, which reserves the most a call may send
in `App\Saasu\DailyBudget` before it starts, runs it on a client held to that number by
`withRequestBudget()`, and gives back what it did not use. **A tool never builds a client
itself**; one that did would spend outside the count.

- **Persisted in the cache store, under a lock.** A client starts a server process per session,
  so a count in the process would reset with every conversation, and two conversations are two
  processes.
- **A rolling 24 hours in hourly buckets, not a calendar day**, because when Saasu's day starts
  is undocumented. An hour counts whole while any of it is in the window.
- **Reserve, then settle.** Checking the remaining count and spending it separately would let two
  processes spend the same room. A call that does not fit is refused before anything is sent.
- **Logins count.** `Gateway::LOGIN_ALLOWANCE` sets aside two: a token when none is cached, and a
  second when Saasu rejects a cached one early. `requestsSent()` includes both.
- **Keyed by Saasu file id, not by inventory key**, and the inventory refuses two keys for one
  file, because the quota is the file's.

The default, 500 a file, was Simon's decision on 2026-11-21. `tests/Feature/BudgetTest.php`
holds the window; change it with a test.

## Lists cannot ask for everything

**Every list needs a date range or a filter, fetches one page of 100 by default and five at
most.** Paging a whole file is how a quota is spent by accident, and Saasu lists have no total,
so an answer can say only `more_exist`. `SaasuTool::moreNote()` says so in words, because a
list that stops without comment reads as complete.

- **`list-payments` requires a range unless given an invoice.** Saasu answers an unranged
  payment list for the last month only, which looks like a complete list.
- **`kind` is required on invoice and payment lists.** Saasu lists sales and purchases together
  otherwise, and a login without purchase permission is refused an untyped payment list with a
  400 about a null collection.
- **`list-accounts` and `list-tax-codes` are cached for 24 hours** in the same store, shared
  across processes, because reading any invoice line needs them.

## Stdio only, and stdout is the protocol

**`bootstrap/ai.php` calls `Mcp::local()` and must never call `Mcp::web()`.**
`tests/Feature/ServerTest.php` asserts the registered servers are exactly `['saasu']`.
`App\Exceptions\Handler` sends a rendered exception to stderr, and `bootstrap/app.php` catches a
malformed `.env` before the handler exists; both are from `xfmcp`, which says why.
`.github/mcp-smoke.sh` is the binary check.

**`ValidationServiceProvider` and `TranslationServiceProvider` are listed in `config/app.php`
because laravel/mcp 1.2 needs them.** From 1.2 it validates every `tools/call` through the
`Validator` facade, which Laravel Zero does not register, and every tool call answered
"Something went wrong" with nothing on stderr. Found on 2026-11-21 under 1.2.1.

**Nothing is logged.** There is no `config/logging.php` and no `Log::` call: the answers are a
business's books, and a log would be a second place for them to rest.

## A failure is a tool error with a sentence in it

**A tool returns `Response::error()`, never an exception and never an empty result.**
`Gateway::explain()` turns each of saasu-api's exceptions into a sentence naming whose problem it
is: the login, a permission, a missing record, Saasu, or the budget. Saasu's own error text is
passed on, capped at 400 characters — the package has already refused to quote an HTML page.

**A record goes out as Saasu sent it, less `_links`.** `Entity::$raw` keeps fields the package
does not model and an `InvoiceType` its enum lacks, both of which a typed presentation would drop.

## The inventory holds no credential

**`files.toml` names files; logins come from `SAASUMCP_<KEY>_USERNAME` and `_PASSWORD`**, gathered
by pattern in `config/saasumcp.php`, as `saasusync` does. The inventory is safe to read and to
show, and a deployment renders the passwords from its secrets store into the environment file alone.
`FileInventory` re-reads the file when its modification time or size changes, as `xfmcp` found it
must, and never quotes the TOML parser.

## Things learnt elsewhere that apply here

- **`config.platform.php` is pinned to 8.3.0.** A lock made under 8.5 otherwise takes
  dependencies 8.3 cannot load (`saasusync`, 2026-11-19).
- **Never set `permission` on the file cache store.** Laravel applies it to directories, and 0600
  makes them impossible to enter; `StateDirectory` creates the parent 0700 instead.
- **Transaction dates are calendar dates.** `Present::dateNote()` says so in every answer that
  carries a record.
- **A method on a tool must not be called `name()`**: `Primitive::name()` is the tool's name.

## Conventions

- PSR-12 through Pint's defaults; `vendor/bin/pint` before committing.
- Configuration through `config()`, never `env()` outside `config/`, and never in
  `config/app.php`, which `app:build` compiles as a literal.
- `SaasuServiceProvider` is listed by hand; Laravel Zero ignores package discovery.
  `tests/Feature/NoNetworkTest.php` fakes nothing to prove the fakes intercept.
- `tests/Fixtures/` are saasu-api's own, which follow Saasu's documentation; see its README.
- No committed file names a real business, file id, host or path.
