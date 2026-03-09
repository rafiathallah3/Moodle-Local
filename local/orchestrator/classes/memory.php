<?php

namespace local_orchestrator;

defined('MOODLE_INTERNAL') || die();

class memory
{

    /**
     * Load the student memory profile and short interaction history.
     */
    public static function load_profile($student, $course)
    {
        global $DB;

        // Fetch student profile.
        $profile_record = $DB->get_record('local_orch_stud_profile', [
            'userid' => is_object($student) ? $student->id : $student,
            'courseid' => is_object($course) ? $course->id : $course
        ]);

        $profile = [
            'level' => 'unknown',
            'mastery_by_kc' => new \stdClass(),
            'misconceptions' => [],
            'preferences' => [
                'language' => 'id',
                'preferred_modality' => 'unknown',
                'hint_style' => 'socratic'
            ],
            'integrity' => [
                'hint_only' => true,
                'no_full_solution' => true,
                'exam_mode' => false
            ]
        ];

        if ($profile_record) {
            if (!empty($profile_record->level)) {
                $profile['level'] = $profile_record->level;
            }
            if (!empty($profile_record->mastery_by_kc)) {
                $profile['mastery_by_kc'] = json_decode($profile_record->mastery_by_kc, true);
                if (is_null($profile['mastery_by_kc']))
                    $profile['mastery_by_kc'] = new \stdClass();
            }
            if (!empty($profile_record->misconceptions)) {
                $profile['misconceptions'] = json_decode($profile_record->misconceptions, true);
            }
            if (!empty($profile_record->preferences)) {
                $profile['preferences'] = array_merge($profile['preferences'], json_decode($profile_record->preferences, true));
            }
            if (!empty($profile_record->integrity)) {
                $profile['integrity'] = array_merge($profile['integrity'], json_decode($profile_record->integrity, true));
            }
        }

        // Fetch short history - getting the most recent single run summary
        $history_record = $DB->get_records('local_orch_int_summ', [
            'userid' => is_object($student) ? $student->id : $student,
            'courseid' => is_object($course) ? $course->id : $course
        ], 'timecreated DESC', '*', 0, 1);

        $history = [
            'last_turns_summary' => '',
            'last_targets' => [],
            'last_next_steps' => [],
            'last_updated' => date('c')
        ];

        if ($history_record) {
            $latest = reset($history_record);
            $history['last_turns_summary'] = $latest->summary;
            if (!empty($latest->tags)) {
                $history['last_targets'] = json_decode($latest->tags, true);
            }
            if (!empty($latest->last_next_steps)) {
                $history['last_next_steps'] = json_decode($latest->last_next_steps, true);
            }
            $history['last_updated'] = date('c', $latest->timecreated);
        }

        return [
            'student_profile' => $profile,
            'history' => $history
        ];
    }

    /**
     * Update the student memory profile from a successful run result.
     */
    public static function update_profile($student, $course, $run_result)
    {
        global $DB;

        // Ensure we have expected updates
        if (empty($run_result['memory_updates'])) {
            return false;
        }

        $userid = is_object($student) ? $student->id : $student;
        $courseid = is_object($course) ? $course->id : $course;

        $memory_update = $run_result['memory_updates'];

        // Upsert student_profile
        $profile_record = $DB->get_record('local_orch_stud_profile', [
            'userid' => $userid,
            'courseid' => $courseid
        ]);

        if (!empty($memory_update['student_model_update'])) {
            $student_model = $memory_update['student_model_update'];

            $record = new \stdClass();
            $record->userid = $userid;
            $record->courseid = $courseid;
            $record->timemodified = time();

            if (isset($student_model['level'])) {
                $record->level = $student_model['level'];
            }
            if (isset($student_model['mastery_by_kc_new'])) {
                $record->mastery_by_kc = json_encode($student_model['mastery_by_kc_new']);
            }
            if (isset($student_model['misconceptions_new'])) {
                $record->misconceptions = json_encode($student_model['misconceptions_new']);
            }
            if (isset($student_model['preferences_new'])) {
                $record->preferences = json_encode($student_model['preferences_new']);
            }
            if (isset($student_model['integrity_new'])) {
                $record->integrity = json_encode($student_model['integrity_new']);
            }

            if ($profile_record) {
                $record->id = $profile_record->id;
                $DB->update_record('local_orch_stud_profile', $record);
            } else {
                $record->timecreated = time();
                $DB->insert_record('local_orch_stud_profile', $record);
            }
        }

        // Append session summary
        if (!empty($memory_update['session_log_update'])) {
            $session_log = $memory_update['session_log_update'];
            $record = new \stdClass();
            $record->userid = $userid;
            $record->courseid = $courseid;
            $record->timecreated = time();
            $record->run_id = isset($run_result['run_id']) ? $run_result['run_id'] : '';

            if (isset($session_log['summary'])) {
                $record->summary = $session_log['summary'];
            }
            if (isset($session_log['tags'])) {
                $record->tags = json_encode($session_log['tags']);
            }
            if (isset($session_log['last_targets'])) {
                // If it passes as last_targets instead of using run info 
                $record->last_targets = json_encode($session_log['last_targets']);
            }
            if (isset($session_log['last_next_steps'])) {
                $record->last_next_steps = json_encode($session_log['last_next_steps']);
            }
            if (isset($session_log['student_visible_outcome'])) {
                $record->student_vis_out = $session_log['student_visible_outcome'];
            }

            $DB->insert_record('local_orch_int_summ', $record);
        }

        return true;
    }
}
