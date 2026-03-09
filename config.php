<?php  // Moodle configuration file

unset($CFG);
global $CFG;
$CFG = new stdClass();

$CFG->dbtype = 'mysqli';
$CFG->dblibrary = 'native';
$CFG->dbhost = 'localhost';
$CFG->dbname = 'moodle';
$CFG->dbuser = 'moodle';
$CFG->dbpass = 'moodle';
$CFG->prefix = 'mdl_';
$CFG->dboptions = array(
  'dbpersist' => 0,
  'dbport' => '',
  'dbsocket' => '',
  'dbcollation' => 'utf8mb4_unicode_ci',
);

$CFG->wwwroot = 'http://moodle.test';
$CFG->dataroot = 'C:\\wamp64\\www/moodledata';
// $CFG->wwwroot = 'http://moodle.test'; // Original value for WAMP
// $CFG->dataroot = 'C:\\wamp64\\www/moodledata'; // Original value for WAMP
$CFG->admin = 'admin';

$CFG->directorypermissions = 0777;

// The following lines were WAMP-specific cURL fixes and are being removed to restore core config.
// // Fix for Local WAMP cURL failing to securely connect to Gemini APIs over PHP
// $CFG->proxybypass = true;
// $CFG->curlsecurity = false;
// $CFG->proxytype = 'HTTP';

// // Globally skip curl strict SSL verification locally or manually provide local CA
// $CFG->pathtocacert = __DIR__ . '/cacert.pem';
// define('CURL_SSL_VERIFY_PEER', true);
// define('CURL_SSL_VERIFY_HOST', 2);

require_once(__DIR__ . '/lib/setup.php');

// There is no php closing tag in this file,
// it is intentional because it prevents trailing whitespace problems!
