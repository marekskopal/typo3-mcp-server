# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/), and this project adheres to [Semantic Versioning](https://semver.org/).

## [1.0.0-beta.1] - 2026-04-18

### Added
- MCP server endpoint at `/mcp` with StreamableHTTP transport
- OAuth 2.1 authentication with PKCE (S256) for backend users
- Dynamic client registration (RFC 7591)
- Protected resource metadata (RFC 9728)
- Authorization server metadata (`.well-known/oauth-authorization-server`)
- CORS headers for cross-origin MCP clients
- Backend module for OAuth client management (System > MCP Server)
- **Page tools:** `pages_list`, `pages_get`, `pages_create`, `pages_update`, `pages_delete`
- **Content tools:** `content_list`, `content_get`, `content_create`, `content_update`, `content_delete`
- **File tools:** `file_list`, `file_get_info`, `file_upload`, `file_upload_from_url`, `file_delete`, `directory_create`, `directory_delete`, `file_reference_add`
- **Schema tool:** `table_schema` for TCA field introspection
- Dynamic CRUD tool registration for custom tables via `EXTCONF`
- News table pre-configured as dynamic tools (`news_list`, `news_get`, `news_create`, `news_update`, `news_delete`)
- `mcp:cleanup` CLI command for expired token and session garbage collection
- 74+ unit tests with PHPStan max-level static analysis
