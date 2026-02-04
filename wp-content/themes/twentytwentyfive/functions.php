<?php
/**
 * Twenty Twenty-Five functions and definitions.
 *
 * @link https://developer.wordpress.org/themes/basics/theme-functions/
 *
 * @package WordPress
 * @subpackage Twenty_Twenty_Five
 * @since Twenty Twenty-Five 1.0
 */

// Adds theme support for post formats.
if (!function_exists('twentytwentyfive_post_format_setup')):
	/**
	 * Adds theme support for post formats.
	 *
	 * @since Twenty Twenty-Five 1.0
	 *
	 * @return void
	 */
	function twentytwentyfive_post_format_setup()
	{
		add_theme_support('post-formats', array('aside', 'audio', 'chat', 'gallery', 'image', 'link', 'quote', 'status', 'video'));
	}
endif;
add_action('after_setup_theme', 'twentytwentyfive_post_format_setup');

// Enqueues editor-style.css in the editors.
if (!function_exists('twentytwentyfive_editor_style')):
	/**
	 * Enqueues editor-style.css in the editors.
	 *
	 * @since Twenty Twenty-Five 1.0
	 *
	 * @return void
	 */
	function twentytwentyfive_editor_style()
	{
		add_editor_style('assets/css/editor-style.css');
	}
endif;
add_action('after_setup_theme', 'twentytwentyfive_editor_style');

// Enqueues the theme stylesheet on the front.
if (!function_exists('twentytwentyfive_enqueue_styles')):
	/**
	 * Enqueues the theme stylesheet on the front.
	 *
	 * @since Twenty Twenty-Five 1.0
	 *
	 * @return void
	 */
	function twentytwentyfive_enqueue_styles()
	{
		$suffix = SCRIPT_DEBUG ? '' : '.min';
		$src = 'style' . $suffix . '.css';

		wp_enqueue_style(
			'twentytwentyfive-style',
			get_parent_theme_file_uri($src),
			array(),
			wp_get_theme()->get('Version')
		);
		wp_style_add_data(
			'twentytwentyfive-style',
			'path',
			get_parent_theme_file_path($src)
		);
	}
endif;
add_action('wp_enqueue_scripts', 'twentytwentyfive_enqueue_styles');

// Registers custom block styles.
if (!function_exists('twentytwentyfive_block_styles')):
	/**
	 * Registers custom block styles.
	 *
	 * @since Twenty Twenty-Five 1.0
	 *
	 * @return void
	 */
	function twentytwentyfive_block_styles()
	{
		register_block_style(
			'core/list',
			array(
				'name' => 'checkmark-list',
				'label' => __('Checkmark', 'twentytwentyfive'),
				'inline_style' => '
				ul.is-style-checkmark-list {
					list-style-type: "\2713";
				}

				ul.is-style-checkmark-list li {
					padding-inline-start: 1ch;
				}',
			)
		);
	}
endif;
add_action('init', 'twentytwentyfive_block_styles');

// Registers pattern categories.
if (!function_exists('twentytwentyfive_pattern_categories')):
	/**
	 * Registers pattern categories.
	 *
	 * @since Twenty Twenty-Five 1.0
	 *
	 * @return void
	 */
	function twentytwentyfive_pattern_categories()
	{

		register_block_pattern_category(
			'twentytwentyfive_page',
			array(
				'label' => __('Pages', 'twentytwentyfive'),
				'description' => __('A collection of full page layouts.', 'twentytwentyfive'),
			)
		);

		register_block_pattern_category(
			'twentytwentyfive_post-format',
			array(
				'label' => __('Post formats', 'twentytwentyfive'),
				'description' => __('A collection of post format patterns.', 'twentytwentyfive'),
			)
		);
	}
endif;
add_action('init', 'twentytwentyfive_pattern_categories');

// Registers block binding sources.
if (!function_exists('twentytwentyfive_register_block_bindings')):
	/**
	 * Registers the post format block binding source.
	 *
	 * @since Twenty Twenty-Five 1.0
	 *
	 * @return void
	 */
	function twentytwentyfive_register_block_bindings()
	{
		register_block_bindings_source(
			'twentytwentyfive/format',
			array(
				'label' => _x('Post format name', 'Label for the block binding placeholder in the editor', 'twentytwentyfive'),
				'get_value_callback' => 'twentytwentyfive_format_binding',
			)
		);
	}
