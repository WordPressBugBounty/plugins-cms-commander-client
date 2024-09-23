<?php

class CMSC_Functions extends CMSC_Core
{
    function __construct()
    {
        parent::__construct();
    }
	
    function api($args)
    {
	
		$appid = $args['appid'];
		$source = $args['source'];
		$request = $args['request'];
		
		if ( function_exists('curl_init') ) {
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0 (compatible; Konqueror/4.0; Microsoft Windows) KHTML/4.0.80 (like Gecko)");
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
			curl_setopt($ch, CURLOPT_URL, $request);
			curl_setopt($ch, CURLOPT_TIMEOUT, 60);
			if($source == "cj" || $source == "commissionjunction") {curl_setopt($ch, CURLOPT_HTTPHEADER, array(
				'Host: link-search.api.cj.com',
				'User-Agent: Mozilla/5.0 (Windows; U; Windows NT 6.0; en-US; rv:1.9.0.8) Gecko/2009032609 Firefox/3.0.8',
				'Authorization: '.$appid,
				'Content-Type: application/xml'
				));	}					
			$response = curl_exec($ch);
			if (!$response) {
				$return["error"] = __("cURL Error Number ","cmsc").curl_errno($ch).": ".curl_error($ch);	
				return $return;
			}		
			curl_close($ch);
		} else { 				
			$response = @file_get_contents($request);
			if (!$response) {
				$return["error"] = __("cURL is not installed on this server!","cmsc");	
				return $return;		
			}
		}
		return $response;
	}	
	
    function bulk_create_post($args)
    {
		$po = new CMSC_Post(); 
		$ids = array();		
		foreach($args["post"] as $arg) {
			$id = $po->create($arg);
			if(is_array($id) && !empty($id["error"])) {
				return $id;
			}
			$ids[] = $id;
			if(!empty($arg['comments'])) {
				$com = new CMSC_Comments(); 
				foreach($arg['comments'] as $comment) {					
					$cid = $com->create(array("post_id" => $id, "postdate" => "", "comment" => array("content" => $comment["content"], "author" => $comment["author"])));
				}
			}
			
			if(!empty($arg['replies'])) {
				
				$post = get_post($id);
				$title="Reply To: " . $post->post_title;
				foreach($arg['replies'] as $comment) {		
				
					$my_post = array(
							 'post_title' => $title,
							 'post_content' => $comment["content"],
							 'post_status' => 'publish',
							 'post_author' => $comment["author"],
							 'post_type' => 'reply',
							 'post_date' => $arg['post_date'],
							 'post_date_gmt' => $arg['post_date_gmt'],
							 'post_parent' => $post->ID
					); 				
				
					$args2 = array();
					$args2['post_data']['post_data'] = $my_post;	
					$args2['post_data']['content'] = $my_post;				
					$repl = $po->create($args2);
				}
			}
		}
		return $ids;	
	} 

    function get_users($args)
    {

    	if(!function_exists('get_users'))
			include_once(ABSPATH . WPINC . '/user.php');			
	
		$num = $args['num'];
		if(empty($num)) {$num = 50;}		

		return get_users('number='.$num);
	} 	
	
    function delete_user($args)
    {	
		$uid = $args['user_id'];  // can accept array of user ids
		if(empty($uid)) {return 'User could not be found.';}			
		
		$aid = $args['username']; 

		$user = get_user_by('login',$aid);
		if($user) {
			if(!function_exists('wp_delete_user'))
				include_once(ABSPATH .  'wp-admin/includes/user.php');				

			return wp_delete_user( $uid, $user->ID );		   		   
		} else {
			return 'Replacement user could not be found.';		
		}
	}		
	
	function bulk_add_users($args)
	{
		$users = new CMSC_User();
	
		$ids = array();		
		foreach($args["users"] as $user) {
			$ids[] = $users->add_user($user);
		}
		return $ids;		
	}		

	function add_category($args)
    {	
		$category = $args['category'];		
		$cat_name = $category['name'];	
		$parent = $category['parent'];	
		
		if(!function_exists('wp_create_category'))
			include_once (ABSPATH . 'wp-admin/includes/taxonomy.php');	
	
		if(empty($cat_name)) {
			return 'Invalid data received.';
		}			

		return wp_create_category( $cat_name, $parent );

	}		
	
