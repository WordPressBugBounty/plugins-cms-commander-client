<?php

function cmsc_autoload($class) {
    if (substr($class, 0, 8) === 'Dropbox_'
        || substr($class, 0, 8) === 'Symfony_'
        || substr($class, 0, 8) === 'Monolog_'
        || substr($class, 0, 5) === 'Gelf_'
		|| substr($class, 0, 5) === 'CMSC_'
        || substr($class, 0, 4) === 'MWP_'
        || substr($class, 0, 4) === 'MMB_'
        || substr($class, 0, 3) === 'S3_'
    ) {
        $file = dirname(__FILE__).'/lib/'.str_replace('_', '/', $class).'.php';
        if (file_exists($file)) {
            include_once $file;
        }
    }
}

/*function cmsc_register_autoload_google() {
    static $registered;

    if ($registered) {
        return;
    } else {
        $registered = true;
    }

    if (version_compare(PHP_VERSION, '5.3', '<')) {
        spl_autoload_register('cmsc_autoload_google');
    } else {
        spl_autoload_register('cmsc_autoload_google', true, true);
    }
}

function cmsc_autoload_google($class) {
    if (substr($class, 0, 7) === 'Google_') {
        $file = dirname(__FILE__).'/lib/'.str_replace('_', '/', $class).'.php';
        if (file_exists($file)) {
            include_once $file;
        }
    }
}*/

function cmsc_dropbox_oauth_factory($appKey, $appSecret, $token, $tokenSecret = null) {
    if ($tokenSecret) {
        $oauthToken       = 'OAuth oauth_version="1.0", oauth_signature_method="PLAINTEXT", oauth_consumer_key="'.$appKey.'", oauth_token="'.$token.'", oauth_signature="'.$appSecret.'&'.$tokenSecret.'"';
        $clientIdentifier = $token;
    } else {
        $oauthToken       = 'Bearer '.$token;
        $clientIdentifier = 'PHP-CMSCommander/1.0';
    }

    return new Dropbox_Client($oauthToken, $clientIdentifier);
}

function cmsc_format_memory_limit($limit)
{
    if ((string) (int) $limit === (string) $limit) {
        // The number is numeric.
        return cmsc_format_bytes($limit);
    }

    $units = strtolower(substr($limit, -1));

    if (!in_array($units, array('b', 'k', 'm', 'g'))) {
        // Invalid size unit.
        return $limit;
    }

    $number = substr($limit, 0, -1);

    if ((string) (int) $number !== $number) {
        // The number isn't numeric.
        return $number;
    }

    switch ($units) {
        case 'g':
            return $number.' GB';
        case 'm':
            return $number.' MB';
        case 'k':
            return $number.' KB';
        case 'b':
        default:
            return $number.' B';
    }
}

function cmsc_format_bytes($bytes)
{
    $bytes = (int) $bytes;

    if ($bytes > 1024 * 1024 * 1024) {
        return round($bytes / 1024 / 1024 / 1024, 2).' GB';
    } elseif ($bytes > 1024 * 1024) {
        return round($bytes / 1024 / 1024, 2).' MB';
    } elseif ($bytes > 1024) {
        return round($bytes / 1024, 2).' KB';
    }

    return $bytes.' B';
}

function cmsc_get_extended_info($stats)
{
	global $cmsc_core;
	$params = get_option('cmsc_stats_filter');
	$filter = isset($params['plugins']['cleanup']) ? $params['plugins']['cleanup'] : array();
    $stats['num_revisions']     = cmsc_num_revisions($filter['revisions']);
    //$stats['num_revisions'] = 5;
    $stats['overhead']          = cmsc_handle_overhead(false);
    $stats['num_spam_comments'] = cmsc_num_spam_comments();
    return $stats;
}

/* Revisions */
function cleanup_delete_cmsc($params = array())
{
    $revision_params = get_option('cmsc_stats_filter');
	$revision_filter = isset($revision_params['plugins']['cleanup']) ? $revision_params['plugins']['cleanup'] : array();
	
    $params_array = explode('_', $params['actions']);
    $return_array = array();

    foreach ($params_array as $param) {
        switch ($param) {
            case 'revision':
                if (cmsc_delete_all_revisions($revision_filter['revisions'])) {
                    $return_array['revision'] = 'OK';
                } else {
                    $return_array['revision_error'] = 'OK, nothing to do';
                }
                break;
            case 'overhead':
                if (cmsc_handle_overhead(true)) {
                    $return_array['overhead'] = 'OK';
                } else {
                    $return_array['overhead_error'] = 'OK, nothing to do';
                }
                break;
            case 'comment':
                if (cmsc_delete_spam_comments()) {
                    $return_array['comment'] = 'OK';
                } else {
                    $return_array['comment_error'] = 'OK, nothing to do';
                }
                break;
            default:
                break;
        }        
    }
    
    unset($params);
    
    cmsc_response($return_array, true);
}

function cmsc_num_revisions($filter)
{
    global $wpdb;

    $num_rev = isset($filter['num_to_keep']) && !empty($filter['num_to_keep']) ? str_replace("r_", "", $filter['num_to_keep']) : 5;

    $query = "SELECT SUM(t.cnt) FROM (SELECT COUNT(ID) - {$num_rev} as cnt FROM {$wpdb->posts} WHERE post_type = 'revision' AND post_parent != 0 GROUP BY post_parent HAVING COUNT(ID) > {$num_rev}) as t";

    return $wpdb->get_var($query);
}

