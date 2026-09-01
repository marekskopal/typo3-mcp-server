# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/), and this project adheres to [Semantic Versioning](https://semver.org/).

## [1.2.0] - 2026-09-01

**Upgrading from 1.1.0.** No database changes. Three behaviours differ from 1.1.0 — none require action, but each is visible to a client or an extending integration:

1. **Successful reads are no longer written to `sys_log`.** The new `auditLogLevel` setting defaults to `mutations`. Writes and *all* failures are still recorded. Set `auditLogLevel = all` in the extension configuration to keep 1.1.0's behaviour.
2. **In a non-live workspace, `pages_list` / `content_list` / `record_search` and the generated `<prefix>_list` return `hasMore` instead of `total`,** and `record_count` reports `{count, exact}`. The live workspace is unchanged. A client that reads `total` unconditionally needs to handle its absence outside live — it was over-reporting there before, so the value it read was wrong anyway.
3. **`record_search` and `record_count` report a malformed `search` or an unknown `orderBy` as a tool error** rather than a `200` carrying `{"error": ...}`. `pages_search` and `content_search`, which previously degraded silently, now report the same way.

For PHP integrations extending the extension: `RecordService::count()` returns `array{count: int, exact: bool}` instead of `int`, and `findByPid()` / `search()` omit `total` outside the live workspace.

### Security
- **Raised the TYPO3 requirement past TYPO3-CORE-SA-2026-021.** `typo3/cms-*` was constrained to `^13.4.24 || ^14.3.0`, both of which permit versions affected by CVE-2026-19418 (Broken Access Control in Backend and Install Tool, fixed in 13.4.34 / 14.3.6) — so a consumer's fresh `composer install` could land on a vulnerable core. The floor is now `^13.4.34 || ^14.3.6`, and the CI matrix legs are pinned to the same versions so the supported floor is what actually gets tested.
- **Guzzle updated past five advisories** (`7.14.2` → `7.15.5`), the worst high severity: noncanonical host bypassing host-based checks (CVE-2026-69246), noncanonical cookie domain keeping subdomain scope (CVE-2026-69245), URI fragments disclosed in redirect `Referer` headers (CVE-2026-67354), host-only cookie scope not preserved (CVE-2026-67355), and unbounded response cookies risking denial of service (CVE-2026-67353). Guzzle is a transitive dependency of `typo3/cms-core`; **this extension does not use it anywhere**. `file_upload_from_url` — the only code here that fetches a remote URL — uses ext-curl directly with `CURLOPT_FOLLOWLOCATION` off, a manual redirect loop that re-resolves and re-validates every hop, and no cookie jar, so none of the five were reachable through it.
- **PHP_CodeSniffer updated past CVE-2026-67434** (OS command injection, `4.0.1` → `4.0.4`). Development-only, but it runs in CI.

`composer audit` is now clean.

### Fixed
- **OAuth consent was broken on plain-HTTP installs.** The `mcp_csrf` cookie hardcoded `Secure`, so the browser silently discarded it and every consent submission was rejected with `403 CSRF validation failed` — the Authorize button appeared dead and the message named the wrong cause. The flag is now derived from the request scheme, as `OAuthContinuationCookie` already did for the sibling cookie in the same flow.
- **`tx_news_domain_model_news` is no longer registered out of the box.** `ext_localconf.php` put it in `EXTCONF` on every installation, exposing nine CRUD and batch tools for it without the administrator opting in — bypassing the extension-table discovery module, burning the `news` prefix globally, and relying on the EXTCONF path that deliberately skips discovery's table/prefix/label validation. The registration moves into the README as a worked example.
- **Tool parameters taking a JSON object are validated instead of asserted.** All 12 `fields` sites decoded under a `@var array<string, mixed>` annotation; `JSON_THROW_ON_ERROR` guarantees valid JSON, not a JSON *object*, so `fields: "5"` reached `array_intersect_key()` and raised a `TypeError` reported as the opaque "An internal error occurred". The new `JsonObjectParser` names the parameter and what arrived. The dynamic `*_create` tools additionally decoded *outside* `RegistrarToolRunner`, so a malformed `fields` produced no `sys_log` entry and no error sanitisation.
- **`pages_search` / `content_search` no longer degrade silently.** A near-miss like `{"title":"Home"` (missing brace) became a LIKE on the raw literal and matched nothing, and an unknown `orderBy` fell back to the default `uid` sort — both without a signal. Parameter handling for all four search tools now lives in one `SearchParamResolver`. Note that `record_search` / `record_count` report a malformed `search` or an unknown `orderBy` as a `ToolCallException` rather than a 200 carrying `{"error": ...}`, matching what `SearchConditionParser` already did for an unsupported operator inside the same tools.
- **`file_search` escapes LIKE wildcards in `namePattern`.** `%` and `_` acted as wildcards, so `report_2024` also matched `reportX2024` and `%` returned the whole (mount-scoped) storage.
- **A backend user with no workspace access is treated as live.** Core parks a non-admin who can reach neither the live workspace nor any custom one at `workspace_id = -99`; `isLive()` read every non-zero id as a workspace, so such an editor took the workspace-overlay path for no reason. The `WorkspaceRestriction` on the query still uses the raw id, so this affects the overlay alone.
- **Workspace reads paginate and count over the overlay.** `findByPid()` / `search()` ran a separate COUNT for `total` and applied `setMaxResults()` before `overlayMany()` dropped rows hidden in the workspace, so `total` over-reported, pages came back short while later records still existed, a client walking `offset += limit` silently skipped records, and `record_count` disagreed with `record_search` for the same query. Outside the live workspace these tools now fetch, overlay, then slice, returning `hasMore` in place of `total`; `count()` walks the overlaid set and returns `{count, exact}`. **API change:** `RecordService::count()` returns `array{count: int, exact: bool}` instead of `int`, and `findByPid()`/`search()` omit `total` in a non-live workspace. The live path is unchanged.

