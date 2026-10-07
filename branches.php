<?php
// branches.php - Redirect to master.php "How Many Branches" tab
// As requested, "How Many Branches" is strictly controlled via Master Portal
require_once __DIR__ . '/config/auth.php';
if (isMaster()) {
    header("Location: master.php?tab=branches");
} else {
    header("Location: dashboard.php");
}
exit;
