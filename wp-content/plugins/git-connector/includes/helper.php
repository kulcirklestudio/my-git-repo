<?php
function git_plugin_get_raw_option($option_name)
{
    return get_option($option_name, '');
}

function git_plugin_get_default_git_binary_candidates()
{
    return [
        'git',
        'C:\\Program Files\\Git\\cmd\\git.exe',
        'C:\\Program Files\\Git\\bin\\git.exe',
        'C:\\Program Files (x86)\\Git\\cmd\\git.exe',
        'C:\\Program Files (x86)\\Git\\bin\\git.exe',
    ];
}

function git_plugin_is_windows_absolute_path($path)
{
    return (bool) preg_match('/^[A-Za-z]:[\\\\\/]/', (string) $path);
}

function git_plugin_detect_git_binary()
{
    foreach (git_plugin_get_default_git_binary_candidates() as $candidate) {
        if ($candidate === 'git') {
            $probe = git_plugin_execute_process('git --version');

            if ($probe['status'] === 0) {
                return 'git';
            }

            continue;
        }

        if (is_file($candidate)) {
            return $candidate;
        }
    }

    return 'git';
}

function git_plugin_get_git_binary()
{
    $saved = trim((string) get_option('git_plugin_git_binary', ''));

    if ($saved !== '') {
        return $saved;
    }

    return git_plugin_detect_git_binary();
}

function git_plugin_validate_git_binary($value)
{
    $value = trim((string) $value);

    if ($value === '') {
        $value = git_plugin_detect_git_binary();
    }

    if (git_plugin_is_windows_absolute_path($value)) {
        if (!is_file($value)) {
            add_settings_error('git_plugin_git_binary', 'invalid_git_binary', 'Git executable path does not exist.');
            return git_plugin_get_raw_option('git_plugin_git_binary');
        }

        return $value;
    }

    $probe = git_plugin_execute_process(escapeshellarg($value) . ' --version');

    if ($probe['status'] !== 0) {
        add_settings_error('git_plugin_git_binary', 'invalid_git_binary_command', 'The configured Git command could not be executed.');
        return git_plugin_get_raw_option('git_plugin_git_binary');
    }

    return $value;
}

function git_plugin_get_page_url()
{
    return admin_url('admin.php?page=create-git-branch');
}

function git_plugin_get_notice_meta_key()
{
    return '_git_plugin_persisted_notices';
}

function git_plugin_notice_persistence_enabled()
{
    return !empty($GLOBALS['git_plugin_persist_notices']);
}

function git_plugin_enable_notice_persistence()
{
    $GLOBALS['git_plugin_persist_notices'] = true;
}

function git_plugin_disable_notice_persistence()
{
    $GLOBALS['git_plugin_persist_notices'] = false;
}

function git_plugin_persist_notice($code, $message, $type)
{
    $user_id = get_current_user_id();

    if (!$user_id) {
        return;
    }

    $existing = get_user_meta($user_id, git_plugin_get_notice_meta_key(), true);

    if (!is_array($existing)) {
        $existing = [];
    }

    $existing[] = [
        'code' => (string) $code,
        'message' => (string) $message,
        'type' => (string) $type,
    ];

    update_user_meta($user_id, git_plugin_get_notice_meta_key(), $existing);
}

function git_plugin_restore_persisted_notices()
{
    $user_id = get_current_user_id();

    if (!$user_id) {
        return;
    }

    $notices = get_user_meta($user_id, git_plugin_get_notice_meta_key(), true);

    if (!is_array($notices) || empty($notices)) {
        return;
    }

    delete_user_meta($user_id, git_plugin_get_notice_meta_key());

    foreach ($notices as $notice) {
        add_settings_error(
            'git_plugin',
            $notice['code'] ?? 'git_plugin_notice',
            $notice['message'] ?? '',
            $notice['type'] ?? 'error'
        );
    }
}

function git_plugin_get_git_timeout_seconds()
{
    return 45;
}

function git_plugin_get_git_env()
{
    return [
        'GIT_TERMINAL_PROMPT' => '0',
        'GCM_INTERACTIVE' => 'Never',
        'GIT_ASKPASS' => '',
        'SSH_ASKPASS' => '',
        'GIT_PAGER' => 'cat',
    ];
}

