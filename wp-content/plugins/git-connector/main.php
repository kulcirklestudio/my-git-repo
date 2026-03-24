<?php
/*
Plugin Name: Git Connect
Plugin URI: https://example.com
Description: Connect Your Local To GIT.
Version: 1.0
Author: Kuldeep Patel
Author URI: https://example.com
License: GPLv2 or later
Text Domain: git-connect-plugin
*/

add_action('admin_init', function () {
    register_setting('git_plugin_settings_group', 'git_plugin_local_path', [
        'type' => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'default' => ''
    ]);
});

// ======================================
// Load CSS for Only Plugin settings page
// ======================================
function git_plugin_enqueue_styles($hook)
{
    if ($hook !== 'toplevel_page_create-git-branch') {
        return;
    }

    wp_enqueue_style(
        'git-plugin-style',
        plugin_dir_url(__FILE__) . 'style.css',
        array(),
        '1.0.0',
        'all'
    );
}
add_action('admin_enqueue_scripts', 'git_plugin_enqueue_styles');

// ===============================
// Admin Menu: Create Git Branch
// ===============================
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

function run_git_command($path, $command)
{
    $output = [];
    $status = 0;

    exec('git -C ' . escapeshellarg($path) . ' ' . $command . ' 2>&1', $output, $status);

    return [
        'output' => $output,
        'status' => $status
    ];
}

function git_get_valid_repo_path()
{
    $path = get_option('git_plugin_local_path');

    if (!$path) {
        add_settings_error('git_plugin', 'no_path', 'No path set');
        return false;
    }

    if (!is_dir($path . '/.git')) {
        add_settings_error('git_plugin', 'invalid_repo', 'Invalid Git repository');
        return false;
    }

    return $path;
}

function git_is_repo_clean($path)
{
    $status = run_git_command($path, 'status --porcelain');
    return empty($status['output']);
}

function git_has_remote($path)
{
    $remote = run_git_command($path, 'remote');
    return !empty($remote['output']);
}

add_action('admin_init', function () {

    if (!current_user_can('manage_options')) {
        return;
    }

    // ======================
    // COMMIT
    // ======================
    if (
        isset($_POST['git_commit']) &&
        wp_verify_nonce($_POST['git_commit_nonce'], 'git_commit_action')
    ) {

        $path = git_get_valid_repo_path();
        $message = sanitize_text_field($_POST['commit_message']);

        if (!$path || !$message)
            return;

        if (!git_is_repo_clean($path)) {
            run_git_command($path, 'add .');
        } else {
            add_settings_error('git_plugin', 'no_changes', 'No changes to commit');
            return;
        }

        $result = run_git_command($path, 'commit -m ' . escapeshellarg($message));

        add_settings_error(
            'git_plugin',
            'commit_result',
            implode("\n", $result['output']),
            $result['status'] === 0 ? 'updated' : 'error'
        );
    }

    // ======================
    // PULL
    // ======================
    if (
        isset($_POST['git_pull']) &&
        wp_verify_nonce($_POST['git_pull_nonce'], 'git_pull_action')
    ) {

        $path = git_get_valid_repo_path();
        if (!$path)
            return;

        if (!git_is_repo_clean($path)) {
            add_settings_error('git_plugin', 'dirty_repo', 'Commit changes before pulling');
            return;
        }

        if (!git_has_remote($path)) {
            add_settings_error('git_plugin', 'no_remote', 'No remote repository connected');
            return;
        }

        $result = run_git_command($path, 'pull');

        add_settings_error('git_plugin', 'pull_result', implode("\n", $result['output']), $result['status'] === 0 ? 'updated' : 'error');
    }

    // ======================
    // PUSH
    // ======================
    if (
        isset($_POST['git_push']) &&
        wp_verify_nonce($_POST['git_push_nonce'], 'git_push_action')
    ) {

        $path = git_get_valid_repo_path();
        if (!$path)
            return;

        if (!git_is_repo_clean($path)) {
            add_settings_error('git_plugin', 'dirty_repo', 'Commit changes before pushing');
            return;
        }

        if (!git_has_remote($path)) {
            add_settings_error('git_plugin', 'no_remote', 'No remote repository connected');
            return;
        }

        $result = run_git_command($path, 'push');

        add_settings_error('git_plugin', 'push_result', implode("\n", $result['output']), $result['status'] === 0 ? 'updated' : 'error');
    }

    // ======================
    // CREATE BRANCH
    // ======================
    if (
        isset($_POST['git_create_branch']) &&
        wp_verify_nonce($_POST['git_create_branch_nonce'], 'git_create_branch_action')
    ) {

        $path = git_get_valid_repo_path();
        $branch = sanitize_text_field($_POST['new_branch']);

        if (!$path || !$branch)
            return;

        if (!git_is_repo_clean($path)) {
            add_settings_error('git_plugin', 'dirty_repo', 'Commit before creating branch');
            return;
        }

        $branches = run_git_command($path, 'branch --format="%(refname:short)"');

        if (in_array($branch, $branches['output'])) {
            add_settings_error('git_plugin', 'branch_exists', 'Branch already exists');
            return;
        }

        $result = run_git_command($path, 'checkout -b ' . escapeshellarg($branch));

        add_settings_error('git_plugin', 'branch_result', implode("\n", $result['output']), $result['status'] === 0 ? 'updated' : 'error');
    }

});

