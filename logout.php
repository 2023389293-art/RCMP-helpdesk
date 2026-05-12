<?php
// uniKL/logout.php
session_start();
session_destroy();
header('Location: login.php');  // ← remove the ../
exit;
?>