<?php
/**
 * Capability definitions for local_practice (Practice Hub).
 *
 * @package    local_practice
 */

defined('MOODLE_INTERNAL') || die();

$capabilities = [
    'local/practice:view' => [
        'captype'      => 'read',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes'   => [
            'student'        => CAP_ALLOW,
            'teacher'        => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
            'manager'        => CAP_ALLOW,
        ]
    ]
];
