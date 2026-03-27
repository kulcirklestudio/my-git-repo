<?php
function render_git_settings_page()
{
    git_plugin_restore_persisted_notices();

    $saved_path = git_plugin_get_saved_local_path();
    $remote_url = git_plugin_get_saved_remote_url();
    $git_binary = git_plugin_get_git_binary();
    $author_name = git_plugin_get_saved_author_name();
    $author_email = git_plugin_get_saved_author_email();
    $allow_protected_direct_changes = git_plugin_allow_protected_direct_changes();
    $protected_branches = implode("\n", git_plugin_get_protected_branch_patterns());
    $path = git_plugin_resolve_local_path($saved_path);
    $is_repo = $path ? git_is_git_repository($path) : false;
    $health_report = git_plugin_get_health_report();
    $activity_log = array_slice(git_plugin_get_activity_log(), 0, 8);

    $current_branch = 'N/A';
    $branches = [];
    $remote_output = [];
    $repo_status = [];
    $primary_remote = '';
    $default_branch = '';
    $connected_remote_name = '';
    $repo_identity_name = '';
    $repo_identity_email = '';

    if ($is_repo) {
        $current_branch = git_get_current_branch($path);
        $branches = git_list_local_branches($path);
        $primary_remote = git_get_primary_remote($path);
        $default_branch = git_get_default_branch($path, $primary_remote);
        $remote_result = run_git_command($path, 'remote -v');
        $remote_output = $remote_result['status'] === 0 ? git_plugin_mask_remote_output($remote_result['output']) : [];
        $status_result = run_git_command($path, 'status --porcelain');
        $repo_status = $status_result['status'] === 0 ? $status_result['output'] : [];
        $repo_identity = git_plugin_get_repo_identity($path);
        $repo_identity_name = $repo_identity['name'];
        $repo_identity_email = $repo_identity['email'];

        if ($remote_url !== '') {
            $connected_remote_name = git_find_remote_by_url($path, $remote_url);
        }
    }
    ?>

    <div class="wrap git-plugin-app">
        <div class="hero-card">
            <div class="hero-copy">
                <p class="eyebrow">Git Connect</p>
                <h1>Manage your repository without using the terminal</h1>
                <p class="hero-text">Set the local folder and remote URL once, then use the guided actions below for commit, pull, push, branch switching, merging, deleting, and manual backup.</p>
            </div>
            <div class="hero-status">
                <div class="status-pill <?php echo esc_attr($is_repo ? 'is-ready' : 'is-pending'); ?>">
                    <?php echo esc_html($is_repo ? 'Repository Ready' : 'Setup Needed'); ?>
                </div>
                <div class="status-meta">
                    <span>Branch: <?php echo esc_html($current_branch !== '' ? $current_branch : 'unknown'); ?></span>
                    <span>Remote: <?php echo esc_html($primary_remote !== '' ? $primary_remote : 'not connected'); ?></span>
                </div>
            </div>
        </div>

        <?php settings_errors(); ?>

        <div class="dashboard-grid">
            <div class="main-column">
                <section class="panel-card">
                    <div class="panel-head">
                        <div>
                            <p class="panel-kicker">Step 1</p>
                            <h2>Connect Repository</h2>
                        </div>
                    </div>

                    <form method="post" action="options.php" class="stack-form">
                        <?php settings_fields('git_plugin_settings_group'); ?>

                        <label class="field-block">
                            <span class="field-label">Local Repository Path</span>
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
                            <span class="field-help">Stored encrypted in the database and hidden on this page by default.</span>
                        </label>

                        <label class="field-block">
                            <span class="field-label">Remote Repository URL</span>
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
                            <span class="field-help">Use HTTPS, SSH, or a local bare repository path.</span>
                        </label>

                        <label class="field-block">
                            <span class="field-label">Git Executable</span>
                            <input
                                type="text"
                                name="git_plugin_git_binary"
                                value="<?php echo esc_attr($git_binary); ?>"
                                placeholder="C:\Program Files\Git\cmd\git.exe">
                            <span class="field-help">Use a full path like `C:\Program Files\Git\cmd\git.exe` if the web server cannot find `git` in PATH.</span>
                        </label>

                        <div class="field-split">
                            <label class="field-block">
                                <span class="field-label">Author Name</span>
                                <input
                                    type="text"
                                    name="git_plugin_author_name"
                                    value="<?php echo esc_attr($author_name); ?>"
                                    placeholder="Your Name">
                                <span class="field-help">Used to configure the repository automatically if `user.name` is missing.</span>
                            </label>

                            <label class="field-block">
                                <span class="field-label">Author Email</span>
                                <input
                                    type="text"
                                    name="git_plugin_author_email"
                                    value="<?php echo esc_attr($author_email); ?>"
                                    placeholder="you@example.com">
                                <span class="field-help">Used to configure the repository automatically if `user.email` is missing.</span>
                            </label>
                        </div>

                        <label class="field-block">
                            <span class="field-label">Protected Branches</span>
                            <textarea name="git_plugin_protected_branches" rows="4" placeholder="main&#10;master&#10;release/*"><?php echo esc_textarea($protected_branches); ?></textarea>
                            <span class="field-help">One branch pattern per line. `*` is allowed, for example `release/*`.</span>
                        </label>

                        <label class="toggle-card">
                            <input type="hidden" name="git_plugin_allow_protected_direct_changes" value="0">
                            <input type="checkbox" name="git_plugin_allow_protected_direct_changes" value="1" <?php checked($allow_protected_direct_changes); ?>>
                            <span>
                                <strong>Allow direct commits, pushes, and merges on protected branches</strong>
                                <small>Keep this off for safer beginner workflows.</small>
                            </span>
                        </label>

                        <?php submit_button('Save Settings'); ?>
                    </form>

                    <div class="action-strip">
                        <form method="post" onsubmit="return confirm('Connect or update the remote repository for this local folder?');">
                            <?php wp_nonce_field('git_connect_remote_action', 'git_connect_remote_nonce'); ?>
                            <button type="submit" name="git_connect_remote" class="button button-primary">
                                <?php echo esc_html($is_repo ? 'Connect or Update Remote' : 'Initialize and Connect'); ?>
                            </button>
                        </form>

                        <form method="post">
                            <?php wp_nonce_field('git_health_check_action', 'git_health_check_nonce'); ?>
                            <button type="submit" name="git_health_check" class="button button-secondary">Run Health Check</button>
                        </form>
                    </div>
                </section>

                <section class="panel-card">
                    <div class="panel-head">
                        <div>
                            <p class="panel-kicker">Step 2</p>
                            <h2>Current Status</h2>
                        </div>
                    </div>

                    <div class="summary-grid">
                        <div class="summary-tile">
                            <span class="summary-label">Local Folder</span>
                            <strong><?php echo esc_html($path ? git_plugin_mask_path($path) : 'Not available'); ?></strong>
                        </div>
                        <div class="summary-tile">
                            <span class="summary-label">Current Branch</span>
                            <strong><?php echo esc_html($current_branch !== '' ? $current_branch : 'unknown'); ?></strong>
                        </div>
                        <div class="summary-tile">
                            <span class="summary-label">Default Branch</span>
                            <strong><?php echo esc_html($default_branch !== '' ? $default_branch : 'unknown'); ?></strong>
                        </div>
                        <div class="summary-tile">
                            <span class="summary-label">Connected Remote</span>
                            <strong><?php echo esc_html($primary_remote !== '' ? $primary_remote : 'Not connected'); ?></strong>
                        </div>
                        <div class="summary-tile">
                            <span class="summary-label">Author Identity</span>
                            <strong><?php echo esc_html(($repo_identity_name !== '' && $repo_identity_email !== '') ? ($repo_identity_name . ' <' . $repo_identity_email . '>') : 'Not configured'); ?></strong>
                        </div>
                    </div>

                    <?php if (!$saved_path): ?>
                        <div class="notice notice-warning"><p>Save the local path first.</p></div>
                    <?php elseif (!$path): ?>
                        <div class="notice notice-error"><p>The saved local path does not exist on this server.</p></div>
                    <?php elseif (!$is_repo): ?>
                        <div class="notice notice-warning"><p>This folder exists but is not a Git repository yet. Use the connect button above to initialize it.</p></div>
                    <?php else: ?>
                        <?php if (git_is_protected_branch($current_branch) && !$allow_protected_direct_changes): ?>
                            <div class="notice notice-warning"><p>Protected branch mode is active. Direct commit, push, and merge into this branch are blocked.</p></div>
                        <?php endif; ?>

                        <div class="subpanel">
                            <h3>Remote Details</h3>
                            <?php if (empty($remote_output)): ?>
                                <p class="empty-state">No remote repository is currently configured.</p>
                            <?php else: ?>
                                <pre><?php echo esc_html(implode("\n", $remote_output)); ?></pre>
                            <?php endif; ?>

                            <?php if ($remote_url !== '' && $connected_remote_name !== ''): ?>
                                <p class="inline-success">Saved remote URL is already connected as <strong><?php echo esc_html($connected_remote_name); ?></strong>.</p>
                            <?php elseif ($remote_url !== '' && !empty($remote_output)): ?>
                                <p class="inline-warning">A remote exists, but it does not match the saved remote URL yet.</p>
                            <?php endif; ?>
                        </div>

                        <div class="subpanel">
                            <h3>Repository Changes</h3>
                            <?php if (empty($repo_status)): ?>
                                <p class="clean-state">Clean working tree.</p>
                            <?php else: ?>
                                <pre><?php echo esc_html(implode("\n", $repo_status)); ?></pre>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </section>

                <?php if ($is_repo): ?>
                    <section class="panel-card">
                        <div class="panel-head">
                            <div>
                                <p class="panel-kicker">Step 3</p>
                                <h2>Daily Actions</h2>
                            </div>
                        </div>

                        <div class="action-group">
                            <div class="task-card">
                                <h3>Pull Latest Changes</h3>
                                <p>Bring the newest changes from the connected remote branch.</p>
                                <form method="post">
                                    <?php wp_nonce_field('git_pull_action', 'git_pull_nonce'); ?>
                                    <button type="submit" name="git_pull" class="button button-primary">Pull from Remote</button>
                                </form>
                            </div>

                            <div class="task-card">
                                <h3>Commit Changes</h3>
                                <p>Save your current local modifications into Git.</p>
                                <?php if (empty($repo_status)): ?>
                                    <p class="empty-state">No changes to commit.</p>
                                <?php else: ?>
                                    <form method="post" class="task-form">
                                        <?php wp_nonce_field('git_commit_action', 'git_commit_nonce'); ?>
                                        <input type="text" name="commit_message" placeholder="Enter commit message" required>
                                        <button type="submit" name="git_commit" class="button button-primary">Commit</button>
                                    </form>
                                <?php endif; ?>
                            </div>

                            <div class="task-card">
                                <h3>Push Changes</h3>
                                <p>Send your local commits to the connected remote repository.</p>
                                <form method="post">
                                    <?php wp_nonce_field('git_push_action', 'git_push_nonce'); ?>
                                    <button type="submit" name="git_push" class="button button-primary">Push to Remote</button>
                                </form>
                            </div>

                            <div class="task-card">
                                <h3>Backup Current Branch</h3>
                                <p>Create a backup branch from the current branch before you do risky work.</p>
                                <form method="post" onsubmit="return confirm('Create a backup branch from the current branch?');">
                                    <?php wp_nonce_field('git_backup_branch_action', 'git_backup_branch_nonce'); ?>
                                    <button type="submit" name="git_backup_branch" class="button button-secondary">Create Backup Branch</button>
                                </form>
                            </div>
                        </div>
                    </section>

                    <section class="panel-card">
                        <div class="panel-head">
                            <div>
                                <p class="panel-kicker">Step 4</p>
                                <h2>Branch Management</h2>
                            </div>
                        </div>

                        <div class="action-group branch-group">
                            <div class="task-card">
                                <h3>Switch Branch</h3>
                                <form method="post" class="task-form">
                                    <?php wp_nonce_field('select_branch_action', 'select_branch_nonce'); ?>
                                    <select name="branch">
                                        <?php foreach ($branches as $branch): ?>
                                            <option value="<?php echo esc_attr($branch); ?>" <?php selected($branch, $current_branch); ?>><?php echo esc_html($branch); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit" name="switch_branch" class="button">Switch</button>
                                </form>
                            </div>

                            <div class="task-card">
                                <h3>Create Branch</h3>
                                <form method="post" class="task-form">
                                    <?php wp_nonce_field('git_create_branch_action', 'git_create_branch_nonce'); ?>
                                    <input type="text" name="new_branch" placeholder="Enter new branch name" required>
                                    <button type="submit" name="git_create_branch" class="button button-primary">Create & Switch</button>
                                </form>
                            </div>

                            <div class="task-card">
                                <h3>Merge Branch</h3>
                                <p>Merge another branch into the branch you are currently on.</p>
                                <form method="post" class="task-form" onsubmit="return confirm('Merge the selected branch into the current branch?');">
                                    <?php wp_nonce_field('git_merge_branch_action', 'git_merge_branch_nonce'); ?>
                                    <select name="merge_branch">
                                        <?php foreach ($branches as $branch): ?>
                                            <?php if ($branch !== $current_branch): ?>
                                                <option value="<?php echo esc_attr($branch); ?>"><?php echo esc_html($branch); ?></option>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit" name="git_merge_branch" class="button button-primary">Merge</button>
                                </form>
                            </div>

                            <div class="task-card danger-card">
                                <h3>Delete Branch</h3>
                                <p>Delete a branch that is not active and not protected.</p>
                                <form method="post" class="task-form" onsubmit="return confirm('Delete the selected branch? If remote delete is checked, the remote branch will also be removed.');">
                                    <?php wp_nonce_field('git_delete_branch_action', 'git_delete_branch_nonce'); ?>
                                    <select name="delete_branch">
                                        <?php foreach ($branches as $branch): ?>
                                            <?php if ($branch === $current_branch || ($default_branch !== '' && $branch === $default_branch)) { continue; } ?>
                                            <option value="<?php echo esc_attr($branch); ?>"><?php echo esc_html($branch); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <label class="delete-remote-chkbx">
                                        <input type="checkbox" name="delete_remote" value="1">
                                        Delete from remote too
                                    </label>
                                    <button type="submit" name="git_delete_branch" class="button button-danger">Delete</button>
                                </form>
                            </div>
                        </div>
                    </section>
                <?php endif; ?>
            </div>

            <aside class="side-column">
                <section class="panel-card side-card">
                    <div class="panel-head">
                        <div>
                            <p class="panel-kicker">Safety</p>
                            <h2>Health Check</h2>
                        </div>
                    </div>

                    <?php if (!empty($health_report['checks'])): ?>
                        <p class="field-help health-meta">Last generated: <?php echo esc_html($health_report['generated_at'] ?? 'unknown'); ?></p>
                        <div class="health-stack">
                            <?php foreach ($health_report['checks'] as $check): ?>
                                <div class="health-item health-<?php echo esc_attr($check['status']); ?>">
                                    <div class="health-top">
                                        <strong><?php echo esc_html($check['label']); ?></strong>
                                        <span><?php echo esc_html(strtoupper($check['status'])); ?></span>
                                    </div>
                                    <p><?php echo esc_html($check['details']); ?></p>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="empty-state health-meta">No health report yet. Run a health check after saving your settings.</p>
                    <?php endif; ?>
                </section>

                <section class="panel-card side-card">
                    <div class="panel-head">
                        <div>
                            <p class="panel-kicker">History</p>
                            <h2>Recent Activity</h2>
                        </div>
                    </div>

                    <?php if (!empty($activity_log)): ?>
                        <div class="timeline">
                            <?php foreach ($activity_log as $entry): ?>
                                <div class="timeline-item">
                                    <div class="timeline-top">
                                        <strong><?php echo esc_html($entry['action'] ?? ''); ?></strong>
                                        <span><?php echo esc_html($entry['status'] ?? ''); ?></span>
                                    </div>
                                    <p class="timeline-meta"><?php echo esc_html(($entry['time'] ?? '') . ' by ' . ($entry['user_login'] ?? '')); ?></p>
                                    <?php if (!empty($entry['path'])): ?>
                                        <p class="timeline-path"><?php echo esc_html($entry['path']); ?></p>
                                    <?php endif; ?>
                                    <p><?php echo esc_html($entry['details'] ?? ''); ?></p>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="empty-state health-meta">No activity recorded yet.</p>
                    <?php endif; ?>
                </section>
            </aside>
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
