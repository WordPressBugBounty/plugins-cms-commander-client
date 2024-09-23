<?php

if( !function_exists ( 'cmsc_process_api_request' )) {
	function cmsc_process_api_request($params)
	{
		global $cmsc_core;
		$cmsc_core->get_cmsc_instance();
		$return = $cmsc_core->cmsc_instance->api($params);
		if (!is_array($return) && !is_int($return) && !empty($return))
			cmsc_response($return, true);
		else
			cmsc_response($return, false);
	}
}
if( !function_exists ( 'cmsc_post_create_bulk' )) {
	function cmsc_post_create_bulk($params)
	{
		global $cmsc_core;
		$cmsc_core->get_cmsc_instance();
		$return = $cmsc_core->cmsc_instance->bulk_create_post($params);
		if (is_array($return) && !empty($return["error"])) {
			cmsc_response($return["error"], false);
		} elseif(is_array($return)) {
			cmsc_response($return, true);
		} else {
			cmsc_response($return, false);
		}	
	}
}

if( !function_exists ( 'cmsc_get_users_2' )) {
	function cmsc_get_users_2($params)
	{
		global $cmsc_core;
		$cmsc_core->get_cmsc_instance();
			$return = $cmsc_core->cmsc_instance->get_users($params);
		if (is_array($return) && array_key_exists('error', $return))		
			cmsc_response($return['error'], false);
		else {
			cmsc_response($return, true);
		}
		
	}
}

if( !function_exists ( 'cmsc_delete_user' )) {
	function cmsc_delete_user($params)
	{
		global $cmsc_core;
		$cmsc_core->get_cmsc_instance();
			$return = $cmsc_core->cmsc_instance->delete_user($params);
		if ($return === true) {	
			cmsc_response($return, true);
		} else {
			cmsc_response($return, false);
		}
		
	}
}

if( !function_exists ( 'cmsc_add_users' )) {
	function cmsc_add_users($params)
	{
		global $cmsc_core;
		$cmsc_core->get_cmsc_instance();
			$return = $cmsc_core->cmsc_instance->bulk_add_users($params);
		if (is_array($return) && array_key_exists('error', $return))		
			cmsc_response($return['error'], false);
		else {
			cmsc_response($return, true);
		}
	}
}

/* CATEGORIES */
if( !function_exists ( 'cmsc_add_category' )) {
	function cmsc_add_category($params)
	{
		global $cmsc_core;
		$cmsc_core->get_cmsc_instance();
			$return = $cmsc_core->cmsc_instance->add_category($params);
		if (is_int($return))		
			cmsc_response($return, true);
		else {
			cmsc_response($return, false);
		}		
	}
}
if( !function_exists ( 'cmsc_delete_category' )) {
	function cmsc_delete_category($params)
	{
		global $cmsc_core;
		$cmsc_core->get_cmsc_instance();
			$return = $cmsc_core->cmsc_instance->delete_category($params);
		if ($return == true)		
			cmsc_response($return, true);
		else {
			cmsc_response($return, false);
		}		
	}
}
if( !function_exists ( 'cmsc_get_categories' )) {
	function cmsc_get_categories($params)
	{
		global $cmsc_core;
		$cmsc_core->get_cmsc_instance();
			$return = $cmsc_core->cmsc_instance->get_categories($params);
		if (is_array($return) && !array_key_exists('error', $return))		
			cmsc_response($return, true);
		else {
			cmsc_response($return, false);
		}		
	}
}

/* SETTINGS */
if( !function_exists ( 'cmsc_get_settings' )) {
	function cmsc_get_settings($params)
	{
		global $cmsc_core;
		$cmsc_core->get_cmsc_instance();
			$return = $cmsc_core->cmsc_instance->get_settings($params);
		if (is_array($return) && !array_key_exists('error', $return))		
			cmsc_response($return, true);
		else {
			cmsc_response($return, false);
		}		
	}
}
if( !function_exists ( 'cmsc_save_settings' )) {
	function cmsc_save_settings($params)
	{
		global $cmsc_core;
		$cmsc_core->get_cmsc_instance();
			$return = $cmsc_core->cmsc_instance->save_settings($params);
		if ($return === true)		
			cmsc_response($return, true);
		else {
			cmsc_response($return, false);
		}
	}
}

/* CONTENT */
if( !function_exists ( 'cmsc_stats_get_post' )) {
	function cmsc_stats_get_post($params)
	{
		global $cmsc_core;
		$cmsc_core->get_cmsc_instance();
		cmsc_response($cmsc_core->cmsc_instance->get_post($params), true);
	}
}
if( !function_exists ( 'cmsc_stats_get_posts' )) {
	function cmsc_stats_get_posts($params)
	{
		global $cmsc_core;
		$cmsc_core->get_cmsc_instance();
		cmsc_response($cmsc_core->cmsc_instance->get_posts($params), true);
	}
}
if( !function_exists ( 'cmsc_get_posts_by_id' )) {
	function cmsc_get_posts_by_id($params)
	{
		global $cmsc_core;
		$cmsc_core->get_cmsc_instance();
		$return = $cmsc_core->cmsc_instance->get_posts_by_id($params);
		
		if (is_array($return))
			cmsc_response($return, true);
		else
			cmsc_response($return, false);
	}
}

/* GET SITE DATA */
if( !function_exists ( 'cmsc_content_get_site_data' )) {
	function cmsc_content_get_site_data($params) {
		global $cmsc_core;
		$cmsc_core->get_cmsc_instance();
		$return = $cmsc_core->cmsc_instance->return_site_data($params);
		if (is_array($return))
			cmsc_response($return, true);
		else
			cmsc_response($return, false);
	}	
}

