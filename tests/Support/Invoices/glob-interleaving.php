<?php

// A controlled stand-in for another exporter. The parent owns this private test directory.
$path = $argv[1].'/register-'.bin2hex(random_bytes(16)).'.xml';
fwrite(STDOUT, "READY\n");
if (trim(fgets(STDIN) ?: '') !== 'CREATE') {
    exit(1);
}
$handle = fopen($path, 'xb');
try {
    fwrite($handle, '<invoices/>');
} finally {
    fclose($handle);
}
try {
    fwrite(STDOUT, "CREATED\n");
    if (trim(fgets(STDIN) ?: '') !== 'DELETE') {
        exit(1);
    }
} finally {
    unlink($path);
}
fwrite(STDOUT, "DELETED\n");