    function get_stats($args)
    {
		// return recent post drafts and comments, upgradeable plugins, core
		$data = array();
		
		// PREMIUM UPDATES		
		$premium_updates = array();
		$upgrades        = apply_filters('mwp_premium_update_notification', $premium_updates);
		if (!empty($upgrades)) {
			$data['premium_updates'] = $upgrades;
		}
		
		// REFRESH UPDATE TRANSIENTS
		if (function_exists('w3tc_pgcache_flush') || function_exists('wp_cache_clear_cache')) {
			$this->cmsc_delete_transient('update_core');
			$this->cmsc_delete_transient('update_plugins');
			$this->cmsc_delete_transient('update_themes');
		}

		global $wp_current_filter;
		// Some plugins that hook to transient setting rely on get_plugin_data() function.
		include_once ABSPATH.'wp-admin/includes/plugin.php';
		$wp_current_filter[] = 'load-update-core.php';

		wp_version_check();
		wp_update_themes();

		wp_update_plugins();

		array_pop($wp_current_filter);

		do_action('load-plugins.php');		    
       
		// GET DRAFTS
		$drafts = get_posts('post_status=draft&numberposts=4&orderby=modified&order=desc');
		$savedrafts = array();
		foreach ($drafts as $id => & $draft) {
			$replace = new stdClass;
			$replace->ID = $draft->ID;
			$replace->guid = $draft->guid;
			$replace->post_title = $draft->post_title;
			
			$savedrafts[$id] = $replace;
		}

		$data["drafts"] = $savedrafts;

		// Comments
		$status = "hold";
        $comments = get_comments('status='.$status.'&number=5');
        foreach ($comments as &$comment) {
            $commented_post      = get_post($comment->comment_post_ID);
            $comment->post_title = $commented_post->post_title;
			$comment->post_link = $commented_post->guid;
			unset($comment->comment_agent);
			unset($comment->comment_author_IP);
			unset($comment->comment_type);
			unset($comment->comment_karma);			
		}		
		$data["comments"] = $comments;

		// Plugins
   		if(!function_exists('get_plugins')){
   			include_once(ABSPATH.'wp-admin/includes/plugin.php');
   		}		

		$all_plugins = get_plugins();
		if(is_array($all_plugins) && !empty($all_plugins)){
		
			$upgradeable_plugins = $this->cmsc_get_transient('update_plugins');
			
			if (empty($upgradeable_plugins->response)) {$upgradeable_plugins = array();} else {$upgradeable_plugins = $upgradeable_plugins->response;}

			$br_i = 0;
			foreach($all_plugins as $path => $plugin){	
				if($plugin['Name'] != 'CMS Commander'){		
					if(!empty($upgradeable_plugins[$path])) {
						$plugins[$br_i]['path'] = $path;
						$plugins[$br_i]['name'] = $plugin['Name'];
						$plugins[$br_i]['version'] = $plugin['Version'];
						//$plugins[$br_i]['description'] = $plugin['Description'];					
						$plugins[$br_i]['update'] = (array) $upgradeable_plugins[$path];
						if(is_array($plugins[$br_i]['update']["sections"])) {unset($plugins[$br_i]['update']["sections"]);}
					}				
					$br_i++;
				}
			}	

			if(!empty($args['ignore']) && is_array($args['ignore']) && is_array($plugins)) {
				foreach($plugins as $id => $plugin){
					if(in_array($plugin['path'], $args['ignore'])) {
						unset($plugins[$id]);
						//$plugins[$id]['name'] = "IGNORE";
					}
				}
			}			
			
		}		
		$data["plugins"] = $plugins;
		
		// Themes
   		if(!function_exists('get_themes')){
   			include_once(ABSPATH.WPINC.'/theme.php');
   		}
		
		$all_themes = get_themes();
		$themes     = array();
		
		if(is_array($all_themes) && !empty($all_themes)) {
		
			$current_theme = get_current_theme();
			
			$upgradeable_themes = $this->cmsc_get_transient('update_themes');
			
			if (empty($upgradeable_themes->response)) {$upgradeable_themes = array();} else {$upgradeable_themes = $upgradeable_themes->response;}			

			$br_i = 0;
			foreach($all_themes as $theme_name => $theme){
				$path = $theme['Template'];				
				if(!empty($upgradeable_themes[$path])) {
					$themes[$br_i]['path'] = $theme['Template'];
					$themes[$br_i]['name'] = strip_tags($theme['Name']);
					$themes[$br_i]['stylesheet'] = $theme['Stylesheet'];
					//$themes[$br_i]['description'] = $theme['Description'];
					$themes[$br_i]['version'] = $theme['Version'];				
					$themes[$br_i]['update'] = (array) $upgradeable_themes[$path];				
				}
				$br_i++;			
			}
		}		
		$data["themes"] = $themes;		
		
		// Core
		global $wp_version;
		$core = $this->cmsc_get_transient('update_core');
		if (isset($core->updates) && !empty($core->updates)) {
			$current_transient = $core->updates[0];
			if ($current_transient->response == "development" || version_compare($wp_version, $current_transient->current, '<')) {
				$current_transient->current_version = $wp_version;
				$data['core_updates'] = $current_transient;
			} else {
				$data['core_updates'] = false;
			}	
		} else {
			$data['core_updates'] = false;
		}
		
		// Backups
		$data['backups'] = get_option('cmsc_backup_tasks');
		$data['versions'] = CMSC_WORKER_VERSION;

		if(function_exists("wp_count_comments")) {
			$comments_count = wp_count_comments();
			$data['counts']['comments_pending'] = $comments_count->moderated;
			$data['counts']['comments_approved'] = $comments_count->approved;
			$data['counts']['comments_spam'] = $comments_count->spam;
			$data['counts']['comments_trash'] = $comments_count->trash;
		}
		
		/*if(function_exists("cmsc_num_revisions")) {
			$data['counts']['num_revisions'] = cmsc_num_revisions("r_6");
		}*/
		
		if(function_exists("cmsc_handle_overhead")) {
			$data['counts']['overhead'] = cmsc_handle_overhead(false);
		}	

		if ( is_plugin_active( 'WPRobot3/wprobot.php' ) ) {
			$data['wprobot'] = "yes";
		} else {
			$data['wprobot'] = "no";
		}			
		
		return $data;		
	}
	