function git_plugin_get_process_env()
{
    $env = [];
    $keys = [
        'SystemRoot',
        'ComSpec',
        'PATH',
        'Path',
        'PATHEXT',
        'TEMP',
        'TMP',
        'USERPROFILE',
        'APPDATA',
        'LOCALAPPDATA',
        'PROGRAMDATA',
        'PROGRAMFILES',
        'PROGRAMFILES(X86)',
        'HOMEDRIVE',
        'HOMEPATH',
        'NUMBER_OF_PROCESSORS',
    ];

    foreach ($keys as $key) {
        $value = getenv($key);

        if ($value !== false && $value !== '') {
            $env[$key] = $value;
        }
    }

    foreach (git_plugin_get_git_env() as $key => $value) {
        $env[$key] = $value;
    }

    return $env;
}

function git_plugin_execute_process($command, $cwd = null, $timeout = null)
{
    $timeout = $timeout ?: git_plugin_get_git_timeout_seconds();
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptors, $pipes, $cwd, git_plugin_get_process_env());

    if (!is_resource($process)) {
        return [
            'output' => ['Could not start Git process.'],
            'status' => 1,
            'timed_out' => false,
            'command' => $command,
        ];
    }

    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $stdout = '';
    $stderr = '';
    $start = microtime(true);
    $timed_out = false;

    do {
        $stdout .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);
        $status = proc_get_status($process);

        if (!$status['running']) {
            break;
        }

        if ((microtime(true) - $start) >= $timeout) {
            $timed_out = true;
            proc_terminate($process);
            break;
        }

        usleep(100000);
    } while (true);

    $stdout .= stream_get_contents($pipes[1]);
    $stderr .= stream_get_contents($pipes[2]);

    fclose($pipes[1]);
    fclose($pipes[2]);

    $exit_code = proc_close($process);

    if ($timed_out) {
        $exit_code = 124;
        $stderr .= ($stderr !== '' ? "\n" : '') . 'Git command timed out.';
    }

    $combined = trim($stdout . ($stderr !== '' ? "\n" . $stderr : ''));
    $lines = $combined === '' ? [] : preg_split('/\r\n|\r|\n/', $combined);

    return [
        'output' => $lines,
        'status' => $exit_code,
        'timed_out' => $timed_out,
        'command' => $command,
    ];
}

function run_git_command($path, $command)
{
    $git_binary = git_plugin_get_git_binary();
    $git_command = git_plugin_is_windows_absolute_path($git_binary) ? escapeshellarg($git_binary) : $git_binary;

    return git_plugin_execute_process($git_command . ' -C ' . escapeshellarg($path) . ' ' . $command, null);
}

function git_plugin_run_git_binary_command($command)
{
    $git_binary = git_plugin_get_git_binary();
    $git_command = git_plugin_is_windows_absolute_path($git_binary) ? escapeshellarg($git_binary) : $git_binary;

    return git_plugin_execute_process($git_command . ' ' . $command, null);
}

function git_plugin_add_notice($code, $message, $type = 'error')
{
    add_settings_error('git_plugin', $code, $message, $type);

    if (git_plugin_notice_persistence_enabled()) {
        git_plugin_persist_notice($code, $message, $type);
    }
}

function git_plugin_sanitize_checkbox($value)
{
    return empty($value) ? '0' : '1';
}

function git_plugin_sanitize_branch_patterns($value)
{
    $value = is_string($value) ? $value : '';
    $lines = preg_split('/\r\n|\r|\n/', $value);
    $clean = [];

    foreach ($lines as $line) {
        $pattern = trim($line);

        if ($pattern === '') {
            continue;
        }

        if (!preg_match('/^[A-Za-z0-9._*\/-]+$/', $pattern)) {
            continue;
        }

        $clean[] = $pattern;
    }

    return implode("\n", array_unique($clean));
}

function git_plugin_crypto_available()
{
    return function_exists('openssl_encrypt') && function_exists('openssl_decrypt') && function_exists('random_bytes');
}

function git_plugin_get_encryption_key()
{
    return hash('sha256', wp_salt('auth'), true);
}

