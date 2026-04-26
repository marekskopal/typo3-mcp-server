# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/), and this project adheres to [Semantic Versioning](https://semver.org/).

## [0.7.0] - 2026-04-26

### Added
- **Extension auto-discovery:** Backend module UI to discover installed TYPO3 extensions with TCA tables, enable/disable them for MCP tool generation, and customize label/prefix — no code changes required. EXTCONF-configured tables remain always-on.
- **`record_count` tool:** Count records in any table without fetching them, with optional pid and search condition filtering
- **`file_search` tool:** Search files by name pattern and/or extension across storage via `sys_file` table
- **Rate limiting:** IP-based rate limiting on all OAuth endpoints with configurable per-endpoint limits and fixed-window counters. Returns 429 Too Many Requests with Retry-After header. Expired entries cleaned up via `mcp:cleanup`.
- Backend module fully translated (English, German, Czech) using `f:translate` ViewHelper
- Extension table management UI: discover, enable/disable, edit label/prefix
- `RecordService::count()` method for lightweight record counting

### Changed
- **Upgraded MCP SDK to v0.5.0** — removed `InitializedSession` and `InitializedSessionFactory` workarounds (SDK's `Session::readData()` bug is fixed upstream). `SessionFactoryInterface` replaced by `SessionManagerInterface`.

### Fixed
- **SSRF prevention:** `file_upload_from_url` now rejects URLs resolving to private/reserved IP ranges
- **DoS prevention:** File downloads now stream with size check instead of loading entirely into memory
- **PKCE hardening:** Code verifier validated for length (43-128 chars) and format per RFC 7636
- **Open redirect prevention:** Redirect URI re-validated in authorize POST against registered client URIs
- **Auth bypass prevention:** Soft-deleted backend users now rejected during token validation
- **Info disclosure prevention:** Error messages no longer leak backend user UIDs; generic "Authentication failed" returned
- **Security headers:** Added `Cache-Control: no-store` and `X-Content-Type-Options: nosniff` to OAuth and MCP responses

## [0.6.0] - 2026-04-25

### Added
- **Audit logging:** All tool and resource invocations are logged to TYPO3's `sys_log` table with backend user ID, tool name, execution time, and success/failure status. Visible in TYPO3 backend log module.
- **Batch operations:** `record_delete_batch`, `record_update_batch`, `record_move_batch` — process multiple records atomically in a single DataHandler cycle
- **New tools:** `file_copy`, `pages_search`, `content_search`
- **New prompts:**
  - `check_translation_status` — scan page subtree, report missing translations per language
  - `audit_content_structure` — find content in non-existent backend layout columns
  - `migrate_content` — move all content between pages with layout compatibility check
- `pages_search` and `content_search` accept plain text for LIKE matching or JSON for advanced conditions
- Shared `SearchConditionParser` extracted from `RecordSearchTool` for reuse

### Fixed
- Tool auto-discovery: SDK's `ArrayLoader` was falling back to method name `execute` instead of reading `#[McpTool]` attribute names. All tools now pass names explicitly.

## [0.5.0] - 2026-04-24

### Added
- **New tools:** `file_move`, `file_rename`, `directory_move`, `directory_rename` for file/directory management
- **Search sorting:** `record_search` now supports `orderBy` and `orderDirection` parameters
- **Operator support:** `record_search` supports `eq`, `neq`, `like`, `gt`, `gte`, `lt`, `lte`, `in`, `null`, `notNull` operators
- **OAuthClientController tests** — 14 unit tests for the backend module controller
- `pages_copy` now supports `includeSubpages` parameter for recursive page tree copy
- `content_copy` tool for duplicating content elements
- `file_reference_list` and `file_reference_remove` tools for managing file references
- Added `dg/bypass-finals` dev dependency for mocking TYPO3 final classes in tests

### Changed
- **Auto-discovery:** Tools, resources, and prompts are now auto-discovered via DI tags (`!tagged_iterator`) instead of hardcoded arrays in `McpServerFactory`. Adding a new tool no longer requires modifying the factory.
- **Centralized error handling:** Removed try/catch boilerplate from all 33 tool classes and 6 resource classes. Error handling is now centralized in `ErrorHandlingProxy`, which wraps instances at the container level and converts exceptions to `ToolCallException`/`ResourceReadException`.
- `LoggerInterface` removed from tool and resource constructors — logging is handled by the proxy
- `McpServerFactory` reduced from 147 lines to ~100 lines
- Net reduction of ~350 lines of identical error handling code

## [0.4.0] - 2026-04-23

### Added
- **MCP Resources** for read-only TYPO3 instance discovery:
  - `typo3://system/info` — TYPO3 version, PHP version, and environment context
  - `typo3://sites` — All configured sites with root pages, base URLs, and languages
  - `typo3://schema/tables` — List of all available TCA database tables
  - `typo3://user/me` — Current authenticated backend user information
  - `typo3://schema/tables/{tableName}` — Full TCA field schema for a specific table
  - `typo3://pages/{pageId}/backend-layout` — Backend layout for a page including available column positions (colPos) and grid structure
- **MCP Prompts** for guided multi-step workflows:
  - `translate_page_content` — Translate a page and all its content elements to a target language
  - `audit_page_seo` — Audit SEO metadata for a page and suggest fixes
  - `summarize_page` — Generate a content inventory and summary of a page
- **Cache tool:** `cache_clear` for flushing TYPO3 caches (all, pages, or specific groups)
- `file_upload` now supports plain text content in addition to base64-encoded content

### Changed
- Tool return values refactored from arrays to typed Result DTOs
- Result DTOs excluded from DI container registration

### Fixed
- Fixed link in readme

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