### Added
- **Configurable audit log level (`auditLogLevel`).** `all` / `mutations` / `errors` / `off`. **The default is `mutations`, which changes existing behaviour**: successful reads are no longer written to `sys_log`. They were the unbounded part — an agent session doing a few thousand `pages_list` / `record_search` / `content_get` calls wrote a few thousand rows, each an `INSERT` in the hot path of the tool call, into a table shared with TYPO3 core's own logging that `mcp:cleanup` does not prune. Every failure is still logged at `mutations`, failed reads included. Set `auditLogLevel = all` to restore the previous behaviour. Read/write classification is fail-closed: the read shapes are enumerated and anything unrecognised counts as a write, so a tool added later under an unfamiliar name lands in the trail rather than falling out of it.
- **Dry-run mode on the destructive tools.** `record_delete_batch`, `record_update_batch`, `record_move_batch`, `pages_delete`, `content_delete` and the generated `<prefix>_delete` / `<prefix>_delete_batch` / `<prefix>_update_batch` / `<prefix>_move_batch` take `dryRun: true` and return exactly what would change — affected UIDs, skipped UIDs, and for updates the fields that would be written and ignored — without touching the database. Responses carry `"dryRun": true`, and single-record deletes report `"deleted": false`, so a preview cannot be read as a completed change. A dry run on a single delete also verifies the record is readable, so it agrees with what the real call would do rather than optimistically reporting "would delete".

### Changed
- **Table CRUD tools are generated from one place.** `DynamicToolRegistrar` was 778 lines of nine near-identical `register*Tool()` methods, each a static closure wrapping another static closure with 8-12 variables threaded through two `use (...)` lists, and the same four-line array-shape docblock pasted nine times. Tools are now invokable handler objects built from a `TableToolConfig` by a `TableToolFactory`, registered through the SDK's `[$instance, '__invoke']` handler form; the registrar is down to 187 lines and only decides which tables get tools. The redirect and scheduler registrars take their get/update/delete from the same factory instead of hand-rolling them, which is where TMS-31's bug came from. No change to any tool's name, parameters or return type; `redirect_update` and `scheduler_update` pick up the dynamic tools' slightly fuller description wording.
- **mcp/sdk bumped to ^0.8.** v0.8.0 adds MCP spec revision `2026-07-28`: `StreamableHttpTransport` now classifies every request and routes it to either the handshake-era or the modern (stateless) dispatcher, so the same `/mcp` endpoint serves both client generations. `McpServerMiddleware` no longer passes `ProtocolVersionMiddleware` in its custom middleware list — since 0.8 that middleware belongs to the handshake leg only (`StreamableHttpTransport::handshakeMiddleware()`, applied by the transport itself), and running it at the edge would reject every modern-era request. The `DnsRebindingProtectionMiddleware` opt-out is unchanged. Every other SDK API the extension consumes (`Server::builder`, the `Builder` fluent methods, `SessionStoreInterface`, `StdioTransport`, `Server::run`, and the `ToolCallException`/`ResourceReadException`/`PromptGetException` exceptions) is unchanged; both eras share one registry, container and session manager, so `ErrorHandlingContainer`, `DatabaseSessionStore` and the audit log apply to modern-era traffic as well. Note that 0.8 answers a not-found tool/prompt/resource with JSON-RPC `-32602` instead of `-32002`.

## [1.1.0] - 2026-07-28

### Security
- **File operations now really honour a non-admin's file mounts** ([#8](https://github.com/marekskopal/typo3-mcp-server/issues/8)). Filemounts, `evaluatePermissions` and the user's `file_permissions` are attached to a `ResourceStorage` by core's `StoragePermissionsAspect`, which is gated on `ApplicationType::fromRequest(...)->isBackend()`. The MCP endpoint runs in the *frontend* middleware stack and `mcp:server` runs in CLI, so that listener never fired and storages were handed out with their constructor defaults. Because `isWithinFileMountBoundaries()` and `checkUserActionPermission()` short-circuit to `true` while `evaluatePermissions` is off, an editor mounted only on `/user_upload/` could read, write, move, rename and delete anywhere in a storage they held any mount in — an upload addressed to `/examples/` silently landed in `fileadmin/examples/` — and their `file_permissions` (addFile/deleteFile/…) were ignored entirely. A new `StoragePermissionService` applies the same restrictions core does, so mount confinement, read-only mounts and file-operation rights are enforced on every file tool. Administrators keep unrestricted access, as in core. Affects 0.12.1 through 1.0.0.
- **`file_search` mount confinement is no longer dead code.** The identifier filter added in 1.0.0 branches on `$storage->getEvaluatePermissions()`, which was always `false` on the MCP paths for the reason above, so non-admin searches still enumerated whole storages. It now takes effect. Its unit tests stubbed the flag to `true` and therefore passed against behaviour production never exhibited; the integration suite now covers the real path with a mounted editor.

### Added
- **`file_storage_list` tool** reporting the storages the current user may access, each with its file mounts (`path`, `title`, `readOnly`) and a `fullAccess` flag for unrestricted (admin) storages. Enforcement alone left an AI client blind — no tool exposed the valid roots, so it would keep addressing paths relative to the storage root and collecting permission errors.
- **`file_list` on `/` returns the user's mount folders** when the storage root lies outside them, mirroring the backend file list, instead of failing with a permission error and leaving the client with no way to discover where it may work. Storages whose root is itself a mount, and unrestricted storages, list normally; a restricted storage with no usable mount still surfaces core's permission error rather than a misleading empty listing.
- File tool descriptions now point at `file_storage_list` for resolving valid directory paths.

### Changed
- **mcp/sdk bumped to ^0.7.** No code changes were required: every SDK API the extension consumes (`Server::builder`, the `Builder` fluent methods, `SessionStoreInterface`, `StreamableHttpTransport`/`StdioTransport` constructors, `Server::run`, and the `ToolCallException`/`ResourceReadException`/`PromptGetException` exceptions) is unchanged in v0.7.0. The release adds an opt-in `Builder::setLazyLoading()`, hardens JSON-RPC input parsing, and now rejects malformed `Mcp-Session-Id` headers itself — the latter overlaps with, but does not conflict with, the existing 400 guard in `McpServerMiddleware`.

