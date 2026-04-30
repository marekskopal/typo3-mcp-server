#!/usr/bin/env node

/**
 * TYPO3 MCP Server Integration Tests
 *
 * Connects to the TYPO3 MCP server via stdio transport and tests every registered tool.
 * Test data is created and cleaned up dynamically through MCP tool calls.
 *
 * Usage:
 *   TYPO3_PATH=/tmp/typo3-project node run-tests.mjs
 */

import { Client } from '@modelcontextprotocol/sdk/client/index.js';
import { StdioClientTransport } from '@modelcontextprotocol/sdk/client/stdio.js';

const TYPO3_PATH = process.env.TYPO3_PATH || '/tmp/typo3-project';
const TOOL_TIMEOUT_MS = 30_000;

// --- Terminal output helpers ---

const isCI = !!process.env.CI;
const c = {
    green: '\x1b[32m',
    red: '\x1b[31m',
    yellow: '\x1b[33m',
    cyan: '\x1b[36m',
    bold: '\x1b[1m',
    reset: '\x1b[0m',
};

function log(msg) { console.log(msg); }
function pass(tool) { log(`  ${c.green}\u2713${c.reset} ${tool}`); }
function fail(tool, err) { log(`  ${c.red}\u2717${c.reset} ${tool}: ${err}`); }
function skip(tool, reason) { log(`  ${c.yellow}\u25CB${c.reset} ${tool}: ${reason}`); }
function section(title) { log(`\n${c.bold}--- ${title} ---${c.reset}`); }

// --- Test runner ---

class IntegrationTestRunner {
    constructor() {
        this.client = null;
        this.transport = null;
        this.passed = [];
        this.failed = [];
        this.skipped = [];
        this.availableTools = new Set();
    }

    // ---- MCP connection ----

    async connect() {
        log('Connecting to MCP server via stdio...');
        log(`  command: php vendor/bin/typo3 mcp:server --user admin`);
        log(`  cwd:     ${TYPO3_PATH}`);

        this.transport = new StdioClientTransport({
            command: 'php',
            args: [
                '-d', 'display_errors=stderr',
                '-d', 'log_errors=1',
                '-d', 'memory_limit=512M',
                'vendor/bin/typo3', 'mcp:server', '--user', 'admin',
            ],
            cwd: TYPO3_PATH,
        });

        this.client = new Client(
            { name: 'typo3-mcp-integration-test', version: '1.0.0' },
            { capabilities: {} },
        );

        await this.client.connect(this.transport);
        log(`${c.green}Connected.${c.reset}`);
    }

    async disconnect() {
        try {
            await this.client?.close();
        } catch { /* ignore close errors */ }
    }

    // ---- Tool helpers ----

    /** Call a tool and return the parsed JSON result. Throws on error. */
    async callTool(name, args = {}) {
        const result = await Promise.race([
            this.client.callTool({ name, arguments: args }),
            new Promise((_, reject) =>
                setTimeout(() => reject(new Error(`Timeout after ${TOOL_TIMEOUT_MS / 1000}s`)), TOOL_TIMEOUT_MS),
            ),
        ]);

        if (result.isError) {
            const text = result.content?.[0]?.text || 'Unknown error';
            throw new Error(text);
        }

        const text = result.content?.[0]?.text ?? '';
        try {
            return JSON.parse(text);
        } catch {
            return { _raw: text };
        }
    }

    /** Call a tool, record pass/fail, return result or null. */
    async testTool(name, args = {}) {
        if (!this.availableTools.has(name)) {
            this.skipped.push({ tool: name, reason: 'not registered' });
            skip(name, 'not registered');
            return null;
        }

        try {
            const result = await this.callTool(name, args);
            this.passed.push({ tool: name });
            pass(name);
            return result;
        } catch (e) {
            this.failed.push({ tool: name, error: e.message });
            fail(name, e.message);
            return null;
        }
    }

