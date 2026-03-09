<?php

$conn = new mysqli('localhost', 'moodle', 'moodle', 'moodle');
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$tables = [
    "CREATE TABLE IF NOT EXISTS `mdl_local_orch_stud_profile` (
    `id` bigint(10) NOT NULL AUTO_INCREMENT,
    `userid` bigint(10) NOT NULL,
    `courseid` bigint(10) NOT NULL,
    `level` varchar(255) DEFAULT NULL,
    `mastery_by_kc` longtext,
    `misconceptions` longtext,
    `preferences` longtext,
    `integrity` longtext,
    `timecreated` bigint(10) NOT NULL,
    `timemodified` bigint(10) NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `mdl_locaorchstudprof_usecou_uix` (`userid`,`courseid`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=COMPRESSED COMMENT='Maintains long-term student profile';",
    "CREATE TABLE IF NOT EXISTS `mdl_local_orch_int_summ` (
    `id` bigint(10) NOT NULL AUTO_INCREMENT,
    `userid` bigint(10) NOT NULL,
    `courseid` bigint(10) NOT NULL,
    `run_id` varchar(255) DEFAULT NULL,
    `summary` longtext,
    `tags` longtext,
    `last_targets` longtext,
    `last_next_steps` longtext,
    `student_vis_out` varchar(255) DEFAULT NULL,
    `timecreated` bigint(10) NOT NULL,
    PRIMARY KEY (`id`),
    KEY `mdl_locaorchintsumm_usecou_ix` (`userid`,`courseid`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=COMPRESSED COMMENT='Short-term interaction history summaries';"
];

foreach ($tables as $sql) {
    if ($conn->query($sql) === TRUE) {
        echo "Table created successfully\n";
    } else {
        echo "Error creating table: " . $conn->error . "\n";
    }
}

$conn->close();
