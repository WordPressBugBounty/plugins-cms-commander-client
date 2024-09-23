<?php
/* 
Plugin Name: CMS Commander
Plugin URI: http://cmscommander.com/
Description: Manage all your Wordpress websites remotely and enhance your articles with targeted images and ads. Visit <a href="http://cmscommander.com">CMSCommander.com</a> to sign up.
Author: CMS Commander
Version: 2.288
Author URI: http://cmscommander.com
Text Domain: cms-commander-client
*/

/*************************************************************
 * 
 * init.php
 * 
 * Initialize the communication with master
 * 
 * 
 * Copyright (c) 2011 Prelovac Media
 * www.prelovac.com
 **************************************************************/

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('MAX_PRIORITY_HOOK')) {
    define('MAX_PRIORITY_HOOK', 2147483647);
}
 
global $wpdb, $wp_version, $cmsc_filters, $_cmsc_item_filter;
$cmsc_wp_version = $wp_version;

require_once dirname(__FILE__).'/init_cmsc.php';

if( !function_exists ( 'cmsc_parse_data' )) {
	function cmsc_parse_data( $data = array() ){
		if( empty($data) )
			return $data;
		
		$data = (array) $data;
		if( isset($data['params']) )
			$data['params'] = cmsc_filter_params( $data['params'] );
		
		$postkeys = array('action', 'cmsc_action', 'params', 'id', 'signature', 'setting', 'cmsc' );
		
		if( !empty($data) ){
			foreach($data as $key => $items){
				if( !in_array($key, $postkeys) )
					unset($data[$key]);
			}
		}
		return $data;
	}
}

if( !function_exists ( 'cmsc_filter_params' )) {
	function cmsc_filter_params( $array = array() ){
		
		$filter = array( 'current_user', 'wpdb' );
		$return = array();
		foreach ($array as $key => $val) { 
			if( !is_int($key) && in_array($key, $filter) )
				continue;
				
			if( is_array( $val ) ) { 
				$return[$key] = cmsc_filter_params( $val );
			} else {
				$return[$key] = $val;
			}
		} 
		
		return $return;
	}
}

if( !function_exists ( 'cmsc_add_site' )) {
	function cmsc_add_site($params) {
		global $cmsc_core;
		$num = extract($params);

		if ($num) {
			if (!get_option('_cmsc_action_message_id') && !get_option('_cmsc_public_key')) {
				$public_key = base64_decode($public_key);
			
				if (function_exists('openssl_verify')) {

					$verify = openssl_verify($action . $id, base64_decode($signature), $public_key);
					if ($verify == 1) {
					
						$cmsc_core->set_master_public_key($public_key);
						$cmsc_core->set_cmsc_message_id($id);
						$cmsc_core->get_stats_instance();
						if(isset($notifications) && is_array($notifications) && !empty($notifications)){
							$cmsc_core->stats_instance->set_notifications($notifications);
						}
						if(isset($brand) && is_array($brand) && !empty($brand)){
							update_option('cmsc_worker_brand',$brand);
						}
						
						if( isset( $add_settigns ) && is_array($add_settigns) && !empty( $add_settigns ) )
							apply_filters( 'cmsc_website_add', $add_settigns );

						cmsc_response($cmsc_core->stats_instance->get_initial_stats(), true);
					} else if ($verify == 0) {
																			
						//cmsc_response('Site could not be added. OpenSSL verification error: "'.openssl_error_string().'". Contact your hosting support to check the OpenSSL configuration.', false);
						
					} else {
						cmsc_response('Command not successful. Please try again.', false);
					}
				} 
					
					if (!get_option('_cmsc_nossl_key')) {
	
						srand();
						
						$random_key = md5(base64_encode($public_key) . rand(0, getrandmax()));
						
						$cmsc_core->set_random_signature($random_key);
						$cmsc_core->set_cmsc_message_id($id);
						$cmsc_core->set_master_public_key($public_key);
						$cmsc_core->get_stats_instance();						
						if(is_array($notifications) && !empty($notifications)){
							$cmsc_core->stats_instance->set_notifications($notifications);
						}
						
						if(is_array($brand) && !empty($brand)){
							update_option('cmsc_worker_brand',$brand);
						}
						
						cmsc_response($cmsc_core->stats_instance->get_initial_stats(), true);
					} else {
						cmsc_response('Please deactivate & activate CMS Commander plugin on your site, then re-add the site to your dashboard.', false);
					}
			} else {
				cmsc_response('Please deactivate & activate CMS Commander plugin on your site and re-add the site to your dashboard.', false);
			}
		} else {
			cmsc_response('Invalid parameters received. Please try again.', false);
		}
	}
}

if( !function_exists ( 'cmsc_wp_checkversion' )) {
	function cmsc_wp_checkversion($params)
	{
		include_once(ABSPATH . 'wp-includes/version.php');
		global $cmsc_wp_version, $cmsc_core;
		cmsc_response($cmsc_wp_version, true);
	}
}

if( !function_exists ('cmsc_edit_posts')) {
	function cmsc_edit_posts($params)
	{
		global $cmsc_core;
		$cmsc_core->get_posts_instance();
		$return = $cmsc_core->posts_instance->edit_posts($params);
		cmsc_response($return, true);
	}
}

