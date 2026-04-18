# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/), and this project adheres to [Semantic Versioning](https://semver.org/).

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
