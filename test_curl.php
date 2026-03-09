<?php
$ch = curl_init('https://generativelanguage.googleapis.com/v1beta/models');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
// We don't need the actual key for just testing the connection/handshake
// but if we want to be sure it works, we should at least see if it fails with 403 (Unauthorized) 
// instead of 77 (Cert error).
$response = curl_exec($ch);
if (curl_errno($ch)) {
    echo 'Curl error: ' . curl_error($ch) . ' (Code: ' . curl_errno($ch) . ")\n";
} else {
    echo "Connection successful! Response length: " . strlen($response) . "\n";
    echo "HTTP Code: " . curl_getinfo($ch, CURLINFO_HTTP_CODE) . "\n";
}
curl_close($ch);
