<?php
// Compatibility for saved links: everyone signs in through the same form.
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Location: login.php'.(isset($_GET['logout']) ? '?logout=1' : ''), true, 303);
exit;
