<?php
function git_plugin_get_raw_option($option_name)
{
    return get_option($option_name, '');
}

function run_git_command($path, $command)
{
    $output = [];
    $status = 0;

    exec('git -C ' . escapeshellarg($path) . ' ' . $command . ' 2>&1', $output, $status);

    return [
        'output' => $output,
        'status' => $status,
    ];
}

function git_plugin_run_git_binary_command($command)
{
    $output = [];
    $status = 0;

    exec('git ' . $command . ' 2>&1', $output, $status);

    return [
        'output' => $output,
        'status' => $status,
    ];
}

function git_plugin_add_notice($code, $message, $type = 'error')
{
    add_settings_error('git_plugin', $code, $message, $type);
}

function git_plugin_sanitize_checkbox($value)
{
    return empty($value) ? '0' : '1';
}

function git_plugin_get_encryption_key()
{
    return hash('sha256', wp_salt('auth'), true);
}

function git_plugin_encrypt_value($value)
{
    $value = (string) $value;

    if ($value === '' || !function_exists('openssl_encrypt')) {
        return $value;
    }

    $iv = random_bytes(16);
    $ciphertext = openssl_encrypt(
        $value,
        'AES-256-CBC',
        git_plugin_get_encryption_key(),
        OPENSSL_RAW_DATA,
        $iv
    );

    if ($ciphertext === false) {
        return $value;
    }

    return 'gitc:v1:' . base64_encode($iv . $ciphertext);
}

function git_plugin_maybe_decrypt_value($value)
{
    $value = (string) $value;

    if ($value === '') {
        return '';
    }

    if (strpos($value, 'gitc:v1:') !== 0) {
        return $value;
    }

    if (!function_exists('openssl_decrypt')) {
        return '';
    }

    $payload = base64_decode(substr($value, 8), true);

    if ($payload === false || strlen($payload) <= 16) {
        return '';
    }

    $iv = substr($payload, 0, 16);
    $ciphertext = substr($payload, 16);
    $plaintext = openssl_decrypt(
        $ciphertext,
        'AES-256-CBC',
        git_plugin_get_encryption_key(),
        OPENSSL_RAW_DATA,
        $iv
    );

    return $plaintext === false ? '' : $plaintext;
}

function git_plugin_get_saved_local_path()
{
    return trim((string) git_plugin_maybe_decrypt_value(git_plugin_get_raw_option('git_plugin_local_path')));
}

function git_plugin_get_saved_remote_url()
{
    return trim((string) git_plugin_maybe_decrypt_value(git_plugin_get_raw_option('git_plugin_remote_url')));
}

function git_plugin_allow_protected_direct_changes()
{
    return get_option('git_plugin_allow_protected_direct_changes', '0') === '1';
}

function git_plugin_resolve_local_path($path)
{
    $path = trim((string) $path);

    if ($path === '') {
        return false;
    }

    $real = realpath($path);

    if ($real === false || !is_dir($real)) {
        return false;
    }

    return $real;
}

function git_plugin_validate_local_path($path)
{
    $path = trim((string) $path);

    if ($path === '') {
        return '';
    }

    $real = git_plugin_resolve_local_path($path);

    if ($real === false) {
        add_settings_error('git_plugin_local_path', 'invalid_path', 'Invalid path or folder does not exist.');
        return git_plugin_get_raw_option('git_plugin_local_path');
    }

    return git_plugin_encrypt_value($real);
}

function git_plugin_validate_remote_url($url)
{
    $url = trim((string) $url);

    if ($url === '') {
        return '';
    }

    if (preg_match('/[\r\n\x00]/', $url)) {
        add_settings_error('git_plugin_remote_url', 'invalid_remote_url', 'Remote URL contains unsupported characters.');
        return git_plugin_get_raw_option('git_plugin_remote_url');
    }

    $is_standard_url = preg_match('/^(https?|ssh|git|file):\/\//i', $url);
    $is_scp_style = preg_match('/^[^@\s]+@[^:\s]+:.+$/', $url);
    $is_local_path = preg_match('/^(\/|[A-Za-z]:[\\\\\/]).+$/', $url);

    if (!$is_standard_url && !$is_scp_style && !$is_local_path) {
        add_settings_error('git_plugin_remote_url', 'invalid_remote_format', 'Enter a valid Git remote URL. Examples: https://..., git@host:repo.git, or a local bare repo path.');
        return git_plugin_get_raw_option('git_plugin_remote_url');
    }

    return git_plugin_encrypt_value($url);
}

