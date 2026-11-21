# saasumcp — Saasu lookups over MCP

A read-only [MCP](https://modelcontextprotocol.io/) server, over stdio, that lets an agent look up
invoices, payments, contacts, accounts, tax codes, items and bank balances in one or more
[Saasu](https://www.saasu.com/) files. It is for an operator's agent that needs to answer "what
is this purchase, and how was it paid?" without holding a Saasu login itself.

**Nothing it does can change a file, and every request it sends is rationed.** Both are the
point of it; see *Safety*.

## Requirements

PHP 8.3 or later. A Saasu login for each file, made for this server alone.

## Installation

Download `saasumcp-<version>` and `SHA256SUMS` from the release, verify, and install the binary:

```bash
sha256sum --ignore-missing -c SHA256SUMS
install -m 0755 saasumcp-<version> /usr/local/bin/saasumcp
```

From a checkout, `composer install` and run `php saasumcp` in place of `saasumcp`.

## Configuration

Three things: an environment file, a file inventory, and a state directory.

**The environment file** says where the state lives, and holds no credential. `.env.example`
is the template and says where the file is looked for.

```dotenv
SAASUMCP_STATE_PATH=/var/lib/saasumcp
```

**The file inventory** names the files and holds the login for each. `files.toml.example` is the
template; by default it is `files.toml` in the state directory. It holds passwords, so
`app:validate` fails it at anything wider than 0600, or root-owned and 0640 for a group.

```toml
[acme]
name = "Acme Example Pty Ltd"
file_id = 12345
username = "mcp@example.com"
password = "..."
# daily_budget = 500
```

The table name is the key every tool takes.

**The state directory** is created 0700 on first use. It holds cached OAuth tokens, the shared
throttle, each file's request count, and the lists kept for a day. Set it absolutely: a compiled
binary otherwise uses the working directory, which the MCP client chooses.

```bash
saasumcp app:config       # what is configured, and the .env actually read
saasumcp app:validate     # checks it, and asks Saasu for every permission the tools need
```

`app:validate` sends about a dozen requests to each file, counted against its budget.
`--offline` skips them; `--strict` exits 2 on a warning.

## Logins

**Give the server its own login in each file, without two-factor authentication**, so its
spending is its own and its token cache is not shared with another integration.

**Give it read access and no write access.** That is enough for every tool, measured against
Saasu: the reads it needs are **sales, purchases, contacts, accounts and inventory items**. A
login without the Purchase permission is refused every invoice list, sales included, and an
untyped payment list. `app:validate` tries each.

## Registering the server

With Claude Code:

```bash
claude mcp add saasu -- saasumcp mcp:start saasu
```

Any MCP client that starts a stdio server works the same way: the command is
`saasumcp mcp:start saasu`. If the environment file is not in one of the places looked for, set
`SAASUMCP_ENV` in the environment the client starts it with.

## Tools

| tool | answers | costs |
|---|---|---|
| `list-files` | the configured files and their budgets | nothing |
| `file-access` | whether the login reaches a file, and what the file is | 1 |
| `get-invoice`, `get-payment`, `get-contact`, `get-account`, `get-item` | one record by its Saasu id | 1 |
| `list-invoices` | sales or purchases by date, number or contact | 1 a page, 5 at most |
| `list-payments` | payments by date, or those applied to one invoice | 1 a page, 5 at most |
| `find-contacts` | contacts by email, reference, name or date changed | 1 a page, 5 at most |
| `find-items` | inventory items by a search | 1 a page, 5 at most |
| `list-accounts`, `list-tax-codes` | the whole chart of accounts, every tax code | kept a day; usually nothing |
| `bank-balances` | each bank account's balance | 1 |

A login costs one more when no token is cached. Records go out as Saasu sends them, less their
hypermedia links. Every answer carries `budget`: what the call sent and what is left.

## Safety

**Read-only twice over.** The server has no tool that writes, and the login it is given has no
write access in Saasu, which the tools do not need. Either alone keeps a file unchanged.

**Saasu blocks a file's whole API for 24 hours once its daily quota is exceeded** — every
integration on it, not only this one. So:

- **Each file has a budget over any 24 hours**, 500 requests unless set otherwise, kept in the
  state directory and shared by every server process. A call sets aside the most it might send
  before it starts; one that does not fit is refused without asking Saasu.
- **Lists need a date range or a filter**, fetch one page of 100 unless asked for more, and never
  more than five. Saasu does not say how many records a list holds, so an answer says only
  whether more exist.
- **One request a second per file**, across every process, through a lock in the state directory.

## Development

```bash
composer check            # phpstan, then pest
composer build            # the release phar, into builds/
```

## Licence

MIT. See [LICENSE](LICENSE).