## [1.0.0] - 2026-07-03

First stable release. **Run `vendor/bin/typo3 database:updateschema` after upgrading** — this release adds a `dynamically_registered` column to `tx_msmcpserver_oauth_client`.

### Fixed
- **Root-level records were invisible to non-admins.** The page-permission read constraint introduced in 0.12.4 restricted non-`pages` tables to `pid IN (accessible pages)` — a set that can never contain `0` — so every record stored at pid 0 disappeared for non-admin users. `sys_redirect` is a rootLevel table, so `redirect_list` returned nothing and `redirect_get` reported "not found" for editors. Root-level records are now readable again under the table-level `tables_select` grant.
- **`record_count` now honours page-level read permissions.** `count()` was the one read path left out of the 0.12.4 page-permission fix, so counts still leaked for pages outside a non-admin's ACLs and disagreed with `record_search` totals.
- **`mcp:cleanup` no longer deletes admin-created OAuth clients.** The unused-client purge applied to *all* clients without authorizations after 30 days — including clients created manually in the backend module, whose random `client_id` is unrecoverable once deleted. Clients now carry a `dynamically_registered` flag set only by the unauthenticated registration endpoint, and the purge is scoped to it.
- **Invalid dynamic-tool prefixes are rejected when saved.** The extension-table module accepted any non-empty prefix (e.g. `News` or the reserved `record`), which tool registration then silently dropped. Validation is now shared between the module and the registrar, and the save fails with a message naming the problem.
- **Unknown search operators produce a client-visible error.** A typo'd operator (or a non-string `op`) surfaced as a generic "internal error"; the MCP client now receives a message naming the offending operator and listing the supported ones.

### Security
- **Non-admin reads are confined to the user's webmounts.** Page ACLs alone did not fully mirror the backend: a page whose `perms_*` grant SHOW was still returned even when it lay outside the user's mounted page trees. Reads now additionally require the page to be inside the webmounts (expanded to descendants with SHOW, cached per request).
- **Password resets via TYPO3's PasswordReset service revoke MCP tokens (TYPO3 v14+).** The 0.12.4 DataHandler hook did not fire for the "forgot password" email flow or `backend:resetpassword`, which write to `be_users` directly; a listener for `PasswordHasBeenResetEvent` now covers them. TYPO3 v13 dispatches no event for this flow — there, additionally disable the user or revoke their tokens in the backend module after an email reset.
- **`file_search` is confined to the user's filemounts.** The storage-access gate from 0.12.4 still allowed enumerating an entire accessible storage; non-admin results are now restricted to identifiers under the user's filemount folders (none → empty result).
- **UID probing, translations and file references honour page permissions.** `findExistingUids`/`findTranslations` now apply the page-level read constraint (closing a per-page existence oracle through batch-tool "skipped" responses), and `file_reference_list` verifies the parent record is visible before returning reference metadata.
- **Audit log records the target of registrar tool calls.** Dynamic/redirect/scheduler/workspace tools logged an empty argument list and never populated `sys_log.tablename`/`recuid`; all 26 call sites now record redacted arguments plus the affected table and record uid.
- **`workspace` is a reserved dynamic-tool prefix**, so a discovered table can no longer shadow the built-in workspace tools.

### Changed
- Extension state is now **stable**; the README beta notice is gone.
- `ext_emconf.php` now requires TYPO3 13.4.24+ / 14.3+, matching composer.json.
- Added `SECURITY.md` (private vulnerability reporting via GitHub Security Advisories or email).
- composer.json gained support links, author email and discoverability keywords; `LICENCE` was renamed to `LICENSE` for automatic license detection.
- README documents the `refreshTokenMaxLifetime` and `sessionLifetime` settings and the unused-client purge.
- The integration suite gained a non-admin editor scenario covering root-level record reads, webmount containment, count/search consistency and table-grant enforcement.

## [0.12.4] - 2026-07-02

Security-hardening release addressing the findings of a full audit (OAuth layer, request/session path, data access, file operations, permissions). **Run `vendor/bin/typo3 database:updateschema` after upgrading** — this release adds a `family_expires` column to `tx_msmcpserver_oauth_authorization`. Existing rows default to `0` (uncapped) and keep working.

### Security
- **Record reads now enforce page-level permissions for non-admins.** Read paths (`findByUid`/`findByPid`/`search`/`count`) only checked the table-wide `tables_select` grant, so an editor could read pages and content across the entire installation regardless of the per-page `perms_*` ACLs the backend enforces. A `PagePermissionRestriction` (PAGE_SHOW) is now applied for non-admins — directly on `pages`, and via a page (`pid`) subquery for other page-bound tables. Admins keep unrestricted access.
- **Deleting an OAuth client now revokes its tokens.** Deletion only soft-deleted the client row, so its access tokens kept working until expiry and its refresh grant could be rotated indefinitely. Deletion now revokes every authorization for the client, and `refreshToken()` additionally verifies the client still exists (covering hidden/disabled clients).
- **`file_search` now enforces storage access.** It queried `sys_file` directly with all restrictions removed, filtered only by storage uid, letting any user enumerate file metadata of any storage. It now resolves the storage through the backend user's accessible storages first.
- **Atomic single-use consumption of authorization codes and refresh tokens.** Both did a non-atomic check-then-update, so two concurrent token requests could both succeed. Consumption is now a conditional `UPDATE … WHERE revoked = 0` with an affected-row check; a refresh token that loses the race is treated as reuse and revokes the family (OAuth 2.1 §6.1).
- **Refresh grants now have an absolute lifetime.** Rotation reset the refresh-token expiry each time, so a grant could be kept alive forever. A `family_expires` cap is recorded at grant creation, carried through rotations, and enforced. Configurable via the `refreshTokenMaxLifetime` setting (default 90 days).
- **Backend-user password changes revoke MCP tokens.** Token validation does not re-check the password, so tokens issued before a credential reset kept working. A DataHandler hook now revokes a user's authorizations when their `be_users.password` changes.
- **`cache_clear` "all" scope is admin-only.** Flushing every cache (including system caches) is now restricted to administrators, matching core; page-scoped flushes remain available to editors.
- **Read helpers gated and unknown search operators rejected.** `findExistingUids`/`findFileReferences`/`findTranslations` now apply the same read-access check as other reads (closing a UID/metadata oracle via the batch tools), and unknown search operators are rejected instead of silently degrading to a broad substring `LIKE`.
- **MCP session writes enforce ownership.** `DatabaseSessionStore::write()` now refuses to overwrite a session owned by a different backend user, mirroring `read`/`destroy`.
- **Dynamic-tool registration validates discovered-table rows.** Prefix/label/table-name values from the DB are validated (format, reserved/collision, exclusion list, control-character stripping) before being turned into tool names and descriptions.
- **Prompt handlers now go through centralized error handling and audit logging**, so prompt invocations are audited and their exceptions sanitized rather than relayed verbatim.
- **OAuth client registration input hardened.** A malformed JSON body now returns `400` instead of an uncaught 500, and `client_name` is capped to the column length.