function git_plugin_get_configured_local_path()
{
    $path = git_plugin_get_saved_local_path();

    if ($path === '') {
        git_plugin_add_notice('no_path', 'Set a local repository path first.');
        return false;
    }

    $real = git_plugin_resolve_local_path($path);

    if ($real === false) {
        git_plugin_add_notice('invalid_path', 'The saved local path no longer exists.');
        return false;
    }

    return $real;
}

function git_is_git_repository($path)
{
    $real = git_plugin_resolve_local_path($path);

    if ($real === false) {
        return false;
    }

    return is_dir($real . DIRECTORY_SEPARATOR . '.git');
}

function git_get_valid_repo_path()
{
    $path = git_plugin_get_configured_local_path();

    if ($path === false) {
        return false;
    }

    if (!git_is_git_repository($path)) {
        git_plugin_add_notice('invalid_repo', 'The selected local path is not a Git repository yet. Use Connect Remote to initialize it first.');
        return false;
    }

    return $path;
}

function git_is_repo_clean($path)
{
    $status = run_git_command($path, 'status --porcelain');

    return $status['status'] === 0 && empty($status['output']);
}

function git_get_remote_names($path)
{
    $remote = run_git_command($path, 'remote');

    if ($remote['status'] !== 0) {
        return [];
    }

    return array_values(array_filter(array_map('trim', $remote['output'])));
}

function git_has_remote($path)
{
    return !empty(git_get_remote_names($path));
}

function git_get_primary_remote($path)
{
    $remotes = git_get_remote_names($path);

    if (in_array('origin', $remotes, true)) {
        return 'origin';
    }

    return $remotes[0] ?? '';
}

function git_is_safe_remote_name($remote_name)
{
    return (bool) preg_match('/^[A-Za-z0-9._-]+$/', $remote_name);
}

function git_get_remote_url($path, $remote_name)
{
    if ($remote_name === '' || !git_is_safe_remote_name($remote_name)) {
        return '';
    }

    $result = run_git_command($path, 'config --get remote.' . $remote_name . '.url');

    if ($result['status'] !== 0) {
        return '';
    }

    return trim($result['output'][0] ?? '');
}

function git_find_remote_by_url($path, $url)
{
    foreach (git_get_remote_names($path) as $remote_name) {
        if (git_get_remote_url($path, $remote_name) === $url) {
            return $remote_name;
        }
    }

    return '';
}

function git_list_local_branches($path)
{
    $branches = run_git_command($path, 'branch --format="%(refname:short)"');

    if ($branches['status'] !== 0) {
        return [];
    }

    return array_values(array_filter(array_map('trim', $branches['output'])));
}

function git_branch_exists($path, $branch)
{
    return in_array($branch, git_list_local_branches($path), true);
}

function git_validate_branch_name($path, $branch)
{
    $branch = trim((string) $branch);

    if ($branch === '') {
        git_plugin_add_notice('invalid_branch', 'Branch name is required.');
        return false;
    }

    $result = run_git_command($path, 'check-ref-format --branch ' . escapeshellarg($branch));

    if ($result['status'] !== 0) {
        git_plugin_add_notice('invalid_branch', 'Invalid branch name.');
        return false;
    }

    return $branch;
}

function git_get_current_branch($path)
{
    $branch = run_git_command($path, 'branch --show-current');

    if ($branch['status'] !== 0) {
        return '';
    }

    return trim($branch['output'][0] ?? '');
}

function git_get_default_branch($path, $remote_name = '')
{
    $remote_name = $remote_name !== '' ? $remote_name : git_get_primary_remote($path);

    if ($remote_name !== '' && git_is_safe_remote_name($remote_name)) {
        $result = run_git_command($path, 'symbolic-ref refs/remotes/' . $remote_name . '/HEAD');

        if ($result['status'] === 0 && !empty($result['output'][0])) {
            return basename(trim($result['output'][0]));
        }
    }

    $current_branch = git_get_current_branch($path);

    if ($current_branch !== '') {
        return $current_branch;
    }

    foreach (['main', 'master'] as $candidate) {
        if (git_branch_exists($path, $candidate)) {
            return $candidate;
        }
    }

    return '';
}

function git_directory_has_non_git_files($path)
{
    $items = scandir($path);

    if ($items === false) {
        return false;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..' || $item === '.git') {
            continue;
        }

        return true;
    }

    return false;
}

function git_is_protected_branch($branch)
{
    return in_array($branch, ['main', 'master'], true);
}