    function get_categories()
    {	

		return get_categories(array("hide_empty" => 0));
	}	
	
	function delete_category($args)
    {	
		$cat_id = $args['cat_id'];	
	
		if(!function_exists('wp_delete_category'))
			include_once (ABSPATH . 'wp-includes/taxonomy.php');	
	
		if(empty($cat_id)) {
			return 'Invalid data received.';
		}			

		return wp_delete_category( $cat_id );

	}

    function get_settings()
    {	
		//return get_alloptions();
		return wp_load_alloptions();
	}	
	
    function save_settings($args)
    {	
		$settings = $args['settings'];	
	
		if(empty($settings) || !is_array($settings)) {
			return 'Invalid data received.';
		}			
	
		foreach($settings as $name => $key) {
		
			//return $name . " LLL " . $key;
		
			if(!empty($name)) {
				update_option($name, $key);
			}
		}

		return true;
	}

    function get_post($args)
    {	
		$post_id = $args['post_id'];
		if(empty($post_id)) {return false;}
		$catnames = array();
		$cats = wp_get_post_categories( $post_id, $args );
		foreach($cats as $cat) {
			$catnames[] = get_cat_name($cat);
		}
		$post = get_post( $post_id, ARRAY_A );
		$post["categories"] = $catnames;
		return $post;
	}	
	
    function get_posts($args)
    {	

        global $wpdb, $cmsc_wp_version, $cmsc_plugin_dir, $wp_version, $wp_local_package;
		
		$num = $args['post_ids'];
		if(empty($num)) {$num = 10;}
		
        $all_posts = get_posts('post_status=any&numberposts='.$num.'&orderby=modified&order=desc');	// 
		
		return $all_posts;
	}
	
