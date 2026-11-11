# ADR 0005: MCP server

Status: accepted by the lead developer (planning session); implementation scheduled after v1

## Context

A Model Context Protocol (MCP) server lets AI clients query and modify TiffinDB projects on a user's behalf. It must run on shared hosting, so it cannot be a long-running process.

## Decision

- TiffinDB will include an MCP server, delivered after the v1 release.
- Transport: HTTP endpoint served by PHP, one JSON-RPC request per HTTP request, plain JSON responses, no server-held session state and no server-initiated streams. This is intended to fit the MCP Streamable HTTP transport's stateless mode; conformance is to be verified against the specification revision current at implementation time.
- Scope: full. Record read and write, schema changes (tables, fields, relations), and imports.
- Authorization: OAuth 2.1 from the first MCP release, with TiffinDB acting as both authorization server and resource server, following the MCP authorization specification current at implementation time.
- The MCP server is disabled by default and enabled per instance by an Admin (opt-in, per the repository rule on network features).

## Required safeguards

Full scope makes an AI client able to damage a database. The following are part of the decision, not optional extras:

| Safeguard | Detail |
|---|---|
| Scopes | Separate `read`, `write`, and `schema` scopes; the consent screen lists them in plain language and defaults to `read` |
| Role ceiling | A grant can never exceed the approving user's own role and project grants; `schema` requires Admin |
| Project selection | Consent is per project, not instance-wide |
| No permanent deletion | Record removal over MCP goes to trash; emptying trash, deleting projects, and user management are not exposed |
| Snapshots | Automatic project snapshot before any schema change or import made over MCP |
| Audit log | Every MCP call logged with client, user, tool, and target; visible to Admins |
| Revocation | Users and Admins can list and revoke client grants |
| Limits | Rate limiting and result-size caps suited to shared hosting |

## Alternatives considered

| Topic | Option | Reason not chosen |
|---|---|---|
| Scope | Read-only, or records only | Lead developer chose full capability |
| Auth | Static bearer tokens first | Lead developer chose OAuth from the start; hosted connector UIs generally require it |
| Timing | Before v1 | Core query, schema, and trash behaviour should be stable first |

## Consequences

- The MCP layer is an adapter over `core/`; no logic may live only in `web/` controllers. This constraint applies from M1.
- Trash, snapshots, and an audit log must exist in core before MCP work begins.
- An OAuth authorization server is a significant security surface and needs its own design document and review before implementation.
- Some client-registration mechanisms in the MCP authorization specification may require the server to fetch a client-supplied URL. That would be an outbound network call and needs explicit maintainer approval under the repository rules; a registration method without outbound fetches is preferred if the specification allows it.
- Some shared hosts do not pass the `Authorization` header to PHP by default; the shipped `.htaccess` must handle this and the installer must test it.
