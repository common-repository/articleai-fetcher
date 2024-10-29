<?php
	/**
	 * Articleai plugin
	 *
	 * @package articleai
	 *
	 * Plugin Name: ArticleAI - AI Generated Articles
	 * Description: Retrieve articles from your ArticleAI queues and post to your
	 * WordPress
	 * Version: 1.0
	 * Author: ArticleAI Dev Team
	 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
// Exit if accessed directly.

/**
 * Register the menu page
 */
function articleai_plugin_menu() {
	add_menu_page(
		'ArticleAI Settings',
		'ArticleAI',
		'manage_options',
		'articleai',
		'articleai_plugin_options'
	);
}
add_action( 'admin_menu', 'articleai_plugin_menu' );

/**
 * Handle form submissions
 */
function articleai_handle_form_submission() {

	if ( isset( $_POST['articleai-save_api_key'] )
		&& ! empty( $_POST['articleai-api_key'] )
	) {
		check_admin_referer( 'articleai-settings' );

		update_option(
			'articleai_api_key',
			sanitize_text_field( wp_unslash( $_POST['articleai-api_key'] ) )
		);
		return 'Api key saved';
	}

	if ( isset( $_POST['articleai-add_queue'] )
		&& ! empty( $_POST['articleai-queue_name'] )
	) {
		check_admin_referer( 'articleai-settings' );

		// Add queue name to an array of queues stored in WP options.
		$queues   = get_option( 'articleai_queues', array() );
		$queues[] = sanitize_text_field( wp_unslash( $_POST['articleai-queue_name'] ) );
		update_option( 'articleai_queues', $queues );
		return 'Queue added';
	}

	if ( isset( $_POST['articleai-update_queue'] )
		&& ! empty( $_POST['articleai-update_queue'] ) &&
		isset( $_POST['article-idx'] )
	&& ! empty( $_POST['article-idx'] )
	) {
		check_admin_referer( 'articleai-settings' );

		$idx    = sanitize_text_field( wp_unslash( $_POST['article-idx'] ) );
		$queues = get_option( 'articleai_queues', array() );
		if ( isset( $_POST[ 'articleai-queue_name_' . $idx ] ) &&
		! empty( $_POST[ 'articleai-queue_name_' . $idx ] ) ) {
			$queues[ $idx ] = sanitize_text_field(
				wp_unslash(
					$_POST[ 'articleai-queue_name_' . $idx ]
				)
			);
			update_option( 'articleai_queues', $queues );
			articleai_update_article_fetcher_cron_jobs();
			return 'Queue updated';
		} else {
			return 'Queue not updated';
		}
	}

	if ( isset( $_POST['articleai-delete_queue'] )
		&& ! empty( $_POST['articleai-delete_queue'] ) &&
		isset( $_POST['article-idx'] )
	&& ! empty( $_POST['article-idx'] )
	) {
		check_admin_referer( 'articleai-settings' );

		$idx    = sanitize_text_field( wp_unslash( $_POST['article-idx'] ) );
		$queues = get_option( 'articleai_queues', array() );
		unset( $queues[ $idx ] );
		update_option( 'articleai_queues', $queues );
		$cats = articleai_get_all_categories();
		foreach ( $cats as $cat ) {
			$queue_val = get_option( 'articleai_queue_' . $cat['id'] );
			if ( (int) $queue_val === (int) $idx ) {
				delete_option( 'articleai_queue_' . $cat['id'] );
				delete_option( 'articleai_frequency_' . $cat['id'] );
			}
		}
		articleai_update_article_fetcher_cron_jobs();
		return 'Queue removed';
	}

	if ( isset( $_POST['articleai-save_mapping'] ) &&
	isset( $_POST['articleai-queue'] ) &&
	isset( $_POST['articleai-frequency'] )
	) {
		check_admin_referer( 'articleai-settings' );

		// Save queue and frequency for each category.
		$queue_array = map_deep( wp_unslash( $_POST['articleai-queue'] ), 'sanitize_text_field' );
		foreach ( $queue_array as $term_id => $queue ) {
			$term_id = trim( $term_id );
			if ( '' !== $queue ) {
				update_option( 'articleai_queue_' . $term_id, $queue );
			} else {
				delete_option( 'articleai_queue_' . $term_id );
			}
		}

		$frequency_array = map_deep(
			wp_unslash( $_POST['articleai-frequency'] ),
			'sanitize_text_field'
		);
		foreach ( $frequency_array as $term_id => $frequency ) {
			$term_id = trim( $term_id );
			if ( '' !== $frequency ) {
				update_option( 'articleai_frequency_' . $term_id, $frequency );
			} else {
				delete_option( 'articleai_frequency_' . $term_id );
			}
		}

		articleai_update_article_fetcher_cron_jobs();
		return 'Mapping saved';
	}
	return '';
}

