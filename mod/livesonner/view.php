<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Main view page for LiveSonner module
 *
 * @package    mod_livesonner
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/completionlib.php');
require_once($CFG->dirroot . '/user/lib.php');

$id = required_param('id', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);

$cm = get_coursemodule_from_id('livesonner', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$livesonner = $DB->get_record('livesonner', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, true, $cm);
$context = context_module::instance($cm->id);

$PAGE->set_url('/mod/livesonner/view.php', ['id' => $cm->id]);
$PAGE->set_title(format_string($livesonner->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->add_body_class('mod-livesonner');
$PAGE->activityheader->disable();

if ($action === 'join') {
    require_sesskey();

    if (!empty($livesonner->isfinished)) {
        redirect(new moodle_url('/mod/livesonner/view.php', ['id' => $cm->id]), get_string('classfinished', 'mod_livesonner'), null,
            \core\output\notification::NOTIFY_INFO);
    }

    if (time() < $livesonner->timestart) {
        redirect(new moodle_url('/mod/livesonner/view.php', ['id' => $cm->id]), get_string('classnotstarted', 'mod_livesonner'), null,
            \core\output\notification::NOTIFY_WARNING);
    }

    if (!$DB->record_exists('livesonner_attendance', ['livesonnerid' => $livesonner->id, 'userid' => $USER->id])) {
        $attendance = (object) [
            'livesonnerid' => $livesonner->id,
            'userid' => $USER->id,
            'timeclicked' => time(),
        ];
        $DB->insert_record('livesonner_attendance', $attendance);
    }

    $url = $livesonner->meeturl;
    if (!preg_match('#^https?://#', $url)) {
        $url = 'https://' . ltrim($url, '/');
    }
    redirect($url, get_string('joinredirectnotice', 'mod_livesonner'), 0, \core\output\notification::NOTIFY_SUCCESS);
}

if ($action === 'finalize') {
    require_sesskey();
    require_capability('mod/livesonner:manage', $context);

    if (empty($livesonner->isfinished)) {
        $livesonner->isfinished = 1;
        $livesonner->timemodified = time();
        $DB->update_record('livesonner', $livesonner);

        $completion = new completion_info($course);
        if ($completion->is_enabled($cm)) {
            $users = get_enrolled_users($context, 'mod/livesonner:view');
            foreach ($users as $user) {
                $completion->update_state($cm, COMPLETION_COMPLETE, $user->id);
            }
        }
        $message = get_string('finishsuccess', 'mod_livesonner');
    } else {
        $message = get_string('finishalready', 'mod_livesonner');
    }

    redirect(new moodle_url('/mod/livesonner/view.php', ['id' => $cm->id]), $message, null, \core\output\notification::NOTIFY_SUCCESS);
}

$event = \mod_livesonner\event\course_module_viewed::create([
    'objectid' => $livesonner->id,
    'context' => $context,
]);
$event->trigger();

$completion = new completion_info($course);
$completion->set_module_viewed($cm);

$now = time();
$remaining = $livesonner->timestart - $now;
$hasstarted = $remaining <= 0;

$buttonurl = new moodle_url('/mod/livesonner/view.php', ['id' => $cm->id, 'action' => 'join', 'sesskey' => sesskey()]);
$buttonclass = 'btn btn-lg btn-primary btn-block';
$buttondisabled = false;
$buttonlabel = get_string('joinclass', 'mod_livesonner');
$statusmessage = '';

if (!empty($livesonner->isfinished)) {
    $buttondisabled = true;
    $buttonclass = 'btn btn-lg btn-secondary btn-block disabled';
    $buttonlabel = get_string('classfinished', 'mod_livesonner');
    $statusmessage = get_string('classfinished', 'mod_livesonner');
} else if (!$hasstarted) {
    $buttondisabled = true;
    $buttonclass = 'btn btn-lg btn-secondary btn-block disabled';
    $statusmessage = get_string('countdownmessage', 'mod_livesonner', livesonner_format_interval($remaining));
}

$videohtml = livesonner_render_video($context);

$PAGE->requires->strings_for_js(['countdownmessage'], 'mod_livesonner');

if (empty($livesonner->isfinished) && !$hasstarted) {
    $PAGE->requires->js_call_amd('mod_livesonner/countdown', 'init', [
        $livesonner->timestart,
        '#livesonner-countdown',
    ]);
}

$PAGE->set_secondary_navigation(false);

echo $OUTPUT->header();

echo html_writer::start_tag('div', ['class' => 'container my-5']);
    echo html_writer::start_tag('div', ['class' => 'card shadow-sm border-0']);
        echo html_writer::start_tag('div', ['class' => 'card-body p-5']);
            echo html_writer::tag('h1', format_string($livesonner->name), ['class' => 'display-5 mb-3 text-primary']);
            echo html_writer::div(format_module_intro('livesonner', $livesonner, $cm->id), 'lead');

            echo html_writer::start_tag('div', ['class' => 'd-flex flex-column flex-md-row gap-3 my-4 align-items-start']);
                echo html_writer::div(html_writer::tag('span', get_string('starttimelabel', 'mod_livesonner', userdate($livesonner->timestart)), ['class' => 'badge bg-info text-dark fs-6 p-3']));
                echo html_writer::div(html_writer::tag('span', get_string('durationlabel', 'mod_livesonner', $livesonner->duration), ['class' => 'badge bg-warning text-dark fs-6 p-3']));
            echo html_writer::end_tag('div');

            if ($statusmessage) {
                echo html_writer::div($statusmessage, 'alert alert-info fs-5', ['id' => 'livesonner-countdown']);
            } else {
                echo html_writer::div('', 'alert alert-info fs-5 d-none', ['id' => 'livesonner-countdown']);
            }

            $buttonattrs = ['class' => $buttonclass, 'role' => 'button'];
            if ($buttondisabled) {
                $buttonattrs['aria-disabled'] = 'true';
            } else {
                $buttonattrs['href'] = $buttonurl;
                $buttonattrs['target'] = '_blank';
                $buttonattrs['rel'] = 'noopener noreferrer';
            }

            echo html_writer::tag('a', $buttonlabel, $buttonattrs);

            if (has_capability('mod/livesonner:manage', $context) && empty($livesonner->isfinished)) {
                $finishurl = new moodle_url('/mod/livesonner/view.php', ['id' => $cm->id, 'action' => 'finalize', 'sesskey' => sesskey()]);
                echo html_writer::tag('a', get_string('finalizeclass', 'mod_livesonner'), ['href' => $finishurl, 'class' => 'btn btn-outline-danger mt-3']);
            }
        echo html_writer::end_tag('div');

        echo html_writer::start_tag('div', ['class' => 'card-footer bg-light p-4']);
            if (!empty($livesonner->isfinished)) {
                echo html_writer::tag('h3', get_string('videosectiontitle', 'mod_livesonner'), ['class' => 'h4 mb-3']);
                echo $videohtml;
            } else if (has_capability('mod/livesonner:manage', $context)) {
                echo html_writer::div(get_string('videoavailableafterfinish', 'mod_livesonner'), 'text-muted');
            }
        echo html_writer::end_tag('div');
    echo html_writer::end_tag('div');

echo html_writer::end_tag('div');

if (has_capability('mod/livesonner:manage', $context)) {
    $attendances = $DB->get_records('livesonner_attendance', ['livesonnerid' => $livesonner->id], 'timeclicked ASC');
    echo html_writer::start_tag('div', ['class' => 'container my-4']);
        echo html_writer::tag('h3', get_string('attendanceheading', 'mod_livesonner'), ['class' => 'h4']);
        if ($attendances) {
            $userids = array_map(static function($attendance) {
                return $attendance->userid;
            }, $attendances);
            $users = user_get_users_by_id(array_unique($userids));

            echo html_writer::div(get_string('attendancecount', 'mod_livesonner', count($attendances)), 'text-muted mb-3');
            echo html_writer::start_tag('div', ['class' => 'table-responsive']);
                echo html_writer::start_tag('table', ['class' => 'table table-striped table-hover']);
                    echo html_writer::start_tag('thead');
                        echo html_writer::start_tag('tr');
                            echo html_writer::tag('th', get_string('attendanceuser', 'mod_livesonner'), ['scope' => 'col']);
                            echo html_writer::tag('th', get_string('timeclicked', 'mod_livesonner'), ['scope' => 'col']);
                        echo html_writer::end_tag('tr');
                    echo html_writer::end_tag('thead');
                    echo html_writer::start_tag('tbody');
                        foreach ($attendances as $attendance) {
                            if (!isset($users[$attendance->userid])) {
                                $users[$attendance->userid] = core_user::get_user($attendance->userid);
                            }
                            $user = $users[$attendance->userid];
                            $profileurl = new moodle_url('/user/view.php', ['id' => $user->id, 'course' => $course->id]);
                            echo html_writer::start_tag('tr');
                                echo html_writer::tag('td', html_writer::link($profileurl, fullname($user)), ['class' => 'align-middle']);
                                echo html_writer::tag('td', userdate($attendance->timeclicked, get_string('strftimedatetimeshort', 'core_langconfig')), ['class' => 'align-middle']);
                            echo html_writer::end_tag('tr');
                        }
                    echo html_writer::end_tag('tbody');
                echo html_writer::end_tag('table');
            echo html_writer::end_tag('div');
        } else {
            echo html_writer::div(get_string('attendanceempty', 'mod_livesonner'), 'text-muted');
        }
    echo html_writer::end_tag('div');
}

echo $OUTPUT->footer();

/**
 * Format a duration interval for display.
 *
 * @param int $seconds seconds remaining
 * @return string
 */
function livesonner_format_interval(int $seconds): string {
    return format_time(max(0, $seconds));
}

/**
 * Render the recorded video area.
 *
 * @param context_module $context context
 * @return string
 */
function livesonner_render_video(context_module $context): string {
    $fs = get_file_storage();
    $files = $fs->get_area_files($context->id, 'mod_livesonner', 'video', 0, 'filename', false);

    if (empty($files)) {
        return html_writer::div(get_string('novideoavailable', 'mod_livesonner'), 'text-muted');
    }

    $output = '';
    foreach ($files as $file) {
        $url = moodle_url::make_pluginfile_url(
            $context->id,
            'mod_livesonner',
            'video',
            0,
            $file->get_filepath(),
            $file->get_filename(),
            false
        );
        $output .= html_writer::tag('video', html_writer::tag('source', '', ['src' => $url, 'type' => $file->get_mimetype()]), [
            'class' => 'w-100 rounded shadow-sm',
            'controls' => 'controls',
        ]);
    }

    return $output;
}
