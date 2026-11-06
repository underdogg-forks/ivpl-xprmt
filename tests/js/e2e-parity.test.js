"use strict";

/*
 * The rule: every PHPUnit Feature test that serves an HTTP route has its equivalent in the Playwright suite, with the
 * same name. This fails when a method has no e2e test of that name and is not recorded in
 * tests/E2E/parity-manifest.json, and when a manifest entry has gone stale. The "backlog" can only shrink:
 *   node tests/js/support/parity-cli.js --write   regenerates it (drops what is now covered, never adds)
 */

const test = require("node:test");
const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const { analyse, readManifest, FEATURE } = require("./support/parity.js");

const analysis = analyse();
const manifest = readManifest();
const exempt = { ...manifest.notARoute, ...manifest.needsServerState };
const exemptMethods = manifest.exemptMethods ?? {};

test("every exempt file exists and says why", () => {
    const problems = Object.entries(exempt).filter(([file, reason]) => !fs.existsSync(path.join(FEATURE, file)) || String(reason).trim().length < 15);

    assert.deepEqual(problems.map(([file]) => file), []);
});

test("every PHPUnit Feature method has an e2e test of the same name, or is in the manifest", () => {
    const unaccounted = [];
    for (const [file, info] of Object.entries(analysis)) {
        if (exempt[file]) continue;
        for (const method of info.uncovered) {
            if (!(manifest.backlog[file] ?? []).includes(method) && !exemptMethods[`${file}::${method}`]) unaccounted.push(`${file}::${method}`);
        }
    }

    assert.deepEqual(unaccounted, [], "mirror these in tests/E2E (same title), or record why in tests/E2E/parity-manifest.json");
});

test("the backlog holds no method that is gone or is now covered", () => {
    const stale = [];
    for (const [file, methods] of Object.entries(manifest.backlog)) {
        const info = analysis[file];
        for (const method of methods) {
            if (!info || !info.methods.includes(method) || !info.uncovered.includes(method)) stale.push(`${file}::${method}`);
        }
    }

    assert.deepEqual(stale, [], "remove these from the backlog in tests/E2E/parity-manifest.json");
});

test("every exempt method exists, is not mirrored, and says why", () => {
    const problems = Object.entries(exemptMethods).filter(([key, reason]) => {
        const [file, method] = key.split("::");
        const info = analysis[file];

        return !info || !info.uncovered.includes(method) || String(reason).trim().length < 15;
    });

    assert.deepEqual(problems.map(([key]) => key), []);
});

test("no file is both exempt and in the backlog", () => {
    assert.deepEqual(Object.keys(manifest.backlog).filter((file) => exempt[file]), []);
});