/**
 * Get all categories including subcategories
 *
 * @param int    $my_parent Parent category.
 * @param string $spacing Space between categories.
 * @param array  $user_tree_array Initial tree array.
 */
function articleai_get_all_categories(
	$my_parent = 0,
	$spacing = '&nbsp;',
	$user_tree_array = array()
) {
	if ( ! is_array( $user_tree_array ) ) {
		$user_tree_array = array();
	}

	$args = array(
		'parent'     => $my_parent,
		'hide_empty' => false,
	);

	$categories = get_categories( $args );

	if ( $categories ) {
		foreach ( $categories as $category ) {
			$user_tree_array[] = array(
				'id'   => $category->term_id,
				'name' => $spacing . '- ' . $category->name,
			);
			$user_tree_array   = articleai_get_all_categories(
				$category->term_id,
				$spacing . '&nbsp;&nbsp;',
				$user_tree_array
			);
		}
	}

	return $user_tree_array;
}

/**
 * Display the menu page
 */
function articleai_plugin_options() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'You do not have sufficient permissions to access this page.' );
	}

	$result = articleai_handle_form_submission();

	// Get all categories.
	$categories = articleai_get_all_categories();

	// Get queues.
	$queues = get_option( 'articleai_queues', array() );

	?>
	<style>fieldset {
		margin: 8px;
		border: 1px solid silver;
		padding: 8px;
		border-radius: 4px;
	}

	legend {
		padding: 2px;
		font-weight:bold;
	}</style>
	<div class="container">
		<div class="row mt-5">
		<div class="col-12">
			<img
			src="<?php echo esc_attr( plugin_dir_url( __FILE__ ) ); ?>images/logo-color-1x.png"
			alt="ArticleAi" width="120" /><br/>
			<?php if ( '' !== $result ) { ?>
			<div class="alert alert-primary" role="alert">
				<?php echo esc_html( $result ); ?>
			</div>
			<?php } ?>
			<form method="post" action="" class="mt-5">
			<?php wp_nonce_field( 'articleai-settings' ); ?>
			<nav>
				<div class="nav nav-tabs nav-fill" id="nav-tab" role="tablist">
				<a class="nav-item nav-link active" id="nav-overview-tab"
				data-toggle="tab" href="#nav-overview" role="tab"
				aria-controls="nav-overview" aria-selected="true">Overview</a>
				<a class="nav-item nav-link" id="nav-credentials-tab"
				data-toggle="tab" href="#nav-credentials" role="tab"
				aria-controls="nav-credentials" aria-selected="true">Credentials</a>
				<a class="nav-item nav-link" id="nav-queues-tab"
				data-toggle="tab" href="#nav-queues" role="tab"
				aria-controls="nav-queues" aria-selected="false">Queues</a>
				<a class="nav-item nav-link" id="nav-categories-tab"
				data-toggle="tab" href="#nav-categories" role="tab"
				aria-controls="nav-categories" aria-selected="false">Categories</a>
				</div>
			</nav>
			<div class="tab-content" id="nav-tabContent">
				<div class="tab-pane fade show active" id="nav-overview"
				role="tabpanel" aria-labelledby="nav-overview-tab">

				<div class="container">
					<div class="row">
					<div class="col-md-12">
						<h2 class="mt-4">Overview</h2>
						<p>The ArticleAI WordPress plugin is designed to fetch
						and post articles automatically from the ArticleAI API
						based on configured schedules. It includes support for
						managing multiple categories and allows scheduling for
						every 2, 3, 4, ..., 30 days.</p>
					</div>
					</div>

					<div class="row">
					<div class="col-md-12">
						<h2 class="mt-4">Configuration</h2>
						<ol>
						<li>After activating the plugin, go to your WordPress
							dashboard and navigate to the settings page of the
							plugin.</li>
						<li>You'll see different sections for managing the
							settings. The available settings are:
							<ul>
							<li><b>API Key:</b> Enter your ArticleAI API key
								here. If the key is not entered or is invalid,
								the plugin won't function.</li>
							<li><b>Queues:</b> A list of available queues for
								fetching articles from the ArticleAI API. Enter
								your queue identifiers here. These queue
								identifiers must match the ones you set when
								creating an article on the ArticleAI website
								(articleai.io).</li>
							<li><b>Category Settings:</b> For each category,
								you can select a queue and a frequency. The queue
								is from where the articles will be fetched, and
								the frequency is how often the articles will be
								fetched.</li>
							</ul>
						</li>
						</ol>
					</div>
					</div>

					<div class="row">
					<div class="col-md-12">
						<h2 class="mt-4">Basic Usage Example</h2>
						<p>Let's assume we have two categories on our WordPress
						site, "Tech" and "Sports". We want to fetch articles for
						the "Tech" category every 3 days and for the "Sports"
						category every 2 days.</p>
						<p>Here is a basic step-by-step guide to achieve this:</p>
						<ol>
						<li>In the ArticleAI plugin settings page, enter your
							API key in the "API Key" field.</li>
						<li>In the "Queues" section, add two queues (for
							example, "tech_queue" and "sports_queue") that
							correspond to the "Tech" and "Sports" categories,
							respectively. These queues must match the ones you set
							on the ArticleAI website when creating the articles.</li>
						<li>In the "Category Settings" section, for the "Tech"
							category, select "tech_queue" as the queue and set the
							frequency to "3". Similarly, for the "Sports" category,
							select "sports_queue" as the queue and set the
							frequency to "2".</li>
						<li>Save the settings. The plugin is now configured and
							will automatically fetch articles based on these
							settings.</li>
						</ol>
					</div>
					</div>

					<div class="row">
					<div class="col-md-12">
						<h2 class="mt-4">Scheduled Tasks</h2>
						<ul>
						<li>The plugin uses WordPress Cron Jobs to schedule
							tasks. These tasks are the fetch and post operations
							that run based on the frequencies set in the category
							settings.</li>
						<li>You can use WordPress's built-in functions to list,
							add, or remove the scheduled tasks. The scheduled
							tasks can also be managed by using any third-party
							cron management plugin for WordPress.</li>
						</ul>
					</div>
					</div>

					<div class="row">
					<div class="col-md-12">
						<p class="mt-4">For any further questions or support,
						please contact the plugin developer or support team.</p>
					</div>
					</div>
				</div>

				</div>
				<div class="tab-pane fade show" id="nav-credentials"
				role="tabpanel" aria-labelledby="nav-credentials-tab">
				<!-- Section 1 -->
				<div class="form-group row mb-5 mt-5">
					<label class="col-xs-12 col-sm-2 col-form-label">
					API Key:
					</label>
					<div class="col-xs-12 col-sm-6 mb-3">
					<input class="form-control" type="text"
					name="articleai-api_key"
			value="<?php echo esc_attr( get_option( 'articleai_api_key' ) ); ?>"
					/>
					</div>
					<div class="col-xs-12 col-sm-3 text-left">
					<input class="btn btn-success" type="submit"
					name="articleai-save_api_key" value="Save" />
					</div>
				</div>
				</div>
				<div class="tab-pane fade" id="nav-queues" role="tabpanel"
				aria-labelledby="nav-queues-tab">
				<!-- Section 2-->
				<div class="form-group row mb-3 mt-5">
				<label class="col-xs-12 col-sm-2 col-form-label">
					Queue Name:
				</label>
				<div class="col-xs-12 col-sm-6 mb-3">
					<input class="form-control" type="text"
					id="articleai-queue_name" name="articleai-queue_name" />
				</div>
				<div class="col-xs-12 col-sm-3 text-left">
					<input class="btn btn-info" type="submit"
					name="articleai-add_queue" value="Add Queue" />
				</div>
				</div>

				<!-- Displaying saved queues -->
				<div class="form-group row mb-5">
				<div class="col-12">
					<label>Queues Registered:</label>
					<input type="hidden" id="article-idx" name="article-idx"
					value="" />
					<?php foreach ( $queues as $idx => $queue ) { ?>
					<div class="form-group row">
						<div class="col-xs-8 col-sm-8 mb-1">
						<input class="form-control" type="text"
						name="articleai-queue_name_<?php echo esc_html( $idx ); ?>"
						value="<?php echo esc_attr( $queue ); ?>" />
						</div>
						<div class="col-xs-4 col-sm-4 text-left">
						<input class="btn btn-warning mr-3" type="submit"
						name="articleai-update_queue"
						onClick="articleai_setIdx(<?php echo esc_html( $idx ); ?>)"
						value="Update" />
						<input class="btn btn-danger" type="submit"
						name="articleai-delete_queue"
						onClick="articleai_setIdx(<?php echo esc_html( $idx ); ?>)"
						value="Remove" />
						</div>
					</div>
					<?php } ?>
					<script>
					function articleai_setIdx(idx) {
					document.getElementById("article-idx").value=idx;
					return true;
					}
					</script>
				</div>
				</div>
			</div>
			<div class="tab-pane fade" id="nav-categories" role="tabpanel"
			aria-labelledby="nav-categories-tab">
				<!-- Section 3 -->
				<div class="form-group row mb-3 mt-5">
				<div class="col-12">
					<table class="table">
					<thead class="thead-dark"><tr><th>Category</th>
					<th>Queue</th><th>Frequency</th></tr></thead>
					<tbody>
					<?php foreach ( $categories as $category ) { ?>
						<tr>
						<td>
						<label>
							<?php echo esc_html( $category['name'] ); ?>
						</label>
						</td>
						<td>
						<select
						name="articleai-queue[
						<?php echo esc_html( $category['id'] ); ?>
						]">
							<option value="">- Select -</option>
							<?php foreach ( $queues as $pos => $queue ) { ?>
							<option
							value="<?php echo esc_attr( $pos ); ?>"
								<?php
								echo selected(
									$pos,
									get_option(
										'articleai_queue_' .
										$category['id']
									)
								);
								?>
								>
								<?php echo esc_html( $queue ); ?>
							</option>
							<?php } ?>
						</select>
						</td>
						<td>
						<select name="articleai-frequency[
						<?php echo esc_html( $category['id'] ); ?>
						]">
							<option value="">- Select -</option>
							<?php for ( $i = 1; $i <= 30; $i++ ) { ?>
							<option value="<?php echo esc_attr( $i ); ?>"
								<?php
								echo selected(
									$i,
									get_option(
										'articleai_frequency_' .
										$category['id']
									)
								);
								?>
								>
								<?php echo esc_html( $i ); ?> day
							</option>'
							<?php } ?>
						</select>
						</td>
					</tr>
					<?php } ?>
					</tbody>
					</table>
					<div class="col-12 text-right">
					<input class="btn btn-primary" type="submit"
					name="articleai-save_mapping" value="Save Mapping" />
					</div>
				</div>
				</div>
			</div>
			</form>
		</div>
		</div>
	</div>
	</div>
	<?php
}