    /** Call a tool silently (for setup/teardown). Returns result or null. */
    async callToolSafe(name, args = {}) {
        try {
            return await this.callTool(name, args);
        } catch {
            return null;
        }
    }

    // ---- Test phases ----

    async discoverCapabilities() {
        section('Discovery');

        const { tools } = await this.client.listTools();
        for (const t of tools) this.availableTools.add(t.name);
        log(`  Tools: ${tools.length}`);

        try {
            const r = await this.client.listResources();
            log(`  Resources: ${r.resources?.length ?? 0}`);
        } catch { /* optional */ }

        try {
            const rt = await this.client.listResourceTemplates();
            log(`  Resource templates: ${rt.resourceTemplates?.length ?? 0}`);
        } catch { /* optional */ }

        try {
            const p = await this.client.listPrompts();
            log(`  Prompts: ${p.prompts?.length ?? 0}`);
        } catch { /* optional */ }

        return tools;
    }

    async testSchemaAndPermissions() {
        section('Schema & Permissions');
        await this.testTool('table_schema', { tableName: 'pages' });
        await this.testTool('table_schema', { tableName: 'tt_content' });
        await this.testTool('permission_check_table', { tableName: 'pages' });
        await this.testTool('permission_check_page', { pageId: 1 });
        await this.testTool('permission_check_summary', {});
    }

    async testPageOperations() {
        section('Page Operations');

        // Create
        const page = await this.testTool('pages_create', {
            pid: 1,
            fields: JSON.stringify({ title: 'Integration Test Page', doktype: 1 }),
        });
        const pageUid = page?.uid;

        const child = await this.testTool('pages_create', {
            pid: pageUid ?? 1,
            fields: JSON.stringify({ title: 'Child Test Page', doktype: 1 }),
        });
        const childUid = child?.uid;

        // Read
        await this.testTool('pages_get', { uid: pageUid ?? 1 });
        await this.testTool('pages_list', { pid: 1 });
        await this.testTool('pages_search', { search: 'Integration Test' });
        await this.testTool('pages_tree', { pid: 0, depth: 2 });

        // Update
        if (pageUid) {
            await this.testTool('pages_update', {
                uid: pageUid,
                fields: JSON.stringify({ title: 'Updated Integration Test Page' }),
            });
        }

        // Copy
        if (childUid && pageUid) {
            await this.testTool('pages_copy', { uid: childUid, target: pageUid });
        }

        return { pageUid, childUid };
    }

    async testContentOperations(pageUid) {
        section('Content Operations');
        const pid = pageUid ?? 1;

        // Create
        const content = await this.testTool('content_create', {
            pid,
            fields: JSON.stringify({ CType: 'text', header: 'Integration Test Content', bodytext: 'Hello from integration tests.' }),
        });
        const contentUid = content?.uid;

        // Read
        await this.testTool('content_get', { uid: contentUid ?? 1 });
        await this.testTool('content_list', { pid });
        await this.testTool('content_search', { pageId: pid, search: 'Integration' });

        // Update
        if (contentUid) {
            await this.testTool('content_update', {
                uid: contentUid,
                fields: JSON.stringify({ header: 'Updated Integration Content' }),
            });
        }

        // Copy & Move
        if (contentUid) {
            const copied = await this.testTool('content_copy', { uid: contentUid, target: pid });
            const copiedUid = copied?.newUid ?? copied?.uid;
            if (copiedUid) {
                await this.testTool('content_move', { uid: copiedUid, target: pid });
                // Clean up copy
                await this.callToolSafe('content_delete', { uid: copiedUid });
            }
        }

        return { contentUid };
    }

    async testSearchOperations(pageUid) {
        section('Search Operations');
        await this.testTool('record_search', {
            tableName: 'pages',
            search: JSON.stringify({ title: { like: 'Integration' } }),
        });
        await this.testTool('record_count', { tableName: 'pages' });
        await this.testTool('pages_search', { search: 'Root' });
        await this.testTool('content_search', { pageId: pageUid ?? 1, search: 'Integration' });
    }

