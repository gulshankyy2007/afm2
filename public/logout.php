<?php
/**
 * AFM#2 - Logout Handler
 */

if (!defined('AFM_APP')) {
    define('AFM_APP', true);
}
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';

logout();
header('Location: login.php?logged_out=1');
exit;