function git_plugin_encrypt_value($value)
{
    $value = (string) $value;

    if ($value === '') {
        return '';
    }

    if (!git_plugin_crypto_available()) {
        return false;
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
        return false;
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

    if (!git_plugin_crypto_available()) {
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

function git_plugin_get_saved_author_name()
{
    return trim((string) get_option('git_plugin_author_name', ''));
}

function git_plugin_get_saved_author_email()
{
    return trim((string) get_option('git_plugin_author_email', ''));
}

function git_plugin_allow_protected_direct_changes()
{
    return get_option('git_plugin_allow_protected_direct_changes', '0') === '1';
}

function git_plugin_get_protected_branch_patterns()
{
    $saved = trim((string) get_option('git_plugin_protected_branches', "main\nmaster"));

    if ($saved === '') {
        return ['main', 'master'];
    }

    return array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $saved))));
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

    $encrypted = git_plugin_encrypt_value($real);

    if ($encrypted === false) {
        add_settings_error('git_plugin_local_path', 'crypto_required', 'OpenSSL support is required to save the local repository path securely.');
        return git_plugin_get_raw_option('git_plugin_local_path');
    }

    return $encrypted;
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

    $encrypted = git_plugin_encrypt_value($url);

    if ($encrypted === false) {
        add_settings_error('git_plugin_remote_url', 'crypto_required_remote', 'OpenSSL support is required to save the remote URL securely.');
        return git_plugin_get_raw_option('git_plugin_remote_url');
    }

    return $encrypted;
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

function git_plugin_get_repo_config($path, $key)
{
    $result = run_git_command($path, 'config --local --get ' . escapeshellarg($key));

    if ($result['status'] !== 0) {
        return '';
    }

    return trim($result['output'][0] ?? '');
}

function git_plugin_set_repo_config($path, $key, $value)
{
    return run_git_command($path, 'config --local ' . escapeshellarg($key) . ' ' . escapeshellarg($value));
}

function git_plugin_get_repo_identity($path)
{
    return [
        'name' => git_plugin_get_repo_config($path, 'user.name'),
        'email' => git_plugin_get_repo_config($path, 'user.email'),
    ];
}

function git_plugin_validate_author_identity($name, $email)
{
    $name = trim((string) $name);
    $email = trim((string) $email);

    if ($name === '' || $email === '') {
        return false;
    }

    return is_email($email) ? ['name' => $name, 'email' => $email] : false;
}

