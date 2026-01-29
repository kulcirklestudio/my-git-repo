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

function render_create_git_branch_page()
{
	if (!current_user_can('manage_options')) {
		return;
	}

	$message = '';

	if (isset($_POST['create_git_branch'])) {
		check_admin_referer('create_git_branch_nonce');

		$branch = trim($_POST['branch_name']);

		// Validate branch name
		if (!preg_match('/^[a-zA-Z0-9._\-\/]+$/', $branch)) {
			$message = '❌ Invalid branch name.';
		} else {

			// CHANGE PATH if WP is not in repo root
			$repo_path = ABSPATH;

			$cmd = "cd " . escapeshellarg($repo_path) . " && "
				. "git fetch origin && "
				. "git checkout main && "
				. "git pull origin main && "
				. "git checkout -b " . escapeshellarg($branch) . " && "
				. "git push --set-upstream origin " . escapeshellarg($branch);

			exec($cmd . " 2>&1", $output, $status);


			if ($status === 0) {
				$message = "✅ Branch '{$branch}' created & pushed successfully.";
			} else {
				$message = "❌ Error:\n" . implode("\n", $output);
			}
		}
	}
	?>

	<div class="wrap">
		<h1>Create Git Branch</h1>

		<form method="post">
			<?php wp_nonce_field('create_git_branch_nonce'); ?>

			<table class="form-table">
				<tr>
					<th>Branch Name</th>
					<td>
						<input type="text" name="branch_name" required placeholder="New Branch Name:" style="width:300px;">
					</td>
				</tr>
			</table>

			<p>
				<button type="submit" name="create_git_branch" class="button button-primary">
					Create Branch
				</button>
			</p>
		</form>

		<?php if ($message): ?>
			<pre style="background:#fff;padding:12px;border-left:4px solid #2271b1;"> <?php echo esc_html($message); ?> </pre>
		<?php endif; ?>

		<!---------------------------------------
		---------------------	List All Branches
		-->
		<div class="list-all-branch" style="background:#fff0;padding:20px;border:4px solid #2272b1;border-radius:10px">
			<h1 style="font-size:25px;text-align: center;font-weight: 700;">All Branches</h1>

			<?php
			// CHANGE PATH if WP is not in repo root
			$repo_path = ABSPATH;

			// IMPORTANT: isolate exec output
			$branch_output = [];
			$branch_status = null;

			// Fetch branches safely (NO noise)
			$cmd = "cd " . escapeshellarg($repo_path)
				. " && git fetch origin --quiet"
				. " && git for-each-ref --format=%(refname:short)";

			exec($cmd . " 2>&1", $branch_output, $branch_status);

			$branches = [];

			foreach ($branch_output as $line) {
				$line = trim($line);

				// Skip HEAD pointer
				if ($line === 'origin/HEAD') {
					continue;
				}

				// Allow ONLY origin branches
				if (!preg_match('#^origin/[a-zA-Z0-9._\-/]+$#', $line)) {
					continue;
				}

				// Remove origin/
				$branches[] = substr($line, 7);
			}

			$protected_branches = ['main', 'master'];

			foreach ($branches as $branch) {
				$is_protected = in_array($branch, $protected_branches);
				?>
				<div
					style="display: flex; justify-content: space-between; align-items: center; gap: 20px; background:#fff; padding: 16px; border-left: 4px solid #2271b1; margin: 10px; border-radius: 4px;">
					<p style="font-size: 16px;font-weight:700;margin:0px;" data-branch="<?php echo esc_html($branch); ?>">
						<?php echo esc_html($branch); ?>
					</p>

					<?php if (!$is_protected) { ?>
						<button type="submit" name="delete_git_branch" class="button button-primary"
							style="font-size: 14px;font-weight:500;" data-branch="<?php echo esc_html($branch); ?>">
							Delete Branch
						</button>
					<?php } else {
						echo '<p style="font-size: 14px;font-weight:500;margin:0px;">Main Branch Cannot Delete!!!</p>';
					} ?>

				</div>
			<?php } ?>

			<form method="post" id="deleteBranchForm">
				<?php wp_nonce_field('delete_git_branch_nonce'); ?>
				<input type="hidden" name="branch_to_delete" id="delete_branch_input">
			</form>

		</div>
		<script>
			document.addEventListener('click', function (e) {
				if (e.target.name === 'delete_git_branch') {
					if (!confirm('This will BACKUP and DELETE the branch. Continue?')) return;

					const branch = e.target.dataset.branch;
					document.getElementById('delete_branch_input').value = branch;
					document.getElementById('deleteBranchForm').submit();
				}
			});

		</script>
	</div>
	<?php
}