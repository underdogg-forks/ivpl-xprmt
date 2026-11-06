"use strict";

/*
 * Compares every #[Test] method in tests/Feature with the titles of the Playwright suite. A PHPUnit method
 * it_does_something_useful is mirrored by an e2e test titled "it does something useful" (a trailing "(label)" and
 * apostrophes are ignored, and a title built with ${...} matches any value there, as in support/einvoice-flow.js).
 */

const fs = require("node:fs");
const path = require("node:path");

const ROOT = path.join(__dirname, "../../..");
const FEATURE = path.join(ROOT, "tests/Feature");
const E2E = path.join(ROOT, "tests/E2E");
const MANIFEST = path.join(E2E, "parity-manifest.json");

const walk = (dir) => fs.readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
    const full = path.join(dir, entry.name);

    return entry.isDirectory() ? (entry.name === ".auth" ? [] : walk(full)) : [full];
});

const words = (title) => title.toLowerCase().replace(/['’]/g, "").replace(/\([^)]*\)/g, "").replace(/[^a-z0-9]+/g, " ").trim();
const escapeRegex = (text) => text.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");

function e2eTitles() {
    const exact = new Set();
    const patterns = [];
    const sources = walk(E2E).filter((file) => file.endsWith(".spec.js") || (file.includes(`${path.sep}support${path.sep}`) && file.endsWith(".js")));

    for (const file of sources) {
        const source = fs.readFileSync(file, "utf8");
        for (const match of source.matchAll(/^\s*(?:test|it)\(\s*(['"`])(.+?)\1\s*,/gm)) {
            const raw = match[2];
            if (raw.includes("${")) {
                const pieces = raw.split(/\$\{[^}]*\}/).map((piece) => escapeRegex(words(piece)));
                patterns.push(new RegExp(`^ *${pieces.join("[a-z0-9 ]*?").trim()} *$`));
            } else {
                exact.add(words(raw));
            }
        }
    }

    return { exact, patterns };
}

function phpunitMethods() {
    const files = {};
    for (const file of walk(FEATURE).filter((f) => f.endsWith(".php")).sort()) {
        const source = fs.readFileSync(file, "utf8");
        const methods = [...source.matchAll(/#\[(?:\\PHPUnit\\Framework\\Attributes\\)?Test\]\s*(?:#\[[^\]]*\]\s*)*public function (\w+)/g)].map((m) => m[1]);
        if (methods.length > 0) {
            files[path.relative(FEATURE, file).split(path.sep).join("/")] = methods;
        }
    }

    return files;
}

function analyse() {
    const { exact, patterns } = e2eTitles();
    const covered = (method) => {
        const title = words(method.replace(/_/g, " "));

        return exact.has(title) || patterns.some((pattern) => pattern.test(title));
    };
    const result = {};
    for (const [file, methods] of Object.entries(phpunitMethods())) {
        result[file] = { total: methods.length, methods, uncovered: methods.filter((method) => !covered(method)), covered };
    }

    return result;
}

const readManifest = () => JSON.parse(fs.readFileSync(MANIFEST, "utf8"));

module.exports = { analyse, readManifest, MANIFEST, ROOT, FEATURE };
