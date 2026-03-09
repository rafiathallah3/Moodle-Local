<?php
defined('MOODLE_INTERNAL') || die();

$observers = array(
    array(
        'eventname' => '\mod_assign\event\assessable_submitted',
        'callback' => '\local_orchestrator\event\observer::assessable_submitted',
        'internal' => false,
    ),
    array(
        'eventname' => '\mod_quiz\event\attempt_submitted',
        'callback' => '\local_orchestrator\event\observer::attempt_submitted',
        'internal' => false,
    ),
);
