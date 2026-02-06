<?php
// cron_punchout.php
require_once 'core.php';
use Spacemount\WorkHub\CoreEngine;

$core = CoreEngine::getInstance();
$core->runAutoPunchOut();
echo "Auto Punch-Out Processed Successfully.";