function cmsc_select_all_revisions()
{
    global $wpdb;
    $sql       = "SELECT * FROM $wpdb->posts WHERE post_type = 'revision'";
    $revisions = $wpdb->get_results($sql);
	
    return $revisions;
}

function cmsc_delete_all_revisions($filter)
{
    global $wpdb;

    $num_rev = isset($filter['num_to_keep']) ? (int) str_replace("r_", "", $filter['num_to_keep']) : 5;

    $allRevisions = $wpdb->get_results("SELECT post_parent FROM {$wpdb->posts} WHERE post_type = 'revision' AND post_parent != 0 GROUP BY post_parent HAVING COUNT(ID) > {$num_rev}");

    if (!is_array($allRevisions)) {
        return false;
    }

    foreach ($allRevisions as $revision) {
        $toKeep = $wpdb->get_results("SELECT ID FROM {$wpdb->posts} WHERE post_type = 'revision' AND post_parent = '{$revision->post_parent}' ORDER BY post_date DESC LIMIT ".$num_rev);

        $keepArray = array();
        foreach ($toKeep as $keep) {
            $keepArray[] = $keep->ID;
        }

        if (empty($keepArray)) {
            continue;
        }

        $keepQuery = implode(', ', $keepArray);
        $wpdb->query("DELETE FROM {$wpdb->posts} WHERE post_type = 'revision' AND post_parent = '{$revision->post_parent}' AND ID NOT IN ({$keepQuery})");
    }

    return true;
}

/* Optimize */
function cmsc_handle_overhead($clear = false)
{
    /** @var wpdb $wpdb */
    global $wpdb;
    $query            = 'SHOW TABLE STATUS';
    $tables           = $wpdb->get_results($query, ARRAY_A);
    $tableOverhead    = 0;
    $tablesToOptimize = array();

    foreach ($tables as $table) {
        if (!isset($table['Engine']) || $table['Engine'] !== 'MyISAM' || $table['Data_free'] == 0) {
            continue;
        }

        if ($wpdb->base_prefix === $wpdb->prefix && !preg_match('/^'.preg_quote($wpdb->prefix).'/Ui', $table['Name'])) {
            continue;
        }

        if ($wpdb->base_prefix !== $wpdb->prefix && !preg_match('/^'.preg_quote($wpdb->prefix).'\d+_/Ui', $table['Name'])) {
            continue;
        }

        $tableOverhead += $table['Data_free'] / 1024;
        $tablesToOptimize[] = $table['Name'];
    }

    if (!$clear) { // we should only return the overhead
        return round($tableOverhead, 3);
    }

    $optimize = true;

    foreach ($tablesToOptimize as $tableToOptimize) {
        $query    = 'OPTIMIZE TABLE '.$tableToOptimize;
        $optimize = ((bool) $wpdb->query($query)) && $optimize;
    }

    return $optimize;
}

/* Spam Comments */
function cmsc_num_spam_comments()
{
    global $wpdb;
    $sql       = "SELECT COUNT(*) FROM $wpdb->comments WHERE comment_approved = 'spam'";
    $num_spams = $wpdb->get_var($sql);
	
    return $num_spams;
}

function cmsc_delete_spam_comments()
{
    global $wpdb;
    $spam  = 1;
    $total = 0;
    while (!empty($spam)) {
        $getCommentsQuery = "SELECT * FROM $wpdb->comments WHERE comment_approved = 'spam' LIMIT 200";
        $spam             = $wpdb->get_results($getCommentsQuery);

        if (empty($spam)) {
            break;
        }

        $commentIds = array();
        foreach ($spam as $comment) {
            $commentIds[] = $comment->comment_ID;

            // Avoid queries to comments by caching the comment.
            // Plugins which hook to 'delete_comment' might call get_comment($id), which in turn returns the cached version.
            wp_cache_add($comment->comment_ID, $comment, 'comment');
            do_action('delete_comment', $comment->comment_ID);
            wp_cache_delete($comment->comment_ID, 'comment');
        }

        $commentIdsList = implode(', ', array_map('intval', $commentIds));
        $wpdb->query("DELETE FROM {$wpdb->comments} WHERE comment_ID IN ($commentIdsList)");
        $wpdb->query("DELETE FROM {$wpdb->commentmeta} WHERE comment_id IN ($commentIdsList)");

        $total += count($spam);
        if (!empty($spam)) {
            usleep(100000);
        }
    }

    return $total;
}

function cmsc_get_spam_comments()
{
    global $wpdb;
    $sql   = "SELECT * FROM $wpdb->comments as a LEFT JOIN $wpdb->commentmeta as b WHERE a.comment_ID = b.comment_id AND a.comment_approved = 'spam'";
    $spams = $wpdb->get_results($sql);
	
    return $spams;
}

function cmsc_is_safe_mode()
{
    $value = ini_get("safe_mode");
    if ((int) $value === 0 || strtolower($value) === "off") {
        return false;
    }

    return true;
}

