<?php
// Seeds real course content (topic pages, a quiz with real questions, and a
// discussion forum with replies) per mata kuliah from the shared
// LMS/seed-data/curriculum-seed.json dataset. Requires seed.php to have run
// first (courses, dosen, mahasiswa must already exist). Safe to re-run:
// existing activities with the same name are left alone.

define('CLI_SCRIPT', true);
require(__DIR__ . '/../config.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');
require_once($CFG->dirroot . '/lib/enrollib.php');
require_once($CFG->dirroot . '/lib/testing/generator/component_generator_base.php');
require_once($CFG->dirroot . '/lib/testing/generator/module_generator.php');
require_once($CFG->dirroot . '/lib/testing/generator/data_generator.php');
require_once($CFG->dirroot . '/question/format.php');
require_once($CFG->dirroot . '/question/format/gift/format.php');

// Run as admin so ownership/capabilities checks on created content pass.
$USER = get_admin();

// Escape GIFT format's special characters in plain question/answer text.
function seed_gift_escape(string $text): string {
    return str_replace(
        ['\\', '{', '}', ':', '=', '~', '#'],
        ['\\\\', '\\{', '\\}', '\\:', '\\=', '\\~', '\\#'],
        $text
    );
}

// Build a GIFT-format text block for a list of {text, choices, correct} questions.
function seed_build_gift(string $quizname, array $questions): string {
    $blocks = [];
    foreach ($questions as $qi => $q) {
        $title = seed_gift_escape($quizname . ' - Soal ' . ($qi + 1));
        $text = seed_gift_escape($q['text']);
        $lines = ["::{$title}::{$text} {"];
        foreach ($q['choices'] as $ci => $choice) {
            $prefix = ($ci === $q['correct']) ? '=' : '~';
            $lines[] = '    ' . $prefix . seed_gift_escape($choice);
        }
        $lines[] = '}';
        $blocks[] = implode("\n", $lines);
    }
    return implode("\n\n", $blocks) . "\n";
}

// Import a GIFT text block into a question category, returning created question ids in order.
function seed_import_gift_questions(stdClass $category, stdClass $course, string $gifttext): array {
    $tmpfile = tempnam(sys_get_temp_dir(), 'seed_gift_');
    file_put_contents($tmpfile, $gifttext);

    $qformat = new qformat_gift();
    $qformat->setCategory($category);
    $qformat->setCourse($course);
    $qformat->setContexts([context::instance_by_id($category->contextid)]);
    $qformat->setFilename($tmpfile);
    $qformat->setRealfilename($tmpfile);
    $qformat->setStoponerror(true);
    $qformat->setMatchgrades('error');
    $qformat->displayprogress = false;

    ob_start();
    $ok = $qformat->importpreprocess() && $qformat->importprocess();
    $qformat->importpostprocess();
    ob_end_clean();

    unlink($tmpfile);

    if (!$ok) {
        cli_error('GIFT import failed for quiz in course ' . $course->shortname);
    }

    return $qformat->questionids;
}

$seedfile = '/var/www/seed-data/curriculum-seed.json';
if (!file_exists($seedfile)) {
    cli_error("Seed file not found: $seedfile");
}
$data = json_decode(file_get_contents($seedfile), true);
if ($data === null) {
    cli_error("Failed to parse seed JSON: " . json_last_error_msg());
}

$generator = new testing_data_generator();
$questioncatgenerator = $generator->get_plugin_generator('core_question');
$forumgenerator = $generator->get_plugin_generator('mod_forum');

function seed_userid(string $username): int {
    global $DB;
    return (int) $DB->get_field('user', 'id', ['username' => $username], MUST_EXIST);
}

foreach ($data['courseContent'] as $shortname => $content) {
    $course = $DB->get_record('course', ['shortname' => $shortname], '*', MUST_EXIST);
    mtrace("Course: {$course->fullname} ({$shortname})");

    // 1. Sections + material pages.
    course_create_sections_if_missing($course, range(1, count($content['sections'])));
    foreach ($content['sections'] as $i => $section) {
        $sectionnum = $i + 1;
        $DB->set_field('course_sections', 'name', $section['name'], ['course' => $course->id, 'section' => $sectionnum]);

        $pagename = 'Materi: ' . $section['name'];
        $exists = $DB->get_record('page', ['course' => $course->id, 'name' => $pagename]);
        if (!$exists) {
            $generator->create_module('page', [
                'course' => $course->id,
                'section' => $sectionnum,
                'name' => $pagename,
                'intro' => '',
                'content' => $section['content'],
                'contentformat' => FORMAT_HTML,
            ]);
            mtrace("  created page: $pagename");
        } else {
            mtrace("  page exists: $pagename");
        }
    }
    rebuild_course_cache($course->id, true);

    // 2. Quiz with real questions, placed in section 1.
    $quizdef = $content['quiz'];
    $quiz = $DB->get_record('quiz', ['course' => $course->id, 'name' => $quizdef['name']]);
    if (!$quiz) {
        $quiz = $generator->create_module('quiz', [
            'course' => $course->id,
            'section' => 1,
            'name' => $quizdef['name'],
            'intro' => $quizdef['intro'],
            'introformat' => FORMAT_HTML,
        ]);

        $category = $questioncatgenerator->create_question_category([
            'contextid' => context_course::instance($course->id)->id,
            'name' => 'Bank Soal ' . $shortname,
        ]);

        $gifttext = seed_build_gift($quizdef['name'], $quizdef['questions']);
        $questionids = seed_import_gift_questions($category, $course, $gifttext);
        foreach ($questionids as $questionid) {
            quiz_add_quiz_question($questionid, $quiz, 0);
        }
        mtrace("  created quiz: {$quizdef['name']} (" . count($questionids) . " soal)");
    } else {
        mtrace("  quiz exists: {$quizdef['name']}");
    }

    // 3. Discussion forum with a starter post and student replies.
    $forumdef = $content['forum'];
    $forum = $DB->get_record('forum', ['course' => $course->id, 'name' => $forumdef['name']]);
    if (!$forum) {
        $forum = $generator->create_module('forum', [
            'course' => $course->id,
            'section' => 1,
            'name' => $forumdef['name'],
            'intro' => $forumdef['intro'],
            'introformat' => FORMAT_HTML,
        ]);

        $teacherid = seed_userid($forumdef['discussion']['teacher']);
        $discussion = $forumgenerator->create_discussion([
            'course' => $course->id,
            'forum' => $forum->id,
            'userid' => $teacherid,
            'name' => $forumdef['discussion']['subject'],
            'subject' => $forumdef['discussion']['subject'],
            'message' => $forumdef['discussion']['message'],
            'messageformat' => FORMAT_HTML,
        ]);

        $rootpost = $DB->get_record('forum_posts', ['discussion' => $discussion->id, 'parent' => 0], '*', MUST_EXIST);

        foreach ($forumdef['replies'] as $reply) {
            $studentid = seed_userid($reply['student']);
            $forumgenerator->create_post([
                'discussion' => $discussion->id,
                'userid' => $studentid,
                'parent' => $rootpost->id,
                'subject' => 'Re: ' . $forumdef['discussion']['subject'],
                'message' => $reply['message'],
                'messageformat' => FORMAT_HTML,
            ]);
        }
        mtrace("  created forum: {$forumdef['name']} (1 diskusi + " . count($forumdef['replies']) . " balasan)");
    } else {
        mtrace("  forum exists: {$forumdef['name']}");
    }
}

mtrace("Content seeding complete.");