if( !function_exists ( 'cmsc_set_notifications' )) {
	function cmsc_set_notifications($params)
	{
		global $cmsc_core;
		$cmsc_core->get_stats_instance();
			$return = $cmsc_core->stats_instance->set_notifications($params);
		if (is_array($return) && array_key_exists('error', $return))
			cmsc_response($return['error'], false);
		else {
			cmsc_response($return, true);
		}		
	}
}

if( !function_exists ('cmsc_get_dbname')) {
	function cmsc_get_dbname($params)
	{
		global $cmsc_core;
		$cmsc_core->get_stats_instance();
		
		$return = $cmsc_core->stats_instance->get_active_db();
		if (is_array($return) && array_key_exists('error', $return))
			cmsc_response($return['error'], false);
		else {
			cmsc_response($return, true);
		}
	}
}
	
add_action('cmsc_backup_tasks', 'cmsc_check_backup_tasks');
if( !function_exists('cmsc_check_backup_tasks') ){
 	function cmsc_check_backup_tasks() {
		global $cmsc_core, $_wp_using_ext_object_cache;
		$_wp_using_ext_object_cache = false;
		
		$cmsc_core->get_backup_instance();
		$cmsc_core->backup_instance->check_backup_tasks();
	}
}

function cmsc_error_handler($errno, $errstr, $errfile, $errline, $errcontext)
{
    $errorId = 'cmsc_error_' . md5($errfile . $errline);
    $error = sprintf("%s\nError [%s]: %s\nIn file: %s:%s", date('Y-m-d H:i:s'), $errno, $errstr, $errfile, $errline);
    set_transient($errorId, $error, 3600);
}

function cmsc_fatal_error_handler()
{
    $isError = false;
    if ($error = error_get_last()) {
        switch ($error['type']) {
            case E_ERROR:
            case E_CORE_ERROR:
            case E_COMPILE_ERROR:
            case E_USER_ERROR:
                $isError = true;
                break;
        }
    }
    if ($isError) {
        cmsc_error_handler($error['type'], $error['message'], $error['file'], $error['line'], array());
    }
}

if (get_option('cmsc_debug_enable')) {
    set_error_handler('cmsc_error_handler');
    register_shutdown_function('cmsc_fatal_error_handler');
}

if (!function_exists('cmsc_init')) {
    function cmsc_init() {
	
        $GLOBALS['CMSC_WORKER_VERSION']  = '2.285';define('CMSC_WORKER_VERSION', '2.285');	

        // Ensure PHP version compatibility.
        if (version_compare(PHP_VERSION, '5.2', '<')) {
            trigger_error("The CMS Commander client plugin requires PHP 5.2 or higher.", E_USER_ERROR);
            exit;
        }	
	
        // Register the autoloader that loads everything except the Google namespace.
        if (version_compare(PHP_VERSION, '5.3', '<')) {
            spl_autoload_register('cmsc_autoload');
        } else {
            // The prepend parameter was added in PHP 5.3.0
            spl_autoload_register('cmsc_autoload', true, true);
        }	
			
		$GLOBALS['cmsc_core']            = $core = $GLOBALS['cmsc_core_backup'] = new CMSC_Core();
        $GLOBALS['cmsc_plugin_dir']      = WP_PLUGIN_DIR.'/'.basename(dirname(__FILE__));
        $GLOBALS['cmsc_plugin_url']      = WP_PLUGIN_URL.'/'.basename(dirname(__FILE__));
		
		$siteurl = function_exists( 'get_site_option' ) ? get_site_option( 'siteurl' ) : get_option( 'siteurl' );
		define('CMSC_XFRAME_COOKIE', $xframe = 'wordpress_'.md5($siteurl).'_xframe');
	
		define('CMSC_BACKUP_DIR', WP_CONTENT_DIR . '/cmscommander/backups');
		define('CMSC_DB_DIR', CMSC_BACKUP_DIR . '/cmsc_db');					

		if(isset($_GET['auto_login'])) {
			$core->automatic_login();	
		}
		//cmsc_add_action('cleanup_delete', 'cleanup_delete_cmsc');	
		add_filter( 'cmsc_website_add', 'cmsc_readd_backup_task' );
							
		add_filter('cmsc_stats_filter', 'cmsc_get_extended_info');
		add_filter('cron_schedules', 'cmsc_more_reccurences');
		add_action('cmsc_remote_upload', 'cmsc_call_scheduled_remote_upload');
		//add_action('cmsc_datasend', 'cmsc_datasend');							
		add_action('init', 'cmsc_plugin_actions', 99999);	
		add_filter('install_plugin_complete_actions','cmsc_iframe_plugins_fix');	

		/*if (!wp_next_scheduled('cmsc_datasend')) {
			wp_schedule_event( time(), 'sixhours', 'cmsc_datasend' );
		}*/

		CMSC_Updater::register();	
			
		register_activation_hook( __FILE__ , array( $core, 'install' ));
		register_deactivation_hook(__FILE__, array( $core, 'uninstall' ));

		if(	isset($_COOKIE[CMSC_XFRAME_COOKIE]) ){
			remove_action( 'admin_init', 'send_frame_options_header');
			remove_action( 'login_init', 'send_frame_options_header');
		}	
		
		cmsc_set_plugin_priority();
	}
	
	require_once dirname(__FILE__).'/functions.php';
	
	cmsc_init();	
}
?>