<?php

namespace Tests\Support;

use Illuminate\Foundation\Testing\DatabaseMigrations;

trait UsesCommittedTestDatabase
{
    use DatabaseMigrations, RequiresTestDatabase {
        RequiresTestDatabase::beforeRefreshingDatabase insteadof DatabaseMigrations;
    }
}
