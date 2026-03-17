<?php
defined('MOODLE_INTERNAL') || die();

$functions = array(
    'qtype_audiorecord_upload_file' => array(
        'classname'   => 'qtype_audiorecord\external',
        'methodname'  => 'upload',
        'description' => 'upload a file to moodle',
        'type'        => 'write',
        'ajax'        => true,
    ),
);
