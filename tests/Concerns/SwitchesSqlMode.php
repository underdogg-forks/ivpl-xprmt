<?php

namespace Tests\Concerns;

use Throwable;

/**
 * Runs a test against the database server with a different global sql_mode.
 *
 * The suite runs with the server's default sql_mode (STRICT_TRANS_TABLES,...); a test that needs another mode, for
 * example ONLY_FULL_GROUP_BY, which MariaDB's default leaves off, switches it here. A request runs in its own PHP process with its
 * own database connection, so a session mode set on the test's connection would never reach it: the server's
 * global mode is the only switch, and it applies to connections opened after it is set. The original mode is put
 * back by restoreSqlMode() (call it from tearDown()) and, should the process die first, at shutdown. It is server-wide
 * state: runs must not overlap (CLAUDE.md, "One run at a time"), and CI gives each job its own database service.
 *
 * Requires InteractsWithDatabase (AbstractTestCase has it).
 */
trait SwitchesSqlMode
{
    private ?string $originalSqlMode = null;

    /**
     * Sets the server's global sql_mode, remembering the original once (and restoring it at shutdown too).
     */
    protected function switchSqlMode(string $mode): void
    {
        $original = (string) $this->databaseSelect('SELECT @@GLOBAL.sql_mode AS mode')[0]['mode'];

        if ($this->originalSqlMode === null) {
            $this->originalSqlMode = $original;
            register_shutdown_function(function () use ($original): void {
                try {
                    $this->databaseRunScript("SET GLOBAL sql_mode = '" . addslashes($original) . "'");
                } catch (Throwable) {
                    // the connection is gone; nothing left to restore through
                }
            });
        }

        $this->databaseRunScript("SET GLOBAL sql_mode = '" . addslashes($mode) . "'");
    }

    /**
     * Puts the original sql_mode back; a no-op when switchSqlMode() was never called.
     */
    protected function restoreSqlMode(): void
    {
        if ($this->originalSqlMode === null) {
            return;
        }

        $this->databaseRunScript("SET GLOBAL sql_mode = '" . addslashes($this->originalSqlMode) . "'");
        $this->originalSqlMode = null;
    }

    /**
     * The server's global sql_mode right now, read through the test's own connection.
     */
    protected function currentGlobalSqlMode(): string
    {
        return (string) $this->databaseSelect('SELECT @@GLOBAL.sql_mode AS mode')[0]['mode'];
    }
}