    function get_posts_by_id($args)
    {	

        global $wpdb, $cmsc_wp_version, $cmsc_plugin_dir, $wp_version, $wp_local_package;
		
		$pids = $args['post_ids']; //array(43,23,65)
		$ptype = $args['post_type'];
		
		if(empty($ptype)) {$ptype = "post";}
		
		if($ptype != "post") {
			$pargs = array(
				'include' => $pids,
				'post_type' => $ptype,
				'post_status' => array("publish", "pending", "draft", "future",  "private"),
			);

			$posts = get_pages($pargs);				
		} else {
			$pargs = array(
				'post__in' => $pids,
				'post_type' => $ptype,
				'post_status' => array("publish", "pending", "draft", "future",  "private"),
			);

			$posts = get_posts($pargs);					
		}
		

		
		$i = 0;$cats = array();
		foreach($posts as $id => $post) {		
			$post_cats = wp_get_post_categories( $post->ID );
			
			foreach($post_cats as $c){
				$cat = get_category( $c );
				$cats[] = $cat->name;
			}
			
			$meta_values = get_post_meta( $post->ID );
			
			///$post_tags = wp_get_post_tags($post->ID);
			
			$src = wp_get_attachment_image_src(get_post_thumbnail_id($post->ID), "full");
			
			$posts[$i]->featured_image = $src[0];
			$posts[$i]->post_categories = $cats;
			$posts[$i]->post_tags = $post_tags;
			$posts[$i]->post_meta = $meta_values;
			$i++;
		}
		
		return $posts;
	}	

	function return_site_data($args)
	{
		$data = array();
		$data["wpusers"] = $this->get_users($params);
		$data["wpcats"] = $this->get_categories($params);		
		$data["wpposttypes"] = get_post_types('','names');		

		return $data;
	}
	
   function get_themes($args)
   {
   		
   		if(!function_exists('get_themes')){
   			include_once(ABSPATH.WPINC.'/theme.php');
   		}
		
		$all_themes = get_themes();
		$themes     = array();
		
		if(is_array($all_themes) && !empty($all_themes)) {
		
			$current_theme = get_current_theme();
			
			$upgradeable_themes = $this->cmsc_get_transient('update_themes');
			
			if (empty($upgradeable_themes->response)) {$upgradeable_themes = array();} else {$upgradeable_themes = $upgradeable_themes->response;}			

			$br_i = 0;
			foreach($all_themes as $theme_name => $theme){
				$path = $theme['Template'];
				$themes[$br_i]['path'] = $theme['Template'];
				$themes[$br_i]['name'] = strip_tags($theme['Name']);
				$themes[$br_i]['stylesheet'] = $theme['Stylesheet'];
				$themes[$br_i]['description'] = $theme['Description'];
				$themes[$br_i]['version'] = $theme['Version'];
				
				if($current_theme == $theme_name){
					$themes[$br_i]['active'] = 1;
				}	

				if(!empty($upgradeable_themes[$path])) {
					$themes[$br_i]['update'] = (array) $upgradeable_themes[$path];
				}	
				$br_i++;
			}
		}
			
		return array("themes" => $themes);		
   }	

    function get_plugins($args)
    {	

   		if(!function_exists('get_plugins')){
   			include_once(ABSPATH.'wp-admin/includes/plugin.php');
   		}
		
		$all_plugins = get_plugins();
		if(is_array($all_plugins) && !empty($all_plugins)){
			$activated_plugins = get_option('active_plugins');
			if(!$activated_plugins) {$activated_plugins = array();}
		
			$upgradeable_plugins = $this->cmsc_get_transient('update_plugins');
			
			if (empty($upgradeable_plugins->response)) {$upgradeable_plugins = array();} else {$upgradeable_plugins = $upgradeable_plugins->response;}

			$br_i = 0;
			foreach($all_plugins as $path => $plugin){
				if($plugin['Name'] != 'CMS Commander'){	
					$plugins[$br_i]['path'] = $path;
					$plugins[$br_i]['name'] = $plugin['Name'];
					$plugins[$br_i]['version'] = $plugin['Version'];
					$plugins[$br_i]['description'] = $plugin['Description'];
					
					if(in_array($path,$activated_plugins)) {$plugins[$br_i]['active'] = 1;} else {$plugins[$br_i]['active'] = 0;}
		
					if(!empty($upgradeable_plugins[$path])) {
						$plugins[$br_i]['update'] = (array) $upgradeable_plugins[$path];
					}

					$br_i++;
				}
			}			
		}
		
		return array("plugins" => $plugins);
	}

