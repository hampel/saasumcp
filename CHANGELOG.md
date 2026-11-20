# Changelog

All notable changes to this project are recorded here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses
[Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- An MCP server over stdio, `saasumcp mcp:start saasu`, with read-only tools: `list-files`,
  `file-access`, `get-invoice`, `get-payment`, `get-contact`, `get-account`, `get-item`,
  `list-invoices`, `list-payments`, `find-contacts`, `find-items`, `list-accounts`,
  `list-tax-codes` and `bank-balances`.
- A request budget per Saasu file over any 24 hours, shared by every server process, which
  refuses a call before it is sent rather than stopping it half way.
- `app:validate`, which asks Saasu for each permission the tools need, and `app:config`.