// Everything below was moved from init.php

if( !function_exists('cmsc_authenticate')) {
    function cmsc_authenticate() {

        global $_cmsc_data, $_cmsc_auth, $cmsc_core;

        if (!isset($HTTP_RAW_POST_DATA)) {
            $HTTP_RAW_POST_DATA = file_get_contents('php://input');
        }

		$_cmsc_data = $HTTP_RAW_POST_DATA;			

		if (strrpos($_cmsc_data, '%23%23CMSC%23%23') !== false) { // new way
			$_cmsc_data = urldecode($_cmsc_data);			
			//$_cmsc_data = str_replace("cmsc=", "", $_cmsc_data);		
			$_cmsc_data = explode("##CMSC##", $_cmsc_data);
			$_cmsc_data = $_cmsc_data[1];
			$_cmsc_data = json_decode($_cmsc_data, true);
			
			if(is_array($_cmsc_data) && !empty($_cmsc_data['signature'])) {
				$_cmsc_data["signature"] = utf8_decode($_cmsc_data["signature"]);
			}			
		} else { // new way
			$_cmsc_data = false;
			$serialized_data = trim(base64_decode($HTTP_RAW_POST_DATA));			
			if (is_serialized($serialized_data)) {
					cmsc_response('Expects json encoded data.', false);
			}
		}
	
        if (!$_cmsc_data){
            return;
        }
			
        if(empty($_cmsc_data['cmsc_action'])) {
            return;
        } else {
			$_cmsc_data['action'] = $_cmsc_data['cmsc_action'];
		}
		
        if($_cmsc_data['cmsc'] !== "yes") {
            return;
        }		

        if (!$cmsc_core->check_if_user_exists($_cmsc_data['params']['username'])) {
            cmsc_response('Username <b>' . $_cmsc_data['params']['username'] . '</b> does not have administrator capabilities. Please check the Admin username.', false);
        }
		
		if(empty($_cmsc_data['url'])) {
            cmsc_response('No valid url received.', false);
		} else {
			$where = urldecode($_cmsc_data['url']);
			$home_url = get_home_url();
			if(empty($home_url)) {$home_url = get_site_url();}
			if(empty($home_url)) {
				cmsc_response("Empty url received. $home_url ", false);
			}
			if(stripos($home_url, $where) === false) {
				cmsc_response("Invalid url received. $home_url ", false);
			} 		
		}

        if($_cmsc_data['action'] === 'add_site') {
			if(empty($_cmsc_data['add_site_auth_code'])) {
				cmsc_response("Missing site activation code. The code is shown inside the wp-admin panel of your site. If it is not try de- and reactivating the CMS Commander client plugin on your site.", false);
			} else {
				$actcode = get_option("cmsc_site_activation_code");
				if($_cmsc_data['add_site_auth_code'] === $actcode) {
					$_cmsc_auth = true;
					return;					
				} else {
					cmsc_response("Invalid site activation code received. The code is shown inside the wp-admin panel of your site. If it is not try de- and reactivating the CMS Commander client plugin on your site.", false);
				}					
			}				

        } else {
            $_cmsc_auth = $cmsc_core->authenticate_message($_cmsc_data['url'] . $_cmsc_data['action'] . $_cmsc_data['id'], $_cmsc_data['signature'], $_cmsc_data['id']);
        }

        if($_cmsc_auth !== true) {
            cmsc_response($_cmsc_auth['error'], false);
        }

        if(isset($_cmsc_data['params']['username']) && !is_user_logged_in()){
            $user = function_exists('get_user_by') ? get_user_by('login', $_cmsc_data['params']['username']) : get_userdatabylogin( $_cmsc_data['params']['username'] );
            wp_set_current_user($user->ID);
            if(@getenv('IS_WPE'))
                wp_set_auth_cookie($user->ID);			
        }
        /*if(!defined("WP_ADMIN"))
            define(WP_ADMIN,true);*/
    }
}

if( !function_exists ( 'cmsc_parse_request' )) {
    function cmsc_parse_request(){	
        global $cmsc_core, $wp_db_version, $wpmu_version, $_wp_using_ext_object_cache, $_cmsc_data, $_cmsc_auth;

        if(empty($_cmsc_auth)) {
            return;
        }		
		
        ob_start();
        $_wp_using_ext_object_cache = false;
        @set_time_limit(1200);

        if (isset($_cmsc_data['action']) && $_cmsc_data['action'] === 'add_site') {	
            cmsc_add_site($_cmsc_data['params']);
            cmsc_response('You should never see this.', false);
        }

        /* in case database upgrade required, do database backup and perform upgrade ( wordpress wp_upgrade() function ) */
        if( strlen(trim($wp_db_version)) && !defined('ACX_PLUGIN_DIR') ){
            if ( get_option('db_version') != $wp_db_version ) {
                /* in multisite network, please update database manualy */
                if (empty($wpmu_version) || (function_exists('is_multisite') && !is_multisite())){
                    if( ! function_exists('wp_upgrade'))
                        include_once(ABSPATH.'wp-admin/includes/upgrade.php');

                    ob_clean();
                    @wp_upgrade();
                    @do_action('after_db_upgrade');
                    ob_end_clean();
                }
            }
        }

        if(isset($_cmsc_data['params']['secure'])){
            if($decrypted = $cmsc_core->_secure_data($_cmsc_data['params']['secure'])){
                $decrypted = maybe_unserialize($decrypted);
                if(is_array($decrypted)){
                    foreach($decrypted as $key => $val){
                        if(!is_numeric($key))
                            $_cmsc_data['params'][$key] = $val;
                    }
                    unset($_cmsc_data['params']['secure']);
                } else $_cmsc_data['params']['secure'] = $decrypted;
            }
        }

        if( isset($_cmsc_data['setting']) ){
            $cmsc_core->save_options( $_cmsc_data['setting'] );
        }

		if(isset($_cmsc_data['action'])) {
			if( !$cmsc_core->register_action_params( $_cmsc_data['action'], $_cmsc_data['params'] ) ){
				global $_cmsc_plugin_actions;
				$_cmsc_plugin_actions[$_cmsc_data['action']] = $_cmsc_data['params'];
			}
		}
        ob_end_clean();
    }
}