	function update_plugins($args) {

		if(empty($args['plugins']) && $args['updateall'] != 1) {
			return array('error' => 'No plugin files to upgrade.');				
		}
	
		$installer = new CMSC_Installer();
		
		if(!empty($args['plugins'])) {
			$plugin_files = $args['plugins'];
		}	

		if(!empty($args['premium'])) {
			$premium_upgrades = $args['premium'];
		}				
		
		if(1 == $args['updateall']) {
			$premium_upgrades = array();
			$upgrade_plugins = $installer->get_upgradable_plugins();
			
			if(!empty($upgrade_plugins)){
				$plugin_files = array();
				foreach($upgrade_plugins as $plugin){				
					if(isset($plugin->file)) {
						$plugin_files[$plugin->file] = $plugin->old_version;
					} else {
						$premium_upgrades[md5($plugin->name)] = $plugin;
					}	
				}
			} else {
				return array('error' => 'No plugin files to upgrade found.');		
			}
		}
		
		if(!empty($args['ignore']) && is_array($args['ignore'])) {
			foreach($plugin_files as $plugin => $ver){
				if(in_array($plugin, $args['ignore'])) {
					unset($plugin_files[$plugin]);
				}
			}
		}
		
		if(empty($premium_upgrades) && empty($plugin_files)) {
			return array('error' => 'No plugin files to upgrade found. Updates already installed or all plugins set to ignore.');		
		}		

		if(!empty($plugin_files)) {
			$upgrades = $installer->upgrade_plugins($plugin_files);
		}

        if (!empty($premium_upgrades)) {
            $premium_upgrades = $installer->upgrade_premium($premium_upgrades);
            if (!empty($premium_upgrades)) {
                if (!empty($upgrades)) {
                    foreach ($upgrades as $key => $val) {
                        if (isset($premium_upgrades[$key])) {
                            $upgrades[$key] = array_merge_recursive($upgrades[$key], $premium_upgrades[$key]);
                        }
                    }
                } else {
                    $upgrades = $premium_upgrades;
                }
            }
        }

		return $upgrades;
    }
	
	function update_themes($args)
	{
		$installer = new CMSC_Installer();
		
		if(1 == $args['updateall']) {
			$upgrade_themes = $installer->get_upgradable_themes();
		} elseif(empty($args['themes'])) {
			return array('error' => 'No theme files to upgrade.');		
		} else {
			$theme_files = $args['themes'];
		}
	
		if(!empty($upgrade_themes)){
			$theme_files = array();
			foreach($upgrade_themes as $theme){
				if(isset($theme['theme_tmp']))
					$theme_files[] = $theme['theme_tmp'];
			}
		}

		if(!empty($theme_files)) {
			$upgrades = $installer->upgrade_themes($theme_files);
		} else {
			return array('error' => 'No theme files to upgrade found.');		
		}
	
		return $upgrades;
    }
	
	function update_core($args)
	{	
	
		$current = $args['current'];
	
		$installer = new CMSC_Installer();
		return $installer->upgrade_core($current);
	}
	
	function get_backup_results($args)
	{	
		return get_option('cmsc_backup_tasks');
	}	
	