endif;
add_action('init', 'twentytwentyfive_register_block_bindings');

// Registers block binding callback function for the post format name.
if (!function_exists('twentytwentyfive_format_binding')):
	/**
	 * Callback function for the post format name block binding source.
	 *
	 * @since Twenty Twenty-Five 1.0
	 *
	 * @return string|void Post format name, or nothing if the format is 'standard'.
	 */
	function twentytwentyfive_format_binding()
	{
		$post_format_slug = get_post_format();

		if ($post_format_slug && 'standard' !== $post_format_slug) {
			return get_post_format_string($post_format_slug);
		}
	}
endif;

// ===============================
// Admin Menu: Create Git Branch
// ===============================
add_action('admin_menu', function () {
	add_menu_page(
		'Create Git Branch',
		'Create Branch',
		'manage_options',
		'create-git-branch',
		'render_create_git_branch_page',
		'dashicons-randomize',
		80
	);
});

function get_main_git_branch($repo_path)
{
	$output = [];
	$status = 0;

	exec(
		"cd " . escapeshellarg($repo_path) . " && git symbolic-ref --short refs/remotes/origin/HEAD 2>/dev/null",
		$output,
		$status
	);

	if ($status === 0 && !empty($output[0])) {
		// origin/main → main
		return str_replace('origin/', '', trim($output[0]));
	}

	return null; // repo without origin or error
}


