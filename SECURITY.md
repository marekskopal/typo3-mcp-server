# Security Policy

## Supported Versions

| Version | Supported |
|---------|-----------|
| 1.x     | ✅        |
| < 1.0   | ❌ — please upgrade |

## Reporting a Vulnerability

Please **do not** open a public GitHub issue for security vulnerabilities.

Instead, report them privately via [GitHub Security Advisories](https://github.com/marekskopal/typo3-mcp-server/security/advisories/new)
or by email to **skopal.marek@gmail.com** with a description of the issue, steps to
reproduce, and the affected version.

You can expect an initial response within a few days. Once a fix is available it is
released as a patch version, and the advisory is published after users have had a
reasonable window to update.

## Scope Notes

- This extension exposes an OAuth 2.1 authorization server and an MCP endpoint tied to
  TYPO3 backend users. Anything that lets an MCP client read or modify data beyond the
  linked backend user's TYPO3 permissions is in scope.
- Token/secret handling: codes and tokens are 256-bit random values stored only as
  SHA-256 hashes; refresh tokens rotate with family-wide theft revocation. Weaknesses in
  these mechanisms are in scope.
- Vulnerabilities in TYPO3 core or the MCP SDK should be reported to those projects
  ([TYPO3 Security Team](https://typo3.org/community/teams/security), [MCP PHP SDK](https://github.com/modelcontextprotocol/php-sdk)).
