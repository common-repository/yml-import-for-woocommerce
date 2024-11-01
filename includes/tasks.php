<?php
class YML_Importer_Tasks{
	private $categories;
	private $offers;
	private $date;
	private $started;
	private $limit;
	function __construct(){
		add_action('yml-importer', array($this, 'upload'), 10, 4);
		add_action('yml-importer-categories', array($this, 'getCategories'), 10, 5);
		add_action('yml-importer-products', array($this, 'getProducts'), 10, 4);
	}
	private function import_remote_file($url){
		$filename = basename($url);
		$filename .= '.txt';
		$upload = wp_upload_bits($filename, null, file_get_contents($url));
		if($upload['error']){
			return $upload;
		}
 
		// Construct the object array.
		$object = array(
			'post_title'     => wp_basename( $upload['file'] ),
			'post_content'   => $upload['url'],
			'post_mime_type' => $upload['type'],
			'guid'           => $upload['url'],
			'context'        => 'import',
			'post_status'    => 'private',
		);
 
		// Save the data.
		$id = wp_insert_attachment( $object, $upload['file'] );
 
		/*
		 * Schedule a cleanup for one day from now in case of failed
		 * import or missing wp_import_cleanup() call.
		 */
		wp_schedule_single_event( time() + DAY_IN_SECONDS, 'importer_scheduled_cleanup', array( $id ) );
 
		return array(
			'file' => $upload['file'],
			'id'   => $id,
		);
	}
	public function upload($url = false, $action = false, $partner_postfix = false, $product_type = false){
		if($url !== false){
			$file = $this->import_remote_file($url);
		}else{
			if(!isset($_REQUEST['upload'])){
				return;
			}
			check_admin_referer('yml-importer-upload');
			if(!function_exists('wp_handle_upload')){
				require_once(ABSPATH . 'wp-admin/includes/file.php');
			}
			if(!function_exists('wp_import_handle_upload')){
				require_once(ABSPATH . 'wp-admin/includes/import.php');
			}
			$file = wp_import_handle_upload();
		}
		if(!empty($file['error'])){
			if(wp_doing_cron()){
				error_log($file['error']);
			}else{
				$redirect = array(
					'page' => 'yml-importer',
					'message' => $file['error'],
				);
				wp_safe_redirect(add_query_arg($redirect, admin_url('tools.php')));
				exit;
			}
		}
		if(!file_exists($file['file'])){
			$error = sprintf( __( 'The export file could not be found at <code>%s</code>. It is likely that this was caused by a permissions problem.', 'wordpress-importer' ), esc_html( $file['file'] ) );
			if(wp_doing_cron()){
				error_log($error);
			}else{
				$redirect = array(
					'page' => 'yml-importer',
					'message' => $error,
				);
				wp_safe_redirect(add_query_arg($redirect, admin_url('tools.php')));
				exit;
			}
		}
		if($action === false
		&& isset($_REQUEST['action'])){
			$action = sanitize_text_field($_REQUEST['action']);
		}
		if($partner_postfix === false
		&& isset($_REQUEST['partner'])){
			$partner_postfix = sanitize_text_field($_REQUEST['partner']);
		}
		if($product_type === false
		&& isset($_REQUEST['type'])){
			$product_type = sanitize_text_field($_REQUEST['type']);
		}
		if($action == 'p'){
			$result = wp_schedule_single_event(time(), 'yml-importer-products', array(0, $file['id'], $partner_postfix, $product_type));
		}else{
			$result = wp_schedule_single_event(time(), 'yml-importer-categories', array(0, $file['id'], $action, $partner_postfix, $product_type));
		}
		if(wp_doing_cron()){
			return;
		}
		$redirect = array(
			'page' => 'yml-importer',
		);
		if($result){
			$redirect['message'] = 'scheduled';
		}else{
			$redirect['message'] = 'cant_schedule';
		}
		wp_safe_redirect(add_query_arg($redirect, admin_url('tools.php')));
		exit;
	}
	public function getXML($id){
		$dom = new DOMDocument;
		$old_value = null;
		if ( function_exists( 'libxml_disable_entity_loader' ) ) {
			$old_value = libxml_disable_entity_loader( true );
		}
		if(!$file = get_attached_file($id)){
			return false;
		}
		$success = $dom->loadXML( file_get_contents( $file ), LIBXML_PARSEHUGE );
		if ( ! is_null( $old_value ) ) {
			libxml_disable_entity_loader( $old_value );
		}

		if ( ! $success /*|| isset( $dom->doctype )*/ ) {
			$error = __( 'There was an error when reading this WXR file', 'wordpress-importer' );
			echo '<p>' . $error . '</p>';
			error_log($error);
			return false;
		}

		$xml = simplexml_import_dom( $dom );
		unset( $dom );

		// halt if loading produces an error
		if ( ! $xml ){
			$error = __( 'There was an error when reading this WXR file', 'wordpress-importer' );
			echo '<p>' . $error . '</p>';
			error_log($error);
			return false;
		}
		return $xml;
	}
	private function getCategory($id){
		$args = array(
			'hide_empty' => false,
			'taxonomy' => 'product_cat',
			'meta_query' => array(
				array(
					'key' => 'yml-importer:id',
					'value' => $id,
				),
			),
		);
		if(!$terms = get_terms( $args )){
			return false;
		}
		$term = array_pop($terms);
		return $term;
	}
	private function getTagId($tag){
		if($term = get_term_by('name', $tag, 'product_tag')){
			return $term->term_id;
		}
		$term = wp_insert_term($tag, 'product_tag');
		if(is_wp_error($term)){
			return false;
		}
		return $term['term_id'];
	}
	private function is_hook_scheduled($hook){
		$crons = _get_cron_array();
		if(empty($crons)){
			return 0;
		}
		$count = 0;
		foreach($crons as $timestamp => $cron){
			if(isset($cron[$hook])){
				$count++;
			}
		}
		return $count;
	}
	private function setLimit(){
		set_time_limit(0);
		if($max_execution_time = ini_get('max_execution_time')){
			$max_execution_time -= 10;
		}else{
			$max_execution_time = MINUTE_IN_SECONDS;
		}
		$this->limit = min(MINUTE_IN_SECONDS, $max_execution_time);
	}
	public function getProducts($o, $file_id, $partner_postfix, $product_type){
		if($this->is_hook_scheduled('yml-importer-categories')){
			wp_schedule_single_event(time() + MINUTE_IN_SECONDS, 'yml-importer-products', array($o, $file_id, $partner_postfix, $product_type));
			return;
		}
		$this->setLimit();
		if(!$xml = $this->getXML($file_id)){
			return;
		}
		$this->date = (string)$xml['date'];
		$this->started = time();
		$this->offers = $xml->shop->offers->offer;
		for($i = $o; $i < count($this->offers); $i++){
			if(time() - $this->started > $this->limit){
				wp_schedule_single_event(time(), 'yml-importer-products', array($i, $file_id, $partner_postfix, $product_type));
				return;
			}
			$offer = $this->offers[$i];
			$id = intval($offer['id']);
			$available = $offer['available'] == 'true';
			$args = array(
				'post_type' => 'product',
				'fields' => 'ids',
				'meta_query' => array(
					array(
						'key' => 'yml-importer:id',
						'value' => $id,
					),
				),
				'orderby' => 'ID',
				'order' => 'DESC',
			);
			if(!$products_ids = get_posts($args)){
				if(!$available){
					continue;
				}
				if($product_type == 'external'){
					$product = new WC_Product_External();
				}else{
					$product = new WC_Product();
				}
			}else{
				$products_id = array_pop($products_ids);
				$product = wc_get_product($products_id);
				while($products_id = array_pop($products_ids)){
					$p = wc_get_product($products_id);
					$p->delete();
				}
			}
			$url = (string)$offer->url;
			$url .= $partner_postfix;
			$price = floatval($offer->price);
			if(isset($offer->oldprice)){
				$oldprice = floatval($offer->oldprice);
			}else{
				$oldprice = false;
			}
			if(isset($offer->picture_big)){
				$picture = (string)$offer->picture_big;
			}else{
				$picture = (string)$offer->picture;
			}
			$name = (string)$offer->name;
			if($name == $description = (string)$offer->description){
				$description = '';
			}
			$tags = array();
			if(!empty($offer->author)){
				$tags[] = (string)$offer->author;
			}
			if(!empty($offer->publisher)){
				$tags[] = (string)$offer->publisher;
			}
			if(!empty($offer->series)){
				$tags[] = (string)$offer->series;
			}
			if(!empty($offer->vendor)){
				$tags[] = (string)$offer->vendor;
			}
			$delivery = $offer->delivery == 'true';
			$image_id = '';
			if($picture){
				$args = array(
					'post_type' => 'attachment',
					'fields' => 'ids',
					'meta_query' => array(
						array(
							'key' => 'yml-importer:picture',
							'value' => $picture,
						),
					),
				);
				if(!$image_ids = get_posts($args)){
					$filename = basename($picture);
					$upload_file = wp_upload_bits($filename, null, file_get_contents($picture));
					if(!$upload_file['error']){
						$wp_filetype = wp_check_filetype($filename, null);
						$attachment = array(
							'post_mime_type' => $wp_filetype['type'],
							'post_title' => $name,
							'post_content' => '',
							'post_status' => 'inherit'
						);
						$image_id = wp_insert_attachment($attachment, $upload_file['file']);
						if(!is_wp_error($image_id)){
							require_once(ABSPATH . "wp-admin" . '/includes/image.php');
							$attachment_data = wp_generate_attachment_metadata($image_id, $upload_file['file']);
							wp_update_attachment_metadata($image_id, $attachment_data);
						}
						update_post_meta($image_id, 'yml-importer:picture', $picture);
					}
				}else{
					$image_id = array_pop($image_ids);
				}
			}
			$categoryIds = array();
			foreach($offer->categoryId as $categoryId){
				$categoryId = intval($categoryId[0]);
				if($category = $this->getCategory($categoryId)){
					$categoryIds[] = $category->term_id;
				}
			}
			sort($categoryIds);
			if($product->get_meta('yml-importer:id', true) == $id
			&& $product->get_meta('yml-importer:date', true) == $this->date){
				continue;
			}
			$save = false;
			if($product->get_id()){
// update meta directly without touching the product save trigger
				update_post_meta($product->get_id(), 'yml-importer:id', $id);
				update_post_meta($product->get_id(), 'yml-importer:date', $this->date);
			}else{
				$product->update_meta_data('yml-importer:id', $id);
				$product->update_meta_data('yml-importer:date', $this->date);
				$save = true;
			}
			if($product->get_type() == 'external'){
				if($product->get_product_url() != $url){
					$product->set_product_url($url);
					$save = true;
				}
			}
//			$product->set_button_text('');
			if($product->get_name() != $name){
				$product->set_name($name);
				$save = true;
			}
//			$product->set_slug($slug);
			if($product->get_short_description() != $description){
				$product->set_short_description($description);
				$save = true;
			}
			if($product->get_description() != $description){
				$product->set_description($description);
				$save = true;
			}
			if($product->get_status() != 'publish'){
				$product->set_status('publish');
				$save = true;
			}
			if($oldprice){
				$regular_price = $oldprice;
				$sale_price = $price;
			}else{
				$regular_price = $price;
				$sale_price = '';
			}
			if($product->get_regular_price() != $regular_price){
				$product->set_regular_price($regular_price);
				$save = true;
			}
			if($product->get_sale_price() != $sale_price){
				$product->set_sale_price($sale_price);
				$save = true;
			}
			if($product->get_price() != $price){
				$product->set_price($price);
				$save = true;
			}
			$productCategoryIds = $product->get_category_ids();
			sort($productCategoryIds);
			if($productCategoryIds != $categoryIds){
				$product->set_category_ids($categoryIds);
				$save = true;
			}
			$tagIds = array();
			foreach($tags as $tag){
				if($tagId = $this->getTagId($tag)){
					$tagIds[] = $tagId;
				}
			}
			sort($tagIds);
			$productTagIds = $product->get_tag_ids();
			sort($productTagIds);
			if($tagIds != $productTagIds){
				$product->set_tag_ids($tagIds);
				$save = true;
			}
			if($available){
				if($product->get_stock_status() != 'instock'){
					$product->set_stock_status( 'instock' );
					$save = true;
				}
				if($product->get_catalog_visibility() != 'visible'){
					$product->set_catalog_visibility('visible');
					$save = true;
				}
			}else{
				if($product->get_type() == 'external'){
					if($product->get_catalog_visibility() != 'hidden'){
						$product->set_catalog_visibility('hidden');
						$save = true;
					}
				}else{
					if($product->get_stock_status() != 'outofstock'){
						$product->set_stock_status( 'outofstock' );
						$save = true;
					}
				}
			}
			if($product->get_image_id() != $image_id){
				$product->set_image_id($image_id);
				$save = true;
			}
//			$product->set_gallery_image_ids( array() );
/*			$product->set_featured(false);
			$product->set_virtual(false);
			$product->set_date_on_sale_from('');
			$product->set_date_on_sale_to('');
			$product->set_downloadable(false);
			if( isset($args['downloadable']) && $args['downloadable'] ) {
				$product->set_downloads(isset($args['downloads']) ? $args['downloads'] : array() );
				$product->set_download_limit(isset($args['download_limit']) ? $args['download_limit'] : '-1' );
				$product->set_download_expiry(isset($args['download_expiry']) ? $args['download_expiry'] : '-1' );
			}
			if ( get_option( 'woocommerce_calc_taxes' ) === 'yes' ) {
				$product->set_tax_status('taxable');
				$product->set_tax_class('');
			}
			if( isset($args['virtual']) && ! $args['virtual'] ) {
				$product->set_sku( isset( $args['sku'] ) ? $args['sku'] : '' );
				$product->set_manage_stock( isset( $args['manage_stock'] ) ? $args['manage_stock'] : false );
				$product->set_stock_status( isset( $args['stock_status'] ) ? $args['stock_status'] : 'instock' );
				if( isset( $args['manage_stock'] ) && $args['manage_stock'] ) {
					$product->set_stock_status( $args['stock_qty'] );
					$product->set_backorders( isset( $args['backorders'] ) ? $args['backorders'] : 'no' ); // 'yes', 'no' or 'notify'
				}
			}
			$product->set_sold_individually(false);
			$product->set_weight( '' );
			$product->set_length( '' );
			$product->set_width( '' );
			$product->set_height( '' );
			if( isset( $args['shipping_class_id'] ) ){
				$product->set_shipping_class_id( $args['shipping_class_id'] );
			}
			$product->set_upsell_ids( '' );
			$product->set_cross_sell_ids( '' );
			if( isset( $args['attributes'] ) ){
				$product->set_attributes( wc_prepare_product_attributes($args['attributes']) );
			}
			if( isset( $args['default_attributes'] ) )
				$product->set_default_attributes( $args['default_attributes'] ); // Needs a special formatting
			}
			$product->set_reviews_allowed( false );
			$product->set_purchase_note( '' );
			if( isset( $args['menu_order'] ) ){
				$product->set_menu_order( $args['menu_order'] );
			}
			if( isset( $args['tag_ids'] ) ){
				$product->set_tag_ids( $args['tag_ids'] );
			}*/
			if($save){
				$product_id = $product->save();
			}
		}
	}
	public function getCategories($parent, $file_id, $action, $partner_postfix, $product_type){
		if(empty($this->categories)){
			$this->setLimit();
			if(!$xml = $this->getXML($file_id)){
				return;
			}
			if(!isset($xml->shop->categories->category)){
				$this->categories = array();
			}else{
				$this->categories = $xml->shop->categories->category;
			}
			$this->date = (string)$xml['date'];
			$this->started = time();
			if(!$parent
			&& $action != 'c'){
				wp_schedule_single_event(time() + MINUTE_IN_SECONDS, 'yml-importer-products', array(0, $file_id, $partner_postfix, $product_type));
			}
		}
		if(!$parent){
			$_parent = 0;
		}else{
			if(!$term = $this->getCategory($parent)){
				return;
			}
			$_parent = $term->term_id;
			$_name = $term->name;
		}
		for($i = 0; $i < count($this->categories); $i++){
			$category = $this->categories[$i];
			if($parent != intval($category['parentId'])){
				continue;
			}
			$id = intval($category['id']);
			if($term = $this->getCategory($id)){
				$term_id = $term->term_id;
				if($term->parent != $_parent){
					wp_update_term($term_id, 'product_cat', array(
						'parent' => $_parent,
					));
				}
			}else{
				$name = (string)$category[0];
				$term = wp_insert_term($name, 'product_cat', array(
					'parent' => $_parent,
				));
				if(is_wp_error($term)){
					$term = wp_insert_term($name, 'product_cat', array(
						'parent' => $_parent,
						'slug' => $name . ' ' . $_name,
					));
					if(is_wp_error($term)){
						$term = wp_insert_term($name, 'product_cat', array(
							'parent' => $_parent,
							'slug' => $id,
						));
						if(is_wp_error($term)){
							$error = $term->get_error_message();
							error_log($error);
							continue;
						}
					}
				}
				$term_id = $term['term_id'];
				update_term_meta($term_id, 'yml-importer:id', $id);
			}
			if(time() - $this->started > $this->limit){
				wp_schedule_single_event(time(), 'yml-importer-categories', array($id, $file_id, $action, $partner_postfix, $product_type));
			}else{
				$this->getCategories($id, $file_id, $action, $partner_postfix, $product_type);
			}
		}
	}
}
