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
```

## Architecture

**Flow:** HTTP request to `/mcp` → `McpServerMiddleware` (Bearer token auth via OAuth) → `AuthorizationService` → `BackendUserBootstrap` → `McpServerFactory` → MCP SDK `Server` with `StreamableHttpTransport` → Tool execution → JSON response.

**OAuth Flow:** `/.well-known/oauth-authorization-server` → `OAuthMiddleware` handles `/mcp/oauth/authorize`, `/mcp/oauth/token`, `/mcp/oauth/register`, `/mcp/oauth/revoke` endpoints. Uses PKCE (S256), dynamic client registration (RFC 7591), token revocation (RFC 7009), protected resource metadata (RFC 9728), and IP-based rate limiting (configurable per-endpoint, 429 Too Many Requests with Retry-After).

**Key classes (all in `Classes/`):**
- `Middleware/McpServerMiddleware` — PSR-15 middleware intercepting `/mcp` requests, handles auth and delegates to MCP SDK
- `Middleware/OAuthMiddleware` — Handles OAuth 2.1 flows (authorize, token, register, revoke, metadata) with IP-based rate limiting
- `OAuth/AuthorizationService` — Creates auth codes, exchanges codes for tokens, validates access tokens, refreshes tokens, revokes tokens. Token lifetimes are configurable via extension settings.
- `OAuth/ClientRepository` — Manages OAuth clients (find, validate redirect URIs, register)
- `OAuth/RateLimitService` — IP-based rate limiting for OAuth endpoints with configurable per-endpoint limits and fixed-window counters
- `OAuth/PkceVerifier` — S256 PKCE verification
- `OAuth/OAuthTokenPair` — DTO for access/refresh token pairs
- `Authentication/BackendUserBootstrap` — Bootstraps a `BackendUserAuthentication` from a be_users record
- `Server/McpServerFactory` — Builds the MCP Server instance; tools/resources/prompts are auto-discovered via DI tags, no hardcoded registration needed
- `Server/ErrorHandlingContainer` — Decorating PSR-11 container that wraps tool/resource instances with centralized error handling
- `Server/ErrorHandlingProxy` — Proxy that catches `\Throwable` from tool/resource methods, logs it, and converts to `ToolCallException`/`ResourceReadException`
- `Service/DataHandlerService` — Wraps TYPO3 DataHandler for create/update/delete operations (single and batch)
- `Service/RecordService` — Read operations via QueryBuilder (findByUid, findByPid, search with pagination capped at 500)
- `Service/FileService` — File operations via TYPO3 ResourceStorage (list, upload, copy, delete, move, rename, directory ops)
- `Service/TcaSchemaService` — TCA field metadata extraction for schema introspection and dynamic tools
- `Service/PermissionService` — Wraps `$GLOBALS['BE_USER']` permission APIs for table/page access checks and permission summaries
- `Service/BackendLayoutService` — Resolves the effective BackendLayout for a page via BackendLayoutView, returns structured DTOs with column positions and grid structure
- `Tool/Pages/*` — CRUD tools for pages table (use `#[McpTool]` attributes)
- `Tool/Content/*` — CRUD tools for tt_content table (use `#[McpTool]` attributes)
- `Tool/File/*` — File management tools (list, search, get info, upload, upload from URL, copy, delete, move, rename, directory create/delete/move/rename, file reference add/list/remove)
- `Tool/Schema/TableSchemaTool` — TCA field introspection for any table
- `Tool/Search/RecordSearchTool` — Search records in any table by field values with operators (eq, neq, like, gt, gte, lt, lte, in, null, notNull) and sorting
- `Tool/Search/RecordCountTool` — Count records without fetching them, with optional pid and search condition filtering
- `Tool/Search/PagesSearchTool` — Search pages by title (plain text LIKE) or JSON conditions
- `Tool/Search/ContentSearchTool` — Search content elements by header with language filtering
- `Tool/Search/SearchConditionParser` — Shared condition parsing for search tools
- `Tool/Batch/*` — Batch operations (record_delete_batch, record_update_batch, record_move_batch) for any table
- `Tool/Permission/*` — Permission checking tools (check table read/write access, page-level permissions, full permission summary)
- `Tool/Cache/CacheClearTool` — Flush TYPO3 caches (all, pages, or specific cache groups)
- `Logging/AuditLogger` — Writes tool/resource invocations to `sys_log` table with user, timing, and outcome
- `Resource/BackendLayoutResource` — MCP Resource Template exposing backend layout and column positions for a page (`typo3://pages/{pageId}/backend-layout`)
- `Tool/Dynamic/DynamicToolRegistrar` — Registers CRUD tools at runtime for tables configured via `EXTCONF` and discovered tables from `tx_msmcpserver_discovered_table`
- `Service/ExtensionTableDiscoveryService` — Scans TCA for extension tables, generates label/prefix, filters system tables
- `Repository/DiscoveredTableRepository` — CRUD for `tx_msmcpserver_discovered_table` (discovered extension tables with enable/disable)
- `Command/CleanupExpiredTokensCommand` — CLI command (`mcp:cleanup`) to purge expired OAuth tokens and stale MCP session files
- `Controller/OAuthClientController` — Backend module for managing OAuth clients (create, edit, delete) and tokens (view, revoke)
- `Controller/ExtensionTableController` — Backend module for extension table discovery and management (discover, enable/disable, edit label/prefix)

**Configuration:**
- `Configuration/Services.yaml` — DI config with tagged services (`mcp.tool`, `mcp.resource`, `mcp.prompt`) for auto-discovery. Tool/resource/prompt classes are `public: true` for MCP SDK container resolution.
- `Configuration/RequestMiddlewares.php` — Registers OAuthMiddleware and McpServerMiddleware in frontend stack
- `Configuration/Backend/Modules.php` — Backend module registration (OAuth client + extension table routes)
- `Configuration/TCA/tx_msmcpserver_oauth_client.php` — TCA for OAuth client table
- `ext_conf_template.txt` — Extension settings for token lifetimes (accessTokenLifetime, refreshTokenLifetime, codeLifetime) and rate limiting (rateLimitEnabled, per-endpoint limits and windows)

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

447 unit tests covering:
- All 44 static MCP tools + 3 batch tools (Pages/Content/File/Schema/Search/Translation/Cache/Permission/Batch CRUD)
- Dynamic tool registration and execution (DynamicToolRegistrar), including merged EXTCONF + discovered tables
- OAuth classes (AuthorizationService incl. revocation, ClientRepository, PkceVerifier, OAuthTokenPair, RateLimitService)
- OAuthMiddleware (metadata, authorize, register, revoke, token endpoints, rate limiting)
- OAuthClientController (create, edit, update, delete, revokeToken)
- ExtensionTableController (discover, toggle, edit, update)
- ExtensionTableDiscoveryService (TCA scanning, label/prefix generation, system table filtering)
- DiscoveredTableRepository (findAll, findEnabled, findByUid, insertIfNew, update, setEnabled)
- BackendUserBootstrap, McpServerFactory, McpServerMiddleware
- Services (RecordService, DataHandlerService, FileService, TcaSchemaService, BackendLayoutService, PermissionService)
- Resources (SystemInfo, SiteConfiguration, TcaTables, BackendUser, TcaTableSchema, BackendLayout)
- CleanupExpiredTokensCommand

Classes are not `final`, so they can be mocked with PHPUnit. Uses `dg/bypass-finals` for TYPO3 final classes (ModuleTemplateFactory). Use `createStub()` (not `createMock()`) when no `expects()` is configured. `TcaSchemaService` is instantiated directly in tests with `$GLOBALS['TCA']` set up in `setUp()`.
