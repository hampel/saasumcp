# Changelog

All notable changes to this project are recorded here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses
[Semantic Versioning](https://semver.org/).

## [Unreleased]

### Changed

- `bank-balances`, `list-journals` and the server's instructions say that a bank transfer made in
  Saasu's own screens cannot be read through its API, and shows only in a balance.

## [0.2.0] - 2026-11-22

### Added

- `get-journal`: one manual journal by id, with every line.
- `list-journals`: journals by date range, optionally for one contact. `account_id` keeps those
  with a line on one account; Saasu has no such filter, so it reads each journal in the range,
  one request each, and refuses a range of more than 25.
- `app:validate` probes the journal list.

## [0.1.0] - 2026-11-21

### Added

- An MCP server over stdio, `saasumcp mcp:start saasu`, with read-only tools: `list-files`,
  `file-access`, `get-invoice`, `get-payment`, `get-contact`, `get-account`, `get-item`,
  `list-invoices`, `list-payments`, `find-contacts`, `find-items`, `list-accounts`,
  `list-tax-codes` and `bank-balances`.
- A request budget per Saasu file over any 24 hours, 500 unless the file sets its own, shared by
  every server process, which refuses a call before it is sent rather than stopping it half way.
- One request a second per file, across every server process.
- Lists that need a date range or a filter, fetch one page of 100 by default and five at most,
  and say whether more exist.
- The chart of accounts and the tax codes kept for 24 hours, so reading invoice lines costs
  nothing after the first call.
- A file inventory in TOML naming each Saasu file and holding its login.
- `app:validate`, which checks the inventory's file mode and the state directory, and asks Saasu
  for each permission the tools need; and `app:config`, which shows what is configured without a
  credential.
