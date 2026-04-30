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

-- Ensure a test workspace exists (only takes effect when typo3/cms-workspaces is installed
-- and the sys_workspace table has been created by extension setup).
-- The test runner skips workspace tests if this insert is a no-op.
INSERT IGNORE INTO sys_workspace (uid, pid, title, deleted, tstamp)
VALUES (1, 0, 'Integration Test', 0, UNIX_TIMESTAMP());

-- Reset the admin's workspace to live so each test run starts in a known state
-- (workspace_switch persists to be_users.workspace_id).
UPDATE be_users SET workspace_id = 0 WHERE username = 'admin';
