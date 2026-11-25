<?php

return [
    'driver' => 'argon2id',
    'argon' => ['memory' => 65536, 'time' => 3, 'threads' => 1, 'verify' => true],
    'dummy' => '$argon2id$v=19$m=65536,t=3,p=1$N1YvMUVYNHlhMmhvZ1VIUQ$/Qm7yF6wnMOZl/Y7GjjWSVPuZHSZiFCbgDDGJavOqhI',
];
