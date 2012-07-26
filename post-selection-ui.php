<?php
/*
Plugin Name: Post Selection UI
Description: An extraction of the post selection interface from the posts-to-posts plugin
Version: 0.1
Author: prettyboymp
Plugin URI: http://voceconnect.com

*/

class Post_Selection_UI {
	
	public static function init() {
		add_action('wp_ajax_psu_box', array(__CLASS__, 'handle_ajax_search'));
		add_action('admin_enqueue_scripts', array(__CLASS__, 'admin_enqueue_scripts'));
	}
	
	public static function admin_enqueue_scripts() {
		wp_enqueue_style('post-selection-ui', self::local_url( 'post-selection-ui.css', __FILE__ ), array() );
		wp_enqueue_script( 'post-selection-ui', self::local_url( 'post-selection-ui.js', __FILE__ ), array( 'jquery', 'jquery-ui-core', 'jquery-ui-sortable' ), null, true );

		wp_localize_script( 'post-selection-ui', 'PostSelectionUI', array(
			'nonce' => wp_create_nonce( 'psu_search' ),
			'spinner' => admin_url( 'images/wpspin_light.gif' ),
			'clearConfirmMessage' => __( 'Are you sure you want to clear the selected items?' ),
		) );

	}
	
	public static function local_url($relative_path, $plugin_path) {
		$template_dir = get_template_directory();

		foreach ( array( 'template_dir', 'plugin_path' ) as $var ) {
			$$var = str_replace( '\\', '/', $$var ); // sanitize for Win32 installs
			$$var = preg_replace( '|/+|', '/', $$var );
		}
		if ( 0 === strpos( $plugin_path, $template_dir ) ) {
			$url = get_template_directory_uri();
			$folder = str_replace( $template_dir, '', dirname( $plugin_path ) );
			if ( '.' != $folder ) {
				$url .= '/' . ltrim( $folder, '/' );
			}
			if ( !empty( $relative_path ) && is_string( $relative_path ) && strpos( $relative_path, '..' ) === false ) {
				$url .= '/' . ltrim( $relative_path, '/' );
			}
			return $url;
		} else {
			return plugins_url( $relative_path, $plugin_path );
		}
	}
	
	public static function handle_ajax_search() {
		check_ajax_referer('psu_search');
		
		$args = array(
			'post_type' => array()
		);
		
		if(!empty($_GET['post_type']) ) {
			$unsanitized_post_types = array_map('sanitize_key', explode(',', $_GET['post_type']));
			foreach($unsanitized_post_types as $post_type) {
			 if(($post_type_obj = get_post_type_object( $post_type )) && current_user_can($post_type_obj->cap->read)) {
				 $args['post_type'][] = $post_type;
			 }
			}
		}
		if(count($args['post_type']) < 1)
			die('-1');
		
		if(!empty($_GET['paged'])) {
			$args['paged'] = absint($_GET['paged']);
		}
		if(!empty($_GET['s'])) {
			$args['s'] = $_GET['s'];
		}
		
		if(!empty($_GET['exclude'])) {
			$selected = array_map('intval', explode(',', $_GET['exclude']));
		} else {
			$selected = array();
		}
		
		$psu_box = new Post_Selection_Box('foobar', array('post_type' => $args['post_type'], 'selected' => $selected));
		
		$response = new stdClass();
		$response->rows = $psu_box->render_results($args);
		die(json_encode($response));
	}
	
}
add_action('init', array('Post_Selection_UI', 'init'));

function post_selection_ui($name, $args) {
	$select_box = new Post_Selection_Box($name, $args);
	return $select_box->render();
}


class Post_Selection_Box {
	private $name;
	private $args;
	
	public function __construct($name, $args = array() ) {
		$defaults = array(
			'post_type' => array('post'),
			'post_status' => array('publish'),
			'limit' => 0,
			'selected' => array(),
			'id' => $name,
			'labels' => array(),
			'sortable' => true
		);
		$args = wp_parse_args($args, $defaults);
		$args['selected'] = array_map('intval', $args['selected']);
		
		$args['post_type'] = (array) $args['post_type'];
		
		if(count($args['post_type']) > 1) {
			$default_labels = array(
				'name' => 'Items',
				'singular_name' => 'Item',
			);
		} else {
			$post_type = get_post_type_object($args['post_type'][0]);
			$default_labels = (array) $post_type->labels;
		}
		$args['labels'] = wp_parse_args($args['labels'], $default_labels);
		
		$this->args = $args;
		
		$this->name = $name;
	}
	
	private function get_addable_query($args) {
		$defaults = array(
			'post_type' => $this->args['post_type'],
			'post_status' => $this->args['post_status'],
			'posts_per_page' => 10,
			'post__not_in' => $this->args['selected'],
			'paged' => 1
		);
			
		$query_args = wp_parse_args($args, $defaults);
		return new WP_Query($query_args);
	}

