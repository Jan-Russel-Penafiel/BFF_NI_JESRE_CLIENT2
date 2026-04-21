<?php
require_once __DIR__ . '/functions.php';

require_login();

$currentPage = basename($_SERVER['SCRIPT_NAME'] ?? ($_SERVER['PHP_SELF'] ?? ''));
if ($currentPage !== '') {
    require_page_access($currentPage);
}
