<?php

namespace App\Shared\Http;

use RuntimeException;

final class ApiProblem extends RuntimeException
{
    public function __construct(public readonly int $status, public readonly string $errorCode)
    {
        parent::__construct($errorCode);
    }
}