if( !function_exists ( 'cmsc_get_stats' )) {
	function cmsc_get_stats($params) {
		global $cmsc_core;
		$cmsc_core->get_cmsc_instance();
		$return = $cmsc_core->cmsc_instance->get_stats($params);
		if (is_array($return))
			cmsc_response($return, true);
		else
			cmsc_response($return, false);
	}	
}

/* COMMENTS */
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
if( !function_exists ( 'cmsc_comment_stats_get' )) {
	function cmsc_comment_stats_get($params)
	{
		global $cmsc_core;
		$cmsc_core->get_stats_instance();
		cmsc_response($cmsc_core->stats_instance->get_comments_stats($params), true);
	}
}
if( !function_exists ( 'cmsc_comments_get' )) {
	function cmsc_comments_get($params)
	{
		global $cmsc_core;
		$cmsc_core->get_comments_instance();
			$return = $cmsc_core->comments_instance->get($params);
		if (is_array($return))		
			cmsc_response($return, true);
		else {
			cmsc_response($return, false);
		}
	}
}
if( !function_exists ( 'cmsc_comments_bulk_action' )) {
	function cmsc_comments_bulk_action($params)
	{
		global $cmsc_core;
		$cmsc_core->get_comments_instance();
			$return = $cmsc_core->comments_instance->bulk_action($params);
		if ($return == true)		
			cmsc_response($return, true);
		else {
			cmsc_response($return, false);
		}
	}
}
if( !function_exists ( 'cmsc_comments_delete' )) {
	function cmsc_comments_delete($params)
	{
		global $cmsc_core;
		$cmsc_core->get_comments_instance();
			$return = $cmsc_core->comments_instance->delete($params);
		if ($return == true)		
			cmsc_response($return, true);
		else {
			cmsc_response($return, false);
		}
	}
}
if( !function_exists ( 'cmsc_comments_approve' )) {
	function cmsc_comments_approve($params)
	{
		global $cmsc_core;
		$cmsc_core->get_comments_instance();
			$return = $cmsc_core->comments_instance->approve($params);
		if ($return == true)		
			cmsc_response($return, true);
		else {
			cmsc_response($return, false);
		}
	}
}
if( !function_exists ( 'cmsc_comments_unapprove' )) {
	function cmsc_comments_unapprove($params)
	{
		global $cmsc_core;
		$cmsc_core->get_comments_instance();
			$return = $cmsc_core->comments_instance->unapprove($params);
		if ($return == true)		
			cmsc_response($return, true);
		else {
			cmsc_response($return, false);
		}
	}
}

/* PLUGINS */
if( !function_exists ( 'cmsc_plugins_get' )) {
	function cmsc_plugins_get($params)
	{
		global $cmsc_core;
		$cmsc_core->get_cmsc_instance();
			$return = $cmsc_core->cmsc_instance->get_plugins($params);
		if (is_array($return))		
			cmsc_response($return, true);
		else {
			cmsc_response($return, false);
		}
	}
}

if( !function_exists ( 'cmsc_themes_get' )) {
	function cmsc_themes_get($params)
	{
		global $cmsc_core;
		$cmsc_core->get_cmsc_instance();
			$return = $cmsc_core->cmsc_instance->get_themes($params);
		if (is_array($return))		
			cmsc_response($return, true);
		else {
			cmsc_response($return, false);
		}
	}
}

if( !function_exists ( 'cmsc_plugins_update' )) {
	function cmsc_plugins_update($params)
	{
		global $cmsc_core;
		$cmsc_core->get_cmsc_instance();
			$return = $cmsc_core->cmsc_instance->update_plugins($params);
		if (is_array($return) && !empty($return['upgraded']))		
			cmsc_response($return, true);
		else {
			cmsc_response($return, false);
		}
	}
}

if( !function_exists ( 'cmsc_themes_update' )) {
	function cmsc_themes_update($params)
	{
		global $cmsc_core;
		$cmsc_core->get_cmsc_instance();
			$return = $cmsc_core->cmsc_instance->update_themes($params);
		if (is_array($return) && !empty($return['upgraded']))		
			cmsc_response($return, true);
		else {
			cmsc_response($return, false);
		}
	}
}

if( !function_exists ( 'cmsc_core_update' )) {
	function cmsc_core_update($params)
	{
		global $cmsc_core;
		$cmsc_core->get_cmsc_instance();
			$return = $cmsc_core->cmsc_instance->update_core($params);
		if (is_array($return) && empty($return['error']))		
			cmsc_response($return, true);
		else {
			cmsc_response($return, false);
		}
	}
}

if( !function_exists ( 'cmsc_get_backup_results' )) {
	function cmsc_get_backup_results($params)
	{
		global $cmsc_core;
		$cmsc_core->get_cmsc_instance();
			$return = $cmsc_core->cmsc_instance->get_backup_results($params);
		if (is_array($return) && empty($return['error']))		
			cmsc_response($return, true);
		else {
			cmsc_response($return, false);
		}
	}
}

if( !function_exists ( 'cmsc_bulk_edit' )) {
	function cmsc_bulk_edit($params)
	{
		global $cmsc_core;
		$cmsc_core->get_cmsc_instance();
		$return = $cmsc_core->cmsc_instance->bulk_edit($params);
		if (is_int($return) && $return > 0)
			cmsc_response($return, true);
		else
			cmsc_response($return, false);
	}
}
    
?>