if( !function_exists ( 'cmsc_response' )) {
	function cmsc_response($response = false, $success = true)
	{
		$return = array();
		
		if ((is_array($response) && empty($response)) || (!is_array($response) && strlen($response) == 0))
			$return['error'] = 'Empty response.';
		else if ($success)
			$return['success'] = $response;
		else
			$return['error'] = $response;
		
		if( !headers_sent() ){
			header('HTTP/1.0 200 OK');
			header('Content-Type: text/plain');
		}
		exit("<CMSCHEADER>_CMSC_JSON_PREFIX_" . base64_encode(json_encode($return))."<ENDCMSCHEADER>");
	}
}

if( !function_exists ( 'cmsc_remove_site' )) {
	function cmsc_remove_site($params)
	{
		extract($params);
		global $cmsc_core;
		$cmsc_core->uninstall( $deactivate );
		
		include_once(ABSPATH . 'wp-admin/includes/plugin.php');
		$plugin_slug = 'cms-commander-client/init.php';
		
		if ($deactivate) {
			deactivate_plugins($plugin_slug, true);
		}
		
		if (!is_plugin_active($plugin_slug))
			cmsc_response(array(
				'deactivated' => 'Site removed successfully. <br /><br />CMS Commander plugin successfully deactivated.'
			), true);
		else
			cmsc_response(array(
				'removed_data' => 'Site removed successfully. <br /><br /><b>CMS Commander plugin was not deactivated.</b>'
			), true);
		
	}
}

if( !function_exists ( 'cmsc_stats_get' )) {
	function cmsc_stats_get($params)
	{
		global $cmsc_core;
		$cmsc_core->get_stats_instance();
		cmsc_response($cmsc_core->stats_instance->get($params), true);
	}
}

if( !function_exists ( 'cmsc_worker_header' )) {
	function cmsc_worker_header()
	{	global $cmsc_core, $current_user;
		
		if(!headers_sent()){
			if(isset($current_user->ID))
				$expiration = time() + apply_filters('auth_cookie_expiration', 10800, $current_user->ID, false);
			else 
				$expiration = time() + 10800;
				
			setcookie(CMSC_XFRAME_COOKIE, md5(CMSC_XFRAME_COOKIE), $expiration, COOKIEPATH, COOKIE_DOMAIN, false, true);
			$_COOKIE[CMSC_XFRAME_COOKIE] = md5(CMSC_XFRAME_COOKIE);
		}
	}
}

if( !function_exists ( 'cmsc_pre_init_stats' )) {
	function cmsc_pre_init_stats( $params )
	{
		global $cmsc_core;
		$cmsc_core->get_stats_instance();
		return $cmsc_core->stats_instance->pre_init_stats($params);
	}
}

