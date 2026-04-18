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

**OAuth Flow:** `/.well-known/oauth-authorization-server` → `OAuthMiddleware` handles `/mcp/oauth/authorize`, `/mcp/oauth/token`, `/mcp/oauth/register`, `/mcp/oauth/revoke` endpoints. Uses PKCE (S256), dynamic client registration (RFC 7591), token revocation (RFC 7009), and protected resource metadata (RFC 9728).

**Key classes (all in `Classes/`):**
- `Middleware/McpServerMiddleware` — PSR-15 middleware intercepting `/mcp` requests, handles auth and delegates to MCP SDK
- `Middleware/OAuthMiddleware` — Handles OAuth 2.1 flows (authorize, token, register, revoke, metadata)
- `OAuth/AuthorizationService` — Creates auth codes, exchanges codes for tokens, validates access tokens, refreshes tokens, revokes tokens. Token lifetimes are configurable via extension settings.
- `OAuth/ClientRepository` — Manages OAuth clients (find, validate redirect URIs, register)
- `OAuth/PkceVerifier` — S256 PKCE verification
- `OAuth/OAuthTokenPair` — DTO for access/refresh token pairs
- `Authentication/BackendUserBootstrap` — Bootstraps a `BackendUserAuthentication` from a be_users record
- `Server/McpServerFactory` — Builds the MCP Server instance with all tools registered (19 static + dynamic)
- `Server/InitializedSession` — Fixed SessionInterface implementation (workaround for SDK's `readData()` bug)
- `Server/InitializedSessionFactory` — Factory for InitializedSession instances
- `Service/DataHandlerService` — Wraps TYPO3 DataHandler for create/update/delete operations
- `Service/RecordService` — Read operations via QueryBuilder (findByUid, findByPid with pagination capped at 500)
- `Service/FileService` — File operations via TYPO3 ResourceStorage (list, upload, delete, directory ops)
- `Service/TcaSchemaService` — TCA field metadata extraction for schema introspection and dynamic tools
- `Tool/Pages/*` — CRUD tools for pages table (use `#[McpTool]` attributes)
- `Tool/Content/*` — CRUD tools for tt_content table (use `#[McpTool]` attributes)
- `Tool/File/*` — File management tools (list, get info, upload, upload from URL, delete, directory create/delete, file reference add)
- `Tool/Schema/TableSchemaTool` — TCA field introspection for any table
- `Tool/Dynamic/DynamicToolRegistrar` — Registers CRUD tools at runtime for tables configured via `EXTCONF`
- `Command/CleanupExpiredTokensCommand` — CLI command (`mcp:cleanup`) to purge expired OAuth tokens and stale MCP session files
- `Controller/OAuthClientController` — Backend module for managing OAuth clients (create, edit, delete) and tokens (view, revoke)

**Configuration:**
- `Configuration/Services.yaml` — DI config (tool classes are `public: true` for MCP SDK container resolution, cleanup command registered)
- `Configuration/RequestMiddlewares.php` — Registers OAuthMiddleware and McpServerMiddleware in frontend stack
- `Configuration/Backend/Modules.php` — Backend module registration (index, create, edit, update, delete, revoke_token routes)
- `Configuration/TCA/tx_msmcpserver_oauth_client.php` — TCA for OAuth client table
- `ext_conf_template.txt` — Extension settings for token lifetimes (accessTokenLifetime, refreshTokenLifetime, codeLifetime)

**SDK Workarounds:**
- `InitializedSession` implements `SessionInterface` directly instead of extending SDK's `Session` class. The SDK's `Session::readData()` uses `isset($this->data)` which is always true (property initialized to `[]`), so `createWithId()` never reads persisted data from the file store. Our implementation uses a `$loaded` flag.
- Tool classes must be `public: true` in Services.yaml because the SDK's `ReferenceHandler` calls `container->has()` which returns false for private TYPO3 services.

## Code Standards

- PHP 8.3+ with `declare(strict_types=1)`
- PHPStan at level **max** with bleeding edge, strict checks, and `checkImplicitMixed: true`
- PHPCS with SlevomatCodingStandard (140 char line limit)
- All classes are `final readonly` where possible
- Supports TYPO3 v13.4 and v14.x
- Tool descriptions use `#[McpTool]` attributes from MCP SDK
- All tools wrap service calls in try/catch, log errors via `LoggerInterface`, and throw `ToolCallException`
- CI runs PHPStan, PHPCS, and PHPUnit via GitHub Actions on PHP 8.3/8.4 with TYPO3 v13/v14 matrix

## Testing

153 unit tests covering:
- All 19 static MCP tools (Pages/Content/File/Schema CRUD + error handling)
- Dynamic tool registration and execution (DynamicToolRegistrar)
- OAuth classes (AuthorizationService incl. revocation, ClientRepository, PkceVerifier, OAuthTokenPair)
- BackendUserBootstrap, McpServerFactory, McpServerMiddleware
- Services (RecordService, DataHandlerService, FileService, TcaSchemaService)
- CleanupExpiredTokensCommand

Final classes cannot be mocked with PHPUnit — tests construct real instances with mocked dependencies. `RecordService` and `DataHandlerService` are `readonly` (not `final`) and can be mocked directly. Use `createStub()` (not `createMock()`) when no `expects()` is configured.
