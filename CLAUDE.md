# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

TYPO3 CMS extension (`ms_mcp_server`) that implements an MCP (Model Context Protocol) server for TYPO3 administration. It exposes tools for CRUD operations on pages, content elements, files, and dynamically configured extension tables via the MCP protocol, using OAuth 2.1 with PKCE for authentication linked to backend users.

## Commands

```bash
# Static analysis (level max, strict)
vendor/bin/phpstan analyse

# Code style check
vendor/bin/phpcs

# Code style fix
vendor/bin/phpcbf

# Run tests
vendor/bin/phpunit

# Install dependencies
composer install

# Clean up expired tokens and sessions
vendor/bin/typo3 mcp:cleanup

# Serve MCP over stdio for a local AI client (the README's recommended local setup)
vendor/bin/typo3 mcp:server --user=admin
```

## Architecture

**Flow:** HTTP request to `/mcp` → `McpServerMiddleware` (Bearer token auth via OAuth) → `AuthorizationService` → `BackendUserBootstrap` → `McpServerFactory` → MCP SDK `Server` with `StreamableHttpTransport` → Tool execution → JSON response.

**Stdio Flow:** `mcp:server --user=<username>` → `BackendUserBootstrap` → `McpServerFactory` → MCP SDK `Server` with `StdioTransport`. No OAuth: the CLI caller is already trusted, and the backend user is named on the command line. This is the path the README recommends for local AI clients.

**OAuth Flow:** `/.well-known/oauth-authorization-server/mcp` (RFC 8414 path-insert) → `OAuthMiddleware` handles `/mcp/oauth/authorize`, `/mcp/oauth/token`, `/mcp/oauth/register`, `/mcp/oauth/revoke` endpoints. Uses PKCE (S256), dynamic client registration (RFC 7591), token revocation (RFC 7009), protected resource metadata (RFC 9728), and IP-based rate limiting (configurable per-endpoint, 429 Too Many Requests with Retry-After). Authentication is delegated to TYPO3's real backend login: an unauthenticated `authorize` GET sets an HMAC-signed `mcp_oauth_continuation` cookie carrying the authorize URL and 302s to `/typo3/login`; the same middleware is also registered in the backend stack and bounces `/typo3/main` post-login back to the authorize endpoint, where a single-click consent screen renders outside the backend shell (so the localhost-callback 302 isn't subject to the backend's CSP).

