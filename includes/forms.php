<?php
class YML_Importer_Forms{
	public function scheduleForm(){
		if(!isset($_GET['id'])
		|| !$event = YML_Importer::instance()->getEvent(sanitize_key($_GET['id']))){
			$key = false;
			$label = 'Add New Task';
			$url = '';
			$start = '';
			$action = '';
			$partner_link = '';
			$product_type = '';
			$recurrence = '';
			$cancel = '';
		}else{
			$key = sanitize_key($_GET['id']);
			$label = 'Edit Task';
			$start = gmdate('H:i', $event['time']);
			$url = $event['args'][0];
			$action = $event['args'][1];
			$partner_link = $event['args'][2];
			$product_type = $event['args'][3];
			$recurrence = $event['schedule'];
			$link = array(
				'page' => 'yml-importer',
			);
			$link = add_query_arg($link, admin_url('tools.php'));
			$cancel = sprintf(
				'<a class="button" href="%s">%s</a>',
				esc_url($link),
				esc_html__('Cancel', 'yml-importer')
			);
		}
		printf('<h2>%s</h2>', esc_html__($label, 'yml-importer'));
		?>
<form method="post" enctype="multipart/form-data">
		<?php
		if($key){
			?>
	<input type="hidden" name="id" value="<?php echo esc_attr($key); ?>">
			<?php
			echo wp_nonce_field("yml-importer-edit-$key");
		}
		?>
	<p>
		<label for="start"><?php esc_html_e('Start at', 'yml-importer'); ?></label><br>
		<input required type="text" id="start" name="start" value="<?php echo esc_attr($start); ?>" placeholder="00:00">
	</p>
	<p>
		<label for="url"><?php esc_html_e('YML File', 'yml-importer'); ?></label><br>
		<input required type="text" id="url" name="url" value="<?php echo esc_attr($url); ?>" placeholder="https://">
	</p>
	<p>
		<label for="action"><?php esc_html_e('Action', 'yml-importer'); ?></label><br>
		<select id="action" name="action">
		<?php
		foreach(YML_Importer::instance()->getActions() as $key => $value){
		?>
			<option <?php echo $action==$key?'selected':''; ?> value="<?php echo esc_attr($key); ?>"><?php echo esc_html($value); ?></option>
		<?php
		}
		?>
		</select>
	</p>
	<p>
		<label for="partner"><?php esc_html_e('Partner Link', 'yml-importer'); ?></label><br>
		<input type="text" id="partner" name="partner" value="<?php echo esc_attr($partner_link); ?>" placeholder="?partner=19657">
	</p>
		<?php
		$product_types = wc_get_product_types();
		?>
	<p>
		<label for="type"><?php esc_html_e('Product Type', 'yml-importer'); ?></label><br>
		<select id="type" name="type">
			<option <?php echo $product_type=='simple'?'selected':''; ?> value="simple"><?php echo esc_html($product_types['simple']); ?></option>
			<option <?php echo $product_type=='external'?'selected':''; ?> value="external"><?php echo esc_html($product_types['external']); ?></option>
		</select>
	</p>
	<p>
		<label for="recurrence"><?php esc_html_e('Recurrence', 'yml-importer'); ?></label><br>
		<select id="recurrence" name="recurrence">
		<?php
		foreach(wp_get_schedules() as $key => $value){
		?>
			<option <?php echo $recurrence==$key?'selected':''; ?> value="<?php echo esc_attr($key); ?>"><?php echo esc_html($value['display']); ?></option>
		<?php
		}
		?>
		</select>
	</p>
	<p>
		<input class="button" type="submit">
		<?php echo $cancel; ?>
	</p>
</form>
		<?php
	}
	public function nowForm(){
		printf('<h2>%s</h2>', esc_html__('Import File Now', 'yml-importer'));
		$bytes = apply_filters( 'import_upload_size_limit', wp_max_upload_size() );
		$size = size_format( $bytes );
		?>
<form method="post" enctype="multipart/form-data">
	<input type="hidden" name="upload" value="1">
	<?php echo wp_nonce_field('yml-importer-upload'); ?>
	<p>
		<label for="import"><?php echo esc_html(sprintf(__('File (%s)', 'yml-importer'), $size)); ?></label><br>
		<input type="file" name="import" id="import">
	</p>
	<p>
		<label for="action-now"><?php esc_html_e('Action', 'yml-importer'); ?></label><br>
		<select id="action-now" name="action">
		<?php
		foreach(YML_Importer::instance()->getActions() as $key => $value){
		?>
			<option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($value); ?></option>
		<?php
		}
		?>
		</select>
	</p>
	<p>
		<label for="partner-now"><?php esc_html_e('Partner Link', 'yml-importer'); ?></label><br>
		<input type="text" id="partner-now" name="partner" value="" placeholder="?partner=19657">
	</p>
		<?php
		$product_types = wc_get_product_types();
		?>
	<p>
		<label for="type-now"><?php esc_html_e('Product Type', 'yml-importer'); ?></label><br>
		<select id="type-now" name="type">
			<option value="simple"><?php echo esc_html($product_types['simple']); ?></option>
			<option value="external"><?php echo esc_html($product_types['external']); ?></option>
		</select>
	</p>
	<p>
		<input class="button" type="submit">
	</p>
</form>
		<?php
	}
}
