<?php

namespace Tests\Support;

use LogicException;

trait RequiresTestDatabase
{
    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'pgsql'
            || config('database.connections.pgsql.database') !== 'emenu_test'
            || config('database.connections.pgsql.url')) {
            throw new LogicException('Phase 2 tests require the dedicated PostgreSQL database emenu_test.');
        }
    }
}
