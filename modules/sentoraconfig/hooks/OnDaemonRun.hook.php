<?php
/**
 * Customer feedback (ctrl_feedback): on the daemon's first run and then once a day, a short report
 * about this server (nothing private) to the Sentora-2027 project, while Sentora Config >
 * customer_feedback is on (the default).
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-09
 */
try {
    $feedbackLine = ctrl_feedback::Run();
    if ($feedbackLine !== '') {
        echo $feedbackLine . fs_filehandler::NewLine();
    }
} catch (Exception $e) {
    echo "Customer feedback: not sent (" . $e->getMessage() . ")" . fs_filehandler::NewLine();
}
