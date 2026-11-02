<?php
require __DIR__ . '/app/bootstrap.php';

if (user()) {
    log_activity('logout', 'user', (int) user()['id']);
}
logout_user();
flash('success', 'You have been signed out.');
redirect(root_url('sign-in.php'));
