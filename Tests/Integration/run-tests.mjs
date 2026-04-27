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
        await this.testTool('directory_create', { path: '/mcp-integration-test' });

        // Upload
        const base64 = Buffer.from('Integration test file content').toString('base64');
        await this.testTool('file_upload', {
            data: base64,
            path: '/mcp-integration-test/test-file.txt',
        });

        // Upload from URL
        await this.testTool('file_upload_from_url', {
            url: 'https://example.com/',
            path: '/mcp-integration-test/from-url.html',
        });
        await this.callToolSafe('file_delete', { path: '/mcp-integration-test/from-url.html' });

        // File info & search
        await this.testTool('file_get_info', { path: '/mcp-integration-test/test-file.txt' });
        await this.testTool('file_search', { path: '/', pattern: 'test-file' });

        // Copy
        await this.testTool('file_copy', {
            sourcePath: '/mcp-integration-test/test-file.txt',
            targetPath: '/mcp-integration-test/copy.txt',
        });

        // Rename
        await this.testTool('file_rename', {
            path: '/mcp-integration-test/copy.txt',
            newName: 'renamed.txt',
        });

        // Move
        await this.testTool('file_move', {
            sourcePath: '/mcp-integration-test/renamed.txt',
            targetPath: '/mcp-integration-test/moved.txt',
        });

        // Delete files
        await this.testTool('file_delete', { path: '/mcp-integration-test/moved.txt' });
        await this.testTool('file_delete', { path: '/mcp-integration-test/test-file.txt' });

        // Directory rename
        await this.testTool('directory_create', { path: '/mcp-int-rename-test' });
        await this.testTool('directory_rename', { path: '/mcp-int-rename-test', newName: 'mcp-int-renamed' });
        await this.testTool('directory_delete', { path: '/mcp-int-renamed' });

        // Directory move
        await this.testTool('directory_create', { path: '/mcp-int-move-src' });
        await this.testTool('directory_move', {
            sourcePath: '/mcp-int-move-src',
            targetPath: '/mcp-integration-test',
        });

        // Clean up remaining directory
        await this.testTool('directory_delete', { path: '/mcp-integration-test' });
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
        await this.callToolSafe('directory_create', { path: '/mcp-ref-test' });
        await this.callToolSafe('file_upload', {
            data: base64,
            path: '/mcp-ref-test/ref-file.txt',
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
                tableName: 'tt_content',
                uid: contentUid,
                fileUid,
                fieldName: 'image',
            });

            await this.testTool('file_reference_list', {
                tableName: 'tt_content',
                uid: contentUid,
                fieldName: 'image',
            });

            const refUid = added?.referenceUids?.[0];
            if (refUid) {
                await this.testTool('file_reference_remove', { referenceUid: refUid });
            } else {
                skip('file_reference_remove', 'no reference UID returned');
                this.skipped.push({ tool: 'file_reference_remove', reason: 'no ref UID' });
            }
        }

        // Clean up
        await this.callToolSafe('file_delete', { path: '/mcp-ref-test/ref-file.txt' });
        await this.callToolSafe('directory_delete', { path: '/mcp-ref-test' });
    }

    async testTranslation(pageUid) {
        section('Translation');
        await this.testTool('site_languages', { pageId: pageUid ?? 1 });

        if (pageUid) {
            await this.testTool('record_translate', {
                tableName: 'pages',
                uid: pageUid,
                targetLanguageUid: 1,
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
            await this.testTool('scheduler_list', {});
        } else {
            for (const t of ['scheduler_list', 'scheduler_get', 'scheduler_update', 'scheduler_delete']) {
                skip(t, 'typo3/cms-scheduler not installed');
                this.skipped.push({ tool: t, reason: 'extension not installed' });
            }
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
