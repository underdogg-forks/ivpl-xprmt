"use strict";

/*
 * node tests/js/support/parity-cli.js            print the parity summary
 * node tests/js/support/parity-cli.js --write    rewrite the manifest backlog (first run seeds it, later runs only drop covered methods)
 */

const fs = require("node:fs");
const { analyse, readManifest, MANIFEST } = require("./parity.js");

const analysis = analyse();
const manifest = fs.existsSync(MANIFEST) ? readManifest() : { notARoute: {}, needsServerState: {}, backlog: {} };
const exempt = { ...manifest.notARoute, ...manifest.needsServerState };
const exemptMethods = manifest.exemptMethods ?? {};
const seeding = Object.keys(manifest.backlog ?? {}).length === 0;

const backlog = {};
let total = 0;
let covered = 0;
let exemptCount = 0;
for (const [file, info] of Object.entries(analysis)) {
    total += info.total;
    if (exempt[file]) { exemptCount += info.total; continue; }
    covered += info.total - info.uncovered.length;
    const keep = (seeding ? info.uncovered : info.uncovered.filter((method) => (manifest.backlog[file] ?? []).includes(method))).filter((method) => !exemptMethods[`${file}::${method}`]);
    if (keep.length > 0) backlog[file] = keep;
}
const open = Object.values(backlog).reduce((sum, methods) => sum + methods.length, 0);

console.log(`PHPUnit Feature methods ${total}: mirrored ${covered}, exempt ${exemptCount}, backlog ${open}`);
if (process.argv.includes("--write")) {
    fs.writeFileSync(MANIFEST, `${JSON.stringify({ notARoute: manifest.notARoute, needsServerState: manifest.needsServerState, exemptMethods, backlog }, null, 2)}\n`);
    console.log(`wrote ${MANIFEST}`);
}
