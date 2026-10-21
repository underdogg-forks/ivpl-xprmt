"use strict";

// The Gruntfile is the contract between the asset sources (resources/assets) and the web root
// (public/assets). This loads it with the real grunt and checks the generated mapping, without
// running a build.

const test = require("node:test");
const assert = require("node:assert/strict");
const path = require("node:path");

const root = path.resolve(__dirname, "../..");

function loadConfig() {
  const grunt = require(path.join(root, "node_modules/grunt"));
  process.chdir(root);
  require(path.join(root, "Gruntfile.js"))(grunt);
  return grunt.config;
}

test("every theme stylesheet is built from resources/assets into public/assets", () => {
  const files = loadConfig().get("sass.build.files");

  const entries = Object.entries(files);
  assert.ok(entries.length >= 8, "both themes and the welcome/report/template sheets are mapped");
  for (const [dest, src] of entries) {
    assert.match(src, /^resources\/assets\/[a-z_]+\/sass\/[a-z_-]+\.scss$/);
    assert.match(dest, /^public\/assets\/[a-z_]+\/css\/[a-z_-]+\.css$/);
    assert.equal(
      dest.replace(/^public\/assets\//, "").replace("/css/", "/sass/").replace(/\.css$/, ""),
      src.replace(/^resources\/assets\//, "").replace(/\.scss$/, "")
    );
  }
  assert.ok(files["public/assets/invoiceplane/css/style.css"]);
});

test("javascript bundles are written to public/assets and never back into the sources", () => {
  const config = loadConfig();

  const uglify = config.get("uglify.build.files");
  for (const dest of Object.keys(uglify)) assert.match(dest, /^public\/assets\/core\/js\/[a-z]+\.min\.js$/);
  assert.equal(uglify["public/assets/core/js/scripts.min.js"][0], "resources/assets/core/js/scripts.js");
  assert.equal(config.get("concat.dependencies.dest"), "public/assets/core/js/dependencies.js");
  assert.ok(config.get("concat.dependencies.src").includes("resources/assets/core/js/jquery-ui.js"));
});

test("clean only ever touches public/assets and keeps its .gitignore", () => {
  const patterns = loadConfig().get("clean.basic");

  assert.ok(patterns.includes("public/assets/**"));
  assert.ok(patterns.includes("!public/assets/.gitignore"));
  for (const p of patterns) assert.ok(p.replace(/^!/, "").startsWith("public/assets"), `unexpected clean target ${p}`);
});

test("static files are copied from the sources next to the build output", () => {
  const [entry] = loadConfig().get("copy.static.files");

  assert.equal(entry.cwd, "resources/assets");
  assert.equal(entry.dest, "public/assets/");
  assert.ok(entry.src.includes("*/*.theme"));
  assert.ok(entry.src.includes("core/img/**"));
});