function git_plugin_check_protected_branch_policy($path, $action_label)
{
    $branch = git_get_current_branch($path);

    if ($branch === '' || !git_is_protected_branch($branch) || git_plugin_allow_protected_direct_changes()) {
        return true;
    }

    git_plugin_add_notice(
        'protected_branch_policy',
        sprintf('Direct %s is blocked on protected branch "%s". Enable the setting if you want to allow it.', $action_label, $branch)
    );

    return false;
}

function git_plugin_slugify_ref($value)
{
    $value = strtolower(trim((string) $value));
    $value = preg_replace('/[^a-z0-9._-]+/', '-', $value);
    $value = trim((string) $value, '-');

    return $value !== '' ? $value : 'ref';
}

function git_plugin_create_backup_branch($path, $reason, $source_ref = 'HEAD')
{
    $name = 'backup/' . gmdate('Ymd-His') . '-' . git_plugin_slugify_ref($reason);

    $result = run_git_command(
        $path,
        'branch ' . escapeshellarg($name) . ' ' . escapeshellarg($source_ref)
    );

    return [
        'name' => $name,
        'result' => $result,
    ];
}

function git_plugin_mask_remote_url($url)
{
    $url = trim((string) $url);

    if ($url === '') {
        return '';
    }

    $masked = preg_replace('/\/\/([^\/:@]+):([^@\/]+)@/', '//$1:***@', $url);

    if (preg_match('/^([^@]+)@([^:]+):(.+)$/', $masked, $matches)) {
        return $matches[1] . '@' . $matches[2] . ':***';
    }

    return $masked;
}

function git_plugin_mask_remote_output(array $lines)
{
    return array_map('git_plugin_mask_remote_url', $lines);
}

function git_plugin_diagnose_git_output(array $output)
{
    $text = strtolower(implode("\n", $output));

    if ($text === '') {
        return '';
    }

    $diagnostics = [];

    if (strpos($text, 'permission denied (publickey)') !== false) {
        $diagnostics[] = 'SSH key authentication failed. Check that the web server user can access the correct SSH key.';
    }

    if (strpos($text, 'could not resolve host') !== false || strpos($text, 'name or service not known') !== false) {
        $diagnostics[] = 'DNS or network resolution failed while contacting the remote host.';
    }

    if (strpos($text, 'repository not found') !== false) {
        $diagnostics[] = 'The remote repository URL may be wrong, or the account used by the server cannot access it.';
    }

    if (strpos($text, 'authentication failed') !== false || strpos($text, 'fatal: could not read username') !== false) {
        $diagnostics[] = 'HTTPS authentication failed. Configure a credential helper, app password, or token for the server account.';
    }

    if (strpos($text, 'host key verification failed') !== false) {
        $diagnostics[] = 'The SSH host is not trusted by the server account. Add the host to known_hosts first.';
    }

    if (strpos($text, 'could not read from remote repository') !== false) {
        $diagnostics[] = 'Git could reach the remote host but could not read the repository. Check credentials and repository permissions.';
    }

    if (strpos($text, 'non-fast-forward') !== false) {
        $diagnostics[] = 'The remote branch has commits your local branch does not have yet. Pull or rebase first.';
    }

    if (strpos($text, 'merge conflict') !== false || strpos($text, 'automatic merge failed') !== false) {
        $diagnostics[] = 'Git detected a merge conflict that needs manual resolution.';
    }

    return implode("\n", array_unique($diagnostics));
}

function git_plugin_format_result_message($result, $success_prefix = '', $failure_prefix = '')
{
    $lines = $result['output'];
    $diagnosis = git_plugin_diagnose_git_output($lines);

    if ($diagnosis !== '') {
        $lines[] = '';
        $lines[] = 'Diagnosis:';
        $lines[] = $diagnosis;
    }

    $body = trim(implode("\n", $lines));

    if ($result['status'] === 0) {
        return trim($success_prefix . ($body !== '' ? "\n" . $body : ''));
    }

    return trim($failure_prefix . ($body !== '' ? "\n" . $body : ''));
}

function git_plugin_record_activity($action, $status, $details = '', $path = '')
{
    $log = get_option('git_plugin_activity_log', []);

    if (!is_array($log)) {
        $log = [];
    }

    $user = wp_get_current_user();
    array_unshift($log, [
        'time' => current_time('mysql'),
        'action' => $action,
        'status' => $status,
        'details' => $details,
        'path' => $path,
        'user_id' => get_current_user_id(),
        'user_login' => $user instanceof WP_User ? $user->user_login : '',
    ]);

    update_option('git_plugin_activity_log', array_slice($log, 0, 50), false);
}

