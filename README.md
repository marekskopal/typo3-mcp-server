# TYPO3 MCP Server

> **Beta** — This extension is under active development. APIs and behavior may change between releases.

TYPO3 CMS extension that implements an [MCP (Model Context Protocol)](https://modelcontextprotocol.io/) server for TYPO3 administration. It exposes 44 tools for managing pages, content elements, files, and custom extension records via the MCP protocol, allowing AI assistants to interact with your TYPO3 instance.

**This extension is designed for fully autonomous AI operation — no workspaces, no approval queues.** Unlike workspace-based approaches that require human review before changes go live, this server lets AI agents manage your TYPO3 site directly. Changes take effect immediately. This is intentional: the goal is to enable AI agents to build, update, and maintain TYPO3 sites end-to-end without human intervention.

### Example Prompts

These are the kinds of tasks an AI agent can accomplish autonomously through this MCP server:

- *"Create a new 'Services' page under the homepage with three subpages: Web Development, Consulting, and Support. Add introductory text content to each."*
- *"Translate all pages and content elements under page 12 to German and French."*
- *"Upload the product images from these URLs and attach them to the corresponding news records."*
- *"Reorganize the page tree: move all blog posts from 2023 under a new '2023 Archive' page."*
- *"Create a contact form page with a header, text element explaining our office hours, and an address content element."*
- *"Review all pages under 'Products' and update their SEO meta descriptions based on their content."*
- *"Set up the site structure for a new microsite: landing page, about, pricing with three tiers, FAQ, and contact — add placeholder content to each."*
- *"Find all hidden pages in the site and list them with their paths so I can decide which to publish or delete."*
- *"Add a news record for today's product launch, upload the press release PDF, and link it as a file reference."*

## Requirements

- PHP 8.3+
- TYPO3 v13.4 or v14.x

## Installation

```bash
composer require marekskopal/typo3-mcp-server
```

After installation:

1. Activate the extension in TYPO3 backend or via CLI:
   ```bash
   vendor/bin/typo3 extension:setup
   ```
2. Run database migrations to create the required OAuth tables:
   ```bash
   vendor/bin/typo3 database:updateschema
   ```

## Setup

The MCP server supports two transports:

- **HTTP transport** — for remote AI clients connecting over the network (requires OAuth)
- **stdio transport** — for local AI tools running on the same machine (no OAuth needed)

### stdio Transport (Recommended for Local Use)

For AI tools running on the same server as TYPO3 (Claude Desktop, Cursor, Windsurf, VS Code, etc.), use the stdio transport. No OAuth setup is required — the server runs as a backend user directly:

```bash
vendor/bin/typo3 mcp:server
```

Use `--user` to specify which backend user to run as (defaults to `admin`):

```bash
vendor/bin/typo3 mcp:server --user editor
```

### HTTP Transport

For remote AI clients, the MCP server is available at `/mcp` on your TYPO3 instance. It uses the [Streamable HTTP transport](https://modelcontextprotocol.io/specification/2025-03-26/basic/transports#streamable-http) (MCP protocol version 2025-03-26).

HTTP transport requires OAuth 2.1 authentication. See the [Authentication](#authentication) section below.

## AI Client Configuration

### Claude Desktop

Add to your Claude Desktop config file:
- macOS: `~/Library/Application Support/Claude/claude_desktop_config.json`
- Windows: `%APPDATA%\Claude\claude_desktop_config.json`

**stdio (local):**

```json
{
  "mcpServers": {
    "typo3": {
      "command": "php",
      "args": ["vendor/bin/typo3", "mcp:server", "--user", "admin"],
      "cwd": "/path/to/your/typo3/project"
    }
  }
}
```

**HTTP (remote):**

```json
{
  "mcpServers": {
    "typo3": {
      "url": "https://your-typo3-site.com/mcp"
    }
  }
}
```

OAuth authentication is handled automatically — Claude Desktop will open a browser window for the authorization flow.

### Claude Code (CLI)

**stdio (local):**

```bash
claude mcp add typo3 -- php vendor/bin/typo3 mcp:server
```

Or add to your project's `.mcp.json`:

```json
{
  "mcpServers": {
    "typo3": {
      "command": "php",
      "args": ["vendor/bin/typo3", "mcp:server"]
    }
  }
}
```

**HTTP (remote):**

```bash
claude mcp add --transport http typo3 https://your-typo3-site.com/mcp
```

Or in `.mcp.json`:

```json
{
  "mcpServers": {
    "typo3": {
      "url": "https://your-typo3-site.com/mcp"
    }
  }
}
```

### Cursor

Add to `.cursor/mcp.json` in your project root:

**stdio (local):**

```json
{
  "mcpServers": {
    "typo3": {
      "command": "php",
      "args": ["vendor/bin/typo3", "mcp:server"],
      "cwd": "/path/to/your/typo3/project"
    }
  }
}
```

**HTTP (remote):**

```json
{
  "mcpServers": {
    "typo3": {
      "url": "https://your-typo3-site.com/mcp"
    }
  }
}
```

### Windsurf

Add to `~/.codeium/windsurf/mcp_config.json`:

**stdio (local):**

```json
{
  "mcpServers": {
    "typo3": {
      "command": "php",
      "args": ["vendor/bin/typo3", "mcp:server"],
      "cwd": "/path/to/your/typo3/project"
    }
  }
}
```

**HTTP (remote):**

```json
{
  "mcpServers": {
    "typo3": {
      "url": "https://your-typo3-site.com/mcp"
    }
  }
}
```

### VS Code (Copilot)

Add to your VS Code settings (`.vscode/settings.json`):

**stdio (local):**

```json
{
  "mcp": {
    "servers": {
      "typo3": {
        "command": "php",
        "args": ["vendor/bin/typo3", "mcp:server"],
        "cwd": "/path/to/your/typo3/project"
      }
    }
  }
}
```

**HTTP (remote):**

```json
{
  "mcp": {
    "servers": {
      "typo3": {
        "url": "https://your-typo3-site.com/mcp"
      }
    }
  }
}
```

### Other MCP Clients

Any MCP-compatible client can connect via either transport:

- **stdio:** Run `php vendor/bin/typo3 mcp:server` — communicates via stdin/stdout, no auth needed
- **HTTP:** Connect to `https://your-typo3-site.com/mcp` — requires OAuth 2.1 with PKCE. Server metadata is available at `/.well-known/oauth-authorization-server` for auto-discovery.

## Authentication

HTTP transport uses **OAuth 2.1 with PKCE** (S256). Each token is linked to a backend user — all operations respect that user's TYPO3 permissions.

The stdio transport does not require OAuth — the user is specified via the `--user` flag.

### OAuth Features

- **Authorization Code flow with PKCE** — standard OAuth 2.1 for MCP clients
- **Dynamic Client Registration** ([RFC 7591](https://datatracker.ietf.org/doc/html/rfc7591)) — clients can self-register
- **Token Revocation** ([RFC 7009](https://datatracker.ietf.org/doc/html/rfc7009)) — revoke access and refresh tokens
- **Protected Resource Metadata** ([RFC 9728](https://datatracker.ietf.org/doc/html/rfc9728)) — auto-discovery of auth requirements

### OAuth Endpoints

| Endpoint | Description |
|----------|-------------|
| `/.well-known/oauth-authorization-server` | Authorization server metadata |
| `/.well-known/oauth-protected-resource` | Protected resource metadata |
| `/mcp/oauth/authorize` | Authorization endpoint |
| `/mcp/oauth/token` | Token endpoint |
| `/mcp/oauth/revoke` | Token revocation endpoint |
| `/mcp/oauth/register` | Dynamic client registration |

### Token Lifetimes

Configurable via **Settings > Extension Configuration > ms_mcp_server**:

| Setting | Default | Description |
|---------|---------|-------------|
| `accessTokenLifetime` | 3600 (1 hour) | Access token lifetime in seconds |
| `refreshTokenLifetime` | 2592000 (30 days) | Refresh token lifetime in seconds |
| `codeLifetime` | 60 (1 minute) | Authorization code lifetime in seconds |

### Rate Limiting

OAuth endpoints are protected by IP-based rate limiting with configurable per-endpoint limits:

| Setting | Default | Description |
|---------|---------|-------------|
| `rateLimitEnabled` | `1` | Enable/disable rate limiting |
| `rateLimitAuthorize` | `5` / 300s | Authorize POST (password brute-force protection) |
| `rateLimitAuthorizeGet` | `20` / 300s | Authorize GET (form display) |
| `rateLimitToken` | `20` / 300s | Token exchange/refresh |
| `rateLimitRegister` | `10` / 3600s | Client registration |
| `rateLimitRevoke` | `20` / 300s | Token revocation |

Returns `429 Too Many Requests` with `Retry-After` header when exceeded.

### Backend Module

The **System > MCP Server** backend module provides:

- Register and manage OAuth clients
- Edit client settings (name, redirect URIs, linked backend user)
- View active tokens per client with status (active/refreshable/expired)
- Revoke individual tokens
- **Discover extension tables** — scan installed extensions, enable/disable for MCP tool generation, customize label/prefix

## Tools Reference

### Pages

| Tool | Description |
|------|-------------|
| `pages_list` | List child pages with pagination. Supports language filtering and field selection. |
| `pages_get` | Get a single page with all readable fields. |
| `pages_create` | Create a new page. Pass fields as JSON, set language with `sysLanguageUid`. |
| `pages_update` | Update page fields. Pass a JSON object with field names and new values. |
| `pages_delete` | Delete a page by UID. |
| `pages_copy` | Copy a page. Set `includeSubpages: true` to copy the entire subtree. |
| `pages_tree` | Get the page tree hierarchy with configurable depth (1-10, default 3). |
| `pages_search` | Search pages by title (plain text) or advanced conditions (JSON). Supports sorting. |

**Target positioning** (for `pages_copy`): Use a positive target to place as child of that page. Use a negative target to place after a specific page (e.g., `target: -42` means "after page 42").

### Content Elements

| Tool | Description |
|------|-------------|
| `content_list` | List content elements on a page with pagination and language filtering. |
| `content_get` | Get a single content element with all readable fields. |
| `content_create` | Create a content element. Pass fields as JSON, set language with `sysLanguageUid`. |
| `content_update` | Update content element fields. |
| `content_delete` | Delete a content element by UID. |
| `content_move` | Move a content element to a new position. |
| `content_copy` | Copy a content element to a new position. |
| `content_search` | Search content by header (plain text) or advanced conditions (JSON). Supports language filtering and sorting. |

**Target positioning** (for `content_move`, `content_copy`): Positive target = place at top of that page. Negative target = place after element with that UID.

### File Management

| Tool | Description |
|------|-------------|
| `file_list` | List files and directories with pagination. |
| `file_search` | Search files by name pattern and/or extension across storage. |
| `file_get_info` | Get file metadata: UID, name, size, MIME type, public URL. |
| `file_upload` | Upload a file from text content or base64-encoded binary data. |
| `file_upload_from_url` | Download a file from URL and store it (max 100 MB). |
| `file_copy` | Copy a file to a directory. |
| `file_delete` | Delete a file by identifier. |
| `file_move` | Move a file to a different directory. |
| `file_rename` | Rename a file. |
| `directory_create` | Create a new directory. |
| `directory_delete` | Delete a directory. Set `recursive: true` for non-empty directories. |
| `directory_move` | Move a directory to a different parent. |
| `directory_rename` | Rename a directory. |

All file tools accept an optional `storageUid` parameter (default: `1` for fileadmin).

### File References

| Tool | Description |
|------|-------------|
| `file_reference_add` | Attach files to a record's image/media field. Pass sys_file UIDs from upload results. |
| `file_reference_list` | List file references for a record field. Returns reference UIDs and metadata. |
| `file_reference_remove` | Remove file references by UID. Detaches files but does not delete them. |

### Schema and Search

| Tool | Description |
|------|-------------|
| `table_schema` | Get TCA field definitions for any table. Use before creating/updating records to discover valid fields and options. |
| `record_search` | Search records in any table with field conditions, operators, and sorting. |
| `record_count` | Count records in any table without fetching them. Supports pid and search condition filtering. |

**Search operators:** `eq`, `neq`, `like` (default), `gt`, `gte`, `lt`, `lte`, `in` (comma-separated), `null`, `notNull`.

**Search examples:**
```json
// Simple LIKE search
{"title": "hello"}

// Advanced operators
{"uid": {"op": "gt", "value": "10"}, "title": {"op": "eq", "value": "Home"}}

// Combined with sorting
orderBy: "title", orderDirection: "DESC"
```

### Translation

| Tool | Description |
|------|-------------|
| `site_languages` | List available languages for a site. Pass any page ID belonging to the site. |
| `record_translate` | Create a translation of a record. Source must be in default language. Uses TYPO3 connected mode. |

### Batch Operations

| Tool | Description |
|------|-------------|
| `record_delete_batch` | Delete multiple records by comma-separated UIDs. Single atomic DataHandler operation. |
| `record_update_batch` | Update same fields on multiple records (e.g., `{"hidden":1}` on UIDs `1,2,3`). |
| `record_move_batch` | Move multiple records to a target position. |

All batch tools work on any TCA table.

### Cache

| Tool | Description |
|------|-------------|
| `cache_clear` | Flush caches. Scopes: `pages` (default), `all`, or `page` (single page by `pageId`). |

### Dynamic Extension Tools

Additional CRUD tools are registered automatically for tables configured via `EXTCONF` or enabled through the **Extension Tables** backend module (auto-discovery). News is pre-configured and generates 6 tools:

| Tool | Description |
|------|-------------|
| `news_list` | List news records by page ID |
| `news_get` | Get a single news record |
| `news_create` | Create a new news record |
| `news_update` | Update news record fields |
| `news_delete` | Delete a news record |
| `news_move` | Move a news record |

See [Adding Support for Other Extensions](#adding-support-for-other-extensions) to register your own tables.

## Resources Reference

Resources provide read-only context about the TYPO3 instance. AI clients can read these to understand the environment before taking actions.

| Resource | URI | Description |
|----------|-----|-------------|
| System Info | `typo3://system/info` | TYPO3 version, PHP version, application context, OS |
| Site Configuration | `typo3://sites` | All sites with root pages, base URLs, and languages |
| TCA Tables | `typo3://schema/tables` | All available database tables with labels |
| Backend User | `typo3://user/me` | Current user's UID, username, admin status, groups |
| Table Schema | `typo3://schema/tables/{tableName}` | Full field schema for a specific table |
| Backend Layout | `typo3://pages/{pageId}/backend-layout` | Page's backend layout with column positions and grid structure |

## Prompts Reference

Prompts provide guided multi-step workflows that instruct the AI through complex tasks.

| Prompt | Parameters | Description |
|--------|------------|-------------|
| `translate_page_content` | `pageId`, `targetLanguageId` (0 = all) | Translate a page and all its content elements to one or all available languages. |
| `audit_page_seo` | `pageId` | Audit SEO metadata, check for missing titles/descriptions/alt text, report findings. |
| `summarize_page` | `pageId` | Generate a content inventory with all elements, translations, and statistics. |
| `check_translation_status` | `pageId`, `depth` (default 3) | Scan page subtree, report missing translations per language with coverage percentages. |
| `audit_content_structure` | `pageId`, `depth` (default 3) | Find content in non-existent backend layout columns (orphaned after layout changes). |
| `migrate_content` | `sourcePageId`, `targetPageId` | Move all content between pages with layout compatibility check. |

## Adding Support for Other Extensions

**Option 1: Auto-discovery (no code changes)**

Go to **System > MCP Server > Manage Extension Tables**, click **Discover Extension Tables**, then enable the tables you want. The extension scans TCA for installed extension tables and lets you toggle them on/off with customizable labels and prefixes.

**Option 2: Code configuration**

Register custom tables in your extension's `ext_localconf.php`:

```php
$GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ms_mcp_server']['tables']['tx_blog_domain_model_post'] = [
    'label' => 'Blog Post',
    'prefix' => 'blog_post',
];
```

This automatically creates 6 tools (`blog_post_list`, `blog_post_get`, `blog_post_create`, `blog_post_update`, `blog_post_delete`, `blog_post_move`) with fields resolved from TCA.

Optional overrides:

```php
$GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ms_mcp_server']['tables']['tx_blog_domain_model_post'] = [
    'label' => 'Blog Post',
    'prefix' => 'blog_post',
    'listFields' => ['uid', 'pid', 'title', 'datetime'],    // Fields shown in list results
    'readFields' => ['title', 'datetime', 'bodytext'],       // Fields returned by get
    'writableFields' => ['title', 'datetime', 'bodytext'],   // Fields accepted by create/update
];
```

## Maintenance

Clean up expired tokens, stale session files, and rate limit entries:

```bash
vendor/bin/typo3 mcp:cleanup
```

Run this periodically via TYPO3 Scheduler or cron.

## Architecture

```
HTTP request → McpServerMiddleware (Bearer auth)
             → ErrorHandlingContainer (wraps tools with error handling)
             → McpServerFactory (auto-discovers tools via DI tags)
             → MCP SDK Server (StreamableHttpTransport)
             → Tool execution → JSON response
```

Tools, resources, and prompts are **auto-discovered** via DI container tags — no manual registration needed. Adding a new tool is as simple as creating a class with a `#[McpTool]` attribute.

Error handling is **centralized** in `ErrorHandlingProxy`. Tool classes contain only business logic — no try/catch boilerplate, no logger injection.

**Audit logging** records every tool and resource invocation to TYPO3's `sys_log` table — visible in the backend log module with user ID, tool name, execution time, and outcome.

## Development

```bash
composer install

# Static analysis (PHPStan level max)
vendor/bin/phpstan analyse

# Code style (Slevomat Coding Standard)
vendor/bin/phpcs
vendor/bin/phpcbf

# Tests (443 tests)
vendor/bin/phpunit
```

## License

GPL-2.0-or-later