### Changed
- **`mcp:cleanup` purges unused OAuth clients.** Dynamically registered clients older than 30 days with no authorizations are now deleted, bounding growth of the unauthenticated-writable client table.
- **Audit log is more complete.** A size-capped, scalar-only subset of tool arguments (uid/pid/table — never field payloads) is now recorded, and swallowed log-write failures are reported to the PSR logger instead of vanishing silently.

## [0.12.3] - 2026-06-17

### Fixed
- **Incompatible with a language prefix on the default language.** On installations where every language — including the default — carries a URL prefix (e.g. `/de/`), `typo3/cms-frontend/base-redirect-resolver` 404'd the prefix-less `/mcp` endpoint and the `/.well-known/...` discovery paths before the MCP middlewares ran. Both frontend middlewares now run *before* `typo3/cms-frontend/site` (and therefore before the base-redirect resolver), short-circuiting their own paths ahead of site/language resolution and passing every other request straight through. The bogus `after: typo3/cms-frontend/normalize-params` dependency (a non-existent identifier that was silently ignored) is corrected to `typo3/cms-core/normalized-params-attribute`. Thanks to @koehnlein for the report (#3).

## [0.12.2] - 2026-06-12

Re-release of 0.12.1. **Use 0.12.2, not 0.12.1** — the 0.12.1 tag was first pushed at a commit whose integration tests failed; the fix below moved the tag, but Packagist pins a tag to its original commit and won't pick up a moved tag, so this is published as a new patch. 0.12.2 contains everything in 0.12.1 (see below) plus the fixes here. The `database:updateschema` note from 0.12.1 still applies.

### Fixed
- **Record search tools** (`record_search`, `pages_search`, `content_search`, `backend_user_list`, `backend_group_list`) failed against a real database. TYPO3's `ExpressionBuilder` already quotes field identifiers, so the defense-in-depth `quoteIdentifier()` call added in 0.12.1 double-quoted them and produced invalid SQL. Reverted — the field names were already safe. (LIKE wildcard escaping of the *value* is unaffected and retained.)
- **File and directory tools** were denied for every user, including admins. 0.12.1 forced `evaluatePermissions` on a storage resolved via `StorageRepository`, which has no filemounts attached, so all actions fell outside the (empty) filemount boundaries. Storages are now resolved through `BackendUserAuthentication::getFileStorages()`, which returns only the user's accessible storages already configured with their filemounts and permission evaluation (admins get every storage with full access) — preserving the filemount enforcement intended in 0.12.1 without breaking access.

## [0.12.1] - 2026-06-12

Security-hardening release from a full audit of the OAuth layer, MCP request path, tools/services, and file operations. **Run `vendor/bin/typo3 database:updateschema` after upgrading** — this release adds a `be_user` column to `tx_msmcpserver_mcp_session` and a `token_family` column to `tx_msmcpserver_oauth_authorization`.

### Security
- **Record reads now enforce `tables_select`.** `RecordService` read methods (`findByUid`, `findByPid`, `search`, `count`) removed all query restrictions, so any authenticated MCP user could read every TCA table via `record_search`/`record_count`/`table_schema` and the generic get/list tools — including `be_users` and `fe_users` — bypassing the admin gate on the dedicated backend-user tools. All read paths and `table_schema` now check the backend user's `tables_select` grant (admins pass automatically).
- **File operations now enforce filemount permissions.** `FileService::getStorage()` returned a `ResourceStorage` with `evaluatePermissions = false`, so a restricted editor could list/read/upload/move/rename/copy/delete files in any storage and path. `setEvaluatePermissions(true)` is now set so FAL honours the user's filemounts and file-operation rights.
- **MCP sessions are bound to the authenticated user.** The session table gained a `be_user` column; `exists`/`read`/`destroy` now refuse a session owned by a different backend user, closing cross-user session-state tampering / DoS via a leaked session id.
- **Refresh-token reuse revokes the whole token family.** Token pairs are tagged with a `token_family` inherited across rotations; replaying an already-revoked refresh token now revokes every token in that family (OAuth 2.1 §6.1).
- **PKCE is validated on the authorize POST**, not just the GET — a crafted POST could previously mint an authorization code bound to an empty or non-S256 challenge.
- **Dynamic client registration validates `redirect_uris`** (RFC 8252: https / http-loopback / reverse-domain private scheme only; count and length capped) instead of storing arbitrary values.
- **Consent screen hardened** with `X-Frame-Options: DENY`, CSP `frame-ancestors 'none'`, `Cache-Control: no-store`, and `X-Content-Type-Options: nosniff` (anti-clickjacking / no-cache).
- **`be_users` `starttime`/`endtime` enforced** when bootstrapping a backend user from a token, so a token for a time-limited account stops working outside its validity window.
- **Registrar-based tools** (dynamic table CRUD, redirects, scheduler, workspaces) are now audit-logged like attribute tools, and no longer relay raw exception messages to the client.
- **Raw exception messages are no longer leaked** to MCP clients (`ErrorHandlingProxy` returns a generic message; full detail is logged server-side).
- **Token endpoint returns a generic `invalid_grant`** instead of distinct per-step error strings (enumeration oracle); the specific reason is logged server-side.
- **`WWW-Authenticate` metadata URL is derived from the trustedHostsPattern-validated host** rather than the raw `Host` header.
- **Audit-log writes hardened**: the error message is kept out of the `details` format string and stripped of control characters, and failures are logged at level `error`.
- **Rate limiting no longer fails open** when the client IP is unresolved (such requests are bucketed under a shared key), and **LIKE search wildcards are escaped**.
- **Defense-in-depth**: loopback redirect URIs must match the query string (only the port may vary); batch UID lists are capped (500) and negative pagination offsets clamped.

