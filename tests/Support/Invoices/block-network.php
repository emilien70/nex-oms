<?php

// Keep Guzzle's stream handler constructible for Http::fake(), but remove actual URL transports.
if (getenv('APP_ENV') !== 'testing' || function_exists('curl_exec') || function_exists('stream_socket_client')) {
    throw new RuntimeException('Unsafe isolated test network configuration.');
}
foreach (['http', 'https', 'ftp', 'ftps'] as $scheme) {
    if (in_array($scheme, stream_get_wrappers(), true) && ! stream_wrapper_unregister($scheme)) {
        throw new RuntimeException('Cannot disable test network stream wrapper.');
    }
}
