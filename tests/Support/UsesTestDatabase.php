<?php

namespace Tests\Support;

use Illuminate\Foundation\Testing\RefreshDatabase;

trait UsesTestDatabase
{
    use RefreshDatabase, RequiresTestDatabase {
        RequiresTestDatabase::beforeRefreshingDatabase insteadof RefreshDatabase;
    }
}