**Key classes (all in `Classes/`):**
- `Middleware/McpServerMiddleware` — PSR-15 middleware intercepting `/mcp` requests, handles auth and delegates to MCP SDK
- `Middleware/OAuthMiddleware` — Handles OAuth 2.1 flows (authorize, token, register, revoke, metadata) with IP-based rate limiting
- `OAuth/AuthorizationService` — Creates auth codes, exchanges codes for tokens, validates access tokens, refreshes tokens, revokes tokens. Token lifetimes are configurable via extension settings.
- `OAuth/ClientRepository` — Manages OAuth clients (find, validate redirect URIs, register)
- `OAuth/RateLimitService` — IP-based rate limiting for OAuth endpoints with configurable per-endpoint limits and fixed-window counters
- `OAuth/PkceVerifier` — S256 PKCE verification
- `OAuth/OAuthTokenPair` — DTO for access/refresh token pairs
- `OAuth/AuthorizeParamsValidator` — Shared OAuth authorize-param validation (used by `OAuthMiddleware` on both GET and POST)
- `OAuth/OAuthContinuationCookie` — HMAC-signed cookie that carries the relative authorize URL across the `/typo3/login` round-trip; signed with `$GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey']`, 600s TTL, `HttpOnly; SameSite=Lax; Secure` when on HTTPS
- `Authentication/BackendUserBootstrap` — Bootstraps a `BackendUserAuthentication` from a be_users record
- `Server/McpServerFactory` — Builds the MCP Server instance; tools/resources/prompts are auto-discovered via DI tags, no hardcoded registration needed. Wires `DatabaseSessionStore` so MCP sessions survive container restarts.
- `Server/Session/DatabaseSessionStore` — `Mcp\Server\Session\SessionStoreInterface` implementation backed by `tx_msmcpserver_mcp_session`. Bumps `last_activity` on read/exists for sliding TTL.
- `Server/ErrorHandlingContainer` — Decorating PSR-11 container that wraps tool/resource instances with centralized error handling
- `Server/ErrorHandlingProxy` — Proxy that catches `\Throwable` from tool/resource methods, logs it, and converts to `ToolCallException`/`ResourceReadException`
- `Service/DataHandlerService` — Wraps TYPO3 DataHandler for create/update/delete operations (single and batch)
- `Service/RecordService` — Read operations via QueryBuilder (findByUid, findByPid, search with pagination capped at 500, count). Outside the live workspace, `findByPid`/`search` fetch-overlay-slice and return `hasMore` instead of `total` (a SQL COUNT cannot be workspace-overlaid), and `count()` returns `array{count: int, exact: bool}` from the overlaid set.
- `Service/FileService` — File operations via TYPO3 ResourceStorage (storage/mount discovery, list, upload, copy, delete, move, rename, directory ops)
- `Service/StoragePermissionService` — Applies a backend user's filemounts, `evaluatePermissions` and `file_permissions` to a `ResourceStorage`. Core's `StoragePermissionsAspect` only runs for backend requests; the MCP endpoint is a frontend/CLI context, so without this a non-admin would reach an entire storage rather than just their mounts.
- `Service/TcaSchemaService` — TCA field metadata extraction for schema introspection and dynamic tools
- `Service/PermissionService` — Wraps `$GLOBALS['BE_USER']` permission APIs for table/page access checks and permission summaries
- `Service/WorkspaceContextService` — Current workspace id, workspace-awareness of a table, the `WorkspaceRestriction` applied to every read, and `overlay()`/`overlayMany()`. `isLive()` treats any id `<= 0` as live: `0` is the live workspace and a negative id is core's "no workspace access" sentinel (`-99`), not a workspace to overlay into.
- `Service/CacheService` — Thin wrapper over `CacheManager` for flushing all caches, the `pages` group, or one page by tag
- `Service/SiteLanguageService` — Resolves a page's site and returns its configured languages (id, title, locale, flag, enabled, hreflang)
- `Service/McpPathProvider` — Single source of truth for the endpoint paths, derived from the `mcpBasePath` setting. Everything that builds or matches an MCP/OAuth URL goes through it rather than hardcoding `/mcp`.
- `Service/BackendLayoutService` — Resolves the effective BackendLayout for a page via BackendLayoutView, returns structured DTOs with column positions and grid structure
- `Tool/Pages/*` — CRUD tools for pages table (use `#[McpTool]` attributes)
- `Tool/Content/*` — CRUD tools for tt_content table (use `#[McpTool]` attributes)
- `Tool/File/*` — File management tools (storage/mount list, list, search, get info, upload, upload from URL, copy, delete, move, rename, directory create/delete/move/rename, file reference add/list/remove). Non-admins are confined to their filemounts; `file_storage_list` reports the valid roots and `file_list` on `/` returns the mount folders.
- `Tool/Schema/TableSchemaTool` — TCA field introspection for any table
- `Tool/Search/RecordSearchTool` — Search records in any table by field values with operators (eq, neq, like, gt, gte, lt, lte, in, null, notNull) and sorting
- `Tool/Search/RecordCountTool` — Count records without fetching them, with optional pid and search condition filtering
- `Tool/Search/PagesSearchTool` — Search pages by title (plain text LIKE) or JSON conditions
- `Tool/Search/ContentSearchTool` — Search content elements by header with language filtering
- `Tool/Search/SearchConditionParser` — Shared condition parsing for search tools
- `Tool/Batch/*` — Batch operations (record_delete_batch, record_update_batch, record_move_batch) for any table. All take `dryRun`, which reports the affected set without calling DataHandler; the result DTOs carry a `dryRun` flag so a preview is distinguishable from a real change.
- `Tool/Redirect/RedirectToolRegistrar` — Conditionally registers redirect management tools when `typo3/cms-redirects` is installed. Get/update/delete come from `TableToolFactory`; only the filtered list and the explicit-parameter create are hand-written.
- `Tool/Scheduler/SchedulerToolRegistrar` — Conditionally registers scheduler task tools when `typo3/cms-scheduler` is installed, with field lists introspected from the table because the columns vary by TYPO3 version. Get/update/delete come from `TableToolFactory`; only the task-type list is hand-written.
- `Tool/Workspace/WorkspaceToolRegistrar` — Conditionally registers workspace tools (`workspace_list`, `_get`, `_switch`, `_changes_list`, `_publish`, `_discard`, `_stage_set`) when `typo3/cms-workspaces` is installed. `workspace_switch` persists to `be_users.workspace_id`, so every later read and write in the session runs in that workspace.
- `Tool/Translation/*` — `record_translate` (localize a record into a target language via DataHandler) and `site_languages` (the languages configured for a page's site)
- `Tool/Permission/*` — Permission checking tools (check table read/write access, page-level permissions, full permission summary)
- `Tool/BackendUser/*` — Admin-only tools to list and inspect `be_users` (list with username/active/admin filters, get by uid). Sensitive fields (`password`, `mfa`) are never selected.
- `Tool/BackendGroup/*` — Admin-only tools to list and inspect `be_groups` (list with title filter, get by uid).
- `Tool/Helper/RowField` — Internal helper for typed extraction of fields from DB rows (excluded from `mcp.tool` auto-discovery)
- `Tool/Helper/MoveTarget` — Internal helper that translates the user-facing `targetPid`/`afterUid` pair into TYPO3 DataHandler's move/copy target convention (excluded from `mcp.tool` auto-discovery)
- `Tool/Helper/UidListParser` — Parses the batch tools' comma-separated UID strings, capped at `MAX_UIDS = 500` so one call cannot enqueue an unbounded DataHandler pass
- `Tool/Helper/JsonObjectParser` — Decodes and **validates** a tool's JSON-object parameter (`fields`, `search`). `JSON_THROW_ON_ERROR` guarantees valid JSON, not a JSON *object*, so use this rather than annotating the decode with `@var` — the annotation only suppresses PHPStan while a scalar still reaches `array_intersect_key()` and raises a `TypeError`.
- `Tool/Helper/RegistrarToolRunner` — Execution wrapper for registrar (closure) tools, which bypass `ErrorHandlingProxy`. Brings them to parity: every call is audited to `sys_log` and raw exception messages are not relayed. Decode arguments **inside** the wrapped closure, or the failure escapes both guarantees.
- `Tool/Search/SearchParamResolver` — Shared `search` / `orderBy` / `orderDirection` handling for all four search tools, so they cannot drift apart again. A value opening with `{` or `[` is read as JSON and parse failures are reported; anything else is a plain-text LIKE term.
- `Tool/Cache/CacheClearTool` — Flush TYPO3 caches (all, pages, or specific cache groups)
- `Logging/AuditLogger` — Writes tool/resource invocations to `sys_log` table with user, timing, and outcome
- `Resource/BackendLayoutResource` — MCP Resource Template exposing backend layout and column positions for a page (`typo3://pages/{pageId}/backend-layout`)
- `Tool/Dynamic/DynamicToolRegistrar` — Decides which tables get tools (from `EXTCONF` and the enabled rows of `tx_msmcpserver_discovered_table`) and resolves their field lists from TCA; the tools themselves come from `TableToolFactory`
- `Tool/Table/TableToolConfig` — Value object describing a table to the generated tools: name, label, prefix, list/read/writable fields, translation fields, and the `noun` its rows are called in tool text (`record`, or `task` for the scheduler)
- `Tool/Table/TableToolFactory` — Builds and registers the CRUD + batch tools from a `TableToolConfig`. Shared by the dynamic, redirect and scheduler registrars, which each used to hand-roll the same get/update/delete bodies — the drift between those copies is what produced the TMS-31 bug.
- `Tool/Table/Handler/*` — One invokable object per generated tool, registered as `[$handler, '__invoke']` (the SDK's instance-handler form) so `__invoke`'s signature *is* the tool's input schema. List and create have a `Translatable*` variant because the translatable form differs in signature. Each handler owns its name and description and runs its body through `AbstractTableToolHandler::run()`, the only path to `RegistrarToolRunner` — so a decode can no longer end up outside the audit wrapper.
- `Service/ExtensionTableDiscoveryService` — Scans TCA for extension tables, generates label/prefix, filters system tables
- `Repository/DiscoveredTableRepository` — CRUD for `tx_msmcpserver_discovered_table` (discovered extension tables with enable/disable)
- `Repository/McpSessionRepository` — CRUD for `tx_msmcpserver_mcp_session` (persistent MCP session storage)
- `Prompt/*` — Six guided workflows exposed as MCP prompts: `translate_page_content`, `audit_page_seo`, `summarize_page`, `check_translation_status`, `audit_content_structure`, `migrate_content`. Auto-discovered via the `mcp.prompt` DI tag.
- `DataHandling/BackendUserPasswordChangeHook` — DataHandler `processDatamapClass` hook that revokes a backend user's MCP authorizations when their password changes
- `EventListener/PasswordResetTokenRevocationListener` — Same revocation for TYPO3's `PasswordReset` service (the "forgot password" email and `backend:resetpassword`), which writes `be_users` directly and never reaches DataHandler. `PasswordHasBeenResetEvent` is **v14+ only**, so that flow is uncovered on v13.
- `Command/CleanupExpiredTokensCommand` — CLI command (`mcp:cleanup`) to purge expired OAuth tokens and stale MCP sessions
- `Command/McpServerCommand` — CLI command (`mcp:server --user=<username>`) serving MCP over `StdioTransport` for local AI clients
- `Controller/OAuthClientController` — Backend module for managing OAuth clients (create, edit, delete) and tokens (view, revoke)
- `Controller/ExtensionTableController` — Backend module for extension table discovery and management (discover, enable/disable, edit label/prefix)

**Configuration:**
- `Configuration/Services.yaml` — DI config with tagged services (`mcp.tool`, `mcp.resource`, `mcp.prompt`) for auto-discovery. Tool/resource/prompt classes are `public: true` for MCP SDK container resolution.
- `Configuration/RequestMiddlewares.php` — Registers OAuthMiddleware and McpServerMiddleware in frontend stack
- `Configuration/Backend/Modules.php` — Backend module registration (OAuth client + extension table routes)
- `Configuration/TCA/tx_msmcpserver_oauth_client.php` — TCA for OAuth client table
- `ext_conf_template.txt` — Extension settings for the endpoint base path (mcpBasePath, default `/mcp`, read through `McpPathProvider`), token lifetimes (accessTokenLifetime, refreshTokenLifetime, refreshTokenMaxLifetime — the absolute cap past which a refresh chain cannot slide further, codeLifetime), session lifetime (sessionLifetime, sliding TTL in seconds, default 86400), and rate limiting (rateLimitEnabled, per-endpoint limits and windows)

**SDK Workarounds:**
- Tool classes must be `public: true` in Services.yaml because the SDK's `ReferenceHandler` calls `container->has()` which returns false for private TYPO3 services.

## Adding a New Tool

1. Create a class in `Classes/Tool/<Category>/` with a `#[McpTool]` attribute on the `execute` method
2. Inject only the services you need (no `LoggerInterface` — error handling is automatic)
3. The tool is auto-discovered via DI tags — no changes to `McpServerFactory` or `Services.yaml` needed
4. Add a test in `Tests/Unit/Tool/<Category>/`

Example minimal tool:
```php
readonly class MyTool
{
    public function __construct(private RecordService $recordService) {}

    #[McpTool(name: 'my_tool', description: 'Does something useful.')]
    public function execute(int $uid): string
    {
        $record = $this->recordService->findByUid('pages', $uid, ['uid', 'title']);
        return json_encode($record, JSON_THROW_ON_ERROR);
    }
}
```

## Code Standards

- PHP 8.3+ with `declare(strict_types=1)`
- PHPStan at level **max** with bleeding edge, strict checks, and `checkImplicitMixed: true`
- PHPCS with SlevomatCodingStandard (140 char line limit)
- Classes are `readonly` where possible — do **not** use `final` (this is a library meant to be extended)
- Supports TYPO3 v13.4 and v14.x
- Tool descriptions use `#[McpTool]` attributes from MCP SDK — tools are auto-discovered via DI tags
- Error handling is centralized in `ErrorHandlingProxy` — tools do NOT need try/catch or `LoggerInterface`
- CI runs PHPStan, PHPCS, and PHPUnit via GitHub Actions on PHP 8.3/8.4 with TYPO3 v13/v14 matrix

## Testing

793 unit tests covering:
- All static MCP tools + batch tools (Pages/Content/File/Schema/Search/Translation/Cache/Permission/BackendUser/BackendGroup/Batch CRUD)
- Dynamic tool registration and execution (DynamicToolRegistrar), including merged EXTCONF + discovered tables
- OAuth classes (AuthorizationService incl. revocation, ClientRepository, PkceVerifier, OAuthTokenPair, RateLimitService)
- OAuthMiddleware (metadata, authorize, register, revoke, token endpoints, rate limiting)
- OAuthClientController (create, edit, update, delete, revokeToken)
- ExtensionTableController (discover, toggle, edit, update)
- ExtensionTableDiscoveryService (TCA scanning, label/prefix generation, system table filtering)
- DiscoveredTableRepository (findAll, findEnabled, findByUid, insertIfNew, update, setEnabled)
- BackendUserBootstrap, McpServerFactory, McpServerMiddleware
- Services (RecordService, DataHandlerService, FileService, StoragePermissionService, TcaSchemaService, BackendLayoutService, PermissionService, WorkspaceContextService, CacheService, SiteLanguageService, McpPathProvider)
- Resources (SystemInfo, SiteConfiguration, TcaTables, BackendUser, TcaTableSchema, BackendLayout)
- Prompts (all six), tool helpers (UidListParser, JsonObjectParser), TableToolFactory + TableToolConfig
- CleanupExpiredTokensCommand

Plus an **integration suite** under `Tests/Integration/` (`setup-typo3.sh` + `run-tests.mjs`) that drives the tools over `mcp:server` against a real TYPO3 and database, in both an admin and a non-admin (editor) scenario. It runs only in the `Integration Tests` GitHub Actions workflow — `vendor/bin/phpunit` does **not** exercise it. It is the only place that covers what unit tests structurally cannot: page/file mount containment for a real editor, and workspace overlay behaviour (a record deleted in a workspace leaves a `DELETE_PLACEHOLDER` that only the overlay drops).

Classes are not `final`, so they can be mocked with PHPUnit. Uses `dg/bypass-finals` for TYPO3 final classes (ModuleTemplateFactory). Use `createStub()` (not `createMock()`) when no `expects()` is configured. `TcaSchemaService` is instantiated directly in tests with `$GLOBALS['TCA']` set up in `setUp()`.

## Releasing

1. Bump the version in **both** `ext_emconf.php` and `McpServerFactory::VERSION`, and add a `CHANGELOG.md` entry.
   Update **`CLAUDE.md` alongside `README.md`** — this file steers AI-assisted work on the repo, so drift here compounds. Check the key-class list, the settings list, and the test count against the tree.
2. Push the release commit to `main` and **wait until every GitHub Actions workflow passes on that commit before creating the tag or release.** There are two workflows — `CI` (PHPStan + PHPCS + PHPUnit) and `Integration Tests` (runs the tools against a real TYPO3 + database). The local `vendor/bin/phpunit` suite does **not** exercise the integration suite, so a green local run is not enough. Check with `gh run list` and only proceed once both are `success`.
3. Only then create the tag and GitHub release (`git tag` + `gh release create`).
4. **Never move or re-tag a published version** — Packagist pins a tag to its original commit and will not pick up a moved tag. If a tagged release is broken, ship a new patch version instead.