function git_plugin_ensure_repo_identity($path)
{
    $identity = git_plugin_get_repo_identity($path);

    if ($identity['name'] !== '' && $identity['email'] !== '') {
        return [
            'ok' => true,
            'configured' => false,
            'message' => 'Repository author identity already configured.',
        ];
    }

    $saved_name = git_plugin_get_saved_author_name();
    $saved_email = git_plugin_get_saved_author_email();
    $saved_identity = git_plugin_validate_author_identity($saved_name, $saved_email);

    if ($saved_identity === false) {
        return [
            'ok' => false,
            'configured' => false,
            'message' => 'Set Author Name and Author Email in the plugin settings before commit or merge.',
        ];
    }

    $name_result = git_plugin_set_repo_config($path, 'user.name', $saved_identity['name']);

    if ($name_result['status'] !== 0) {
        return [
            'ok' => false,
            'configured' => false,
            'message' => git_plugin_format_result_message($name_result, '', 'Could not set repository author name.'),
        ];
    }

    $email_result = git_plugin_set_repo_config($path, 'user.email', $saved_identity['email']);

    if ($email_result['status'] !== 0) {
        return [
            'ok' => false,
            'configured' => false,
            'message' => git_plugin_format_result_message($email_result, '', 'Could not set repository author email.'),
        ];
    }

    return [
        'ok' => true,
        'configured' => true,
        'message' => 'Configured repository author identity from plugin settings.',
    ];
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

function git_plugin_branch_matches_pattern($branch, $pattern)
{
    if ($pattern === $branch) {
        return true;
    }

    if (strpos($pattern, '*') === false) {
        return false;
    }

    $quoted = preg_quote($pattern, '/');
    $regex = '/^' . str_replace('\*', '.*', $quoted) . '$/';

    return (bool) preg_match($regex, $branch);
}

function git_is_protected_branch($branch)
{
    foreach (git_plugin_get_protected_branch_patterns() as $pattern) {
        if (git_plugin_branch_matches_pattern($branch, $pattern)) {
            return true;
        }
    }

    return false;
}

function git_plugin_check_protected_branch_policy($path, $action_label)
{
    $branch = git_get_current_branch($path);

    if ($branch === '' || !git_is_protected_branch($branch) || git_plugin_allow_protected_direct_changes()) {
        return true;
    }

    git_plugin_add_notice(
        'protected_branch_policy',
        sprintf('Direct %s is blocked on protected branch "%s". Update the protected branch settings if this should be allowed.', $action_label, $branch)
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
    $result = run_git_command($path, 'branch ' . escapeshellarg($name) . ' ' . escapeshellarg($source_ref));

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

function git_plugin_mask_path($path)
{
    $path = trim((string) $path);

    if ($path === '') {
        return '';
    }

    $normalized = str_replace('\\', '/', $path);
    $segments = array_values(array_filter(explode('/', $normalized), 'strlen'));
    $count = count($segments);

    if ($count <= 2) {
        return '...' . DIRECTORY_SEPARATOR . end($segments);
    }

    return '...' . DIRECTORY_SEPARATOR . $segments[$count - 2] . DIRECTORY_SEPARATOR . $segments[$count - 1];
}

function git_plugin_shorten_text($text, $limit = 220)
{
    $text = trim((string) $text);

    if (strlen($text) <= $limit) {
        return $text;
    }

    return rtrim(substr($text, 0, $limit - 3)) . '...';
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

    if (strpos($text, 'getaddrinfo() thread failed to start') !== false) {
        $diagnostics[] = 'The PHP child process is missing required Windows environment variables for network lookups. Configure the Git executable path and ensure the web server process keeps SystemRoot, PATH, TEMP, and USERPROFILE.';
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

    if (strpos($text, 'timed out') !== false) {
        $diagnostics[] = 'The Git command took too long and was stopped. Check network access, credentials, or repository size.';
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
    $safe_details = git_plugin_shorten_text($details, 240);
    array_unshift($log, [
        'time' => current_time('mysql'),
        'action' => $action,
        'status' => $status,
        'details' => $safe_details,
        'path' => $path !== '' ? git_plugin_mask_path($path) : '',
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
        'details' => $path && is_dir($path) ? git_plugin_mask_path($path) : 'Configured local path is missing or invalid.'
    ];

    if ($path && is_dir($path)) {
        $report['checks'][] = [
            'label' => 'Repository',
            'status' => git_is_git_repository($path) ? 'success' : 'warning',
            'details' => git_is_git_repository($path) ? 'Git repository detected.' : 'Folder exists but is not a Git repository yet.'
        ];

        if (git_is_git_repository($path)) {
            $identity = git_plugin_get_repo_identity($path);
            $saved_identity = git_plugin_validate_author_identity(
                git_plugin_get_saved_author_name(),
                git_plugin_get_saved_author_email()
            );

            $report['checks'][] = [
                'label' => 'Author identity',
                'status' => ($identity['name'] !== '' && $identity['email'] !== '') ? 'success' : ($saved_identity ? 'warning' : 'error'),
                'details' => ($identity['name'] !== '' && $identity['email'] !== '')
                    ? sprintf('Repo identity is set to %s <%s>.', $identity['name'], $identity['email'])
                    : ($saved_identity
                        ? sprintf('Repo identity is missing. Plugin settings can apply %s <%s> automatically.', $saved_identity['name'], $saved_identity['email'])
                        : 'Repo identity is missing. Set Author Name and Author Email in plugin settings.')
            ];
        }
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
            'details' => git_plugin_format_result_message($probe, 'Remote access succeeded.', 'Remote access failed.')
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

function git_plugin_get_lock_key($path)
{
    return 'git_plugin_lock_' . md5((string) $path);
}

function git_plugin_acquire_lock($path, $operation)
{
    $lock_key = git_plugin_get_lock_key($path);
    $existing = get_transient($lock_key);

    if (is_array($existing) && !empty($existing['token'])) {
        git_plugin_add_notice(
            'git_plugin_lock_active',
            sprintf('Another Git operation is already running for %s. Wait for it to finish before starting %s.', git_plugin_mask_path($path), $operation),
            'error'
        );
        return false;
    }

    $token = wp_generate_password(20, false, false);
    set_transient($lock_key, [
        'token' => $token,
        'operation' => $operation,
        'time' => time(),
    ], 2 * MINUTE_IN_SECONDS);

    return $token;
}

function git_plugin_release_lock($path, $token)
{
    if ($token === false || $token === null) {
        return;
    }

    $lock_key = git_plugin_get_lock_key($path);
    $existing = get_transient($lock_key);

    if (is_array($existing) && ($existing['token'] ?? '') === $token) {
        delete_transient($lock_key);
    }
}