### Fixed
- A malformed `Mcp-Session-Id` header returned HTTP 500 (uncaught `Uuid::fromString` exception); it now returns a clean 400.

### Documentation
- README Tools Reference now documents the Permissions (`permission_check_*`), Redirects (`redirect_*`), and Scheduler (`scheduler_*`) tools, and corrects the dynamic extension tools from 6 to 9 per table (the `*_delete_batch` / `*_update_batch` / `*_move_batch` variants).

### Internal
- **Tests grew from 637 to 660.** PHPStan max + PHPCS green. Shared helpers extracted for registrar tool execution (`RegistrarToolRunner`) and batch UID parsing (`UidListParser`).

## [0.12.0] - 2026-06-02

### Changed
- **OAuth login delegated to the real TYPO3 backend login form.** The `/mcp/oauth/authorize` endpoint no longer ships its own username/password form. Unauthenticated authorize requests now set a short-lived HMAC-signed `mcp_oauth_continuation` cookie (sha256 over the relative URL + 600s expiry, keyed off `$GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey']`) and 302 to `/typo3/login`. A sibling backend-stack middleware intercepts `/typo3/main` post-login, verifies the cookie + `BE_USER`, and issues a top-level 302 back to `/mcp/oauth/authorize` — so the consent screen (single "Authorize Access" / "Cancel" button per RFC 6749 §4.1.2.1) renders outside the backend shell and the desktop-OAuth localhost callback redirect is not subject to the backend's `form-action 'self'` CSP. The bounce path-validates the cookie payload against `<basePath>/oauth/authorize?` before honouring it. MFA, `starttime`/`endtime`, per-user lockout, and `sys_log` failed-login entries come along for free (closes TMS-4).

### Removed
- **Custom credential flow.** `OAuthMiddleware::authenticateBackendUser`, `renderAuthorizeForm`, the inline `mcp_csrf`-only username/password form, and the `PasswordHashFactory` / `ConnectionPool` dependencies in `OAuthMiddleware` are gone. Existing `tx_msmcpserver_oauth_client` registrations keep working — only the *user-facing* auth UI changed.

### Internal
- **mcp/sdk bumped to ^0.6.** The v0.6.0 default `StreamableHttpTransport` middleware stack added `DnsRebindingProtectionMiddleware`, which only allows `localhost`/`127.0.0.1`/`[::1]` for `Host`/`Origin` and would 403 every real TYPO3 deployment. `McpServerMiddleware` now passes an explicit middleware list (`[CorsMiddleware, ProtocolVersionMiddleware]`) — bearer-token auth above is the actual protection here. Also adapted `Builder::addResource`/`addResourceTemplate` calls to the new `$title` parameter via named arguments.
- **Tests grew from 613 to 637** (+ `AuthorizeParamsValidatorTest`, `OAuthContinuationCookieTest`, refreshed `OAuthMiddlewareTest` covering unauth → backend-login redirect with continuation cookie, authed → consent form rendered, POST → auth-code created, POST without BE session → 401, `/typo3/main` bounce success / tampered cookie / unauthenticated / foreign URL paths). PHPStan max + PHPCS green.

## [0.11.0] - 2026-05-24

### Added
- **Configurable MCP base path:** new `mcpBasePath` extension setting (default `/mcp`) lets the MCP endpoint and OAuth sub-paths live under a different prefix when `/mcp` is already taken by another handler. OAuth endpoints follow the prefix (e.g. `/typo3-mcp/oauth/authorize`); the OAuth metadata advertises the configured path. Discovery documents follow RFC 8414 §3.1 / RFC 9728 §3 path-insert — for `mcpBasePath = /typo3-mcp` they live at `/.well-known/oauth-authorization-server/typo3-mcp` and `/.well-known/oauth-protected-resource/typo3-mcp`, with `issuer` / `resource` set to the full `https://host<mcpBasePath>` URL. Default `/mcp` now serves at `/.well-known/oauth-authorization-server/mcp` (previously `/.well-known/oauth-authorization-server`); spec-compliant clients rediscover the URL automatically via the WWW-Authenticate `resource_metadata=` hint.
- **`pages_move` tool:** moves a page (with its subpages) to a new position. Takes exactly one of `targetPid` (move as first child of that parent) or `afterUid` (move as next sibling after that page). Mirrors `content_move`; shares the `MoveTarget` helper. Closes the gap where single-page moves required falling back to the generic `record_move_batch`.

### Security
- **`file_upload_from_url` hardened against DNS rebinding and redirect-based SSRF.** Previously the host's IP was validated once via `gethostbyname`, then `fopen($url, ...)` re-resolved DNS at read time — a short-TTL rebind could flip to a private IP between the two calls. `follow_location: 1` also let a public URL 302 to a cloud metadata endpoint (e.g. `169.254.169.254`) without re-validation. The downloader now uses cURL with `CURLOPT_RESOLVE` pinning the validated IP for the entire connection, `CURLOPT_FOLLOWLOCATION` disabled, and a manual redirect loop (max 5 hops) that re-runs scheme + host + IP checks at every hop. `CURLOPT_XFERINFOFUNCTION` enforces the 100 MB cap. `ext-curl` is now an explicit composer requirement.

### Fixed
- **Race-safe upsert in `McpSessionRepository` and `DiscoveredTableRepository`.** Both repositories switched from SELECT-then-INSERT/UPDATE to INSERT-first + catch `UniqueConstraintViolationException` (the pattern already used by `RateLimitService`). Closes a narrow Postgres race window between SELECT and INSERT, and also resolves the original MariaDB "0 affected rows on identical-payload UPDATE" quirk that motivated v0.10.0's d165022 — the UPDATE no longer relies on row-count semantics.