	function bulk_edit($args)
	{	
		include_once ABSPATH . 'wp-admin/includes/taxonomy.php';
		$scount = 0;
		$posts = $args['posts'];
		foreach($posts as $post) {
			$data = get_post( $post, ARRAY_A );
			$new_post = array();
			$new_post['ID'] = (int) $post;	
			
			if(!empty($args['add']['content'])) {

				if($args['add']['where'] == "beginning") {
					$new_post['post_content'] = $args['add']['content'] . $data["post_content"];
				} else {
					$new_post['post_content'] =  $data["post_content"] . $args['add']['content'];
				}

			}			
			
			if(!empty($args['replace']['text'])) {
				$data["post_content"] = str_replace($args['replace']['text'], $args['replace']['with'], $data["post_content"]);	
				$new_post['post_content'] = $data["post_content"];
			}
			
			if(!empty($args['cats']['cats'])) {
				$cats = explode(",", $args['cats']['cats']);
				
				$cat_ids = wp_create_categories($cats);//, $post
				
				if($args['cats']['replace'] == 1) {
					$data['post_category'] = $cat_ids;	
				} else {
					$data['post_category'] = wp_get_post_categories( $post );
					foreach($cat_ids as $cat_id) {
						$data['post_category'][] = $cat_id;
					}
				} 
				$new_post['post_category'] = $data["post_category"];
			}	

			if(!empty($args['tags']['tags'])) {
				$tags = explode(",", $args['tags']['tags']);
				if($args['tags']['replace'] == 1) {
					$data['tags_input'] = $tags;	
				} else {
					$currtags = wp_get_post_tags( $post );
					foreach($currtags as $ctag) {
						$data['tags_input'][] = $ctag->name;
					}
					foreach($tags as $tag) {
						$data['tags_input'][] = $tag;
					}				
				}
				$new_post['tags_input'] = $data['tags_input'];
			}

			if(!empty($args['status'])) {
				if($args['status'] == "publish" || $args['status'] == "draft" || $args['status'] == "private" || $args['status'] == "pending") {
					$new_post['post_status'] = $args['status'];
				}
			}			

			if(!empty($args['link']['keywords'])) {
				if(!empty($new_post['post_content'])) {$data['post_content'] = $new_post['post_content'];}
				$keywords = explode(",", $args['link']['keywords']);
				foreach($keywords as $keyword) {
					preg_match_all("/ ".$keyword." /s", $data["post_content"], $matches);
					foreach($matches[0] as $match) {
						if($args['link']['chance'] >= rand(0, 100)) {
							if($args['link']['newwindow'] == 1) {$newwindow = ' target="_blank"';} else {$newwindow = ' ';}					
							if($args['link']['nofollow'] == 1) {$nofollow = ' rel="nofollow"';} else {$nofollow = ' ';}
							$link = ' <a href="'.$args['link']['url'].'" '.$nofollow.$newwindow.'>'.trim($match).'</a> ';		
							$data["post_content"] = preg_replace('/'.$match.'/', $link, $data["post_content"], 1);
						}
					}
				}
				$new_post['post_content'] = $data['post_content'];
			}
			
			if(!empty($args['content']) && $args['content']['yes'] == 1) {
				if($args['content']['where'] == "end") {
					$data['post_content'] = $data['post_content'] . " " . $args['contents'][$post];
				} else {
					$data['post_content'] = $args['contents'][$post] . " " . $data['post_content'];				
				}
				$new_post['post_content'] = $data['post_content'];
			}			
			
			$res = wp_update_post( $new_post ); // save results
			if($res != 0) {$scount++;}
		}
		return $scount;
	}		
}

function cmsc_ebay_handler($atts, $content = null) {

	$campID = $atts['cid'];	
	
	$lang = $atts['lang'];
	if (empty($lang)) {$lang="en-US";}			
	
	$country = $atts['country'];
	if (empty($country)) {$country=0;}		
	
	$sortby = $atts['sort'];
	if (empty($sortby)) {$sortby="bestmatch";}	
	
	$ebaycat = $atts["ebcat"];
	if (empty($ebaycat) || $ebaycat == "all") {$ebaycat="-1";}		
	
	$number = $atts['num'];
	if(empty($number)) {$number = rand(1,30);}	
	
	$arrFeeds = array();

	require_once ( ABSPATH . WPINC .  '/rss.php' );	

	if($country == 0) {$program = 1;}
	elseif($country == 205) {$program = 2;}
	elseif($country == 16) {$program = 3;}
	elseif($country == 15) {$program = 4;}
	elseif($country == 23) {$program = 5;}
	elseif($country == 2) {$program = 7;}
	elseif($country == 71) {$program = 10;}
	elseif($country == 77) {$program = 11;}
	elseif($country == 101) {$program = 12;}
	elseif($country == 186) {$program = 13;}
	elseif($country == 193) {$program = 14;}
	elseif($country == 3) {$program = 15;}
	elseif($country == 146) {$program = 16;}
	else {$program = $country;}	
	$rssurl= "http://rest.ebay.com/epn/v1/find/item.rss?keyword=" . str_replace(" ","+", ($atts['kw']))."&campaignid=" . urlencode($campID) . "&sortOrder=BestMatch&programid=".$program."";	
	
	if(!empty($ebaycat) && $ebaycat != -1){
		$rssurl.="&categoryId1=".$ebaycat;
	}		
	
	
	$therss = fetch_rss($rssurl);
	
	if($therss->items != "" && $therss->items != null) {
		foreach ($therss->items as $item) { 
			$itemRSS = array (
				'title' => $item['title'],
				'desc' => $item['description'],
				'link' => $item['link'],
				'date' => $item['pubDate']
				);
			array_push($arrFeeds, $itemRSS);
		}
	}

	$ebcontent = "<strong>".$arrFeeds[$number]['title']."</strong>".$arrFeeds[$number]['desc'];	
	if($arrFeeds[$number]['title'] != "") {
	} else {$ebcontent = "";}

	return $ebcontent;
}
add_shortcode('cmsc_ebay', 'cmsc_ebay_handler' );

?>