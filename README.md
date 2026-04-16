# TYPO3 MCP Server

> **Experimental** — This extension is under active development and not yet ready for production use. APIs and behavior may change without notice.

TYPO3 CMS extension that implements an [MCP (Model Context Protocol)](https://modelcontextprotocol.io/) server for TYPO3 administration. It exposes tools for managing pages, content elements, and news records via the MCP protocol, allowing AI assistants to interact with your TYPO3 instance.

## Requirements

- PHP 8.3+
- TYPO3 v13.4 or v14.x
- [mcp/sdk](https://github.com/modelcontextprotocol/php-sdk) (installed via Composer)

## Installation

```bash
composer require marekskopal/typo3-mcp-server
```

## Setup

1. Install and activate the extension
2. Run database migrations to create the `tx_msmcpserver_token` table
3. Go to **System > MCP Server Tokens** in the TYPO3 backend
4. Create a new token and select the backend user it should act as
5. Copy the generated token — it is shown only once

## Usage

The MCP server is available at `/mcp` on your TYPO3 instance. Authenticate with a Bearer token:

```
Authorization: Bearer <your-token>
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

## Authentication & Permissions

Each token is linked to a specific backend user. The MCP server acts as that user — all operations respect the user's TYPO3 permissions (page access, table access, field access). Tokens are stored as SHA-256 hashes and can be disabled or set to expire.

## Adding Support for Other Extensions

The news tools follow the same pattern as pages and content. To add CRUD tools for another extension's records (e.g. `tx_blog_domain_model_post`), create 5 tool classes in `Classes/Tool/Blog/` following the same structure as the News tools — define the table name, field lists, and register them in `McpServerFactory`.

## Development

```bash
composer install

# Static analysis
vendor/bin/phpstan analyse

# Code style
vendor/bin/phpcs
vendor/bin/phpcbf

# Tests
vendor/bin/phpunit
```

## License

GPL-2.0-or-later