/**
 * Fetch articles from articleai.io queue name and post on the WP category_id
 *
 * @param string $queue Queue name.
 * @param int    $category_id Category id.
 */
function articleai_fetch_and_post_articles_for_queue( $queue, $category_id ) {

	// Set the API key and headers.
	$api_key = get_option( 'articleai_api_key', '' );
	if ( '' === $api_key ) {
		return;
	}
	$headers = array(
		'Content-Type' => 'application/json',
		'Accept'       => 'application/json',
		'apiKey'       => $api_key,
	);
	// Set the API endpoint.
	$endpoint = 'https://www.articleai.io/article/queue/' . $queue . '/1';

	$raw_response = wp_remote_get(
		$endpoint,
		array(
			'headers'    => $headers,
			'user-agent' => 'Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:101.0) Gecko/20100101 Firefox/101.0',
		)
	);

	if ( is_wp_error( $raw_response ) ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG === true ) {
		  // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			error_log(
				'Error: Invalid RAW response from ArticleAI API for queue: '
				. $queue
			);
		}
		return;
	}

	$response = wp_remote_retrieve_body( $raw_response );

	// Decode the JSON response.
	$data = json_decode( $response, true );
	if ( ! $data ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG === true ) {
		  // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			error_log(
				'Error: Invalid JSON response from ArticleAI API for queue: '
				. $queue
			);
		}
		return;
	}

	// Loop through the response data and create posts.
	foreach ( $data as $article ) {
		$article['data'] = json_decode( $article['data'], true );

		// Create the content for the post.
		$content = '';
		foreach ( $article['data']['article'] as $paragraph ) {
			$content .= '<p>' . $paragraph . '</p>';
		}

		// Define the post data.
		$post_data = array(
			'post_title'    => wp_strip_all_tags( $article['title'] ),
			'post_content'  => $content,
			'post_status'   => 'publish',
			'post_author'   => 1,
			'post_category' => array( $category_id ),
		);

		// Insert the post.
		$post_id = wp_insert_post( $post_data );

		if ( is_wp_error( $post_id ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG === true ) {
			  // phpcs:ignore WordPress.PHP.DevelopmentFunctions
				error_log( 'Error inserting post: ' . $post_id->get_error_message() );
			}
		}
	}
}

