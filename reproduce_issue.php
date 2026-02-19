<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/public/config.php');

try {
    echo "Attempting to instantiate response_summarise_text...\n";
    $r = new \core_ai\aiactions\responses\response_summarise_text(success: true);
    echo "Success!\n";
    var_dump($r);
} catch (Throwable $e) {
    echo "Caught Throwable: " . get_class($e) . "\n";
    echo "Message: " . $e->getMessage() . "\n";
    echo "Trace:\n" . $e->getTraceAsString() . "\n";
}
