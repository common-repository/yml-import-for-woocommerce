<?php
require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
class YML_Importer_Table extends WP_List_Table{
	public function __construct(){
		parent::__construct(array(
			'singular' => 'yml-importer-event',
			'plural'   => 'yml-importer-events',
			'ajax'     => false,
			'screen'   => 'yml-importer-events',
		));
	}
	public function prepare_items(){
		$events = $this->getEvents();

		$count    = count($events);
		$per_page = 50;
		$offset   = ($this->get_pagenum() - 1) * $per_page;

		$this->items = array_slice( $events, $offset, $per_page );

		$this->set_pagination_args(array(
			'total_items' => $count,
			'per_page'    => $per_page,
			'total_pages' => ceil( $count / $per_page ),
		));
	}
	public function get_columns(){
		return array(
			'cb' => '<input type="checkbox" />',
			'yml_file' => __('YML File', 'yml-importer'),
			'yml_action' => __('Action', 'yml-importer'),
			'yml_partner_link' => __('Partner Link', 'yml-importer'),
			'yml_product_type' => __('Product Type', 'yml-importer'),
			'yml_next_run' => __('Next Run', 'yml-importer'),
			'yml_recurrence' => __('Recurrence', 'yml-importer'),
		);
	}
	protected function get_table_classes() {
		return array( 'widefat', 'striped', $this->_args['plural'] );
	}
	protected function get_bulk_actions(){
		return array(
			'yml-importer-delete' => esc_html__('Delete', 'yml-importer'),
		);
	}
	public function single_row($event){
	?>
<tr>
	<?php $this->single_row_columns($event); ?>
</tr>
	<?php
	}
	protected function handle_row_actions( $event, $column_name, $primary ) {
		if ( $primary !== $column_name ) {
			return '';
		}
		$links = array();
		$link = array(
			'page' => 'yml-importer',
			'id' => rawurlencode($event['key']),
		);
		$link = add_query_arg($link, admin_url('tools.php'));
		$links[] = "<a href='" . esc_url( $link ) . "'>" . esc_html__('Edit', 'yml-importer') . '</a>';
		$link = array(
			'page' => 'yml-importer',
			'run' => rawurlencode($event['key']),
		);
		$link = add_query_arg($link, admin_url('tools.php'));
		$link = wp_nonce_url($link, "yml-importer-run-{$event['key']}");
		$links[] = "<a href='" . esc_url( $link ) . "'>" . esc_html__('Run Now', 'yml-importer') . '</a>';
		$link = array(
			'page' => 'yml-importer',
			'delete' => rawurlencode($event['key']),
		);
		$link = add_query_arg($link, admin_url('tools.php'));
		$link = wp_nonce_url($link, "yml-importer-delete-{$event['key']}");
		$links[] = "<span class='delete'><a href='" . esc_url( $link ) . "'>" . esc_html__('Delete', 'yml-importer') . '</a></span>';
		return $this->row_actions($links);
	}
	protected function column_cb($event){
		printf(
			'<input type="checkbox" name="ids[]" value="%s">',
			esc_attr($event['key'])
		);
	}
	protected function column_yml_file($event){
		echo esc_html($event['args'][0]);
	}
	protected function column_yml_action($event){
		echo esc_html(YML_Importer::instance()->getActions()[$event['args'][1]]);
	}
	protected function column_yml_partner_link($event){
		echo esc_html($event['args'][2]);
	}
	protected function column_yml_product_type($event){
		$product_types = wc_get_product_types();
		echo esc_html($product_types[$event['args'][3]]);
	}
	protected function column_yml_next_run($event){
		echo esc_html(gmdate('Y-m-d H:i', $event['time']));
	}
	protected function column_yml_recurrence($event){
		$schedules = wp_get_schedules();
		echo esc_html($schedules[$event['schedule']]['display']);
	}
	private function getEvents(){
		$events = array();
		$crons = _get_cron_array();
		foreach($crons as $timestamp => $cron){
			if(!isset($cron['yml-importer'])){
				continue;
			}
			$keys = array_keys($cron['yml-importer']);
			$key = array_pop($keys);
			$event = $crons[$timestamp]['yml-importer'][$key];
			if(!$event['schedule']){
				continue;
			}
			$event['key'] = $key;
			$event['time'] = $timestamp;
			$events[] = $event;
		}
		return $events;
	}
}