### Internal
- **CI test matrix aligned with `composer.json`:** TYPO3 v14 row was pinned to `^14.1` but `composer.json` requires `^14.3.0`. Matrix now tests `^14.3`.
- **Code coverage reporting in CI:** new `coverage` workflow job runs PHPUnit with pcov and writes a Classes / Methods / Lines summary into the GitHub Actions step summary so coverage trends are visible on each PR.
- **README:** added concrete TYPO3 Scheduler UI steps and a cron example for `mcp:cleanup`. Refreshed the "48+ tools" / "545 tests" claims that had drifted.

## [0.10.0] - 2026-05-21

### Added
- **Persistent MCP sessions:** Session state moves from the SDK's `FileSessionStore` (`var/mcp-sessions/`, wiped on container restart) to a new `tx_msmcpserver_mcp_session` table via `DatabaseSessionStore`. Containers can restart without invalidating active clients; the `Mcp-Session-Id` header keeps working across deploys.
- **Sliding session TTL:** Every read and existence check on a session bumps `last_activity`, so idle TTL only triggers when truly idle. Configurable via the new `sessionLifetime` extension setting (default 86400 seconds / 1 day).

### Changed
- **`mcp:cleanup`** prunes expired DB sessions via `McpSessionRepository::deleteExpired` (TTL-aware cutoff) instead of scanning `var/mcp-sessions/`. The old directory becomes vestigial after upgrade.

### Fixed
- **`404 + JSON-RPC -32600 "Session not found"` rewritten to `401 + WWW-Authenticate`** by `McpServerMiddleware`. MCP clients that auto-retry on 401 transparently re-run OAuth + handshake instead of surfacing the error to the user.
- `McpSessionRepository::upsert` uses a SELECT-then-INSERT/UPDATE pattern (matching `DiscoveredTableRepository::insertIfNew`) to avoid duplicate-key errors when MariaDB's update returns 0 affected rows for identical-payload writes in the same second.

### Upgrade notes
Run `vendor/bin/typo3 database:updateschema` after upgrading to create the new `tx_msmcpserver_mcp_session` table. In-flight sessions on existing installs will be lost once on first deploy; clients will re-handshake on the next request.

## [0.9.3] - 2026-05-21

### Fixed
- **`passthrough` TCA columns stripped from create/update payloads:** Columns with `type: passthrough` (most commonly inline parent FKs like `tx_mspricing_domain_model_planfeature.plan`) were dropped before reaching DataHandler, so child records created via `<prefix>_create` ended up with their parent FK at `0` and never appeared under the parent. `_update` rejected the same field with "No valid fields provided". `passthrough` is now allowlisted in `TcaSchemaService`; DataHandler writes the scalar value directly, and system fields stay excluded via `getSystemFields()`.
- **Scheduler tools failed on TYPO3 v13** with `Unknown column 'pid'`. `SchedulerToolRegistrar` now introspects `tx_scheduler_task` at registration time via `AbstractSchemaManager::introspectTableByUnquotedName()` and exposes only the columns that exist. The `tasktype` / `task_group` / `disable` filter parameters are dropped from queries when their column is missing, so the tool degrades gracefully on schemas without TCA-defined columns. (The bug was latent before v0.9.2 — the default 50-tool pagination hid `scheduler_list` from the test client.)

## [0.9.2] - 2026-05-21

### Fixed
- **Tools missing for clients without pagination support:** The MCP SDK's default `paginationLimit` of 50 truncated `tools/list` responses, hiding dynamic CRUD tools past the first page for clients that don't follow `nextCursor`. `McpServerFactory` now sets the limit to 500 so practical installs fit in a single page.
- **`pi_flexform` (and any `flex` TCA column) stripped from create/update payloads:** `TcaSchemaService` excluded the `flex` type from readable/writable fields, so `content_create`, `content_update`, `record_update_batch`, and every dynamic `<prefix>_create` / `<prefix>_update` tool silently dropped the field with "No valid writable fields provided". `flex` is now allowlisted; DataHandler accepts the raw XML string.
- **`record_search` rejected empty input:** Passing `""` produced "Invalid JSON" and `"{}"` produced "No valid search fields provided", so listing everything in a `pid` required inventing a bogus `field=value`. Empty input is now treated as "no field filter"; inputs containing only unknown fields still error so typos remain visible.

## [0.9.1] - 2026-05-20

### Fixed
- **Soft-deleted records leaking into read tools:** `RecordService` applied `removeAll()` on restrictions but never re-added `DeletedRestriction`, so `content_list`, `record_search`, `pages_search`, `content_search`, and `record_count` returned rows with `deleted=1`. Tools like `content_delete` and `record_delete_batch` worked, but the deleted rows were still visible afterwards. `WorkspaceContextService::applyRestriction()` now always adds `DeletedRestriction` (no-op for tables without soft-delete) alongside `WorkspaceRestriction`. `RecordService::findExistingUids()` and `RecordService::count()` now also apply restrictions.

### Changed
- **`*_move` / `*_copy` tools — explicit `targetPid` / `afterUid` parameters:** `content_move`, `content_copy`, `pages_copy`, `record_move_batch`, and the dynamic `<prefix>_move` / `<prefix>_move_batch` tools no longer take a sign-overloaded `target` parameter. They now take two named parameters and require exactly one:
  - `targetPid` (>= 0): destination page id, places the record at the top of that page
  - `afterUid` (> 0): sibling record uid, places after that sibling on the same page/column
  - Returns an `ErrorResult` when neither or both are provided. Shared resolution logic lives in `Tool/Helper/MoveTarget`.
- **`TcaSchemaService::getReadFields()`** now includes the table's `sortby` field (e.g. `tt_content.sorting`) so the `sorting` value is exposed in list/search responses and can be used as `orderBy`. It remains excluded from `getWritableFields()` because DataHandler is the source of truth — use `*_move` with `afterUid` to reorder.

