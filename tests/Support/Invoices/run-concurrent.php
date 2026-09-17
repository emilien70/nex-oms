<?php

require dirname(__DIR__, 3).'/vendor/autoload.php';

use Tests\Support\Invoices\ExportTestWorkspace;

$workspace = new ExportTestWorkspace;
$failed = false;
$filter = 'SalesRegisterExportIsolationTest|native_download_has_exact|native_xml_structure|real_endpoint_validates_official|generation_exception_is_safe|safe_failure_cleans|private_file_is_removed|original_vat_code_and_conflicting';
try {
    for ($round = 1; $round <= 5; $round++) {
        $children = [];
        try {
            for ($worker = 1; $worker <= 2; $worker++) {
                $log = $workspace->root.'/scratch/round-'.$round.'-process-'.$worker.'.log';
                $process = proc_open([PHP_BINARY, __DIR__.'/run-isolated.php', '--wait-for-start', 'tests/Feature/Invoices', '--filter='.$filter],
                    [0 => ['pipe', 'r'], 1 => ['file', $log, 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 3));
                if (! is_resource($process)) {
                    throw new RuntimeException('Cannot start parallel test process.');
                }
                $children[] = compact('process', 'pipes', 'log', 'worker');
            }
            // Both processes are alive and isolated before either starts its PHPUnit child.
            foreach ($children as $child) {
                if (fgets($child['pipes'][2]) !== "READY\n") {
                    throw new RuntimeException('Parallel test readiness barrier failed.');
                }
            }
            foreach ($children as $child) {
                fwrite($child['pipes'][0], "START\n");
                fclose($child['pipes'][0]);
            }
            foreach ($children as $index => $child) {
                $errors = stream_get_contents($child['pipes'][2]);
                fclose($child['pipes'][2]);
                $exit = proc_close($child['process']);
                unset($children[$index]);
                echo 'ROUND '.$round.' PROCESS '.$child['worker'].' EXIT '.$exit."\n";
                echo file_get_contents($child['log']).$errors;
                $failed = $failed || $exit !== 0;
            }
        } finally {
            foreach ($children as $child) {
                foreach ($child['pipes'] as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
                proc_close($child['process']);
            }
        }
    }
} finally {
    $workspace->remove();
}
exit($failed ? 1 : 0);