/*if( !function_exists ( 'cmsc_datasend' )) {
	function cmsc_datasend( $params = array() )
	{
		global $cmsc_core, $_cmsc_item_filter, $_cmsc_options;
		if( isset($_cmsc_options['datacron']) ){
	
			$_cmsc_remoteurl = get_option('home');
			$_cmsc_remoteown = isset($_cmsc_options['dataown']) && !empty($_cmsc_options['dataown']) ? $_cmsc_options['dataown'] : false;
			
			if( empty($_cmsc_remoteown) )
				return;
			
			$_cmsc_item_filter['pre_init_stats'] = array( 'core_update', 'hit_counter', 'comments', 'backups', 'posts', 'drafts', 'scheduled' );
			$_cmsc_item_filter['get'] = array( 'updates', 'errors' );
			$cmsc_core->get_stats_instance();
			
			$filter = array(
				'refresh' => '',
				'item_filter' => array(
					'get_stats' => array(
						array('updates', array('plugins' => true, 'themes' => true, 'premium' => true )),
						array('core_update', array('core' => true )),
						array('posts', array('numberposts' => 5 )),
						array('drafts', array('numberposts' => 5 )),
						array('scheduled', array('numberposts' => 5 )),
						array('hit_counter'),
						array('comments', array('numberposts' => 5 )),
						array('backups'),
						'plugins' => array('cleanup' => array(
										'overhead' => array(),
										'revisions' => array( 'num_to_keep' => 'r_5'),
										'spam' => array(),
									)
						),
					),
				)
			);
			
			$pre_init_data = $cmsc_core->stats_instance->pre_init_stats($filter);
			$init_data = $cmsc_core->stats_instance->get($filter);
			
			$data = array_merge($init_data, $pre_init_data);
			$cmsc_core->get_cmsc_instance();
			$data = $cmsc_core->cmsc_instance->get_stats(array());
			
			$hash = $cmsc_core->get_secure_hash();
		
			$datasend['datasend'] = $cmsc_core->encrypt_data($data);
			$datasend['sitehome'] = base64_encode($_cmsc_remoteown.'[]'.$_cmsc_remoteurl);
			$datasend['sitehash'] = md5($hash.$_cmsc_remoteown.$_cmsc_remoteurl);
			$datasend['secure'] = $cmsc_core->get_random_signature();
			
			if ( !class_exists('WP_Http') )
                include_once(ABSPATH . WPINC . '/class-http.php');
			
			$remote = array();
			$remote['body'] = $datasend;
			$result = wp_remote_post($_cmsc_options['datacron'], $remote);
			if(!is_wp_error($result)){
				if(isset($result['body']) && !empty($result['body'])){
					$settings = @unserialize($result['body']);

					$brand = '';
					if (isset($settings['worker_brand']) && $settings['worker_brand']) {
						$brand = $settings['worker_brand'];
					}
					//update_option("cmsc_worker_brand",$brand);

					$w_version = $settings['worker_updates']['version'];
					$w_url = $settings['worker_updates']['url'];
					if(version_compare(CMSC_WORKER_VERSION, $w_version, '<')){
						//automatic update
						$cmsc_core->update_worker_plugin(array("download_url" => $w_url));
					}					
				}
			}else{
				//$cmsc_core->_log($result);
			}			
		}
	}
}*/

// POST
if( !function_exists ( 'cmsc_post_create' )) {
	function cmsc_post_create($params)
	{
		global $cmsc_core;
		$cmsc_core->get_post_instance();
		$return = $cmsc_core->post_instance->create($params);
		if (is_int($return))
			cmsc_response($return, true);
		else{
			if(isset($return['error'])){
				cmsc_response($return['error'], false);
			} else {
				cmsc_response($return, false);
			}
		}
	}
}

if( !function_exists ( 'cmsc_change_post_status' )) {
	function cmsc_change_post_status($params)
	{
		global $cmsc_core;
		$cmsc_core->get_post_instance();
		$return = $cmsc_core->post_instance->change_status($params);
		if (is_wp_error($return)) {
			cmsc_response($return->get_error_message(), false);
		} elseif (empty($return)) {
			cmsc_response("Post status can not be changed", false);
		} else {
			cmsc_response($return, true);
		}		
	}
}

if( !function_exists ( 'cmsc_backup_now' )) {
//backup
	function cmsc_backup_now($params)
	{
		global $cmsc_core;
		
		$cmsc_core->get_backup_instance();
		$return = $cmsc_core->backup_instance->backup($params);
		
		if (is_array($return) && array_key_exists('error', $return))
			cmsc_response($return['error'], false);
		else {
			cmsc_response($return, true);
		}
	}
}

if( !function_exists ( 'cmsc_run_task_now' )) {
	function cmsc_run_task_now($params)
	{
		global $cmsc_core;
		$cmsc_core->get_backup_instance();
		
		$task_name = isset($params['task_name']) ? $params['task_name'] : false;
		$google_drive_token = isset($params['google_drive_token']) ? $params['google_drive_token'] : false;
		$resultUuid         = !empty($params['resultUuid']) ? $params['resultUuid'] : false;
	
		if ($task_name) {
			$return = $cmsc_core->backup_instance->task_now($task_name, $google_drive_token, $resultUuid);
			if (is_array($return) && array_key_exists('error', $return))
				cmsc_response($return['error'], false);
			else {
				cmsc_response($return, true);
			}		
		} else {
			cmsc_response("Task name is not provided.", false);
		}
	}
}

if( !function_exists ( 'cmsc_email_backup' )) {
	function cmsc_email_backup($params)
	{
		global $cmsc_core;
		$cmsc_core->get_backup_instance();
		$return = $cmsc_core->backup_instance->email_backup($params);
		
		if (is_array($return) && array_key_exists('error', $return))
			cmsc_response($return['error'], false);
		else {
			cmsc_response($return, true);
		}
	}
}

if( !function_exists ( 'cmsc_check_backup_compat' )) {
	function cmsc_check_backup_compat($params)
	{
		global $cmsc_core;
		$cmsc_core->get_backup_instance();
		$return = $cmsc_core->backup_instance->check_backup_compat($params);
		
		if (is_array($return) && array_key_exists('error', $return))
			cmsc_response($return['error'], false);
		else {
			cmsc_response($return, true);
		}
	}
}

if( !function_exists ( 'cmsc_get_backup_req' )) {
	function cmsc_get_backup_req( $params )
	{
		global $cmsc_core;
		$cmsc_core->get_stats_instance();
		$return = $cmsc_core->stats_instance->get_backup_req($params);
		
		cmsc_response($return, true);
	}
}

