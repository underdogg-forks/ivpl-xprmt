/**
 * Per-test database reset.
 *
 * Runs tests/Support/reset-test-db.php (truncate + reseed baseline) so every
 * browser test starts from the same clean state the PHPUnit Feature suite gets.
 * Without this the shared app database accumulates rows across the run and
 * list/pagination assertions become order-dependent and flaky.
 *
 * The DB_* environment is passed straight through; a local host run needs
 * `DB_HOSTNAME=127.0.0.1` (the value in ipconfig.php, `mariadb`, only resolves
 * inside the Docker network). CI already exports DB_* for the job.
 */

import { execFileSync } from 'node:child_process';
import path from 'node:path';

const RESET_SCRIPT = path.resolve('tests/Support/reset-test-db.php');

export function resetDatabase() {
  execFileSync('php', [RESET_SCRIPT], {
    stdio: 'pipe',
    env: { ...process.env, DB_HOSTNAME: process.env.DB_HOSTNAME || '127.0.0.1' },
  });
}