function render_create_git_branch_page()
{

	if (!current_user_can('manage_options')) {
		return;
	}

	// -----------------------------
	// CONFIG
	// -----------------------------
	$repo_path = ABSPATH;
	$main_branch = get_main_git_branch($repo_path);

	// fallback safety
	if (!$main_branch) {
		$main_branch = 'main';
	}

	$protected_branches = [$main_branch];

	$message = '';

	// -----------------------------
	// CREATE BRANCH
	// -----------------------------
	if (isset($_POST['create_git_branch'])) {

		check_admin_referer('create_git_branch_nonce');

		$branch = sanitize_text_field(trim($_POST['branch_name']));

		if (!preg_match('/^[a-zA-Z0-9._\-\/]+$/', $branch)) {
			$message = '❌ Invalid branch name.';
		} else {

			$cmd = implode(' && ', [
				'cd ' . escapeshellarg($repo_path),
				'git fetch origin --quiet',
				'git checkout main',
				'git pull origin main',
				'git checkout -b ' . escapeshellarg($branch),
				'git push --set-upstream origin ' . escapeshellarg($branch),
			]);

			exec($cmd . ' 2>&1', $output, $status);

			$message = ($status === 0)
				? "✅ Branch '{$branch}' created & pushed successfully."
				: "❌ Error:\n" . implode("\n", $output);
		}
	}

	// -----------------------------
	// DELETE BRANCH
	// -----------------------------
	if (isset($_POST['branch_to_delete'])) {

		check_admin_referer('delete_git_branch_nonce');

		$branch = sanitize_text_field($_POST['branch_to_delete']);

		if (in_array($branch, $protected_branches, true)) {
			$message = '❌ Protected branch cannot be deleted.';
		} else {

			$cmd = implode(' && ', [
				'cd ' . escapeshellarg($repo_path),
				'git bundle create allbackup/Backup-Branch-' . escapeshellarg($branch) . '-' . date('Y-m-d_H-i-s') . '.bundle ' . escapeshellarg($branch) . '',
				'git checkout main',
				'git branch -D ' . escapeshellarg($branch),
				'git push origin --delete ' . escapeshellarg($branch),
			]);

			exec($cmd . ' 2>&1', $output, $status);
			$message = ($status === 0)
				? "✅ Branch '{$branch}' deleted successfully."
				: "❌ Error deleting branch:\n" . implode("\n", $output);
		}
	}

	// -----------------------------
	// FETCH BRANCHES (ONCE)
	// -----------------------------
	$branches = [];
	$output = [];
	$status = 0;

	$cmd = 'cd ' . escapeshellarg($repo_path)
		. ' && git fetch origin --quiet'
		. ' && git branch -r';

	exec($cmd . ' 2>&1', $output, $status);

	if ($status === 0) {
		foreach ($output as $line) {
			$line = trim($line);

			// Skip HEAD pointer
			if ($line === 'origin/HEAD -> origin/main') {
				continue;
			}

			// Allow only origin branches
			if (!preg_match('#^origin/[a-zA-Z0-9._\-/]+$#', $line)) {
				continue;
			}

			// Remove "origin/"
			$branches[] = substr($line, 7);
		}
	}

	// -----------------------------
	// UI
	// -----------------------------
	?>
	<div class="wrap">
		<h1>Create Git Branch</h1>

		<form method="post">
			<?php wp_nonce_field('create_git_branch_nonce'); ?>
			<input type="text" name="branch_name" required placeholder="New Branch Name" style="width:300px;">
			<p>
				<button type="submit" name="create_git_branch" class="button button-primary">
					Create Branch
				</button>
			</p>
		</form>

		<?php if ($message): ?>
			<pre class="notice notice-info"><?php echo esc_html($message); ?></pre>
		<?php endif; ?>

		<hr>

		<h2>All Branches</h2>

		<?php foreach ($branches as $branch): ?>
			<div class="git-branch-row">
				<p class="git-branch-name" data-branch="<?php echo esc_html($branch); ?>">
					<?php echo esc_html($branch); ?>
				</p>
				<div class="status">
					<?php
					$cmd = 'cd ' . escapeshellarg($repo_path)
						. ' && git checkout ' . esc_html($branch) . ' '
						. ' && git status';

					exec($cmd . ' 2>&1', $output, $status); foreach ($output as $op) {
						echo '<p>' . $op . '</p>';
					}
					?>
				</div>

				<?php if (!in_array($branch, $protected_branches)) { ?>
					<button type="button" name="delete_git_branch" class="button git-branch-delete"
						data-branch="<?php echo esc_html($branch); ?>">
						Delete Branch
					</button>
				<?php } else { ?>
					<span class="git-branch-protected">Protected branch</span>
				<?php } ?>
			</div>

		<?php endforeach; ?>

		<form method="post" id="deleteBranchForm">
			<?php wp_nonce_field('delete_git_branch_nonce'); ?>
			<input type="hidden" name="branch_to_delete" id="delete_branch_input">
		</form>
	</div>

	<script>		document.addEventListener('click', function (e) { if (e.target.classList.contains('git-branch-delete')) { if (!confirm('This will delete the branch locally and remotely. Continue?')) return; document.getElementById('delete_branch_input').value = e.target.dataset.branch; document.getElementById('deleteBranchForm').submit(); } });</script>
	<style>
		/* Container */
		.list-all-branch {
			background: #f6f7f7;
			padding: 20px;
			border-radius: 10px;
			border: 1px solid #dcdcde;
			margin-top: 20px;
		}

		/* Heading */
		.list-all-branch h1 {
			font-size: 22px;
			text-align: center;
			font-weight: 700;
			margin-bottom: 20px;
		}

		/* Branch row */
		.git-branch-row {
			display: flex;
			justify-content: space-between;
			align-items: center;
			gap: 16px;
			background: #ffffff;
			padding: 14px 18px;
			border-left: 4px solid #2271b1;
			border-radius: 6px;
			margin-bottom: 12px;
			transition: box-shadow 0.2s ease, transform 0.2s ease;
		}

		/* Hover effect */
		.git-branch-row:hover {
			box-shadow: 0 6px 16px rgba(0, 0, 0, 0.08);
			transform: translateY(-1px);
		}

		/* Branch name */
		.git-branch-name {
			font-size: 15px;
			font-weight: 600;
			color: #1d2327;
			margin: 0;
			word-break: break-all;
		}

		/* Protected branch */
		.git-branch-protected {
			font-size: 13px;
			font-weight: 600;
			color: #8c8f94;
		}

		/* Delete button */
		.git-branch-delete {
			background: #d63638;
			border-color: #d63638;
			color: #fff;
		}

		.git-branch-delete:hover {
			background: #b32d2e;
			border-color: #b32d2e;
		}

		/* Success & error messages */
		.git-notice {
			padding: 12px 16px;
			border-left: 4px solid;
			background: #fff;
			margin-top: 15px;
			border-radius: 4px;
		}

		.git-notice.success {
			border-color: #00a32a;
		}

		.git-notice.error {
			border-color: #d63638;
		}

		/* Mobile-friendly */
		@media (max-width: 600px) {
			.git-branch-row {
				flex-direction: column;
				align-items: flex-start;
			}

			.git-branch-row button {
				width: 100%;
			}
		}
	</style>
	<?php
}