// Fires when Backup Now, or some backup task is saved.
if( !function_exists ( 'cmsc_scheduled_backup' )) {
	function cmsc_scheduled_backup($params)
	{
		global $cmsc_core;
		$cmsc_core->get_backup_instance();
		$return = $cmsc_core->backup_instance->set_backup_task($params);
		cmsc_response($return, $return);
	}
}

if( !function_exists ( 'cmsc_delete_backup' )) {
	function cmsc_delete_backup($params)
	{
		global $cmsc_core;
		$cmsc_core->get_backup_instance();
		$return = $cmsc_core->backup_instance->delete_backup($params);
		cmsc_response($return, $return);
	}
}

if( !function_exists ( 'cmsc_optimize_tables' )) {
	function cmsc_optimize_tables($params)
	{
		global $cmsc_core;
		$cmsc_core->get_backup_instance();
		$return = $cmsc_core->backup_instance->optimize_tables();
		if ($return)
			cmsc_response($return, true);
		else
			cmsc_response(false, false);
	}
}
if( !function_exists ( 'cmsc_restore_now' )) {
	function cmsc_restore_now($params)
	{
		global $cmsc_core;
		$cmsc_core->get_backup_instance();
		$return = $cmsc_core->backup_instance->restore($params);
		if (is_array($return) && array_key_exists('error', $return))
			cmsc_response($return['error'], false);
		else
			cmsc_response($return, true);
		
	}
}

if( !function_exists ( 'cmsc_remote_backup_now' )) {
	function cmsc_remote_backup_now($params)
	{
		global $cmsc_core;
		$backup_instance = $cmsc_core->get_backup_instance();
		$return = $cmsc_core->backup_instance->remote_backup_now($params);
		if (is_array($return) && array_key_exists('error', $return))
			cmsc_response($return['error'], false);
		else
			cmsc_response($return, true);
	}
}

if( !function_exists ( 'cmsc_clean_orphan_backups' )) {
	function cmsc_clean_orphan_backups()
	{
		global $cmsc_core;
		$backup_instance = $cmsc_core->get_backup_instance();
		$return = $cmsc_core->backup_instance->cleanup();
		if(is_array($return))
			cmsc_response($return, true);
		else
			cmsc_response($return, false);
	}
}

function cmsc_run_backup_action() {

    //if (!isset($_POST['cmsc_backup_nonce']) || (isset($_POST['cmsc_backup_nonce']) && !wp_verify_nonce($_POST['cmsc_backup_nonce'], 'cmsc-backup-nonce'))) {
    if (!isset($_POST['cmsc_backup_nonce'])) {
        return false;
    }

    $public_key = get_option('_cmsc_public_key');
    if (!isset($_POST['public_key']) || $public_key !== $_POST['public_key']) {
        return false;
    }

	$args = @json_decode(stripslashes($_POST['args']), true);
	if (!$args) return false;
	
	$cron_action = isset($_POST['backup_cron_action']) ? $_POST['backup_cron_action'] : false;
	if ($cron_action) {
		do_action($cron_action, $args);
	}
	//unset($_POST['public_key']);
	unset($_POST['cmsc_backup_nonce']);
	unset($_POST['args']);
	unset($_POST['backup_cron_action']);
	return true;
}

if (!function_exists('cmsc_readd_backup_task')) {
	function cmsc_readd_backup_task($params = array()) {
		global $cmsc_core;
		$backup_instance = $cmsc_core->get_backup_instance();
		$settings = $backup_instance->readd_tasks($params);
		return $settings;
	}
}

if( !function_exists ( 'cmsc_update_cmsc_plugin' )) {
	function cmsc_update_cmsc_plugin($params)
	{
		global $cmsc_core;
		cmsc_response($cmsc_core->update_cmsc_plugin($params), true);
	}
}

if( !function_exists ( 'cmsc_install_addon' )) {
	function cmsc_install_addon($params)
	{
		global $cmsc_core;
		$cmsc_core->get_installer_instance();
		$return = $cmsc_core->installer_instance->install_remote_file($params);
		cmsc_response($return, true);
		
	}
}

if( !function_exists ( 'cmsc_do_upgrade' )) {
	function cmsc_do_upgrade($params)
	{
		global $cmsc_core, $cmsc_upgrading;
		$cmsc_core->get_installer_instance();
		$return = $cmsc_core->installer_instance->do_upgrade($params);
		cmsc_response($return, true);
		
	}
}

if( !function_exists ('cmsc_reply_comment')) {
	function cmsc_reply_comment($params)
	{
		global $cmsc_core;
		$cmsc_core->get_comment_instance();
		
			$return = $cmsc_core->comment_instance->reply_comment($params);
		if (is_array($return) && array_key_exists('error', $return))
			cmsc_response($return['error'], false);
		else {
			cmsc_response($return, true);
		}
	}
}

if( !function_exists ( 'cmsc_add_user' )) {
	function cmsc_add_user($params)
	{
		global $cmsc_core;
		$cmsc_core->get_user_instance();
			$return = $cmsc_core->user_instance->add_user($params);
		if (is_array($return) && array_key_exists('error', $return))
		
			cmsc_response($return['error'], false);
		else {
			cmsc_response($return, true);
		}
		
	}
}

