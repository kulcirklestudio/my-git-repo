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
    // Test Repo
    if (
        isset($_POST['test_git_repo']) &&
        isset($_POST['test_git_repo_nonce']) &&
        wp_verify_nonce($_POST['test_git_repo_nonce'], 'test_git_repo_action')
    ) {

        $path = get_option('git_plugin_local_path');

        if (!$path) {
            echo '<div class="notice notice-error"><p>No path set</p></div>';
            return;
        }

        $output = [];
        $status = 0;

        exec('git -C ' . escapeshellarg($path) . ' status 2>&1', $output, $status);

        echo '<div class="notice ' . ($status === 0 ? 'notice-success' : 'notice-error') . '"><pre>';
        echo esc_html(implode("\n", $output));
        echo '</pre></div>';
    }

    // Switch Between Branches
    if (
        isset($_POST['switch_branch']) &&
        isset($_POST['select_branch_nonce']) &&
        wp_verify_nonce($_POST['select_branch_nonce'], 'select_branch_action')
    ) {
        $branch = sanitize_text_field($_POST['branch']);
        $path = get_option('git_plugin_local_path');

        $output = [];
        $status = 0;

        exec(
            'git -C ' . escapeshellarg($path) . ' checkout ' . escapeshellarg($branch) . ' 2>&1',
            $output,
            $status
        );

        echo '<div class="notice ' . ($status === 0 ? 'notice-success' : 'notice-error') . '"><pre>';
        echo esc_html(implode("\n", $output));
        echo '</pre></div>';
    }

    // local path
    $path = get_option('git_plugin_local_path', '');

    // Current Active Branch
    $branch_output = [];
    exec('git -C ' . escapeshellarg($path) . ' branch --show-current', $branch_output);

    $current_branch = $branch_output[0] ?? 'unknown';

    // List All Branches
    $branches = [];
    exec('git -C ' . escapeshellarg($path) . ' branch --format="%(refname:short)"', $branches);
    ?>
    <div class="wrap">

        <h1>Git Plugin Settings</h1>

        <?php settings_errors(); ?>

        <form method="post" action="options.php">
            <?php
            settings_fields('git_plugin_settings_group');
            ?>

            <table class="form-table">
                <tr>
                    <th scope="row">Local Repository Path</th>
                    <td>
                        <input type="text" name="git_plugin_local_path" value="<?php echo esc_attr($path); ?>"
                            class="regular-text" placeholder="C:\xampp\htdocs\git">
                    </td>
                    <td>
                        <?php submit_button(); ?>
                    </td>
                </tr>
            </table>
        </form>
        <div class="current_branch_wrapper">
            <div class="current-branch">
                <p>Current Active Branch:</p>
                <p>
                    <?php echo $current_branch ?>
                </p>
            </div>
            <form method="post" class="test-repo-form">
                <?php wp_nonce_field('test_git_repo_action', 'test_git_repo_nonce'); ?>

                <input type="hidden" name="test_git_repo" value="1">

                <button type="submit" class="button button-secondary">
                    🔗 Test Repository
                </button>
            </form>
        </div>
        <?php if (!empty($path)): ?>

            <h2>Repository Info</h2>

            <p><strong>Current Branch:</strong>
                <?php echo esc_html($current_branch); ?>
            </p>

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

        <?php endif; ?>
    </div>
    <?php
}