/**
 * Add the cron schedule
 *
 * @param array $schedules output array.
 */
function articleai_add_custom_cron_schedule( $schedules ) {
	// Adding custom cron schedules for every 2, 3, 4, ..., 30 days.
	for ( $i = 2; $i <= 30; $i++ ) {
		$schedules[ 'every_' . $i . '_days' ] = array(
			'interval' => $i * DAY_IN_SECONDS,
			'display'  => 'Every ' . $i . ' Days',
		);
	}
	return $schedules;
}
// phpcs:ignore WordPress.WP.CronInterval
add_filter( 'cron_schedules', 'articleai_add_custom_cron_schedule' );

/**
 * Update Cron Jobs
 */
function articleai_update_article_fetcher_cron_jobs() {
	// Get current options.
	$queue_names = get_option( 'articleai_queues', array() );
	$cats        = articleai_get_all_categories();

	foreach ( $cats as $cat ) {
		$category_id = $cat['id'];

		$queue_val = trim( get_option( 'articleai_queue_' . $category_id ) );
		$queue     = '';
		if ( '' !== $queue_val && isset( $queue_names[ $queue_val ] ) ) {
			$queue = $queue_names[ $queue_val ];
		}

		$frequency_val = get_option( 'articleai_frequency_' . $category_id );
		$hook_name     = 'articleai-fetch_and_post_articles_for_' . $category_id;
		$old_args      = get_option( 'old_args_' . $hook_name, array() );
		$args          = array( $queue, $category_id );

		$schedule  = wp_get_schedule( $hook_name );
		$schedules = wp_get_schedules();
		$interval  = '';
		if ( isset( $schedules[ $schedule ] ) ) {
			$interval = $schedules[ $schedule ]['interval'];
		}

		$add = false;
		if ( ! wp_next_scheduled( $hook_name, $args )
		|| $interval !== $frequency_val || '' === $queue ) {
			if ( '' !== $frequency_val && '' !== $queue ) {
				$add = true;
			}
			if ( $old_args ) {
				// Unscheduling the old event.
				wp_clear_scheduled_hook( $hook_name, $old_args );
				delete_option( 'old_args_' . $hook_name );
			}
		}

		if ( $add ) {
			if ( '1' === $frequency_val ) {
				wp_schedule_event( time(), 'daily', $hook_name, $args );
			} else {
				wp_schedule_event(
					time(),
					'every_' . $frequency_val . '_days',
					$hook_name,
					$args
				);
			}
			update_option( 'old_args_' . $hook_name, $args );

		}
	}
}

