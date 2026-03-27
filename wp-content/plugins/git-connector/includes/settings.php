<?php
add_action('admin_init', function () {
    register_setting('git_plugin_settings_group', 'git_plugin_local_path', [
        'type' => 'string',
        'sanitize_callback' => 'git_plugin_validate_local_path',
        'default' => ''
    ]);

    register_setting('git_plugin_settings_group', 'git_plugin_remote_url', [
        'type' => 'string',
        'sanitize_callback' => 'git_plugin_validate_remote_url',
        'default' => ''
    ]);

    register_setting('git_plugin_settings_group', 'git_plugin_git_binary', [
        'type' => 'string',
        'sanitize_callback' => 'git_plugin_validate_git_binary',
        'default' => ''
    ]);

    register_setting('git_plugin_settings_group', 'git_plugin_author_name', [
        'type' => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'default' => ''
    ]);

    register_setting('git_plugin_settings_group', 'git_plugin_author_email', [
        'type' => 'string',
        'sanitize_callback' => 'sanitize_email',
        'default' => ''
    ]);

    register_setting('git_plugin_settings_group', 'git_plugin_allow_protected_direct_changes', [
        'type' => 'string',
        'sanitize_callback' => 'git_plugin_sanitize_checkbox',
        'default' => '0'
    ]);

    register_setting('git_plugin_settings_group', 'git_plugin_protected_branches', [
        'type' => 'string',
        'sanitize_callback' => 'git_plugin_sanitize_branch_patterns',
        'default' => "main\nmaster"
    ]);
});

add_action('admin_menu', function () {
    add_menu_page(
        'Create Git Branch',
        'Create Branch',
        'manage_options',
        'create-git-branch',
        'render_git_settings_page',
        'dashicons-randomize',
        80
    );
});
