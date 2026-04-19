# TYPO3 MCP Server

> **Beta** — This extension is under active development. APIs and behavior may change between releases.

TYPO3 CMS extension that implements an [MCP (Model Context Protocol)](https://modelcontextprotocol.io/) server for TYPO3 administration. It exposes tools for managing pages, content elements, files, and custom extension records via the MCP protocol, allowing AI assistants to interact with your TYPO3 instance.

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
- [mcp/sdk](https://github.com/php-mcp/sdk) (installed via Composer)

## Installation

```bash
composer require marekskopal/typo3-mcp-server
```

## Setup

1. Install and activate the extension
2. Run database migrations to create the required tables
3. Go to **System > MCP Server** in the TYPO3 backend
4. Register an OAuth client for your MCP client application
5. Optionally configure token lifetimes in **Settings > Extension Configuration > ms_mcp_server**

## Authentication

The extension uses **OAuth 2.1 with PKCE** (S256) for authentication. It supports:

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

Each OAuth token is linked to a specific backend user. The MCP server acts as that user — all operations respect the user's TYPO3 permissions (page access, table access, field access).

### Token Lifetimes

Configurable via extension settings (defaults):

| Token | Lifetime |
|-------|----------|
| Access token | 1 hour |
| Refresh token | 30 days |
| Authorization code | 60 seconds |

## Usage

### HTTP Transport

The MCP server is available at `/mcp` on your TYPO3 instance. Authenticate with a Bearer token obtained via the OAuth flow:

```
Authorization: Bearer <access-token>
```

The server uses the [Streamable HTTP transport](https://modelcontextprotocol.io/specification/2025-03-26/basic/transports#streamable-http) (MCP protocol version 2025-03-26).

### stdio Transport

For local AI tools (Claude Desktop, Cursor, etc.), the server can run directly via stdin/stdout — no HTTP server or OAuth setup required:

```bash
vendor/bin/typo3 mcp:server
```

Use the `--user` option to specify which backend user to run as (defaults to `admin`):

```bash
vendor/bin/typo3 mcp:server --user editor
```

#### Claude Desktop Configuration

Add to your Claude Desktop config (`claude_desktop_config.json`):

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

### Available Tools

#### Pages
| Tool | Description |
|------|-------------|
| `pages_list` | List pages by parent page ID with pagination |
| `pages_get` | Get a single page by uid |
| `pages_create` | Create a new page |
| `pages_update` | Update page fields |
| `pages_delete` | Delete a page |
| `pages_tree` | Get page tree hierarchy with configurable depth |

#### Content Elements (tt_content)
| Tool | Description |
|------|-------------|
| `content_list` | List content elements by page ID |
| `content_get` | Get a single content element by uid |
| `content_create` | Create a new content element |
| `content_update` | Update content element fields |
| `content_delete` | Delete a content element |

#### File Management (fileadmin)
| Tool | Description |
|------|-------------|
| `file_list` | List files and directories with pagination |
| `file_get_info` | Get file metadata (size, MIME type, public URL) |
| `file_upload` | Upload a file from base64-encoded content |
| `file_upload_from_url` | Download and upload a file from a URL |
| `file_delete` | Delete a file |
| `directory_create` | Create a directory |
| `directory_delete` | Delete a directory |
| `file_reference_add` | Attach a file to a record's image/media field |

#### Schema Introspection
| Tool | Description |
|------|-------------|
| `table_schema` | Get TCA field definitions for any table |

#### Dynamic Extension Tools

Additional CRUD tools are registered automatically for tables configured via `EXTCONF`. News is pre-configured:

| Tool | Description |
|------|-------------|
| `news_list` | List news records by page ID |
| `news_get` | Get a single news record by uid |
| `news_create` | Create a new news record |
| `news_update` | Update news record fields |
| `news_delete` | Delete a news record |

### Backend Module

The **System > MCP Server** backend module provides:

- Register and manage OAuth clients
- Edit client settings (name, redirect URIs, backend user)
- View active tokens per client with status (active/refreshable/expired)
- Revoke individual tokens

## Adding Support for Other Extensions

Register custom tables in your extension's `ext_localconf.php`:

```php
$GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ms_mcp_server']['tables']['tx_blog_domain_model_post'] = [
    'label' => 'Blog Post',
    'prefix' => 'blog_post',
];
```

This automatically creates 5 CRUD tools (`blog_post_list`, `blog_post_get`, `blog_post_create`, `blog_post_update`, `blog_post_delete`) with fields resolved from TCA. You can optionally specify `listFields`, `readFields`, and `writableFields` arrays to override the defaults.

## Maintenance

Clean up expired tokens and stale session files:

```bash
vendor/bin/typo3 mcp:cleanup
```

This can be run as a TYPO3 Scheduler task for automated cleanup.

## Development

```bash
composer install

# Static analysis
vendor/bin/phpstan analyse

# Code style
vendor/bin/phpcs
vendor/bin/phpcbf

# Tests (185 tests, 643 assertions)
vendor/bin/phpunit
```

## License

GPL-2.0-or-later