/**
 * Deactivate plugin
 */
function articleai_deactivate_my_plugin() {
	// Get all scheduled events.
	$scheduled_events = get_option( 'cron', array() );
	// Unschedule events for removed queues.
	foreach ( $scheduled_events as $timestamp => $events ) {
		if ( 'version' !== $timestamp ) {
			foreach ( $events as $hook_name => $data ) {
				if ( strpos(
					$hook_name,
					'articleai-fetch_and_post_articles_for_'
				) !== false
				) {
					wp_clear_scheduled_hook( $hook_name );
				}
			}
		}
	}
}

/**
 * Enqueue admin scripts
 *
 * @param string $hook Hook name.
 */
function articleai_enqueue_admin_scripts( $hook ) {
	if ( 'toplevel_page_articleai' !== $hook ) {
		return;
	}
	wp_enqueue_style(
		'bootstrap-css',
		plugin_dir_url( __FILE__ ) .
		'css/bootstrap.min.css',
		array(),
		'4.3.1'
	);

	// Enqueue jQuery (comes with WordPress).
	wp_enqueue_script( 'jquery' );

	// Enqueue Bootstrap JS.
	wp_enqueue_script(
		'bootstrap-js',
		plugin_dir_url( __FILE__ ) .
		'js/bootstrap.min.js',
		array( 'jquery' ),
		'4.3.1',
		array( 'in_footer' => true )
	);
}
add_action( 'admin_enqueue_scripts', 'articleai_enqueue_admin_scripts' );

register_deactivation_hook( __FILE__, 'articleai_deactivate_my_plugin' );

/**
 * Activate plugin
 */
function articleai_activate_my_plugin() {
	articleai_update_article_fetcher_cron_jobs();
}
register_activation_hook( __FILE__, 'articleai_activate_my_plugin' );

foreach ( articleai_get_all_categories() as $my_cat ) {
	$category_id = $my_cat['id'];
	$hook_name   = 'articleai-fetch_and_post_articles_for_' . $category_id;
	// Add the action. This ensures that the action hook is always registered.
	add_action( $hook_name, 'articleai_fetch_and_post_articles_for_queue', 10, 2 );
}

?>
