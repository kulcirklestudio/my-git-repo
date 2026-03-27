<?php
function git_plugin_finish_action($action, $status, $message, $type = 'error', $path = '')
{
    git_plugin_add_notice($action, $message, $type);
    git_plugin_record_activity($action, $status, $message, $path);
}

function git_plugin_handle_connect_remote()
{
    $path = git_plugin_get_configured_local_path();
    $remote_url = git_plugin_get_saved_remote_url();

    if ($path === false) {
        return;
    }

    if ($remote_url === '') {
        git_plugin_finish_action('connect_remote', 'failed', 'Set a remote URL before connecting.', 'error', $path);
        return;
    }

    $path_was_repo = git_is_git_repository($path);
    $directory_has_files = git_directory_has_non_git_files($path);

    if (!$path_was_repo) {
        $init_result = run_git_command($path, 'init');

        if ($init_result['status'] !== 0) {
            git_plugin_finish_action('connect_remote', 'failed', git_plugin_format_result_message($init_result, '', 'Repository initialization failed.'), 'error', $path);
            return;
        }

        git_plugin_finish_action('connect_remote', 'success', 'Initialized a new Git repository in the selected local path.', 'updated', $path);
    }

    $remote_name = git_find_remote_by_url($path, $remote_url);

    if ($remote_name !== '') {
        git_plugin_finish_action('connect_remote', 'success', 'Repository is already connected to this remote using "' . $remote_name . '".', 'updated', $path);
    } else {
        $origin_url = git_get_remote_url($path, 'origin');

        if ($origin_url !== '') {
            $connect_result = run_git_command($path, 'remote set-url origin ' . escapeshellarg($remote_url));
            $remote_name = 'origin';
            $success_message = 'Updated the origin remote URL.';
        } else {
            $connect_result = run_git_command($path, 'remote add origin ' . escapeshellarg($remote_url));
            $remote_name = 'origin';
            $success_message = 'Connected the local repository to the remote as origin.';
        }

        if ($connect_result['status'] !== 0) {
            git_plugin_finish_action('connect_remote', 'failed', git_plugin_format_result_message($connect_result, '', 'Remote connection failed.'), 'error', $path);
            return;
        }

        git_plugin_finish_action('connect_remote', 'success', $success_message, 'updated', $path);
    }

    $fetch_result = run_git_command($path, 'fetch ' . escapeshellarg($remote_name) . ' --prune');

    if ($fetch_result['status'] !== 0) {
        git_plugin_finish_action('connect_remote', 'failed', git_plugin_format_result_message($fetch_result, '', 'Remote was saved, but fetch failed.'), 'error', $path);
        return;
    }

    git_plugin_finish_action('connect_remote', 'success', 'Fetched the latest remote references.', 'updated', $path);

    $current_branch = git_get_current_branch($path);
    $default_branch = git_get_default_branch($path, $remote_name);

    if ($current_branch === '' && $default_branch !== '') {
        if ($directory_has_files) {
            git_plugin_finish_action('connect_remote', 'success', 'Remote was connected, but no branch was checked out automatically because the local folder already contains files.', 'updated', $path);
            return;
        }

        $checkout_result = run_git_command(
            $path,
            'checkout -B ' . escapeshellarg($default_branch) . ' --track ' . escapeshellarg($remote_name . '/' . $default_branch)
        );

        if ($checkout_result['status'] !== 0) {
            git_plugin_finish_action('connect_remote', 'failed', git_plugin_format_result_message($checkout_result, '', 'Remote was connected, but automatic checkout of the default branch failed.'), 'error', $path);
            return;
        }

        git_plugin_finish_action('connect_remote', 'success', 'Checked out the remote default branch "' . $default_branch . '".', 'updated', $path);
    }
}

function git_plugin_handle_health_check()
{
    $path = git_plugin_get_configured_local_path();
    $remote_url = git_plugin_get_saved_remote_url();
    $report = git_plugin_build_health_report($path, $remote_url);

    git_plugin_save_health_report($report);
    git_plugin_finish_action('health_check', 'success', git_plugin_render_health_summary($report), 'updated', $path ?: '');
}