    async testFileOperations() {
        section('File Operations');

        // List root
        await this.testTool('file_list', { directoryPath: '/' });

        // Directory create
        await this.testTool('directory_create', { directoryName: 'mcp-int-test' });

        // Upload
        const base64 = Buffer.from('Integration test file content').toString('base64');
        await this.testTool('file_upload', {
            fileName: 'test-file.txt',
            base64Content: base64,
            directoryPath: '/mcp-int-test',
        });

        // Upload from URL
        await this.testTool('file_upload_from_url', {
            url: 'https://raw.githubusercontent.com/typo3/typo3/main/LICENSE.txt',
            directoryPath: '/mcp-int-test',
            fileName: 'from-url.txt',
        });
        await this.callToolSafe('file_delete', { fileIdentifier: '/mcp-int-test/from-url.txt' });

        // File info & search
        await this.testTool('file_get_info', { fileIdentifier: '/mcp-int-test/test-file.txt' });
        await this.testTool('file_search', { namePattern: 'test-file' });

        // Copy (to a subdirectory so name doesn't conflict)
        await this.testTool('directory_create', { directoryName: 'sub', parentPath: '/mcp-int-test' });
        await this.testTool('file_copy', {
            fileIdentifier: '/mcp-int-test/test-file.txt',
            targetDirectory: '/mcp-int-test/sub',
        });

        // Rename the copy
        await this.testTool('file_rename', {
            fileIdentifier: '/mcp-int-test/sub/test-file.txt',
            newName: 'renamed.txt',
        });

        // Move the renamed file back
        await this.testTool('file_move', {
            fileIdentifier: '/mcp-int-test/sub/renamed.txt',
            targetDirectory: '/mcp-int-test',
        });

        // Delete files
        await this.testTool('file_delete', { fileIdentifier: '/mcp-int-test/renamed.txt' });
        await this.testTool('file_delete', { fileIdentifier: '/mcp-int-test/test-file.txt' });

        // Directory rename
        await this.testTool('directory_rename', { directoryIdentifier: '/mcp-int-test/sub', newName: 'renamed-sub' });
        await this.testTool('directory_delete', { directoryIdentifier: '/mcp-int-test/renamed-sub' });

        // Directory move
        await this.testTool('directory_create', { directoryName: 'move-src' });
        await this.testTool('directory_move', {
            directoryIdentifier: '/move-src',
            targetDirectory: '/mcp-int-test',
        });

        // Clean up
        await this.testTool('directory_delete', { directoryIdentifier: '/mcp-int-test', recursive: true });
    }

    async testFileReferences(contentUid) {
        section('File Reference Operations');

        if (!contentUid) {
            for (const t of ['file_reference_add', 'file_reference_list', 'file_reference_remove']) {
                skip(t, 'no content record available');
                this.skipped.push({ tool: t, reason: 'no content record' });
            }
            return;
        }

        // Upload a file for referencing
        const base64 = Buffer.from('File for reference test').toString('base64');
        await this.callToolSafe('directory_create', { directoryName: 'mcp-ref-test' });
        await this.callToolSafe('file_upload', {
            fileName: 'ref-file.txt',
            base64Content: base64,
            directoryPath: '/mcp-ref-test',
        });

        // Find the sys_file UID
        let fileUid = null;
        try {
            const search = await this.callTool('record_search', {
                tableName: 'sys_file',
                search: JSON.stringify({ name: { eq: 'ref-file.txt' } }),
                limit: 1,
            });
            fileUid = search?.records?.[0]?.uid;
        } catch { /* sys_file might not be searchable */ }

        if (!fileUid) {
            for (const t of ['file_reference_add', 'file_reference_list', 'file_reference_remove']) {
                skip(t, 'could not resolve sys_file UID');
                this.skipped.push({ tool: t, reason: 'sys_file UID not found' });
            }
        } else {
            const added = await this.testTool('file_reference_add', {
                table: 'tt_content',
                uid: contentUid,
                fileUids: String(fileUid),
                fieldName: 'image',
            });

            await this.testTool('file_reference_list', {
                table: 'tt_content',
                uid: contentUid,
                fieldName: 'image',
            });

            const refUid = added?.referenceUids?.[0];
            if (refUid) {
                await this.testTool('file_reference_remove', { referenceUids: String(refUid) });
            } else {
                skip('file_reference_remove', 'no reference UID returned');
                this.skipped.push({ tool: 'file_reference_remove', reason: 'no ref UID' });
            }
        }

        // Clean up
        await this.callToolSafe('file_delete', { fileIdentifier: '/mcp-ref-test/ref-file.txt' });
        await this.callToolSafe('directory_delete', { directoryIdentifier: '/mcp-ref-test' });
    }

