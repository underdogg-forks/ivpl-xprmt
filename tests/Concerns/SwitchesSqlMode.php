<?php

namespace Tests\Concerns;

use Throwable;

/**
 * Runs a test against the database server with a different global sql_mode.
 *
 * The suite normally runs with sql_mode '' (tests/Support/sandbox-mariadb.sh, the phpunit workflow), which hides
 * every query that ONLY_FULL_GROUP_BY or the STRICT modes reject. A request runs in its own PHP process with its
 * own database connection, so a session mode set on the test's connection would never reach it: the server's
 * global mode is the only switch, and it applies to connections opened after it is set. The original mode is put
 * back by restoreSqlMode() (call it from tearDown()) and, should the process die first, at shutdown.
 *
 * Requires InteractsWithDatabase (AbstractTestCase has it).
 */
trait SwitchesSqlMode
{
    private ?string $originalSqlMode = null;

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

    protected function restoreSqlMode(): void
    {
        if ($this->originalSqlMode === null) {
            return;
        }

        $this->databaseRunScript("SET GLOBAL sql_mode = '" . addslashes($this->originalSqlMode) . "'");
        $this->originalSqlMode = null;
    }

    protected function currentGlobalSqlMode(): string
    {
        return (string) $this->databaseSelect('SELECT @@GLOBAL.sql_mode AS mode')[0]['mode'];
    }
}
