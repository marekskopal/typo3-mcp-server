# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/), and this project adheres to [Semantic Versioning](https://semver.org/).

## [0.3.0] - 2026-04-20

### Added
- **Search tool:** `record_search` for searching records in any table by field values (LIKE match) with optional pid filtering
- `RecordService::search()` method for flexible record queries
- Unit tests for `InitializedSession`, `InitializedSessionFactory`, and `OAuthMiddleware` (26 new tests)
- `McpServerFactory::VERSION` constant as single source of truth for version string

### Fixed
- CORS headers missing on authenticated MCP responses — browser-based MCP clients now work correctly
- OAuth client `crdate` always showing 1970-01-01 — both `ClientRepository` and `OAuthClientController` now set `crdate`/`tstamp` on insert
- PHPUnit notices caused by using `createMock()` instead of `createStub()` where no expectations were configured
- `pages_update` ignoring `hidden`, `starttime`, `endtime`, and `fe_group` fields — enablecolumns are no longer treated as system fields
- Translated pages invisible to `pages_get`, `pages_list`, and other read operations — query restrictions now removed for backend-context queries
- `sys_language_uid` treated as system field — update tools can now modify the language field
- `record_translate` creating broken translations from records with `sys_language_uid = -1` — now validates that the source record is in the default language before localizing


## [0.2.0] - 2026-04-19

### Changed
- Page and content tools now load fields dynamically from TCA instead of static field lists, matching the dynamic tool pattern
- Create tools (`pages_create`, `content_create`) now accept a JSON `fields` parameter instead of explicit typed parameters, consistent with dynamic tools and update tools
- Translation field names (`sys_language_uid`, `l10n_parent`, `l18n_parent`) are resolved dynamically from TCA ctrl configuration
- Removed `final` modifier from all classes — this is a library meant to be extended by other TYPO3 extensions

### Fixed
- Fixed locallang translation files
- Fixed extension icon

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
