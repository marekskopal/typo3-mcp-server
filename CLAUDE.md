# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

TYPO3 CMS extension (`ms_mcp_server`) that implements an MCP (Model Context Protocol) server for TYPO3 administration. It exposes tools for CRUD operations on pages, content elements, and news records via the MCP protocol, using Bearer token authentication linked to backend users.

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
```

## Architecture

**Flow:** HTTP request to `/mcp` → `McpServerMiddleware` (Bearer token auth) → `TokenAuthenticator` → `BackendUserBootstrap` → `McpServerFactory` → MCP SDK `Server` with `StreamableHttpTransport` → Tool execution → JSON response.

**Key classes (all in `Classes/`):**
- `Middleware/McpServerMiddleware` — PSR-15 middleware intercepting `/mcp` requests, handles auth and delegates to MCP SDK
- `Authentication/TokenAuthenticator` — Validates Bearer tokens against `tx_msmcpserver_token` table
- `Authentication/BackendUserBootstrap` — Bootstraps a `BackendUserAuthentication` from a be_users record
- `Server/McpServerFactory` — Builds the MCP Server instance with all tools registered
- `Service/DataHandlerService` — Wraps TYPO3 DataHandler for create/update/delete operations
- `Service/RecordService` — Read operations via QueryBuilder (findByUid, findByPid)
- `Tool/Pages/*` — CRUD tools for pages table
- `Tool/Content/*` — CRUD tools for tt_content table
- `Tool/News/*` — CRUD tools for tx_news_domain_model_news table
- `Controller/TokenManagementController` — Backend module for managing API tokens

**Configuration:**
- `Configuration/Services.yaml` — DI config
- `Configuration/RequestMiddlewares.php` — Registers McpServerMiddleware in frontend stack
- `Configuration/Backend/Modules.php` — Backend module registration
- `Configuration/TCA/tx_msmcpserver_token.php` — TCA for token table

## Code Standards

- PHP 8.3+ with `declare(strict_types=1)`
- PHPStan at level **max** with bleeding edge, strict checks, and `checkImplicitMixed: true`
- PHPCS with SlevomatCodingStandard (140 char line limit)
- All classes are `final readonly` where possible
- Supports TYPO3 v13.4 and v14.x