	/**
	 * Renders the add_rows for the selection box
	 * @param WP_Query $wp_query
	 * @return string 
	 */
	public function render_addable_rows($wp_query) {
		$output = '';
		foreach($wp_query->posts as $post) {
			if(!get_post($post->ID)) {
				continue;
			}
			if( current_user_can( get_post_type_object( $post->post_type )->cap->edit_post, $post->ID ) )
				$title = sprintf( '<a href="%s" title="Edit Post" target="_blank">%s</a>', get_edit_post_link( $post->ID ), esc_html( get_the_title( $post->ID ) ) );	
			else
				$title = esc_html(get_the_title($post->ID));
			
			$title = apply_filters('post-selection-ui-row-title', $title, $post->ID, $this->name, $this->args);
			$output .= "<tr data-post_id='{$post->ID}' data-permalink='".  get_permalink($post->ID) . "'>\n".
				"\t<td class='psu-col-create'><a href='#' title='Add'></a></td>".
				"\t<td class='psu-col-title'>\n";
			$output .= $title;
			$output .= "\n\t</td>\n</tr>\n";
		}
		return $output;
	}
	
	/**
	 * Renders the s_rows for the selection box
	 * @param array $post_ids
	 * @return string 
	 * 
	 * @todo look into pre-caching the posts all at once
	 */
	private function render_selected_rows($post_ids) {
		$output = '';
		foreach($post_ids as $post_id) {
			if(!get_post($post_id)) {
				continue;
			}
			
			if( current_user_can( get_post_type_object( get_post_type($post_id) )->cap->edit_post, $post_id ) )
				$title = sprintf( '<a href="%s" title="Edit Post" target="_blank">%s</a>', get_edit_post_link( $post_id ), esc_html( get_the_title( $post_id ) ) );	
			else
				$title = esc_html( get_the_title( $post_id ) );
			
			$title = apply_filters('post-selection-ui-row-title', $title, $post_id, $this->name, $this->args);

			$output .= "<tr data-post_id='{$post_id}'>\n".
				"\t<td class='psu-col-delete'><a href='#' title='Remove'></a></td>".
				"\t<td class='psu-col-title'>\n";
			$output .= $title;
			$output .= "\n</td>\n";
			if($this->args['sortable']) {
				$output .= "\t<td class='psu-col-order'>&nbsp;</td>";
			}
			$output .= "</tr>\n";
		}
		return $output;
	}
	
	public function render_results($args) {
		$wp_query = $this->get_addable_query($args);
		$cpage = intval($wp_query->get('paged'));
		$max_pages = intval($wp_query->max_num_pages);
		
		$output = "<table class='psu-results'>\n".
			"\t<tbody>" . $this->render_addable_rows($wp_query) . "</tbody>\n".
			"</table>".
			"<div class='psu-navigation'>\n".
			"\t<div class='psu-prev button inactive' title='previous'>&lsaquo;</div>".
			"\t<div>\n".
			"\t\t<span class='psu-current' data-num='".$cpage."'>".$cpage."</span>\n".
			"\t\tof\n".
			"\t\t<span class='psu-total' data-num='".$max_pages."'>".$max_pages."</span>\n".
			"\t</div>\n".
			"\t<div class='psu-next button ' title='next'>&rsaquo;</div>\n".
			"</div>\n";
		return $output;
	}
	
	public function render() {
		ob_start();
		?>
		<div id="<?php echo esc_attr($this->args['id'] )?>" class="psu-box" data-post_type='<?php echo esc_attr(implode(',', $this->args['post_type'])) ?>' data-cardinality='<?php echo $this->args['limit'] ?>'>
			<input type="hidden" name="<?php echo esc_attr($this->name); ?>" value="<?php echo join(',', $this->args['selected']) ?>" />
			<table class="psu-selected" >
				<?php if($this->args['limit'] != 1): ?>
				<thead>
					<tr>
						<th class="psu-col-delete"><a href="#" title="<?php printf(__("Remove all %s"), $this->args['labels']['name']) ?>"></a></th>
						<th class="psu-col-title"><?php echo esc_html($this->args['labels']['singular_name']); ?></th>
						<?php if($this->args['sortable']) : ?>
							<th class="psu-col-order"><?php _e('Sort'); ?></th>
						<?php endif; ?>
					</tr>
				</thead>
				<?php endif; ?>
				<tbody>
					<?php echo $this->render_selected_rows($this->args['selected']); ?>
				</tbody>
			</table>

			<div class="psu-add-posts" >
				<p><strong><?php printf(__('Add %s'), esc_html($this->args['labels']['singular_name'])); ?>:</strong></p>

				<ul class="wp-tab-bar clearfix">
					<li class="wp-tab-active" data-ref=".psu-tab-list"><a href="#">View All</a></li>
					<li data-ref=".psu-tab-search"><a href="#">Search</a></li>
				</ul>

				<div class="psu-tab-search tabs-panel">
					<div class="psu-search">
						<input type="text" name="p2p_search" autocomplete="off" placeholder="Search Posts" />
					</div>
				</div>
				
				<div class="psu-tab-list tabs-panel">
					<?php echo $this->render_results(array()); ?>

				</div>
			</div>

		</div>
		<?php
		return ob_get_clean();
	}
}