    async testTranslation(pageUid) {
        section('Translation');
        await this.testTool('site_languages', { pageId: pageUid ?? 1 });

        if (pageUid) {
            await this.testTool('record_translate', {
                table: 'pages',
                uid: pageUid,
                targetLanguageId: 1,
            });
        }
    }

    async testBatchOperations(pageUid) {
        section('Batch Operations');
        const pid = pageUid ?? 1;

        // Create records for batch testing
        const b1 = await this.callToolSafe('content_create', {
            pid,
            fields: JSON.stringify({ CType: 'text', header: 'Batch Test 1' }),
        });
        const b2 = await this.callToolSafe('content_create', {
            pid,
            fields: JSON.stringify({ CType: 'text', header: 'Batch Test 2' }),
        });

        const uid1 = b1?.uid;
        const uid2 = b2?.uid;

        if (uid1 && uid2) {
            const uids = `${uid1},${uid2}`;

            await this.testTool('record_update_batch', {
                tableName: 'tt_content',
                uids,
                fields: JSON.stringify({ header: 'Batch Updated' }),
            });

            await this.testTool('record_move_batch', {
                tableName: 'tt_content',
                uids,
                target: pid,
            });

            await this.testTool('record_delete_batch', {
                tableName: 'tt_content',
                uids,
            });
        } else {
            for (const t of ['record_update_batch', 'record_move_batch', 'record_delete_batch']) {
                skip(t, 'could not create batch test records');
                this.skipped.push({ tool: t, reason: 'setup failed' });
            }
        }
    }

    async testCache() {
        section('Cache');
        await this.testTool('cache_clear', { cacheGroup: 'all' });
    }

    async testConditionalTools() {
        section('Conditional Tools');

        // --- Redirects (require typo3/cms-redirects) ---
        if (this.availableTools.has('redirect_list')) {
            await this.testTool('redirect_list', {});

            const redirect = await this.testTool('redirect_create', {
                sourceHost: '*',
                sourcePath: '/mcp-test-redirect',
                target: 'https://example.com',
            });
            const rUid = redirect?.uid;

            if (rUid) {
                await this.testTool('redirect_get', { uid: rUid });
                await this.testTool('redirect_update', {
                    uid: rUid,
                    fields: JSON.stringify({ target: 'https://example.org' }),
                });
                await this.testTool('redirect_delete', { uid: rUid });
            }
        } else {
            const tools = ['redirect_list', 'redirect_get', 'redirect_create', 'redirect_update', 'redirect_delete'];
            for (const t of tools) {
                skip(t, 'typo3/cms-redirects not installed');
                this.skipped.push({ tool: t, reason: 'extension not installed' });
            }
        }

        // --- Scheduler (require typo3/cms-scheduler) ---
        if (this.availableTools.has('scheduler_list')) {
            const tasks = await this.testTool('scheduler_list', {});

            // scheduler_get/update/delete require an existing task — skip if none
            const taskUid = tasks?.records?.[0]?.uid;
            if (taskUid) {
                await this.testTool('scheduler_get', { uid: taskUid });
                await this.testTool('scheduler_update', {
                    uid: taskUid,
                    fields: JSON.stringify({ description: 'Updated by integration test' }),
                });
                // Don't delete the only task — just verify the tool is callable
            } else {
                for (const t of ['scheduler_get', 'scheduler_update', 'scheduler_delete']) {
                    skip(t, 'no scheduler tasks exist');
                    this.skipped.push({ tool: t, reason: 'no tasks' });
                }
            }
        } else {
            for (const t of ['scheduler_list', 'scheduler_get', 'scheduler_update', 'scheduler_delete']) {
                skip(t, 'typo3/cms-scheduler not installed');
                this.skipped.push({ tool: t, reason: 'extension not installed' });
            }
        }
    }