## [0.9.0] - 2026-04-30

### Added
- **Backend user & group tools (admin-gated):**
  - `backend_user_list` — list `be_users` with `search` / `activeOnly` / `adminOnly` filters
  - `backend_user_get` — fetch a single backend user by uid
  - `backend_group_list` — list `be_groups` with optional title search
  - `backend_group_get` — fetch a single backend group by uid
  - All four restricted to admin backend users via the new `PermissionService::isAdmin()` helper. Sensitive `be_users` columns (`password`, `mfa`) are never selected; soft-deleted records are always excluded.
  - Added `Tool/Helper/RowField` for type-safe extraction of mixed DB-row values.
- **Workspace support (gated on `typo3/cms-workspaces`):**
  - New `WorkspaceContextService` consulted by `RecordService` so reads (`findByUid`, `findByPid`, `search`, `findFileReferences`, `findTranslations`) apply `WorkspaceRestriction` and `BackendUtility::workspaceOL()` for workspace-aware tables. Live workspace and tables without `versioningWS` behave unchanged.
  - `BackendUserBootstrap` now calls `BackendUserAuthentication::setWorkspace()` from the persisted `be_users.workspace_id` when the workspaces extension is loaded, so DataHandler operations write into the active workspace as drafts.
  - **New tools:** `workspace_list`, `workspace_get`, `workspace_switch`, `workspace_changes_list`, `workspace_publish`, `workspace_discard`, `workspace_stage_set` — registered via `WorkspaceToolRegistrar` only when `typo3/cms-workspaces` is installed.
  - `DataHandlerService::processCommand()` for raw cmdmap dispatch (used by publish/discard).
  - **Integration tests:** end-to-end coverage of the workspace lifecycle (switch → modify → overlay-verify → discard → publish → stage_set) added to `Tests/Integration/run-tests.mjs`. `typo3/cms-workspaces` is now installed by the integration test setup. Backend user/group tool coverage added alongside.

## [0.8.0] - 2026-04-27

### Added
- **Scheduler task tools:** `scheduler_task_list`, `scheduler_task_get`, `scheduler_task_update`, `scheduler_task_delete` — conditionally registered when `typo3/cms-scheduler` is installed
- **Redirect management tools:** `redirect_list`, `redirect_get`, `redirect_create`, `redirect_update`, `redirect_delete` — conditionally registered when `typo3/cms-redirects` is installed
- **Permission checking tools:** Pre-flight access verification for table read/write access, page-level permissions, and full permission summary
- **Batch tools for dynamic tables:** Batch delete, update, and move operations now available for dynamically registered extension tables

### Fixed
- Rate-limit `hit_count` increment failing with `Incorrect integer value` — removed redundant `expr()->literal()` wrapping on raw SQL expression
- UID existence validation added before processing in batch tools
- PHPUnit notices resolved by using `createStub()` instead of `createMock()` where no expectations were configured

## [0.7.1] - 2026-04-26

