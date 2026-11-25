<?php

return [
    'trusted_proxy_ips' => array_filter(array_map(trim(...), explode(',', (string) env('PUBLIC_MENU_TRUSTED_PROXY_IPS', '')))),
];