    async testWorkspaceOperations(pageUid) {
        section('Workspace Operations');

        const workspaceTools = [
            'workspace_list',
            'workspace_get',
            'workspace_switch',
            'workspace_changes_list',
            'workspace_publish',
            'workspace_discard',
            'workspace_stage_set',
        ];

        if (!this.availableTools.has('workspace_list')) {
            for (const t of workspaceTools) {
                skip(t, 'typo3/cms-workspaces not installed');
                this.skipped.push({ tool: t, reason: 'extension not installed' });
            }
            return;
        }

        // ---- workspace_list: should include live (uid 0) and the seeded test workspace (uid 1) ----
        const list = await this.testTool('workspace_list', {});
        const hasLive = Array.isArray(list) && list.some(w => w.uid === 0);
        const testWorkspace = Array.isArray(list) ? list.find(w => w.uid !== 0) : null;
        if (!hasLive) {
            this.failed.push({ tool: 'workspace_list', error: 'live workspace not in list' });
            fail('workspace_list (live missing)', 'live workspace (uid 0) not returned');
        }
        if (!testWorkspace) {
            // No custom workspace seeded — skip the rest of the lifecycle but the tool itself worked.
            for (const t of ['workspace_switch', 'workspace_changes_list', 'workspace_publish', 'workspace_discard', 'workspace_stage_set']) {
                skip(t, 'no custom sys_workspace record (run database:updateschema?)');
                this.skipped.push({ tool: t, reason: 'no test workspace' });
            }
            // Still smoke-test workspace_get on live
            await this.testTool('workspace_get', { workspaceId: 0 });
            return;
        }

        const wsId = testWorkspace.uid;

        // ---- workspace_get: live + custom ----
        await this.testTool('workspace_get', { workspaceId: 0 });
        await this.testTool('workspace_get', { workspaceId: wsId });

        // ---- Create a dedicated test page in live so we can compare live vs. workspace overlay ----
        const liveTitle = 'Workspace Live Title';
        const created = await this.callToolSafe('pages_create', {
            pid: pageUid ?? 1,
            fields: JSON.stringify({ title: liveTitle, doktype: 1 }),
        });
        const wsPageUid = created?.uid;
        if (!wsPageUid) {
            for (const t of ['workspace_switch', 'workspace_changes_list', 'workspace_publish', 'workspace_discard', 'workspace_stage_set']) {
                skip(t, 'could not create test page in live');
                this.skipped.push({ tool: t, reason: 'setup failed' });
            }
            return;
        }

        try {
            // ---- workspace_switch into the test workspace ----
            await this.testTool('workspace_switch', { workspaceId: wsId });

            // ---- pages_update under workspace context — should produce a workspace draft, not modify live ----
            const draftTitle = 'Workspace Draft Title';
            await this.callToolSafe('pages_update', {
                uid: wsPageUid,
                fields: JSON.stringify({ title: draftTitle }),
            });

            // ---- pages_get with workspace overlay — should reflect the draft ----
            const overlaid = await this.callToolSafe('pages_get', { uid: wsPageUid });
            if (overlaid?.title === draftTitle) {
                this.passed.push({ tool: 'workspace overlay (pages_get)' });
                pass('workspace overlay (pages_get returned draft title)');
            } else {
                this.failed.push({ tool: 'workspace overlay (pages_get)', error: `expected '${draftTitle}', got '${overlaid?.title}'` });
                fail('workspace overlay (pages_get)', `expected '${draftTitle}', got '${overlaid?.title}'`);
            }

            // ---- workspace_changes_list — should show the modified page ----
            const changes1 = await this.testTool('workspace_changes_list', {});
            const pagesChanges = changes1?.tables?.pages ?? [];
            const wsVersion = pagesChanges.find(c => c.liveUid === wsPageUid);
            if (!wsVersion) {
                this.failed.push({ tool: 'workspace_changes_list', error: 'modified page not in changes list' });
                fail('workspace_changes_list (verify content)', 'modified page not in changes list');
            }

            // ---- workspace_discard the change, verify live data is restored on re-read ----
            if (wsVersion) {
                await this.testTool('workspace_discard', { table: 'pages', workspaceVersionUid: wsVersion.uid });
                const afterDiscard = await this.callToolSafe('pages_get', { uid: wsPageUid });
                if (afterDiscard?.title === liveTitle) {
                    this.passed.push({ tool: 'workspace_discard (verify)' });
                    pass('workspace_discard (live title restored)');
                } else {
                    this.failed.push({ tool: 'workspace_discard (verify)', error: `expected live title after discard, got '${afterDiscard?.title}'` });
                    fail('workspace_discard (verify)', `expected '${liveTitle}', got '${afterDiscard?.title}'`);
                }
            }

            // ---- Make a fresh change, exercise stage_set, then publish ----
            const publishedTitle = 'Workspace Published Title';
            await this.callToolSafe('pages_update', {
                uid: wsPageUid,
                fields: JSON.stringify({ title: publishedTitle }),
            });
            const changes2 = await this.callToolSafe('workspace_changes_list', {});
            const newVersion = changes2?.tables?.pages?.find(c => c.liveUid === wsPageUid);

            if (newVersion) {
                // workspace_stage_set: editing -> ready to publish (-10)
                await this.testTool('workspace_stage_set', {
                    table: 'pages',
                    workspaceVersionUid: newVersion.uid,
                    stage: -10,
                });

                // workspace_publish: swap with live
                await this.testTool('workspace_publish', { table: 'pages', workspaceVersionUid: newVersion.uid });
            } else {
                for (const t of ['workspace_stage_set', 'workspace_publish']) {
                    skip(t, 'no workspace version to act on after second update');
                    this.skipped.push({ tool: t, reason: 'no version' });
                }
            }

            // ---- Switch back to live to verify the publish landed ----
            await this.callToolSafe('workspace_switch', { workspaceId: 0 });
            const liveAfter = await this.callToolSafe('pages_get', { uid: wsPageUid });
            if (liveAfter?.title === publishedTitle) {
                this.passed.push({ tool: 'workspace_publish (verify)' });
                pass('workspace_publish (live updated after switch back)');
            } else {
                this.failed.push({ tool: 'workspace_publish (verify)', error: `expected live to reflect '${publishedTitle}', got '${liveAfter?.title}'` });
                fail('workspace_publish (verify)', `expected '${publishedTitle}', got '${liveAfter?.title}'`);
            }
        } finally {
            // Always switch back to live and remove the test page, regardless of test outcome
            await this.callToolSafe('workspace_switch', { workspaceId: 0 });
            if (wsPageUid) {
                await this.callToolSafe('pages_delete', { uid: wsPageUid });
            }
        }
    }

