<?php
require __DIR__ . '/includes/bootstrap.php';

if (is_post() && csrf_valid()) {
    logout_user();
}
redirect('index.php');