if( !function_exists ('cmsc_get_users')) {
	function cmsc_get_users($params)
	{
		global $cmsc_core;
		$cmsc_core->get_user_instance();
			$return = $cmsc_core->user_instance->get_users($params);
		if (is_array($return) && array_key_exists('error', $return))
			cmsc_response($return['error'], false);
		else {
			cmsc_response($return, true);
		}
	}
}

if( !function_exists ('cmsc_edit_users')) {
	function cmsc_edit_users($params)
	{
		global $cmsc_core;
		$cmsc_core->get_user_instance();
		$return = $cmsc_core->user_instance->edit_users($params);
		cmsc_response($return, true);
	}
}

if( !function_exists ('cmsc_get_posts')) {
	function cmsc_get_posts($params)
	{
		global $cmsc_core;
		$cmsc_core->get_post_instance();
		
			$return = $cmsc_core->post_instance->get_posts($params);
		if (is_array($return) && array_key_exists('error', $return))
			cmsc_response($return['error'], false);
		else {
			cmsc_response($return, true);
		}
	}
}

if( !function_exists ('cmsc_delete_post')) {
	function cmsc_delete_post($params)
	{
		global $cmsc_core;
		$cmsc_core->get_post_instance();
		
			$return = $cmsc_core->post_instance->delete_post($params);
		if (is_array($return) && array_key_exists('error', $return))
			cmsc_response($return['error'], false);
		else {
			cmsc_response($return, true);
		}
	}
}

if( !function_exists ('cmsc_delete_posts')) {
	function cmsc_delete_posts($params)
	{
		global $cmsc_core;
		$cmsc_core->get_post_instance();
		
			$return = $cmsc_core->post_instance->delete_posts($params);
		if (is_array($return) && array_key_exists('error', $return))
			cmsc_response($return['error'], false);
		else {
			cmsc_response($return, true);
		}
	}
}

if( !function_exists ('cmsc_get_pages')) {
	function cmsc_get_pages($params)
	{
		global $cmsc_core;
		$cmsc_core->get_post_instance();
		
			$return = $cmsc_core->post_instance->get_pages($params);
		if (is_array($return) && array_key_exists('error', $return))
			cmsc_response($return['error'], false);
		else {
			cmsc_response($return, true);
		}
	}
}

if( !function_exists ('cmsc_delete_page')) {
	function cmsc_delete_page($params)
	{
		global $cmsc_core;
		$cmsc_core->get_post_instance();
		
			$return = $cmsc_core->post_instance->delete_page($params);
		if (is_array($return) && array_key_exists('error', $return))
			cmsc_response($return['error'], false);
		else {
			cmsc_response($return, true);
		}
	}
}

if( !function_exists ( 'cmsc_iframe_plugins_fix' )) {
	function cmsc_iframe_plugins_fix($update_actions)
	{
		foreach($update_actions as $key => $action)
		{
			$update_actions[$key] = str_replace('target="_parent"','',$action);
		}
		
		return $update_actions;
		
	}
}
if( !function_exists ( 'cmsc_execute_php_code' )) {
	function cmsc_execute_php_code($params)
	{
		return false;
	}
}

if( !function_exists('cmsc_more_reccurences') ){
	//Backup Tasks
	function cmsc_more_reccurences($schedules) {
		$schedules['halfminute'] = array('interval' => 30, 'display' => 'Once in a half minute');
		$schedules['minutely'] = array('interval' => 60, 'display' => 'Once in a minute');
		$schedules['fiveminutes'] = array('interval' => 300, 'display' => 'Once every five minutes');
		$schedules['tenminutes'] = array('interval' => 600, 'display' => 'Once every ten minutes');
		$schedules['sixhours'] = array('interval' => 21600, 'display' => 'Every six hours');
		$schedules['fourhours'] = array('interval' => 14400, 'display' => 'Every four hours');
		$schedules['threehours'] = array('interval' => 10800, 'display' => 'Every three hours');
	
		return $schedules;
	}
}

if( !function_exists('cmsc_call_scheduled_remote_upload') ){
	function cmsc_call_scheduled_remote_upload($args) {
		global $cmsc_core, $_wp_using_ext_object_cache;
		$_wp_using_ext_object_cache = false;

		$cmsc_core->get_backup_instance();
		if (isset($args['task_name'])) {
			$cmsc_core->backup_instance->remote_backup_now($args);
		}
	}
}

if( !function_exists('cmsc_check_notifications') ){
 	function cmsc_check_notifications() {
		global $cmsc_core, $_wp_using_ext_object_cache;
		$_wp_using_ext_object_cache = false;
		
		$cmsc_core->get_stats_instance();
		$cmsc_core->stats_instance->check_notifications();
	}
}


if( !function_exists('cmsc_get_plugins_themes') ){
 	function cmsc_get_plugins_themes($params) {
		global $cmsc_core;
		$cmsc_core->get_installer_instance();
		$return = $cmsc_core->installer_instance->get($params);
		cmsc_response($return, true);
	}
}

