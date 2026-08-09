<?php

/* Remote tag operations are receive-pack updates under refs/tags/. */
function register_tag_operation(&$application) {
    $application['push_ref_rules'][] = array(
        'name' => 'tag',
        'prefix' => 'refs/tags/',
        'option' => 'tags');
}
