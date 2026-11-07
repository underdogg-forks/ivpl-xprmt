"use strict";

/*
 * Guards the shape of the Playwright suite so a spec can never again sit where it is silently not run
 * (tests/e2e/*.spec.ts was outside testDir and testMatch), or opt out of the harness (per-test database
 * reset, browser/PHP error capture) or skip itself away.
 */

const test = require("node:test");
const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");

const ROOT = path.join(__dirname, "../..");
const TESTS = path.join(ROOT, "tests");
const E2E = path.join(TESTS, "E2E");

function walk(dir) {
    return fs.readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
        const full = path.join(dir, entry.name);

        return entry.isDirectory() ? (entry.name === "node_modules" || entry.name === ".auth" ? [] : walk(full)) : [full];
    });
}

const rel = (file) => path.relative(ROOT, file);

// support/auth.js drives a bare Playwright context (the wrapper depends on it), so it cannot go through the wrapper.
const MAY_IMPORT_PLAYWRIGHT = [path.join(E2E, "support", "auth.js")];

// Spec files that exist but declare no tests yet. This list may only shrink: the test below fails when a
// listed file gains tests (remove it from here) and when an unlisted file has none.
const PLACEHOLDER_SPECS = [];

const declaresTests = (source) => /^\s*test\(/m.test(source) || /\beinvoiceFlowSuite\(/.test(source);
const allFiles = walk(TESTS);
const specs = allFiles.filter((file) => /\.(spec|test)\.[cm]?[jt]sx?$/.test(file) && file.startsWith(E2E + path.sep));
const e2eSpecs = specs.filter((file) => file.endsWith(".spec.js"));

test("the Playwright config runs exactly the tests/E2E directory with .spec.js files", () => {
    const config = fs.readFileSync(path.join(ROOT, "playwright.config.js"), "utf8");

    assert.match(config, /testDir:\s*'\.\/tests\/E2E'/);
    assert.match(config, /testMatch:\s*'\*\*\/\*\.spec\.js'/);
});

test("there is a case-insensitive single e2e directory", () => {
    const siblings = fs.readdirSync(TESTS).filter((name) => name.toLowerCase() === "e2e");

    assert.deepEqual(siblings, ["E2E"]);
});

test("no spec file lives anywhere except tests/E2E", () => {
    const stray = allFiles.filter((file) => /\.spec\.[cm]?[jt]sx?$/.test(file) && !file.startsWith(E2E + path.sep));

    assert.deepEqual(stray.map(rel), []);
});

test("every file under tests/E2E named like a spec is a .spec.js file Playwright will match", () => {
    const unmatched = specs.filter((file) => !file.endsWith(".spec.js"));

    assert.deepEqual(unmatched.map(rel), []);
    assert.ok(e2eSpecs.length > 0, "the e2e suite must not be empty");
});

test("no spec or support module imports from @playwright/test, which would bypass the per-test reset and error capture", () => {
    const supportFiles = walk(path.join(E2E, "support")).filter((file) => file.endsWith(".js"));
    const direct = [...e2eSpecs, ...supportFiles].filter((file) => !MAY_IMPORT_PLAYWRIGHT.includes(file)).filter((file) => /from\s+['"]@playwright\/test['"]/.test(fs.readFileSync(file, "utf8")));

    assert.deepEqual(direct.map(rel), []);
});

test("every spec gets its test object from the project wrapper or a support helper built on it", () => {
    const outside = e2eSpecs.filter((file) => !PLACEHOLDER_SPECS.includes(rel(file))).filter((file) => !/from\s+['"](\.\.?\/)+(test\.js|support\/[\w-]+\.js)['"]/.test(fs.readFileSync(file, "utf8")));

    assert.deepEqual(outside.map(rel), []);
});

test("every spec declares tests; the placeholder list only shrinks", () => {
    const empty = e2eSpecs.filter((file) => !declaresTests(fs.readFileSync(file, "utf8"))).map(rel);

    assert.deepEqual(empty.sort(), [...PLACEHOLDER_SPECS].sort());
});

test("no spec skips itself, except an obsolete test declared with its reason in the title", () => {
    // test.skip("title (obsolete: why)", ...) is a visible, reported skip. A runtime test.skip() inside a body (what the
    // old specs used to dodge a missing element), test.fixme and test.fail are not allowed.
    const skipping = e2eSpecs.filter((file) => /\btest\.(skip|fixme|fail)\((?!\s*['"`][^'"`]*\(obsolete: [^)]{10,}\)['"`])/.test(fs.readFileSync(file, "utf8")));

    assert.deepEqual(skipping.map(rel), []);
});

test("no spec sleeps for a fixed time", () => {
    const sleeping = e2eSpecs.filter((file) => /\bwaitForTimeout\(/.test(fs.readFileSync(file, "utf8")));

    assert.deepEqual(sleeping.map(rel), []);
});

test("no spec guards an assertion behind a visibility check, which turns a missing element into a pass", () => {
    const conditional = e2eSpecs.filter((file) => /\bif\s*\(\s*(await\s+)?[^)]*\.(isVisible|count)\(\)/.test(fs.readFileSync(file, "utf8")));

    assert.deepEqual(conditional.map(rel), []);
});
