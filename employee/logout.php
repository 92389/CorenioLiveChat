<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/employee_auth.php';

// Clear the employee session and send them back to the login screen.
lc_employee_logout();
header('Location: login.php');
exit;

