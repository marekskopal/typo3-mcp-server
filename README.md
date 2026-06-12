# TYPO3 MCP Server

> **Beta** — This extension is under active development. APIs and behavior may change between releases.

TYPO3 CMS extension that implements an [MCP (Model Context Protocol)](https://modelcontextprotocol.io/) server for TYPO3 administration. It exposes 60+ tools for managing pages, content elements, files, backend users, and custom extension records via the MCP protocol, allowing AI assistants to interact with your TYPO3 instance.

**This extension is designed primarily for direct, fully autonomous AI operation — changes take effect immediately, with no approval queue between the AI and the live site.** The goal is to let AI agents build, update, and maintain TYPO3 sites end-to-end without human intervention.

Workspace support is **also available** when `typo3/cms-workspaces` is installed: the AI can switch into a workspace, make draft changes, and use the workspace tools to publish or discard them. This is a secondary mode for cases where review-before-publish is required — direct mode remains the primary use case.

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
2. Run database migrations to create the required OAuth and session tables:
   ```bash
   vendor/bin/typo3 database:updateschema
   ```
   Re-run this command after every extension update — new tables (e.g. `tx_msmcpserver_mcp_session` introduced for persistent sessions) won't be created automatically.

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

The base path is configurable via the `mcpBasePath` extension setting if `/mcp` is already in use by another handler — see [Base Path](#base-path).

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
- **HTTP:** Connect to `https://your-typo3-site.com/mcp` — requires OAuth 2.1 with PKCE. Server metadata is available at `/.well-known/oauth-authorization-server/mcp` (RFC 8414 path-insert) for auto-discovery.

## Authentication

HTTP transport uses **OAuth 2.1 with PKCE** (S256). Each token is linked to a backend user — all operations respect that user's TYPO3 permissions.

The stdio transport does not require OAuth — the user is specified via the `--user` flag.

### OAuth Features

- **Authorization Code flow with PKCE** — standard OAuth 2.1 for MCP clients
- **Authentication via the real TYPO3 backend login** — the `/mcp/oauth/authorize` endpoint redirects unauthenticated users to `/typo3/login` and only renders a single-click consent screen once `BE_USER` is established; MFA, `starttime`/`endtime`, per-user lockout, and `sys_log` failed-login entries come from the standard backend pipeline
- **Dynamic Client Registration** ([RFC 7591](https://datatracker.ietf.org/doc/html/rfc7591)) — clients can self-register
- **Token Revocation** ([RFC 7009](https://datatracker.ietf.org/doc/html/rfc7009)) — revoke access and refresh tokens
- **Protected Resource Metadata** ([RFC 9728](https://datatracker.ietf.org/doc/html/rfc9728)) — auto-discovery of auth requirements

### OAuth Endpoints

| Endpoint | Description |
|----------|-------------|
| `/.well-known/oauth-authorization-server/mcp` | Authorization server metadata (RFC 8414) |
| `/.well-known/oauth-protected-resource/mcp` | Protected resource metadata (RFC 9728) |
| `/mcp/oauth/authorize` | Authorization endpoint |
| `/mcp/oauth/token` | Token endpoint |
| `/mcp/oauth/revoke` | Token revocation endpoint |
| `/mcp/oauth/register` | Dynamic client registration |

### Base Path

The MCP endpoint and all OAuth sub-paths share a single configurable prefix. Override it via **Settings > Extension Configuration > ms_mcp_server**:

| Setting | Default | Description |
|---------|---------|-------------|
| `mcpBasePath` | `/mcp` | Base URL path for the MCP endpoint. Must start with `/`. May contain directories (e.g. `/typo3-mcp`, `/api/mcp`). |

Per [RFC 8414 §3.1](https://datatracker.ietf.org/doc/html/rfc8414#section-3.1) and [RFC 9728 §3](https://datatracker.ietf.org/doc/html/rfc9728#section-3), the `.well-known/*` document for an issuer/resource `https://host/<path>` lives at `https://host/.well-known/<doc>/<path>` — the well-known string is *inserted* between the host and the issuer's path component, not appended. Spec-compliant MCP clients (e.g. Claude Code 2.1+) use that form.

Example with `mcpBasePath = /typo3-mcp`:

| Endpoint | Path |
|----------|------|
| MCP server | `/typo3-mcp` |
| Authorization | `/typo3-mcp/oauth/authorize` |
| Token | `/typo3-mcp/oauth/token` |
| Registration | `/typo3-mcp/oauth/register` |
| Revocation | `/typo3-mcp/oauth/revoke` |
| Authorization server metadata | `/.well-known/oauth-authorization-server/typo3-mcp` |
| Protected resource metadata | `/.well-known/oauth-protected-resource/typo3-mcp` |

And with `mcpBasePath = /api/mcp`:

| Endpoint | Path |
|----------|------|
| MCP server | `/api/mcp` |
| Authorization server metadata | `/.well-known/oauth-authorization-server/api/mcp` |
| Protected resource metadata | `/.well-known/oauth-protected-resource/api/mcp` |

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
| `rateLimitAuthorize` | `5` / 300s | Authorize POST (consent submission — auth code minting) |
| `rateLimitAuthorizeGet` | `20` / 300s | Authorize GET (consent form display) |
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
| `pages_move` | Move a page in the tree. Subpages move with the page. |
| `pages_tree` | Get the page tree hierarchy with configurable depth (1-10, default 3). |
| `pages_search` | Search pages by title (plain text) or advanced conditions (JSON). Supports sorting. |

**Target positioning** (for `pages_copy`, `pages_move`): Provide exactly one of `targetPid` (place as first child of that parent page) or `afterUid` (place as a sibling after that page, under the same parent).

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

### Backend Users & Groups

Restricted to admin backend users only. Sensitive `be_users` columns (`password`, `mfa`) are never returned; soft-deleted records are always excluded.

| Tool | Description |
|------|-------------|
| `backend_user_list` | List `be_users`. Optional `search` (LIKE on username), `activeOnly`, `adminOnly` filters; paginated. |
| `backend_user_get` | Get a single backend user by uid with extended fields (groups, mounts, language, TSconfig, etc.). |
| `backend_group_list` | List `be_groups` with optional title search; paginated. |
| `backend_group_get` | Get a single backend group by uid with permission and mount details. |

### Permissions

Tools for inspecting the authenticated backend user's effective permissions.

| Tool | Description |
|------|-------------|
| `permission_check_table` | Check whether the current user can read (select) and/or write (modify) a specific table. |
| `permission_check_page` | Check what the current user can do on a page: show, edit, delete, create subpages, edit content. |
| `permission_check_summary` | Summary of the current user's permissions: admin status, allowed tables for read/write, languages, file permissions, web/file mounts. |

### Redirects

Registered only when `typo3/cms-redirects` is installed. Operates on the `sys_redirect` table through DataHandler.

| Tool | Description |
|------|-------------|
| `redirect_list` | List redirects with pagination; filter by `sourceHost`, `sourcePath`, `target` (LIKE) and `disabled`. |
| `redirect_get` | Get a single redirect record by uid. |
| `redirect_create` | Create a redirect. Required: `sourceHost`, `sourcePath`, `target`; optional `targetStatuscode` and extra `fields` JSON. |
| `redirect_update` | Update an existing redirect. Pass fields as a JSON object. |
| `redirect_delete` | Delete a redirect by uid. |

### Scheduler

Registered only when `typo3/cms-scheduler` is installed. Operates on the `tx_scheduler_task` table.

| Tool | Description |
|------|-------------|
| `scheduler_list` | List scheduler tasks with pagination; filter by `tasktype` (LIKE), `taskGroup`, `disable`. |
| `scheduler_get` | Get a single scheduler task by uid. |
| `scheduler_update` | Update a task. Writable fields: `disable`, `description`, `task_group`. |
| `scheduler_delete` | Delete a scheduler task by uid. |

### Workspaces

Registered only when `typo3/cms-workspaces` is installed. Direct (live-mode) operation is the primary use case for this extension — these tools enable a secondary draft/publish workflow when review is required. After `workspace_switch`, all subsequent reads and writes (`pages_*`, `content_*`, etc.) operate on the chosen workspace.

| Tool | Description |
|------|-------------|
| `workspace_list` | List workspaces accessible to the current backend user (including the implicit live workspace, uid 0). |
| `workspace_get` | Get workspace metadata by uid: title, custom stages flag, current user access level. |
| `workspace_switch` | Switch the active workspace. Persists to `be_users.workspace_id`. Use uid `0` to return to live. |
| `workspace_changes_list` | List records modified in the current workspace, grouped by table, with `t3ver_state` and stage. |
| `workspace_publish` | Publish a workspace version to live (swap). |
| `workspace_discard` | Discard a workspace version, dropping unpublished changes. |
| `workspace_stage_set` | Move a workspace version to a different stage (`-10` ready to publish, `-20` ready to review, `0` editing, or a custom stage uid). |

### Dynamic Extension Tools

Additional CRUD tools are registered automatically for tables configured via `EXTCONF` or enabled through the **Extension Tables** backend module (auto-discovery). News is pre-configured and generates 9 tools:

| Tool | Description |
|------|-------------|
| `news_list` | List news records by page ID |
| `news_get` | Get a single news record |
| `news_create` | Create a new news record |
| `news_update` | Update news record fields |
| `news_delete` | Delete a news record |
| `news_move` | Move a news record |
| `news_delete_batch` | Delete multiple news records by comma-separated UIDs |
| `news_update_batch` | Update the same fields on multiple news records |
| `news_move_batch` | Move multiple news records to a target position |

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

This automatically creates 9 tools (`blog_post_list`, `blog_post_get`, `blog_post_create`, `blog_post_update`, `blog_post_delete`, `blog_post_move`, plus the batch variants `blog_post_delete_batch`, `blog_post_update_batch`, `blog_post_move_batch`) with fields resolved from TCA.

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

The `mcp:cleanup` command purges expired OAuth authorization codes / access tokens / refresh tokens, idle MCP sessions past `sessionLifetime`, and stale rate-limit window rows:

```bash
vendor/bin/typo3 mcp:cleanup
```

Run it daily. Two ways:

### Via TYPO3 Scheduler (recommended)

1. **System > Scheduler > Scheduled tasks > Add task**.
2. **Class:** *Execute console commands* (`TYPO3\CMS\Scheduler\Task\ExecuteSchedulableCommandTask`).
3. **Frequency:** pick an interval (`86400` seconds for daily) or a cron expression (`0 3 * * *` for 03:00 every day).
4. **Schedulable Command:** select `mcp:cleanup` from the dropdown.
5. Save. Use *Run task* once to verify it works.

The command is auto-discovered via the `console.command` tag — no extra registration needed.

### Via cron

```cron
0 3 * * * cd /path/to/typo3-project && vendor/bin/typo3 mcp:cleanup >/dev/null 2>&1
```

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

# Tests
vendor/bin/phpunit
```

## License

GPL-2.0-or-later