function git_plugin_handle_backup_current_branch()
{
    $path = git_get_valid_repo_path();

    if (!$path) {
        return;
    }

    $current_branch = git_get_current_branch($path);

    if ($current_branch === '') {
        git_plugin_finish_action('backup_branch', 'failed', 'Could not detect the current branch.', 'error', $path);
        return;
    }

    $backup = git_plugin_create_backup_branch($path, 'manual-' . $current_branch, 'HEAD');

    if ($backup['result']['status'] !== 0) {
        git_plugin_finish_action('backup_branch', 'failed', git_plugin_format_result_message($backup['result'], '', 'Backup branch creation failed.'), 'error', $path);
        return;
    }

    git_plugin_finish_action('backup_branch', 'success', 'Created backup branch "' . $backup['name'] . '" from "' . $current_branch . '".', 'updated', $path);
}

add_action('admin_init', function () {
    if (!current_user_can('manage_options')) {
        return;
    }

    if (
        isset($_POST['git_connect_remote'], $_POST['git_connect_remote_nonce']) &&
        wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['git_connect_remote_nonce'])), 'git_connect_remote_action')
    ) {
        git_plugin_handle_connect_remote();
    }

    if (
        isset($_POST['git_health_check'], $_POST['git_health_check_nonce']) &&
        wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['git_health_check_nonce'])), 'git_health_check_action')
    ) {
        git_plugin_handle_health_check();
    }

    if (
        isset($_POST['git_backup_branch'], $_POST['git_backup_branch_nonce']) &&
        wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['git_backup_branch_nonce'])), 'git_backup_branch_action')
    ) {
        git_plugin_handle_backup_current_branch();
    }

    if (
        isset($_POST['test_git_repo'], $_POST['test_git_repo_nonce']) &&
        wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['test_git_repo_nonce'])), 'test_git_repo_action')
    ) {
        $path = git_get_valid_repo_path();

        if (!$path) {
            return;
        }

        $result = run_git_command($path, 'status');
        git_plugin_finish_action('debug_repo', $result['status'] === 0 ? 'success' : 'failed', git_plugin_format_result_message($result, 'Repository debug completed.', 'Repository debug failed.'), $result['status'] === 0 ? 'updated' : 'error', $path);
    }

    if (
        isset($_POST['switch_branch'], $_POST['select_branch_nonce']) &&
        wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['select_branch_nonce'])), 'select_branch_action')
    ) {
        $path = git_get_valid_repo_path();

        if (!$path) {
            return;
        }

        $branch = git_validate_branch_name($path, sanitize_text_field(wp_unslash($_POST['branch'] ?? '')));

        if (!$branch) {
            return;
        }

        if (!git_branch_exists($path, $branch)) {
            git_plugin_finish_action('switch_branch', 'failed', 'Selected branch does not exist.', 'error', $path);
            return;
        }

        if (!git_is_repo_clean($path)) {
            git_plugin_finish_action('switch_branch', 'failed', 'Commit or stash changes before switching branches.', 'error', $path);
            return;
        }

        if ($branch === git_get_current_branch($path)) {
            git_plugin_finish_action('switch_branch', 'success', 'That branch is already active.', 'updated', $path);
            return;
        }

        $result = run_git_command($path, 'checkout ' . escapeshellarg($branch));
        git_plugin_finish_action('switch_branch', $result['status'] === 0 ? 'success' : 'failed', git_plugin_format_result_message($result, 'Branch switched successfully.', 'Branch switch failed.'), $result['status'] === 0 ? 'updated' : 'error', $path);
    }

    if (
        isset($_POST['git_commit'], $_POST['git_commit_nonce']) &&
        wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['git_commit_nonce'])), 'git_commit_action')
    ) {
        $path = git_get_valid_repo_path();
        $message = sanitize_text_field(wp_unslash($_POST['commit_message'] ?? ''));

        if (!$path || $message === '') {
            return;
        }

        if (!git_plugin_check_protected_branch_policy($path, 'commit')) {
            git_plugin_record_activity('commit', 'blocked', 'Protected branch policy blocked a direct commit.', $path);
            return;
        }

        if (git_is_repo_clean($path)) {
            git_plugin_finish_action('commit', 'failed', 'No changes to commit.', 'error', $path);
            return;
        }

        $stage_result = run_git_command($path, 'add -A');

        if ($stage_result['status'] !== 0) {
            git_plugin_finish_action('commit', 'failed', git_plugin_format_result_message($stage_result, '', 'Staging failed.'), 'error', $path);
            return;
        }

        $result = run_git_command($path, 'commit -m ' . escapeshellarg($message));
        git_plugin_finish_action('commit', $result['status'] === 0 ? 'success' : 'failed', git_plugin_format_result_message($result, 'Commit completed.', 'Commit failed.'), $result['status'] === 0 ? 'updated' : 'error', $path);
    }

    if (
        isset($_POST['git_pull'], $_POST['git_pull_nonce']) &&
        wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['git_pull_nonce'])), 'git_pull_action')
    ) {
        $path = git_get_valid_repo_path();

        if (!$path) {
            return;
        }

        if (!git_is_repo_clean($path)) {
            git_plugin_finish_action('pull', 'failed', 'Commit or stash changes before pulling.', 'error', $path);
            return;
        }

        if (!git_has_remote($path)) {
            git_plugin_finish_action('pull', 'failed', 'No remote repository is connected yet.', 'error', $path);
            return;
        }

        $upstream_check = run_git_command($path, 'rev-parse --abbrev-ref --symbolic-full-name @{u}');

        if ($upstream_check['status'] !== 0) {
            git_plugin_finish_action('pull', 'failed', 'Current branch has no upstream branch. Push once to set it.', 'error', $path);
            return;
        }

        $result = run_git_command($path, 'pull');
        git_plugin_finish_action('pull', $result['status'] === 0 ? 'success' : 'failed', git_plugin_format_result_message($result, 'Pull completed.', 'Pull failed.'), $result['status'] === 0 ? 'updated' : 'error', $path);
    }

    if (
        isset($_POST['git_push'], $_POST['git_push_nonce']) &&
        wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['git_push_nonce'])), 'git_push_action')
    ) {
        $path = git_get_valid_repo_path();

        if (!$path) {
            return;
        }

        if (!git_plugin_check_protected_branch_policy($path, 'push')) {
            git_plugin_record_activity('push', 'blocked', 'Protected branch policy blocked a direct push.', $path);
            return;
        }

        if (!git_is_repo_clean($path)) {
            git_plugin_finish_action('push', 'failed', 'Commit changes before pushing.', 'error', $path);
            return;
        }

        $remote_name = git_get_primary_remote($path);

        if ($remote_name === '') {
            git_plugin_finish_action('push', 'failed', 'No remote repository is connected yet.', 'error', $path);
            return;
        }

        $branch = git_get_current_branch($path);

        if ($branch === '') {
            git_plugin_finish_action('push', 'failed', 'Could not detect the current branch.', 'error', $path);
            return;
        }

        $upstream_check = run_git_command($path, 'rev-parse --abbrev-ref --symbolic-full-name @{u}');
        $result = $upstream_check['status'] !== 0
            ? run_git_command($path, 'push -u ' . escapeshellarg($remote_name) . ' ' . escapeshellarg($branch))
            : run_git_command($path, 'push');

        git_plugin_finish_action('push', $result['status'] === 0 ? 'success' : 'failed', git_plugin_format_result_message($result, 'Push completed.', 'Push failed.'), $result['status'] === 0 ? 'updated' : 'error', $path);
    }

    if (
        isset($_POST['git_create_branch'], $_POST['git_create_branch_nonce']) &&
        wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['git_create_branch_nonce'])), 'git_create_branch_action')
    ) {
        $path = git_get_valid_repo_path();

        if (!$path) {
            return;
        }

        $branch = git_validate_branch_name($path, sanitize_text_field(wp_unslash($_POST['new_branch'] ?? '')));

        if (!$branch) {
            return;
        }

        if (!git_is_repo_clean($path)) {
            git_plugin_finish_action('create_branch', 'failed', 'Commit changes before creating a branch.', 'error', $path);
            return;
        }

        if (git_branch_exists($path, $branch)) {
            git_plugin_finish_action('create_branch', 'failed', 'Branch already exists.', 'error', $path);
            return;
        }

        $result = run_git_command($path, 'checkout -b ' . escapeshellarg($branch));
        git_plugin_finish_action('create_branch', $result['status'] === 0 ? 'success' : 'failed', git_plugin_format_result_message($result, 'Branch created.', 'Branch creation failed.'), $result['status'] === 0 ? 'updated' : 'error', $path);
    }

    if (
        isset($_POST['git_merge_branch'], $_POST['git_merge_branch_nonce']) &&
        wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['git_merge_branch_nonce'])), 'git_merge_branch_action')
    ) {
        $path = git_get_valid_repo_path();

        if (!$path) {
            return;
        }

        $branch = git_validate_branch_name($path, sanitize_text_field(wp_unslash($_POST['merge_branch'] ?? '')));

        if (!$branch) {
            return;
        }

        if (!git_branch_exists($path, $branch)) {
            git_plugin_finish_action('merge', 'failed', 'Selected branch does not exist.', 'error', $path);
            return;
        }

        if ($branch === git_get_current_branch($path)) {
            git_plugin_finish_action('merge', 'failed', 'Cannot merge the active branch into itself.', 'error', $path);
            return;
        }

        if (!git_is_repo_clean($path)) {
            git_plugin_finish_action('merge', 'failed', 'Commit changes before merging.', 'error', $path);
            return;
        }

        $result = run_git_command($path, 'merge ' . escapeshellarg($branch));
        git_plugin_finish_action('merge', $result['status'] === 0 ? 'success' : 'failed', git_plugin_format_result_message($result, 'Merge completed.', 'Merge failed.'), $result['status'] === 0 ? 'updated' : 'error', $path);
    }

    if (
        isset($_POST['git_delete_branch'], $_POST['git_delete_branch_nonce']) &&
        wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['git_delete_branch_nonce'])), 'git_delete_branch_action')
    ) {
        $path = git_get_valid_repo_path();

        if (!$path) {
            return;
        }

        $branch = git_validate_branch_name($path, sanitize_text_field(wp_unslash($_POST['delete_branch'] ?? '')));

        if (!$branch) {
            return;
        }

        if (!git_branch_exists($path, $branch)) {
            git_plugin_finish_action('delete_branch', 'failed', 'Selected branch does not exist.', 'error', $path);
            return;
        }

        if (!git_is_repo_clean($path)) {
            git_plugin_finish_action('delete_branch', 'failed', 'Commit changes before deleting a branch.', 'error', $path);
            return;
        }

        $current_branch = git_get_current_branch($path);

        if ($branch === $current_branch) {
            git_plugin_finish_action('delete_branch', 'failed', 'Cannot delete the active branch.', 'error', $path);
            return;
        }

        $default_branch = git_get_default_branch($path);

        if ($default_branch !== '' && $branch === $default_branch) {
            git_plugin_finish_action('delete_branch', 'failed', 'Cannot delete the default branch.', 'error', $path);
            return;
        }

        $result = run_git_command($path, 'branch -d ' . escapeshellarg($branch));
        git_plugin_finish_action('delete_branch', $result['status'] === 0 ? 'success' : 'failed', git_plugin_format_result_message($result, 'Branch deleted.', 'Branch deletion failed.'), $result['status'] === 0 ? 'updated' : 'error', $path);

        if ($result['status'] !== 0 || empty($_POST['delete_remote'])) {
            return;
        }

        $remote_name = git_get_primary_remote($path);

        if ($remote_name === '') {
            git_plugin_finish_action('delete_branch', 'failed', 'No remote found for deletion.', 'error', $path);
            return;
        }

        $remote_result = run_git_command($path, 'push ' . escapeshellarg($remote_name) . ' --delete ' . escapeshellarg($branch));
        git_plugin_finish_action('delete_branch', $remote_result['status'] === 0 ? 'success' : 'failed', git_plugin_format_result_message($remote_result, 'Remote branch deleted.', 'Remote branch deletion failed.'), $remote_result['status'] === 0 ? 'updated' : 'error', $path);
    }
});
