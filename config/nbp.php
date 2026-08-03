<?php

return [
    'base_url' => 'https://api.nbp.pl/api/exchangerates/tables',
    'rates_base_url' => 'https://api.nbp.pl/api/exchangerates/rates',
    'connect_timeout' => 5,
    'timeout' => 15,
    'retries' => 2,
    'retry_delay_ms' => 250,
    'historical_lookup_days' => 93,
];
