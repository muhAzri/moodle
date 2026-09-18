<?php
// Seeds curriculum data (courses, dosen, mahasiswa, enrolments) from the
// shared LMS/seed-data/curriculum-seed.json dataset so Moodle and Polaris
// can be compared against identical data. Safe to re-run.

define('CLI_SCRIPT', true);
require(__DIR__ . '/../config.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/lib/enrollib.php');

$seedfile = '/var/www/seed-data/curriculum-seed.json';
if (!file_exists($seedfile)) {
    cli_error("Seed file not found: $seedfile");
}

$data = json_decode(file_get_contents($seedfile), true);
if ($data === null) {
    cli_error("Failed to parse seed JSON: " . json_last_error_msg());
}

function seed_get_or_create_user(array $u, string $password): int {
    global $DB, $CFG;

    $existing = $DB->get_record('user', ['username' => $u['username'], 'deleted' => 0]);
    if ($existing) {
        mtrace("  user exists: {$u['username']}");
        return (int) $existing->id;
    }

    $user = new stdClass();
    $user->username = $u['username'];
    $user->password = $password;
    $user->firstname = $u['firstname'];
    $user->lastname = $u['lastname'];
    $user->email = $u['email'];
    $user->confirmed = 1;
    $user->mnethostid = $CFG->mnet_localhost_id;
    $user->auth = 'manual';
    $user->lang = 'en';

    $userid = user_create_user($user, true, false);
    mtrace("  created user: {$u['username']}");
    return (int) $userid;
}

function seed_enrol_user(int $courseid, int $userid, string $roleshortname): void {
    global $DB;
    static $roleids = [];

    if (!isset($roleids[$roleshortname])) {
        $roleids[$roleshortname] = (int) $DB->get_field('role', 'id', ['shortname' => $roleshortname]);
    }

    enrol_try_internal_enrol($courseid, $userid, $roleids[$roleshortname]);
}

// 1. Category.
mtrace("Category: {$data['category']}");
$category = $DB->get_record('course_categories', ['name' => $data['category']]);
if (!$category) {
    $category = core_course_category::create(['name' => $data['category']]);
    mtrace("  created category");
} else {
    mtrace("  category exists");
}

// 2. Courses.
mtrace("Courses:");
$courseidmap = [];
foreach ($data['courses'] as $c) {
    $course = $DB->get_record('course', ['shortname' => $c['shortname']]);
    if (!$course) {
        $newcourse = new stdClass();
        $newcourse->fullname = $c['fullname'];
        $newcourse->shortname = $c['shortname'];
        $newcourse->category = $category->id;
        $newcourse->format = 'topics';
        $newcourse->visible = 1;
        $newcourse->summary = '';
        $newcourse->summaryformat = FORMAT_HTML;
        $course = create_course($newcourse);
        mtrace("  created: {$c['fullname']} ({$c['shortname']})");
    } else {
        mtrace("  exists: {$c['shortname']}");
    }
    $courseidmap[$c['shortname']] = (int) $course->id;
}

// 3. Teachers (dosen).
mtrace("Teachers:");
foreach ($data['teachers'] as $t) {
    $userid = seed_get_or_create_user($t, $t['password']);
    foreach ($t['courses'] as $shortname) {
        if (isset($courseidmap[$shortname])) {
            seed_enrol_user($courseidmap[$shortname], $userid, 'editingteacher');
        }
    }
    mtrace("  {$t['firstname']} {$t['lastname']} -> " . implode(', ', $t['courses']));
}

// 4. Students (mahasiswa).
mtrace("Students:");
foreach ($data['students'] as $s) {
    $userid = seed_get_or_create_user($s, $data['studentPassword']);
    foreach ($data['studentCourses'] as $shortname) {
        if (isset($courseidmap[$shortname])) {
            seed_enrol_user($courseidmap[$shortname], $userid, 'student');
        }
    }
    mtrace("  {$s['firstname']} {$s['lastname']} ({$s['username']})");
}

mtrace("Seeding complete.");