function validate_git_path($path)
{
    $path = trim($path);

    if (empty($path)) {
        return '';
    }

    $real = realpath($path);

    if (!$real || !is_dir($real)) {
        add_settings_error(
            'git_plugin_local_path',
            'invalid_path',
            'Invalid path or folder does not exist'
        );
        return get_option('git_plugin_local_path');
    }

    if (!is_dir($real . '/.git')) {
        add_settings_error(
            'git_plugin_local_path',
            'not_git_repo',
            'This is not a Git repository'
        );
        return get_option('git_plugin_local_path');
    }

    // 🔒 Restrict path (IMPORTANT — you skipped this earlier)
    $allowed_base = realpath(WP_CONTENT_DIR . '/git-sync/');
    if ($allowed_base && strpos($real, $allowed_base) !== 0) {
        add_settings_error(
            'git_plugin_local_path',
            'not_allowed',
            'Path must be inside /wp-content/git-sync/'
        );
        return get_option('git_plugin_local_path');
    }

    return $real;
}

function render_git_settings_page()
{
    $path = get_option('git_plugin_local_path', '');

    // ======================
    // TEST REPO
    // ======================
    if (
        isset($_POST['test_git_repo']) &&
        wp_verify_nonce($_POST['test_git_repo_nonce'], 'test_git_repo_action')
    ) {
        if (!$path) {
            echo '<div class="notice notice-error"><p>No path set</p></div>';
        } else {
            $result = run_git_command($path, 'status');

            echo '<div class="notice ' . ($result['status'] === 0 ? 'notice-success' : 'notice-error') . '"><pre>';
            echo esc_html(implode("\n", $result['output']));
            echo '</pre></div>';
        }
    }

    // ======================
    // SWITCH BRANCH (SAFE)
    // ======================
    if (
        isset($_POST['switch_branch']) &&
        wp_verify_nonce($_POST['select_branch_nonce'], 'select_branch_action')
    ) {
        $branch = sanitize_text_field($_POST['branch']);

        // 🔴 Check for uncommitted changes
        $status_check = run_git_command($path, 'status --porcelain');

        if (!empty($status_check['output'])) {
            echo '<div class="notice notice-error"><p>Uncommitted changes exist. Commit or stash before switching branch.</p></div>';
        } else {
            $result = run_git_command($path, 'checkout ' . escapeshellarg($branch));

            echo '<div class="notice ' . ($result['status'] === 0 ? 'notice-success' : 'notice-error') . '"><pre>';
            echo esc_html(implode("\n", $result['output']));
            echo '</pre></div>';
        }
    }

    // ======================
    // LOAD DATA (ONLY IF PATH EXISTS)
    // ======================
    $current_branch = 'N/A';
    $branches = [];
    $remote = [];

    if ($path) {
        $branch_output = run_git_command($path, 'branch --show-current');
        $current_branch = $branch_output['output'][0] ?? 'unknown';

        $branches_result = run_git_command($path, 'branch --format="%(refname:short)"');
        $branches = $branches_result['output'];

        $remote_result = run_git_command($path, 'remote -v');
        $remote = $remote_result['output'];

        $status_result = run_git_command($path, 'status --porcelain');
        $repo_status = $status_result['output'];
    }

    ?>

    <div class="wrap">
        <h1>Git Plugin Settings</h1>

        <?php settings_errors(); ?>

        <!-- SETTINGS FORM -->
        <form method="post" action="options.php">
            <?php settings_fields('git_plugin_settings_group'); ?>

            <table class="form-table">
                <tr>
                    <th>Local Repository Path</th>
                    <td>
                        <input type="text" name="git_plugin_local_path" value="<?php echo esc_attr($path); ?>"
                            class="regular-text" placeholder="C:\xampp\htdocs\git">
                    </td>
                </tr>
            </table>

            <?php submit_button(); ?>
        </form>

        <?php if ($path): ?>

            <!-- CURRENT BRANCH + TEST -->
            <div class="current_branch_wrapper">
                <div><strong>Current Branch:</strong>
                    <p class="active_branch">
                        <?php echo esc_html($current_branch); ?>
                    </p>
                </div>

                <form method="post">
                    <?php wp_nonce_field('test_git_repo_action', 'test_git_repo_nonce'); ?>
                    <input type="hidden" name="test_git_repo" value="1">
                    <button type="submit" class="button button-secondary">
                        Debug Repository
                    </button>
                </form>
            </div>

            <div class="repo-status">
                <h2>Repository Status</h2>

                <?php if (empty($repo_status)): ?>
                    <p style="color:green;"><strong>Clean (no changes)</strong></p>
                <?php else: ?>
                    <p style="color:red;"><strong>Uncommitted Changes:</strong></p>
                    <pre><?php echo esc_html(implode("\n", $repo_status)); ?></pre>
                <?php endif; ?>
            </div>

            <div class="commit-section">
                <h2>Commit Changes</h2>

                <?php if (empty($repo_status)): ?>
                    <p>No changes to commit</p>
                <?php else: ?>
                    <form method="post">
                        <?php wp_nonce_field('git_commit_action', 'git_commit_nonce'); ?>

                        <input type="text" name="commit_message" placeholder="Enter commit message" style="width: 300px;" required>

                        <button type="submit" name="git_commit" class="button button-primary">
                            Commit
                        </button>
                    </form>
                <?php endif; ?>
            </div>

            <div class="pull-section">
                <h2>Pull Latest Changes</h2>

                <form method="post">
                    <?php wp_nonce_field('git_pull_action', 'git_pull_nonce'); ?>

                    <button type="submit" name="git_pull" class="button button-primary">
                        Pull from Remote
                    </button>
                </form>
            </div>

            <div class="push-section">
                <h2>Push Changes</h2>

                <form method="post">
                    <?php wp_nonce_field('git_push_action', 'git_push_nonce'); ?>

                    <button type="submit" name="git_push" class="button button-primary">
                        Push to Remote
                    </button>
                </form>
            </div>

            <!-- BRANCH SWITCH -->
            <div class="repo-dd-wrapper">
                <h2>Switch Branch</h2>

                <form method="post">
                    <?php wp_nonce_field('select_branch_action', 'select_branch_nonce'); ?>

                    <select name="branch">
                        <?php foreach ($branches as $branch): ?>
                            <option value="<?php echo esc_attr($branch); ?>" <?php selected($branch, $current_branch); ?>>
                                <?php echo esc_html($branch); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <button type="submit" name="switch_branch" class="button">
                        Switch Branch
                    </button>
                </form>
            </div>

            <div class="create-branch">
                <h2>Create Branch</h2>

                <form method="post">
                    <?php wp_nonce_field('git_create_branch_action', 'git_create_branch_nonce'); ?>

                    <input type="text" name="new_branch" placeholder="Enter branch name" required>

                    <button type="submit" name="git_create_branch" class="button button-primary">
                        Create & Switch
                    </button>
                </form>
            </div>

            <!-- REMOTE INFO -->
            <div class="remote-info">
                <h2>Remote</h2>

                <?php if (empty($remote)): ?>
                    <div class="notice notice-warning">
                        <p>No remote repository connected</p>
                    </div>
                <?php else: ?>
                    <pre><?php echo esc_html(implode("\n", $remote)); ?></pre>
                <?php endif; ?>
            </div>

        <?php endif; ?>

    </div>
    <?php
}