function git_plugin_get_activity_log()
{
    $log = get_option('git_plugin_activity_log', []);

    return is_array($log) ? $log : [];
}

function git_plugin_save_health_report($report)
{
    update_option('git_plugin_last_health_report', $report, false);
}

function git_plugin_get_health_report()
{
    $report = get_option('git_plugin_last_health_report', []);

    return is_array($report) ? $report : [];
}

function git_plugin_get_remote_scheme($remote_url)
{
    if ($remote_url === '') {
        return 'none';
    }

    if (preg_match('/^[^@\s]+@[^:\s]+:.+$/', $remote_url)) {
        return 'ssh';
    }

    if (stripos($remote_url, 'https://') === 0 || stripos($remote_url, 'http://') === 0) {
        return 'https';
    }

    if (stripos($remote_url, 'file://') === 0 || preg_match('/^(\/|[A-Za-z]:[\\\\\/]).+$/', $remote_url)) {
        return 'file';
    }

    return 'other';
}

function git_plugin_build_health_report($path = false, $remote_url = '')
{
    $report = [
        'generated_at' => current_time('mysql'),
        'checks' => [],
    ];

    $git_version = git_plugin_run_git_binary_command('--version');
    $report['checks'][] = [
        'label' => 'Git binary',
        'status' => $git_version['status'] === 0 ? 'success' : 'error',
        'details' => $git_version['status'] === 0 ? implode("\n", $git_version['output']) : git_plugin_format_result_message($git_version, '', 'Git is not available.')
    ];

    $report['checks'][] = [
        'label' => 'Local path',
        'status' => $path && is_dir($path) ? 'success' : 'error',
        'details' => $path && is_dir($path) ? $path : 'Configured local path is missing or invalid.'
    ];

    if ($path && is_dir($path)) {
        $report['checks'][] = [
            'label' => 'Repository',
            'status' => git_is_git_repository($path) ? 'success' : 'warning',
            'details' => git_is_git_repository($path) ? 'Git repository detected.' : 'Folder exists but is not a Git repository yet.'
        ];
    }

    $scheme = git_plugin_get_remote_scheme($remote_url);
    $report['checks'][] = [
        'label' => 'Remote URL',
        'status' => $remote_url !== '' ? 'success' : 'warning',
        'details' => $remote_url !== '' ? git_plugin_mask_remote_url($remote_url) . ' (' . strtoupper($scheme) . ')' : 'No remote URL configured.'
    ];

    if ($remote_url !== '') {
        $probe = git_plugin_run_git_binary_command('ls-remote --heads ' . escapeshellarg($remote_url) . ' HEAD');

        $report['checks'][] = [
            'label' => 'Remote access',
            'status' => $probe['status'] === 0 ? 'success' : 'error',
            'details' => git_plugin_format_result_message(
                $probe,
                'Remote access succeeded.',
                'Remote access failed.'
            )
        ];

        if ($scheme === 'ssh') {
            $report['checks'][] = [
                'label' => 'SSH expectations',
                'status' => 'info',
                'details' => 'SSH remote detected. The web server account must have a usable SSH key and known_hosts entry.'
            ];
        }

        if ($scheme === 'https') {
            $report['checks'][] = [
                'label' => 'HTTPS expectations',
                'status' => 'info',
                'details' => 'HTTPS remote detected. The web server account may need a credential helper, token, or app password.'
            ];
        }
    }

    return $report;
}

function git_plugin_render_health_summary($report)
{
    if (empty($report['checks']) || !is_array($report['checks'])) {
        return 'No health report available yet.';
    }

    $lines = ['Health report generated at: ' . ($report['generated_at'] ?? 'unknown')];

    foreach ($report['checks'] as $check) {
        $lines[] = sprintf('[%s] %s', strtoupper($check['status']), $check['label']);

        if (!empty($check['details'])) {
            $lines[] = $check['details'];
        }

        $lines[] = '';
    }

    return trim(implode("\n", $lines));
}

function git_plugin_get_ahead_behind_summary($path)
{
    $result = run_git_command($path, 'rev-list --left-right --count HEAD...@{u}');

    if ($result['status'] !== 0 || empty($result['output'][0])) {
        return '';
    }

    $parts = preg_split('/\s+/', trim($result['output'][0]));

    if (count($parts) !== 2) {
        return '';
    }

    return 'Ahead: ' . $parts[0] . ', Behind: ' . $parts[1];
}
