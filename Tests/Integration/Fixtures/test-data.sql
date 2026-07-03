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

-- ============================================================================
-- Non-admin (editor) scenario fixtures
-- ============================================================================

-- Editor group: read access to pages/tt_content/sys_redirect, webmount on the root page (uid 1).
INSERT INTO be_groups (uid, pid, title, tables_select, tables_modify, db_mountpoints, deleted, hidden, crdate, tstamp)
VALUES (10, 0, 'Integration Editors', 'pages,tt_content,sys_redirect', 'pages,tt_content', '1', 0, 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE
    title = 'Integration Editors',
    tables_select = 'pages,tt_content,sys_redirect',
    tables_modify = 'pages,tt_content',
    db_mountpoints = '1',
    deleted = 0, hidden = 0;

-- Editor user. The password is never used: mcp:server bootstraps the backend user by username.
INSERT INTO be_users (uid, pid, username, password, admin, usergroup, deleted, disable, crdate, tstamp)
VALUES (10, 0, 'editor', 'locked-no-password-login', 0, '10', 0, 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE usergroup = '10', admin = 0, deleted = 0, disable = 0, workspace_id = 0;

-- Give the editor group SHOW rights on the mounted tree (root + fixture page below it).
UPDATE pages SET perms_userid = 1, perms_groupid = 10, perms_group = 31 WHERE uid = 1;

INSERT INTO pages (uid, pid, title, doktype, slug, hidden, deleted, perms_userid, perms_groupid, perms_group, perms_everybody, crdate, tstamp)
VALUES (80, 1, 'Editor Visible Page', 1, '/editor-visible', 0, 0, 1, 10, 31, 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE title = 'Editor Visible Page', pid = 1, hidden = 0, deleted = 0, perms_groupid = 10, perms_group = 31, perms_everybody = 0;

-- A page tree OUTSIDE the editor's webmount whose ACL nevertheless grants SHOW to everybody.
-- The MCP read paths must still refuse it: ACL alone is not enough without webmount containment.
INSERT INTO pages (uid, pid, title, doktype, slug, hidden, deleted, perms_userid, perms_groupid, perms_group, perms_everybody, crdate, tstamp)
VALUES
    (90, 0, 'Outside Mount Root', 1, '/outside-mount', 0, 0, 1, 0, 0, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
    (91, 90, 'Outside Mount Page', 1, '/outside-mount/page', 0, 0, 1, 0, 0, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE hidden = 0, deleted = 0, perms_everybody = 1;

-- Root-level redirect (sys_redirect is a rootLevel table — every row lives at pid 0).
-- The editor must be able to list it through the pid-0 allowance of the page read constraint.
INSERT IGNORE INTO sys_redirect (uid, pid, source_host, source_path, target, deleted, disabled, createdon, updatedon)
VALUES (900, 0, '*', '/integration-fixture-redirect', 'https://example.com/', 0, 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP());
