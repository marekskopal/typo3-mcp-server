# TYPO3 MCP Server

> **Experimental** — This extension is under active development and not yet ready for production use. APIs and behavior may change without notice.

TYPO3 CMS extension that implements an [MCP (Model Context Protocol)](https://modelcontextprotocol.io/) server for TYPO3 administration. It exposes tools for managing pages, content elements, and news records via the MCP protocol, allowing AI assistants to interact with your TYPO3 instance.

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

## Authentication

The extension uses **OAuth 2.1 with PKCE** (S256) for authentication. It supports:

- **Authorization Code flow with PKCE** — standard OAuth 2.1 for MCP clients
- **Dynamic Client Registration** ([RFC 7591](https://datatracker.ietf.org/doc/html/rfc7591)) — clients can self-register
- **Protected Resource Metadata** ([RFC 9728](https://datatracker.ietf.org/doc/html/rfc9728)) — auto-discovery of auth requirements

### OAuth Endpoints

| Endpoint | Description |
|----------|-------------|
| `/.well-known/oauth-authorization-server` | Authorization server metadata |
| `/.well-known/oauth-protected-resource` | Protected resource metadata |
| `/mcp/oauth/authorize` | Authorization endpoint |
| `/mcp/oauth/token` | Token endpoint |
| `/mcp/oauth/register` | Dynamic client registration |

Each OAuth token is linked to a specific backend user. The MCP server acts as that user — all operations respect the user's TYPO3 permissions (page access, table access, field access).

## Usage

The MCP server is available at `/mcp` on your TYPO3 instance. Authenticate with a Bearer token obtained via the OAuth flow:

```
Authorization: Bearer <access-token>
```

The server uses the [Streamable HTTP transport](https://modelcontextprotocol.io/specification/2025-03-26/basic/transports#streamable-http) (MCP protocol version 2025-03-26).

### Available Tools

#### Pages
| Tool | Description |
|------|-------------|
| `pages_list` | List pages by parent page ID with pagination |
| `pages_get` | Get a single page by uid |
| `pages_create` | Create a new page |
| `pages_update` | Update page fields |
| `pages_delete` | Delete a page |

#### Content Elements (tt_content)
| Tool | Description |
|------|-------------|
| `content_list` | List content elements by page ID |
| `content_get` | Get a single content element by uid |
| `content_create` | Create a new content element |
| `content_update` | Update content element fields |
| `content_delete` | Delete a content element |

#### News (tx_news)
| Tool | Description |
|------|-------------|
| `news_list` | List news records by page ID |
| `news_get` | Get a single news record by uid |
| `news_create` | Create a new news record |
| `news_update` | Update news record fields |
| `news_delete` | Delete a news record |

## Adding Support for Other Extensions

The news tools follow the same pattern as pages and content. To add CRUD tools for another extension's records (e.g. `tx_blog_domain_model_post`):

1. Create 5 tool classes in `Classes/Tool/Blog/` following the same structure as the News tools
2. Define the table name, field lists, and use `#[McpTool]` attributes for name and description
3. Register them in `McpServerFactory`
4. Add a public DI entry in `Configuration/Services.yaml` for the new tool namespace

## Development

```bash
composer install

# Static analysis
vendor/bin/phpstan analyse

# Code style
vendor/bin/phpcs
vendor/bin/phpcbf

# Tests (74 tests, 270 assertions)
vendor/bin/phpunit
```

## License

GPL-2.0-or-later