    async testDynamicTools(pageUid) {
        section('Dynamic Tools (news)');

        if (!this.availableTools.has('news_list')) {
            const tools = ['news_list', 'news_get', 'news_create', 'news_update', 'news_delete', 'news_move'];
            for (const t of tools) {
                skip(t, 'news extension not installed or tools not registered');
                this.skipped.push({ tool: t, reason: 'not available' });
            }
            return;
        }

        const pid = pageUid ?? 1;

        // Create
        const news = await this.testTool('news_create', {
            pid,
            fields: JSON.stringify({ title: 'Integration Test News' }),
        });
        const newsUid = news?.uid;

        // List
        await this.testTool('news_list', { pid });

        // Get
        if (newsUid) {
            await this.testTool('news_get', { uid: newsUid });

            // Update
            await this.testTool('news_update', {
                uid: newsUid,
                fields: JSON.stringify({ title: 'Updated Integration Test News' }),
            });

            // Move
            await this.testTool('news_move', { uid: newsUid, target: pid });

            // Delete
            await this.testTool('news_delete', { uid: newsUid });
        }
    }

    async cleanupRecords(pageUid, childUid, contentUid) {
        section('Cleanup');
        if (contentUid) await this.testTool('content_delete', { uid: contentUid });
        if (childUid) await this.testTool('pages_delete', { uid: childUid });
        if (pageUid) await this.testTool('pages_delete', { uid: pageUid });
    }

