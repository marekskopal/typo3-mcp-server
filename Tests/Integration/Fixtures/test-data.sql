-- TYPO3 MCP Server Integration Test Fixtures
-- Creates minimal test data needed for integration tests.
-- Most test data is created dynamically by the test runner via MCP tools.

-- Ensure root page exists and is marked as site root
INSERT INTO pages (uid, pid, title, doktype, slug, is_siteroot, hidden, deleted, crdate, tstamp)
VALUES (1, 0, 'Root Page', 1, '/', 1, 0, 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE title = 'Root Page', is_siteroot = 1, hidden = 0, deleted = 0;

-- Ensure default file storage exists (fileadmin/)
INSERT INTO sys_file_storage (uid, pid, name, driver, configuration, is_online, is_default, is_browsable, is_public, is_writable, crdate, tstamp)
VALUES (
    1, 0, 'fileadmin/ (auto-created)', 'Local',
    '<?xml version="1.0" encoding="utf-8" standalone="yes" ?>\n<T3FlexForms>\n<data>\n<sheet index="sDEF">\n<language index="lDEF">\n<field index="basePath"><value index="vDEF">fileadmin/</value></field>\n<field index="pathType"><value index="vDEF">relative</value></field>\n</language>\n</sheet>\n</data>\n</T3FlexForms>',
    1, 1, 1, 1, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()
)
ON DUPLICATE KEY UPDATE is_online = 1, is_writable = 1;
