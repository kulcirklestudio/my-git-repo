<?php
function render_git_settings_page()
{
    $saved_path = git_plugin_get_saved_local_path();
    $remote_url = git_plugin_get_saved_remote_url();
    $allow_protected_direct_changes = git_plugin_allow_protected_direct_changes();
    $path = git_plugin_resolve_local_path($saved_path);
    $is_repo = $path ? git_is_git_repository($path) : false;
    $health_report = git_plugin_get_health_report();
    $activity_log = git_plugin_get_activity_log();

    $current_branch = 'N/A';
    $branches = [];
    $remote_output = [];
    $repo_status = [];
    $primary_remote = '';
    $default_branch = '';
    $connected_remote_name = '';

    if ($is_repo) {
        $current_branch = git_get_current_branch($path);
        $branches = git_list_local_branches($path);
        $primary_remote = git_get_primary_remote($path);
        $default_branch = git_get_default_branch($path, $primary_remote);
        $remote_result = run_git_command($path, 'remote -v');
        $remote_output = $remote_result['status'] === 0 ? git_plugin_mask_remote_output($remote_result['output']) : [];
        $status_result = run_git_command($path, 'status --porcelain');
        $repo_status = $status_result['status'] === 0 ? $status_result['output'] : [];

        if ($remote_url !== '') {
            $connected_remote_name = git_find_remote_by_url($path, $remote_url);
        }
    }
    ?>

    <div class="wrap">
        <h1>Git Plugin Settings</h1>

        <?php settings_errors(); ?>

        <div class="repo-info common-wrapper">
            <p class="sec-title">Repository Information</p>

            <form method="post" action="options.php">
                <?php settings_fields('git_plugin_settings_group'); ?>

                <table class="form-table">
                    <tr>
                        <th>Local Repository Path</th>
                        <td>
                            <div class="secure-input-row">
                                <input
                                    type="password"
                                    name="git_plugin_local_path"
                                    value="<?php echo esc_attr($saved_path); ?>"
                                    class="regular-text git-plugin-sensitive-input"
                                    data-visible-label="Hide"
                                    data-hidden-label="Show"
                                    placeholder="C:\xampp\htdocs\git\my-project"
                                    autocomplete="off">
                                <button type="button" class="button button-secondary git-plugin-toggle-sensitive">Show</button>
                            </div>
                            <p class="description">Choose an existing local folder. It can already be a Git repo or an empty folder for first-time setup.</p>
                            <p class="description">Stored encrypted in the database and hidden on this page by default.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Remote Repository URL</th>
                        <td>
                            <div class="secure-input-row">
                                <input
                                    type="password"
                                    name="git_plugin_remote_url"
                                    value="<?php echo esc_attr($remote_url); ?>"
                                    class="regular-text git-plugin-sensitive-input"
                                    data-visible-label="Hide"
                                    data-hidden-label="Show"
                                    placeholder="https://github.com/example/repo.git"
                                    autocomplete="off">
                                <button type="button" class="button button-secondary git-plugin-toggle-sensitive">Show</button>
                            </div>
                            <p class="description">Examples: HTTPS URL, SSH URL, or a local bare repository path.</p>
                            <p class="description">Stored encrypted in the database and hidden on this page by default.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Protected Branch Policy</th>
                        <td>
                            <label class="settings-checkbox">
                                <input type="hidden" name="git_plugin_allow_protected_direct_changes" value="0">
                                <input type="checkbox" name="git_plugin_allow_protected_direct_changes" value="1" <?php checked($allow_protected_direct_changes); ?>>
                                Allow direct commits and pushes on `main` / `master`
                            </label>
                            <p class="description">Leave this off to force safer workflows on protected branches.</p>
                        </td>
                    </tr>
                </table>

                <?php submit_button('Save Connection Settings'); ?>
            </form>

            <div class="connection-overview">
                <h2>Connection Status</h2>

                <?php if (!$saved_path): ?>
                    <div class="notice notice-warning"><p>Set a local path before using Git actions.</p></div>
                <?php elseif (!$path): ?>
                    <div class="notice notice-error"><p>The saved local path does not exist on this server.</p></div>
                <?php elseif (!$is_repo): ?>
                    <div class="notice notice-warning"><p>This folder exists but is not a Git repository yet. Use the connect button to initialize it and attach the remote.</p></div>
                <?php else: ?>
                    <p><strong>Primary Remote:</strong> <?php echo esc_html($primary_remote !== '' ? $primary_remote : 'Not connected'); ?></p>

                    <?php if (empty($remote_output)): ?>
                        <div class="notice notice-warning"><p>No remote repository is currently configured.</p></div>
                    <?php else: ?>
                        <pre><?php echo esc_html(implode("\n", $remote_output)); ?></pre>
                    <?php endif; ?>

                    <?php if ($remote_url !== '' && $connected_remote_name !== ''): ?>
                        <p class="connection-success">Saved remote URL is already connected as <strong><?php echo esc_html($connected_remote_name); ?></strong>.</p>
                    <?php elseif ($remote_url !== '' && !empty($remote_output)): ?>
                        <p class="connection-warning">A remote is configured, but it does not match the saved remote URL yet.</p>
                    <?php endif; ?>
                <?php endif; ?>

                <div class="action-row">
                    <form method="post" class="connect-form">
                        <?php wp_nonce_field('git_connect_remote_action', 'git_connect_remote_nonce'); ?>
                        <button type="submit" name="git_connect_remote" class="button button-primary">
                            <?php echo esc_html($is_repo ? 'Connect or Update Remote' : 'Initialize and Connect'); ?>
                        </button>
                    </form>

                    <form method="post" class="connect-form">
                        <?php wp_nonce_field('git_health_check_action', 'git_health_check_nonce'); ?>
                        <button type="submit" name="git_health_check" class="button button-secondary">Run Health Check</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="health-report common-wrapper">
            <p class="sec-title">Health Check</p>
            <div class="health-check-body">
                <?php if (!empty($health_report['checks'])): ?>
                    <p class="description">Last generated: <?php echo esc_html($health_report['generated_at'] ?? 'unknown'); ?></p>
                    <div class="health-grid">
                        <?php foreach ($health_report['checks'] as $check): ?>
                            <div class="health-card health-<?php echo esc_attr($check['status']); ?>">
                                <h3><?php echo esc_html($check['label']); ?></h3>
                                <p class="health-status"><?php echo esc_html(strtoupper($check['status'])); ?></p>
                                <pre><?php echo esc_html($check['details']); ?></pre>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p>No health report generated yet.</p>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($is_repo): ?>
            <div class="branch-status common-wrapper">
                <p class="sec-title">Branch Status</p>

                <div class="current_branch_wrapper">
                    <div><strong>Current Branch:</strong><p class="active_branch"><?php echo esc_html($current_branch !== '' ? $current_branch : 'unknown'); ?></p></div>
                    <div><strong>Default Branch:</strong><p class="active_branch default-branch"><?php echo esc_html($default_branch !== '' ? $default_branch : 'unknown'); ?></p></div>
                    <form method="post">
                        <?php wp_nonce_field('test_git_repo_action', 'test_git_repo_nonce'); ?>
                        <input type="hidden" name="test_git_repo" value="1">
                        <button type="submit" class="button button-secondary">Debug Repository</button>
                    </form>
                </div>

                <?php if (git_is_protected_branch($current_branch) && !$allow_protected_direct_changes): ?>
                    <div class="notice notice-warning"><p>Protected branch policy is active. Direct commits and pushes to this branch are blocked.</p></div>
                <?php endif; ?>

                <div class="repo-status">
                    <h2>Repository Status</h2>

                    <?php if (empty($repo_status)): ?>
                        <p style="color:green;"><strong>Clean (no changes)</strong></p>
                    <?php else: ?>
                        <p style="color:red;"><strong>Uncommitted Changes:</strong></p>
                        <pre><?php echo esc_html(implode("\n", $repo_status)); ?></pre>
                    <?php endif; ?>
                </div>
            </div>

            <div class="quick-action common-wrapper">
                <p class="sec-title">Quick Action</p>

                <div class="pull-section">
                    <h2>Pull Latest Changes</h2>
                    <div class="action-row">
                        <form method="post">
                            <?php wp_nonce_field('git_pull_action', 'git_pull_nonce'); ?>
                            <button type="submit" name="git_pull" class="button button-primary">Pull from Remote</button>
                        </form>
                        <form method="post">
                            <?php wp_nonce_field('git_preview_pull_action', 'git_preview_pull_nonce'); ?>
                            <button type="submit" name="git_preview_pull" class="button button-secondary">Preview Pull</button>
                        </form>
                    </div>
                </div>

                <div class="commit-section">
                    <h2>Commit Changes</h2>
                    <?php if (empty($repo_status)): ?>
                        <p>No changes to commit</p>
                    <?php else: ?>
                        <form method="post">
                            <?php wp_nonce_field('git_commit_action', 'git_commit_nonce'); ?>
                            <input type="text" name="commit_message" placeholder="Enter commit message" required>
                            <button type="submit" name="git_commit" class="button button-primary">Commit</button>
                        </form>
                    <?php endif; ?>
                </div>

                <div class="push-section">
                    <h2>Push Changes</h2>
                    <form method="post">
                        <?php wp_nonce_field('git_push_action', 'git_push_nonce'); ?>
                        <button type="submit" name="git_push" class="button button-primary">Push to Remote</button>
                    </form>
                </div>
            </div>

            <div class="branch-management common-wrapper">
                <p class="sec-title">Branch Management</p>

                <div class="repo-dd-wrapper">
                    <h2>Switch Branch</h2>
                    <form method="post">
                        <?php wp_nonce_field('select_branch_action', 'select_branch_nonce'); ?>
                        <select name="branch">
                            <?php foreach ($branches as $branch): ?>
                                <option value="<?php echo esc_attr($branch); ?>" <?php selected($branch, $current_branch); ?>><?php echo esc_html($branch); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" name="switch_branch" class="button">Switch Branch</button>
                    </form>
                </div>

                <div class="create-branch repo-dd-wrapper">
                    <h2>Create Branch</h2>
                    <form method="post">
                        <?php wp_nonce_field('git_create_branch_action', 'git_create_branch_nonce'); ?>
                        <input type="text" name="new_branch" placeholder="Enter branch name" required>
                        <button type="submit" name="git_create_branch" class="button button-primary">Create & Switch</button>
                    </form>
                </div>

                <div class="merge-branch">
                    <h2>Merge Branch</h2>
                    <div class="action-row">
                        <form method="post">
                            <?php wp_nonce_field('git_merge_branch_action', 'git_merge_branch_nonce'); ?>
                            <select name="merge_branch">
                                <?php foreach ($branches as $branch): ?>
                                    <?php if ($branch !== $current_branch): ?>
                                        <option value="<?php echo esc_attr($branch); ?>"><?php echo esc_html($branch); ?></option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" name="git_merge_branch" class="button button-primary">Merge into Current Branch</button>
                        </form>
                        <form method="post">
                            <?php wp_nonce_field('git_preview_merge_action', 'git_preview_merge_nonce'); ?>
                            <select name="merge_branch">
                                <?php foreach ($branches as $branch): ?>
                                    <?php if ($branch !== $current_branch): ?>
                                        <option value="<?php echo esc_attr($branch); ?>"><?php echo esc_html($branch); ?></option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" name="git_preview_merge" class="button button-secondary">Preview Merge</button>
                        </form>
                    </div>
                    <p class="description">A safety backup branch is created automatically before merge.</p>
                </div>

                <div class="delete-branch repo-dd-wrapper">
                    <h2>Delete Branch</h2>
                    <div class="action-row">
                        <form method="post">
                            <?php wp_nonce_field('git_delete_branch_action', 'git_delete_branch_nonce'); ?>
                            <select name="delete_branch">
                                <?php foreach ($branches as $branch): ?>
                                    <?php if ($branch === $current_branch || ($default_branch !== '' && $branch === $default_branch)) { continue; } ?>
                                    <option value="<?php echo esc_attr($branch); ?>"><?php echo esc_html($branch); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <label class="delete-remote-chkbx">
                                <input type="checkbox" name="delete_remote" value="1">
                                Also delete from remote
                            </label>
                            <button type="submit" name="git_delete_branch" class="button button-danger">Delete Branch</button>
                        </form>
                        <form method="post">
                            <?php wp_nonce_field('git_preview_delete_action', 'git_preview_delete_nonce'); ?>
                            <select name="delete_branch">
                                <?php foreach ($branches as $branch): ?>
                                    <?php if ($branch === $current_branch || ($default_branch !== '' && $branch === $default_branch)) { continue; } ?>
                                    <option value="<?php echo esc_attr($branch); ?>"><?php echo esc_html($branch); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" name="git_preview_delete" class="button button-secondary">Preview Delete</button>
                        </form>
                    </div>
                    <p class="description">A safety backup branch is created automatically before deletion.</p>

                    <?php if ($default_branch === ''): ?>
                        <p style="color:orange;">Default branch could not be detected. Deletion rules stay conservative.</p>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="activity-log common-wrapper">
            <p class="sec-title">Activity Log</p>
            <div class="activity-body">
                <?php if (!empty($activity_log)): ?>
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th>Time</th>
                                <th>User</th>
                                <th>Action</th>
                                <th>Status</th>
                                <th>Details</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($activity_log as $entry): ?>
                                <tr>
                                    <td><?php echo esc_html($entry['time'] ?? ''); ?></td>
                                    <td><?php echo esc_html($entry['user_login'] ?? ''); ?></td>
                                    <td><?php echo esc_html($entry['action'] ?? ''); ?></td>
                                    <td><?php echo esc_html($entry['status'] ?? ''); ?></td>
                                    <td><pre><?php echo esc_html($entry['details'] ?? ''); ?></pre></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p>No activity recorded yet.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            document.querySelectorAll('.git-plugin-toggle-sensitive').forEach(function (button) {
                button.addEventListener('click', function () {
                    var wrapper = button.closest('.secure-input-row');
                    var input = wrapper ? wrapper.querySelector('.git-plugin-sensitive-input') : null;

                    if (!input) {
                        return;
                    }

                    var showing = input.type === 'text';
                    input.type = showing ? 'password' : 'text';
                    button.textContent = showing ? (input.dataset.hiddenLabel || 'Show') : (input.dataset.visibleLabel || 'Hide');
                });
            });
        });
    </script>
    <?php
}