    // ---- Main execution ----

    async run() {
        const tools = await this.discoverCapabilities();

        if (tools.length === 0) {
            log(`\n${c.red}ERROR: No tools discovered. Is the extension installed?${c.reset}`);
            process.exit(2);
        }

        await this.testSchemaAndPermissions();

        const { pageUid, childUid } = await this.testPageOperations();
        const { contentUid } = await this.testContentOperations(pageUid);

        await this.testSearchOperations(pageUid);
        await this.testFileOperations();
        await this.testFileReferences(contentUid);
        await this.testTranslation(pageUid);
        await this.testBatchOperations(pageUid);
        await this.testCache();
        await this.testConditionalTools();
        await this.testDynamicTools(pageUid);
        await this.testWorkspaceOperations(pageUid);
        await this.cleanupRecords(pageUid, childUid, contentUid);

        // Check for any tools that were discovered but not tested
        section('Coverage');
        const tested = new Set([
            ...this.passed.map(r => r.tool),
            ...this.failed.map(r => r.tool),
            ...this.skipped.map(r => r.tool),
        ]);
        const untested = [...this.availableTools].filter(t => !tested.has(t));
        if (untested.length > 0) {
            log(`  Untested tools (${untested.length}): ${untested.join(', ')}`);
        } else {
            log(`  All ${this.availableTools.size} discovered tools covered.`);
        }
    }

    printReport() {
        const total = this.passed.length + this.failed.length + this.skipped.length;

        log(`\n${c.bold}=== Results ===${c.reset}`);
        log(`  Total:   ${total}`);
        log(`  ${c.green}Passed:  ${this.passed.length}${c.reset}`);
        log(`  ${c.red}Failed:  ${this.failed.length}${c.reset}`);
        log(`  ${c.yellow}Skipped: ${this.skipped.length}${c.reset}`);

        if (this.failed.length > 0) {
            log(`\n${c.bold}${c.red}Failed tools:${c.reset}`);
            for (const f of this.failed) {
                log(`  ${c.red}\u2717${c.reset} ${f.tool}: ${f.error}`);
            }
        }

        // GitHub Actions annotation format
        if (isCI && this.failed.length > 0) {
            for (const f of this.failed) {
                console.log(`::error title=MCP Tool Failed: ${f.tool}::${f.error}`);
            }
        }

        return this.failed.length === 0;
    }
}

// --- Entry point ---

async function main() {
    log(`${c.bold}=== TYPO3 MCP Server Integration Tests ===${c.reset}`);
    log(`TYPO3 path: ${TYPO3_PATH}`);

    const runner = new IntegrationTestRunner();

    try {
        await runner.connect();
        await runner.run();
    } catch (e) {
        log(`\n${c.red}Fatal error: ${e.message}${c.reset}`);
        if (e.stack) log(e.stack);
        await runner.disconnect();
        process.exit(2);
    }

    await runner.disconnect();
    const success = runner.printReport();
    process.exit(success ? 0 : 1);
}

main();
