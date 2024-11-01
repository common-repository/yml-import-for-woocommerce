<?php
/*
 * Plugin Name: YML Import for Woocommerce
 * Description: The plugin helps you to import product categories and tags, simple and external products from YML file.
 * Author: Victor Polezhaev
 * Author URI: https://t.me/bot11x11
 * Version: 1.0
 * License: GPLv3 or later
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 */
class YML_Importer{
	static private $instance;
	static public function instance(){
		if(empty(self::$instance)){
			self::$instance = new self;
			self::$instance->init();
		}
		return self::$instance;
	}
	public function init(){
		register_deactivation_hook(__FILE__, array($this, 'register_deactivation_hook'));
		add_action('after_setup_theme', array($this, 'after_setup_theme'));
		add_action('admin_menu', array($this, 'admin_menu'));
		add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'plugin_action_links'));
	}
	public function plugin_action_links($links){
		$links = array(
			'<a href="' . admin_url('/tools.php?page=yml-importer') . '">' . __('Settings', 'woocommerce') . '</a>',
		) + $links;
		return $links;
	}
	public function after_setup_theme(){
		if(!current_user_can('manage_woocommerce')
		&& !wp_doing_cron()){
			return;
		}
		$this->handleActions();
		require_once __DIR__ . '/includes/tasks.php';
		$tasks = new YML_Importer_Tasks();
		$tasks->upload();
	}
	public function admin_menu(){
		add_management_page('YML Import', 'YML Import', 'manage_woocommerce', 'yml-importer', array($this, 'admin_page'));
	}
	public function getEvent($key){
		$crons = _get_cron_array();
		foreach($crons as $timestamp => $cron){
			if(isset($cron['yml-importer'][$key])){
				$event = $crons[$timestamp]['yml-importer'][$key];
				$event['key'] = $key;
				$event['time'] = $timestamp;
				return $event;
			}
		}
		return false;
	}
	public function admin_page(){
		printf('<h1>%s</h1>', esc_html__('YML Import', 'yml-importer'));
		printf('<p>%s</p>', sprintf(esc_html__('Max execution time is %s seconds', 'yml-importer'), ini_get('max_execution_time')));
		$this->message();
		require_once __DIR__ . '/includes/forms.php';
		$forms = new YML_Importer_Forms();
		$forms->scheduleForm();
		printf('<h2>%s</h2>', esc_html__('Scheduled tasks', 'yml-importer'));
		require_once __DIR__ . '/includes/table.php';
		$table = new YML_Importer_Table();
		$table->prepare_items();
		$table->views();
		?>
<form method="post" action="tools.php?page=yml-importer">
	<div class="table-responsive"><?php $table->display(); ?></div>
</form>
		<?php
		$forms->nowForm();
	}
	private function handleActions(){
		$redirect = array(
			'page' => 'yml-importer',
		);
		if(isset($_REQUEST['start'])
		&& isset($_REQUEST['url'])
		&& isset($_REQUEST['action'])
		&& isset($_REQUEST['partner'])
		&& isset($_REQUEST['type'])
		&& isset($_REQUEST['recurrence'])){
			if(isset($_REQUEST['id'])){
				$id = sanitize_key($_REQUEST['id']);
				check_admin_referer("yml-importer-edit-{$id}");
				if($event = $this->getEvent($id)){
					wp_unschedule_event($event['time'], 'yml-importer', $event['args']);
				}
			}
			if(empty($_REQUEST['start'])){
				$time = time();
			}else{
				$time = sanitize_text_field($_REQUEST['start']);
				$time = strtotime("$time UTC");
				if($time < time()){
					$time += DAY_IN_SECONDS;
				}
			}
			if(wp_schedule_event($time, sanitize_text_field($_REQUEST['recurrence']), 'yml-importer', array(
				sanitize_text_field($_REQUEST['url']),
				sanitize_text_field($_REQUEST['action']),
				sanitize_text_field($_REQUEST['partner']),
				sanitize_text_field($_REQUEST['type']),
			))){
				$redirect['message'] = 'scheduled';
			}else{
				$redirect['message'] = 'cant_schedule';
			}
			wp_safe_redirect(add_query_arg($redirect, admin_url('tools.php')));
			exit;
		}
		if(isset($_REQUEST['delete'])){
			$delete = sanitize_key($_REQUEST['delete']);
			check_admin_referer("yml-importer-delete-{$delete}");
			if($event = $this->getEvent($delete)){
				wp_unschedule_event($event['time'], 'yml-importer', $event['args']);
				$redirect['message'] = 'deleted';
			}
			wp_safe_redirect(add_query_arg($redirect, admin_url('tools.php')));
			exit;
		}
		if(isset($_REQUEST['run'])){
			$run = sanitize_key($_REQUEST['run']);
			check_admin_referer("yml-importer-run-{$run}");
			if($event = $this->getEvent($run)){
				$result = wp_schedule_single_event(time(), 'yml-importer', $event['args']);
				$redirect['message'] = 'executed';
			}
			wp_safe_redirect(add_query_arg($redirect, admin_url('tools.php')));
			exit;
		}
		if((isset($_POST['action']) && 'yml-importer-delete' === $_POST['action'])
		|| (isset( $_POST['action2']) && 'yml-importer-delete' === $_POST['action2'])){
			check_admin_referer('bulk-yml-importer-events');
			if(empty($_POST['ids'])
			|| !is_array($_POST['ids'])){
				return;
			}
			$ids = array_map('sanitize_key', $_POST['ids']);
			foreach($ids as $delete){
				if($event = $this->getEvent($delete)){
					if(wp_unschedule_event($event['time'], 'yml-importer', $event['args'])){
						$redirect['message'] = 'deleted';
					}
				}
			}
			wp_safe_redirect(add_query_arg($redirect, admin_url('tools.php')));
			exit;
		}
	}
	public function register_deactivation_hook(){
		wp_unschedule_hook('yml-importer');
	}
	private function getMessages($message){
		$messages = array(
			'executed' => array(
				__('Executed!', 'yml-importer'),
				'success',
			),
			'scheduled' => array(
				__('Scheduled!', 'yml-importer'),
				'success',
			),
			'cant_scheduled' => array(
				'',
				__("Can't scheduled!", 'yml-importer'),
				'error',
			),
			'deleted' => array(
				__('Deleted!', 'yml-importer'),
				'success',
			),
		);
		if(isset($messages[$message])){
			return $messages[$message];
		}
		return array(
			$message,
			'error',
		);
	}
	private function message(){
		if(isset($_REQUEST['message'])){
			$message = sanitize_text_field($_REQUEST['message']);
			$message = $this->getMessages($message);
			printf(
				'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
				esc_attr($message[1]),
				esc_html($message[0])
			);
		}
	}
	public function getActions(){
		return array(
			'cp' => __('Update Catecories and Products', 'yml-importer'),
			'p' => __('Update Products Only', 'yml-importer'),
			'c' => __('Update Catecories Only', 'yml-importer'),
		);
	}
}
YML_Importer::instance();