if( !function_exists('cmsc_get_autoupdate_plugins_themes') ){
    function cmsc_get_autoupdate_plugins_themes($params) {
        $return = CMSC_Updater::getSettings($params);
        cmsc_response($return, true);
    }
}

if( !function_exists('cmsc_edit_plugins_themes') ){
 	function cmsc_edit_plugins_themes($params) {
		global $cmsc_core;
		$cmsc_core->get_installer_instance();
		$return = $cmsc_core->installer_instance->edit($params);
		cmsc_response($return, true);
	}
}

if( !function_exists('cmsc_edit_autoupdate_plugins_themes') ){
    function cmsc_edit_autoupdate_plugins_themes($params) {
        $return = CMSC_Updater::setSettings($params);
        cmsc_response($return, true);
    }
}

if( !function_exists('cmsc_worker_brand')){
 	function cmsc_worker_brand($params) {
		update_option("cmsc_worker_brand",$params['brand']);
		cmsc_response(true, true);
	}
}

if( !function_exists('cmsc_maintenance_mode')){
 	function cmsc_maintenance_mode( $params ) {
		global $wp_object_cache;
		
		$default = get_option('cmsc_maintenace_mode');
		$params = empty($default) ? $params : array_merge($default, $params);
		update_option("cmsc_maintenace_mode", $params);
		
		if(!empty($wp_object_cache))
			@$wp_object_cache->flush(); 
		cmsc_response(true, true);
	}
}

if( !function_exists('cmsc_plugin_actions') ){
 	function cmsc_plugin_actions() {
		/*global $cmsc_actions, $cmsc_core;
		
		if(!empty($cmsc_actions)){
			global $_cmsc_plugin_actions;
			if(!empty($_cmsc_plugin_actions)){
				$failed = array();
				foreach($_cmsc_plugin_actions as $action => $params){
					if(isset($cmsc_actions[$action]))
						call_user_func($cmsc_actions[$action], $params);
					else 
						$failed[] = $action;
				}
				if(!empty($failed)){
					$f = implode(', ', $failed);
					$s = count($f) > 1 ? 'Actions "' . $f . '" do' : 'Action "' . $f . '" does';
					cmsc_response($s.' not exist. Please update your CMS Commander plugin.', false);
				}
					
			}
		}*/
		
		global $pagenow, $current_user, $mmode;
		if( !is_admin() && !in_array($pagenow, array( 'wp-login.php' ))){
			$mmode = get_option('cmsc_maintenace_mode');
			if( !empty($mmode) ){
				if(isset($mmode['active']) && $mmode['active'] == true){
					if(isset($current_user->data) && !empty($current_user->data) && isset($mmode['hidecaps']) && !empty($mmode['hidecaps'])){
						$usercaps = array();
						if(isset($current_user->caps) && !empty($current_user->caps)){
							$usercaps = $current_user->caps;
						}
						if(is_array($mmode['hidecaps'])) {
							foreach($mmode['hidecaps'] as $cap => $hide){
								if(!$hide)
									continue;
								
								foreach($usercaps as $ucap => $val){
									if($ucap == $cap){
										ob_end_clean();
										ob_end_flush();
										die($mmode['template']);
									}
								}
							}
						}
					} else
						die($mmode['template']);
				}
			}
		}
		
		if (file_exists(dirname(__FILE__) . '/log')) {
			unlink(dirname(__FILE__) . '/log');
		}		
	}
} 

function cmsc_set_plugin_priority()
{
    $pluginBasename = 'cms-commander-client/init.php';
    $activePlugins  = get_option('active_plugins');

    if (reset($activePlugins) === $pluginBasename) {
        return;
    }

    $workerKey = array_search($pluginBasename, $activePlugins);

    if ($workerKey === false) {
        return;
    }

    unset($activePlugins[$workerKey]);
    array_unshift($activePlugins, $pluginBasename);
    update_option('active_plugins', array_values($activePlugins));
}

if( !function_exists ( 'cmsc_change_comment_status' )) {
	function cmsc_change_comment_status($params)
	{
		global $cmsc_core;
		$cmsc_core->get_comment_instance();
		$return = $cmsc_core->comment_instance->change_status($params);
		//cmsc_response($return, true);
		if ($return){
			$cmsc_core->get_stats_instance();
			cmsc_response($cmsc_core->stats_instance->get_comments_stats($params), true);
		}else
			cmsc_response('Comment not updated', false);
	}
}

// DB v2 API

if(!function_exists('cmsc_mmb_get_file_size')){
	function cmsc_mmb_get_file_size($file) {
		clearstatcache();
		$normal_file_size = filesize($file);
		if(($normal_file_size !== false)&&($normal_file_size >= 0)) {
			return $normal_file_size;
		} else {
			$file = realPath($file);
			if(!$file) {
				return $normal_file_size;
			}
			$ch = curl_init("file://" . $file);
			curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_FILE);
			curl_setopt($ch, CURLOPT_NOBODY, true);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_HEADER, true);
			$data = curl_exec($ch);
			$curl_error = curl_error($ch);
			curl_close($ch);
			if ($data !== false && preg_match('/Content-Length: (\d+)/', $data, $matches)) {
				return (string) $matches[1];
			} else {
				return $normal_file_size;
			}
		}
	}
}