### Changed
- **Upgraded MCP SDK to v0.5.0** — removed `InitializedSession` and `InitializedSessionFactory` workarounds (SDK's `Session::readData()` bug is fixed upstream). `SessionFactoryInterface` replaced by `SessionManagerInterface`.

### Fixed
- Missing `uid`/`pid` columns in `tx_msmcpserver_discovered_table` and `tx_msmcpserver_rate_limit` — tables without TCA need explicit column definitions
- `SearchConditionParser` incorrectly tagged as `mcp.tool` causing runtime crash — excluded from auto-discovery in `Services.yaml`
- Added test to catch future Tool classes missing `#[McpTool]` attribute

## [0.7.0] - 2026-04-26

### Added
- **Extension auto-discovery:** Backend module UI to discover installed TYPO3 extensions with TCA tables, enable/disable them for MCP tool generation, and customize label/prefix — no code changes required. EXTCONF-configured tables remain always-on.
- **`record_count` tool:** Count records in any table without fetching them, with optional pid and search condition filtering
- **`file_search` tool:** Search files by name pattern and/or extension across storage via `sys_file` table
- **Rate limiting:** IP-based rate limiting on all OAuth endpoints with configurable per-endpoint limits and fixed-window counters. Returns 429 Too Many Requests with Retry-After header. Expired entries cleaned up via `mcp:cleanup`.
- Backend module fully translated (English, German, Czech) using `f:translate` ViewHelper
- Extension table management UI: discover, enable/disable, edit label/prefix
- `RecordService::count()` method for lightweight record counting

### Fixed
- **SSRF prevention:** `file_upload_from_url` now rejects URLs resolving to private/reserved IP ranges
- **DoS prevention:** File downloads now stream with size check instead of loading entirely into memory
- **PKCE hardening:** Code verifier validated for length (43-128 chars) and format per RFC 7636
- **Open redirect prevention:** Redirect URI re-validated in authorize POST against registered client URIs
- **Auth bypass prevention:** Soft-deleted backend users now rejected during token validation
- **Info disclosure prevention:** Error messages no longer leak backend user UIDs; generic "Authentication failed" returned
- **Security headers:** Added `Cache-Control: no-store` and `X-Content-Type-Options: nosniff` to OAuth and MCP responses

## [0.6.0] - 2026-04-25

### Added
- **Audit logging:** All tool and resource invocations are logged to TYPO3's `sys_log` table with backend user ID, tool name, execution time, and success/failure status. Visible in TYPO3 backend log module.
- **Batch operations:** `record_delete_batch`, `record_update_batch`, `record_move_batch` — process multiple records atomically in a single DataHandler cycle
- **New tools:** `file_copy`, `pages_search`, `content_search`
- **New prompts:**
  - `check_translation_status` — scan page subtree, report missing translations per language
  - `audit_content_structure` — find content in non-existent backend layout columns
  - `migrate_content` — move all content between pages with layout compatibility check
- `pages_search` and `content_search` accept plain text for LIKE matching or JSON for advanced conditions
- Shared `SearchConditionParser` extracted from `RecordSearchTool` for reuse

### Fixed
- Tool auto-discovery: SDK's `ArrayLoader` was falling back to method name `execute` instead of reading `#[McpTool]` attribute names. All tools now pass names explicitly.

## [0.5.0] - 2026-04-24

### Added
- **New tools:** `file_move`, `file_rename`, `directory_move`, `directory_rename` for file/directory management
- **Search sorting:** `record_search` now supports `orderBy` and `orderDirection` parameters
- **Operator support:** `record_search` supports `eq`, `neq`, `like`, `gt`, `gte`, `lt`, `lte`, `in`, `null`, `notNull` operators
- **OAuthClientController tests** — 14 unit tests for the backend module controller
- `pages_copy` now supports `includeSubpages` parameter for recursive page tree copy
- `content_copy` tool for duplicating content elements
- `file_reference_list` and `file_reference_remove` tools for managing file references
- Added `dg/bypass-finals` dev dependency for mocking TYPO3 final classes in tests

### Changed
- **Auto-discovery:** Tools, resources, and prompts are now auto-discovered via DI tags (`!tagged_iterator`) instead of hardcoded arrays in `McpServerFactory`. Adding a new tool no longer requires modifying the factory.
- **Centralized error handling:** Removed try/catch boilerplate from all 33 tool classes and 6 resource classes. Error handling is now centralized in `ErrorHandlingProxy`, which wraps instances at the container level and converts exceptions to `ToolCallException`/`ResourceReadException`.
- `LoggerInterface` removed from tool and resource constructors — logging is handled by the proxy
- `McpServerFactory` reduced from 147 lines to ~100 lines
- Net reduction of ~350 lines of identical error handling code

## [0.4.0] - 2026-04-23

### Added
- **MCP Resources** for read-only TYPO3 instance discovery:
  - `typo3://system/info` — TYPO3 version, PHP version, and environment context
  - `typo3://sites` — All configured sites with root pages, base URLs, and languages
  - `typo3://schema/tables` — List of all available TCA database tables
  - `typo3://user/me` — Current authenticated backend user information
  - `typo3://schema/tables/{tableName}` — Full TCA field schema for a specific table
  - `typo3://pages/{pageId}/backend-layout` — Backend layout for a page including available column positions (colPos) and grid structure
- **MCP Prompts** for guided multi-step workflows:
  - `translate_page_content` — Translate a page and all its content elements to a target language
  - `audit_page_seo` — Audit SEO metadata for a page and suggest fixes
  - `summarize_page` — Generate a content inventory and summary of a page
- **Cache tool:** `cache_clear` for flushing TYPO3 caches (all, pages, or specific groups)
- `file_upload` now supports plain text content in addition to base64-encoded content

### Changed
- Tool return values refactored from arrays to typed Result DTOs
- Result DTOs excluded from DI container registration

### Fixed
- Fixed link in readme

## [0.3.0] - 2026-04-20

### Added
- **Search tool:** `record_search` for searching records in any table by field values (LIKE match) with optional pid filtering
- `RecordService::search()` method for flexible record queries
- Unit tests for `InitializedSession`, `InitializedSessionFactory`, and `OAuthMiddleware` (26 new tests)
- `McpServerFactory::VERSION` constant as single source of truth for version string

### Fixed
- CORS headers missing on authenticated MCP responses — browser-based MCP clients now work correctly
- OAuth client `crdate` always showing 1970-01-01 — both `ClientRepository` and `OAuthClientController` now set `crdate`/`tstamp` on insert
- PHPUnit notices caused by using `createMock()` instead of `createStub()` where no expectations were configured
- `pages_update` ignoring `hidden`, `starttime`, `endtime`, and `fe_group` fields — enablecolumns are no longer treated as system fields
- Translated pages invisible to `pages_get`, `pages_list`, and other read operations — query restrictions now removed for backend-context queries
- `sys_language_uid` treated as system field — update tools can now modify the language field
- `record_translate` creating broken translations from records with `sys_language_uid = -1` — now validates that the source record is in the default language before localizing


## [0.2.0] - 2026-04-19

### Changed
- Page and content tools now load fields dynamically from TCA instead of static field lists, matching the dynamic tool pattern
- Create tools (`pages_create`, `content_create`) now accept a JSON `fields` parameter instead of explicit typed parameters, consistent with dynamic tools and update tools
- Translation field names (`sys_language_uid`, `l10n_parent`, `l18n_parent`) are resolved dynamically from TCA ctrl configuration
- Removed `final` modifier from all classes — this is a library meant to be extended by other TYPO3 extensions

### Fixed
- Fixed locallang translation files
- Fixed extension icon

## [0.1.0] - 2026-04-18

### Added
- MCP server endpoint at `/mcp` with Streamable HTTP transport
- **stdio transport** via `mcp:server` CLI command for local AI tools (Claude Desktop, Cursor)
- OAuth 2.1 authentication with PKCE (S256) for backend users
- Dynamic client registration (RFC 7591)
- Token revocation (RFC 7009)
- Protected resource metadata (RFC 9728)
- Authorization server metadata (`.well-known/oauth-authorization-server`)
- Configurable token lifetimes via extension settings
- CORS headers for cross-origin MCP clients
- Backend module for OAuth client management with token overview and revocation
- **Page tools:** `pages_list`, `pages_get`, `pages_create`, `pages_update`, `pages_delete`, `pages_tree`
- **Content tools:** `content_list`, `content_get`, `content_create`, `content_update`, `content_delete`
- **File tools:** `file_list`, `file_get_info`, `file_upload`, `file_upload_from_url`, `file_delete`, `directory_create`, `directory_delete`, `file_reference_add`
- **Schema tool:** `table_schema` for TCA field introspection
- **Translation tools:** `site_languages`, `record_translate`
- Dynamic CRUD tool registration for custom tables via `EXTCONF`
- News table pre-configured as dynamic tools (`news_list`, `news_get`, `news_create`, `news_update`, `news_delete`)
- `mcp:cleanup` CLI command for expired token and session garbage collection
- Pagination capped at 500 records per request
- 182 unit tests with PHPStan max-level static analysis
- GitHub Actions CI on PHP 8.3/8.4 with TYPO3 v13/v14 matrix
