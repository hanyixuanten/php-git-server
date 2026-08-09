<?php

/* Remote branch operations are receive-pack updates under refs/heads/. */
function register_branch_operation(&$application) {
    $application['push_ref_rules'][] = array(
        'name' => 'branch',
        'prefix' => 'refs/heads/',
        'option' => 'branches');
}
