/**
 * Runs the whole suite once without recording anything, then re-runs only the tests that failed with a trace, a video
 * and a screenshot each (E2E_DIAGNOSTICS=1), in folders of their own, and says which failures were flaky.
 *
 *   node tests/E2E/run-with-diagnostic-rerun.mjs [playwright args…]
 *
 * The exit status is the first pass's: a test that passes on the re-run is still a failure of the run, it is reported
 * as flaky. Output: test-results-rerun/ (videos, traces, screenshots), playwright-report-rerun/ (HTML),
 * tests/E2E/error-report.md (first pass), tests/E2E/error-report-rerun.md and tests/E2E/rerun-summary.md.
 */

import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';

const args = process.argv.slice(2);
const run = (extraArgs, env = {}) => spawnSync('npx', ['playwright', 'test', ...extraArgs], { stdio: 'inherit', env: { ...process.env, ...env } });

console.log('\n=== E2E pass 1: every test, nothing recorded ===\n');
const first = run(args);
if (first.status === 0) process.exit(0);

const lastRun = path.resolve('test-results/.last-run.json');
const failedIds = fs.existsSync(lastRun) ? (JSON.parse(fs.readFileSync(lastRun, 'utf8')).failedTests ?? []) : [];
if (failedIds.length === 0) {
  console.log('\nPass 1 failed without a failed-test list (a setup or config error?): nothing to re-run.');
  process.exit(first.status ?? 1);
}

console.log(`\n=== E2E pass 2: ${failedIds.length} failed test(s) again, with trace, video and screenshot ===\n`);
const jsonFile = path.resolve('test-results-rerun.json');
fs.rmSync(jsonFile, { force: true });
const second = run(
  [...args, '--last-failed', `--last-failed-file=${lastRun}`, '--output=test-results-rerun', '--reporter=list,html,json,./tests/E2E/error-summary-reporter.js'],
  { E2E_DIAGNOSTICS: '1', PLAYWRIGHT_HTML_REPORT: 'playwright-report-rerun', PLAYWRIGHT_JSON_OUTPUT_NAME: jsonFile, E2E_ERROR_REPORT: 'tests/E2E/error-report-rerun.md' },
);

const lines = [];
const walk = (suite) => {
  for (const spec of suite.specs ?? []) {
    for (const test of spec.tests ?? []) {
      const outcome = test.results?.at(-1)?.status;
      lines.push(`${outcome === 'passed' ? 'FLAKY (passed on the re-run)' : 'FAILED AGAIN'}  ${spec.file} › ${spec.title}`);
    }
  }
  (suite.suites ?? []).forEach(walk);
};
if (fs.existsSync(jsonFile)) (JSON.parse(fs.readFileSync(jsonFile, 'utf8')).suites ?? []).forEach(walk);

const summary = [
  `# E2E failures: ${failedIds.length} in pass 1, re-run with diagnostics`,
  '',
  ...(lines.length ? lines.map((line) => `- ${line}`) : ['- the re-run produced no result (see its log above)']),
  '',
  'Videos, traces and screenshots: test-results-rerun/. HTML report: playwright-report-rerun/.',
].join('\n');
fs.writeFileSync(path.resolve('tests/E2E/rerun-summary.md'), `${summary}\n`);
console.log(`\n${summary}\n`);

process.exit(first.status ?? second.status ?? 1);
