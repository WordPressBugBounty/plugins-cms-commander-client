<?php
/*************************************************************
 * 
 * stats.class.php
 * 
 * Get Site Stats
 * 
 * 
 * Copyright (c) 2011 Prelovac Media
 * www.prelovac.com
 **************************************************************/
if(basename($_SERVER['SCRIPT_FILENAME']) == "stats.class.php"):
    exit;
endif;

class CMSC_Stats extends CMSC_Core
{
    function __construct()
    {
        parent::__construct();
    }
    
    /*************************************************************
     * FACADE functions
     * (functions to be called after a remote call from Master)
     **************************************************************/
    
    public function get_site_statistics($stats, $options = array())
    {
        /** @var wpdb $wpdb */
        global $wpdb;
        $siteStatistics = array();
        $prefix         = $wpdb->prefix;
        $basePrefix     = $wpdb->base_prefix;

        if (!empty($options['users'])) {
            if (!$this->cmsc_multisite) {
                $siteStatistics['users'] = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$basePrefix}users");
            } else {
                $siteStatistics['users'] = count(get_users(
                    array(
                        'blog_id' => $wpdb->blogid,
                    )
                ));
            }
        }

        if (!empty($options['approvedComments'])) {
            $siteStatistics['approvedComments'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}comments c INNER JOIN {$prefix}posts p ON c.comment_post_ID = p.ID WHERE comment_approved = '1' AND p.post_status = 'publish'");
        }

        if (!empty($options['activePlugins'])) {
            $siteStatistics['activePlugins'] = count((array) get_option('active_plugins', array()));
        }

        if (!empty($options['publishedPosts'])) {
            $siteStatistics['publishedPosts'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}posts WHERE post_type='post' AND post_status='publish'");
        }

        if (!empty($options['draftPosts'])) {
            $siteStatistics['draftPosts'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}posts WHERE post_type='post' AND post_status='draft'");
        }

        if (!empty($options['publishedPages'])) {
            $siteStatistics['publishedPages'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}posts WHERE post_type='page' AND post_status='publish'");
        }

        if (!empty($options['draftPages'])) {
            $siteStatistics['draftPages'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}posts WHERE post_type='page' AND post_status='draft'");
        }

        $stats['site_statistics'] = $siteStatistics;

        return $stats;
    }    
    
    function get_core_update($stats, $options = array())
    {
        global $wp_version;
        $current_transient = null;
        if (isset($options['core']) && $options['core']) {
            $locale = get_locale();
            $core   = $this->cmsc_get_transient('update_core');
            if (isset($core->updates) && !empty($core->updates)) {
                foreach ($core->updates as $update) {
                    if ($update->locale == $locale && strtolower($update->response) == "upgrade") {
                        $current_transient = $update;
                        break;
                    }
                }
                //fallback to first
                if (!$current_transient) {
                    $current_transient = $core->updates[0];
                }
				
                if ($current_transient->response == "development" || version_compare($wp_version, $current_transient->current, '<') || $locale !== $current_transient->locale) {
                    $current_transient->current_version = $wp_version;
                    $stats['core_updates']              = $current_transient;
                } else {
                    $stats['core_updates'] = false;
                }
            } else {
                $stats['core_updates'] = false;
            }
        }

        return $stats;
    }
    
    function get_hit_counter($stats, $options = array())
    {
        $stats['hit_counter'] = get_option('user_hit_count');

        return $stats;
    }
    
    function get_comments($stats, $options = array())
    {
        $nposts  = isset($options['numberposts']) ? (int) $options['numberposts'] : 20;
        $trimlen = isset($options['trimcontent']) ? (int) $options['trimcontent'] : 200;
        
        if ($nposts) {
            $comments = get_comments('status=hold&number=' . $nposts);
            if (!empty($comments)) {
                foreach ($comments as &$comment) {
                    $commented_post           = get_post($comment->comment_post_ID);
                    $comment->post_title      = $commented_post->post_title;
                    $comment->comment_content = $this->trim_content($comment->comment_content, $trimlen);
                    unset($comment->comment_author_url);
                    unset($comment->comment_author_email);
                    unset($comment->comment_author_IP);
                    unset($comment->comment_date_gmt);
                    unset($comment->comment_karma);
                    unset($comment->comment_agent);
                    unset($comment->comment_type);
                    unset($comment->comment_parent);
                    unset($comment->user_id);
                }
                $stats['comments']['pending'] = $comments;
            }
            
            $comments = get_comments('status=approve&number=' . $nposts);
            if (!empty($comments)) {
                foreach ($comments as &$comment) {
                    $commented_post           = get_post($comment->comment_post_ID);
                    $comment->post_title      = $commented_post->post_title;
                    $comment->comment_content = $this->trim_content($comment->comment_content, $trimlen);
                    unset($comment->comment_author_url);
                    unset($comment->comment_author_email);
                    unset($comment->comment_author_IP);
                    unset($comment->comment_date_gmt);
                    unset($comment->comment_karma);
                    unset($comment->comment_agent);
                    unset($comment->comment_type);
                    unset($comment->comment_parent);
                    unset($comment->user_id);
                }
                $stats['comments']['approved'] = $comments;
            }
        }
        return $stats;
    }
    
    function get_posts($stats, $options = array())
    {
        $nposts = isset($options['numberposts']) ? (int) $options['numberposts'] : 20;
        
        if ($nposts) {
            $posts        = get_posts('post_status=publish&numberposts=' . $nposts . '&orderby=post_date&order=desc');
            $recent_posts = array();
            if (!empty($posts)) {
                foreach ($posts as $id => $recent_post) {
                    $recent                 = new stdClass();
                    $recent->post_permalink = get_permalink($recent_post->ID);
                    $recent->ID             = $recent_post->ID;
                    $recent->post_date      = $recent_post->post_date;
                    $recent->post_title     = $recent_post->post_title;
					$recent->post_type      = $recent_post->post_type;
				    $recent->comment_count  = (int) $recent_post->comment_count;
                    $recent_posts[]         = $recent;
                }
            }
            
            $posts                  = get_pages('post_status=publish&numberposts=' . $nposts . '&orderby=post_date&order=desc');
            $recent_pages_published = array();
            if (!empty($posts)) {
                foreach ((array) $posts as $id => $recent_page_published) {
                    $recent                 = new stdClass();
                    $recent->post_permalink = get_permalink($recent_page_published->ID);
                    $recent->post_type      = $recent_page_published->post_type;
                    $recent->ID         = $recent_page_published->ID;
                    $recent->post_date  = $recent_page_published->post_date;
                    $recent->post_title = $recent_page_published->post_title;
                    
                    $recent_posts[] = $recent;
                }
            }
            if (!empty($recent_posts)) {
                usort($recent_posts, array(
                    $this,
                    'cmp_posts_worker'
                ));
                $stats['posts'] = array_slice($recent_posts, 0, $nposts);
            }
        }
        return $stats;
    }
    
    function get_drafts($stats, $options = array())
    {
        $nposts = isset($options['numberposts']) ? (int) $options['numberposts'] : 20;
        
        if ($nposts) {
            $drafts        = get_posts('post_status=draft&numberposts=' . $nposts . '&orderby=post_date&order=desc');
            $recent_drafts = array();
            if (!empty($drafts)) {
                foreach ($drafts as $id => $recent_draft) {
                    $recent                 = new stdClass();
                    $recent->post_permalink = get_permalink($recent_draft->ID);
                    $recent->post_type      = $recent_draft->post_type;
					$recent->ID             = $recent_draft->ID;
                    $recent->post_date      = $recent_draft->post_date;
                    $recent->post_title     = $recent_draft->post_title;
                    
                    $recent_drafts[] = $recent;
                }
            }
            $drafts              = get_pages('post_status=draft&numberposts=' . $nposts . '&orderby=post_date&order=desc');
            $recent_pages_drafts = array();
            if (!empty($drafts)) {
                foreach ((array) $drafts as $id => $recent_pages_draft) {
                    $recent                 = new stdClass();
                    $recent->post_permalink = get_permalink($recent_pages_draft->ID);
                    $recent->post_type      = $recent_pages_draft->post_type;
					$recent->ID             = $recent_pages_draft->ID;
                    $recent->post_date      = $recent_pages_draft->post_date;
                    $recent->post_title     = $recent_pages_draft->post_title;
                    
                    $recent_drafts[] = $recent;
                }
            }
            if (!empty($recent_drafts)) {
                usort($recent_drafts, array(
                    $this,
                    'cmp_posts_worker'
                ));
                $stats['drafts'] = array_slice($recent_drafts, 0, $nposts);
            }
        }
        return $stats;
    }
    
    function get_scheduled($stats, $options = array())
    {
        $numberOfItems  = isset($options['numberposts']) ? (int) $options['numberposts'] : 20;
        $scheduledItems = array();

        if (!$numberOfItems) {
            return $stats;
        }
        $scheduledPosts = get_posts('post_status=future&numberposts='.$numberOfItems.'&orderby=post_date&order=desc');
        foreach ($scheduledPosts as $id => $scheduledPost) {
            $recentPost                 = new stdClass();
            $recentPost->post_permalink = get_permalink($scheduledPost->ID);
            $recentPost->ID             = $scheduledPost->ID;
            $recentPost->post_date      = $scheduledPost->post_date;
            $recentPost->post_type      = $scheduledPost->post_type;
            $recentPost->post_title     = $scheduledPost->post_title;

            $scheduledItems[] = $recentPost;
        }
        $scheduledPages = get_pages('post_status=future&numberposts='.$numberOfItems.'&orderby=post_date&order=desc');
        foreach ((array) $scheduledPages as $id => $scheduledPage) {
            $recentPage                 = new stdClass();
            $recentPage->post_permalink = get_permalink($scheduledPage->ID);
            $recentPage->ID             = $scheduledPage->ID;
            $recentPage->post_type      = $scheduledPage->post_type;
            $recentPage->post_date      = $scheduledPage->post_date;
            $recentPage->post_title     = $scheduledPage->post_title;

            $scheduledItems[] = $recentPage;
        }
        if (!empty($scheduledItems)) {
            usort($scheduledItems, array($this, 'cmp_posts_worker'));
            $stats['scheduled'] = array_slice($scheduledItems, 0, $numberOfItems);
        }

        return $stats;
    }
	
    function get_backup_stats()
    {
        $tasks = get_option('cmsc_backup_tasks');

        if (empty($tasks) || !is_array($tasks)) {
            return array();
        }

        $stats = array();

        foreach ($tasks as $task_name => $info) {
            if (empty($info['task_results']) || !is_array($info['task_results'])) {
                continue;
            }

            foreach ($info['task_results'] as $key => $result) {
                if (!isset($result['server']) || isset($result['error'])) {
                    continue;
                }

                if (!isset($result['server']['file_path']) || $info['task_args']['del_host_file']) {
                    continue;
                }

                if (file_exists($result['server']['file_path'])) {
                    continue;
                }

                $info['task_results'][$key]['error'] = 'Backup created but manually removed from server.';
            }

            $stats[$task_name] = $info['task_results'];
        }

        return $stats;
    }	
    
    function get_backups($stats, $options = array())
    {
        $stats['cmsc_backups']      = $this->get_backup_stats();

        return $stats;
    }
    
    function get_backup_req($stats = array(), $options = array())
    {
        $stats['cmsc_backups']      = $this->get_backup_stats();
        $stats['cmsc_backup_req']   = $this->get_backup_instance()->check_backup_compat();
        
        return $stats;
    }
    
    function get_updates($stats, $options = array())
    {
        $premium = array();
        if (isset($options['premium']) && $options['premium']) {
            $premium_updates = array();
            $upgrades        = apply_filters('mwp_premium_update_notification', $premium_updates);
            if (!empty($upgrades)) {
                foreach ($upgrades as $data) {
                    if (isset($data['Name'])) {
                        $premium[] = $data['Name'];
                    }
                }
                $stats['premium_updates'] = $upgrades;
            }
        }
        if (isset($options['themes']) && $options['themes']) {
            $this->get_installer_instance();
            $upgrades = $this->installer_instance->get_upgradable_themes( $premium );
            if (!empty($upgrades)) {
                $stats['upgradable_themes'] = $upgrades;
            }
        }
        
        if (isset($options['plugins']) && $options['plugins']) {
            $this->get_installer_instance();
            $upgrades = $this->installer_instance->get_upgradable_plugins( $premium );
            if (!empty($upgrades)) {
                $stats['upgradable_plugins'] = $upgrades;
            }
        }
        
        return $stats;
    }
    
	function get_errors($stats, $options = array())
    {
        $period     = isset($options['days']) ? (int) $options['days'] * 86400 : 86400;
        $maxerrors  = isset($options['max']) ? (int) $options['max'] : 100;
        $last_bytes = isset($options['last_bytes']) ? (int) $options['last_bytes'] : 20480; //20KB
        $errors     = array();
        if (isset($options['get']) && $options['get'] == true) {
            if (function_exists('ini_get')) {
                $logpath = ini_get('error_log');
                if (!empty($logpath) && file_exists($logpath)) {
                    $logfile    = @fopen($logpath, 'r');
                    $filesize   = @filesize($logpath);
                    $read_start = 0;
                    if (is_resource($logfile) && $filesize > 0) {
                        if ($filesize > $last_bytes) {
                            $read_start = $filesize - $last_bytes;
                        }
                        fseek($logfile, $read_start, SEEK_SET);
                        while (!feof($logfile)) {
                            $line = fgets($logfile);
                            preg_match('/\[(.*)\]/Ui', $line, $match);
                            if (!empty($match) && (strtotime($match[1]) > ((int) time() - $period))) {
                                $key = str_replace($match[0], '', $line);
                                if (!isset($errors[$key])) {
                                    $errors[$key] = 1;
                                } else {
                                    $errors[$key] = $errors[$key] + 1;
                                }
                                if (count($errors) >= $maxerrors) {
                                    break;
                                }
                            }
                        }
                    }
                    if (is_resource($logfile)) {
                        fclose($logfile);
                    }
                    if (!empty($errors)) {
                        $stats['errors']  = $errors;
                        $stats['logpath'] = $logpath;
                        $stats['logsize'] = $filesize;
                    }
                }
            }
        }

        return $stats;
    }
    
    public function getUserList()
    {
        $filter = array(
            'user_roles'      => array(
                'administrator',
            ),
            'username'        => '',
            'username_filter' => '',
        );
        $users  = $this->get_user_instance()->get_users($filter);

        if (empty($users['users']) || !is_array($users['users'])) {
            return array();
        }

        $userList = array();
        foreach ($users['users'] as $user) {
            $userList[] = $user['user_login'];
        }

        return $userList;
    }  

    function remove_filter_by_plugin_class($tag, $class_name)
    {
        if (!class_exists($class_name)) {
            return null;
        }

        global $wp_filter;

        if (empty($wp_filter[$tag][10])) {
            return null;
        }

        foreach ($wp_filter[$tag][10] as $callable) {
            if (empty($callable['function']) || !is_array($callable['function'])) {
                continue;
            }

            if (!is_a($callable['function'][0], $class_name)) {
                continue;
            }

            remove_filter($tag, $callable['function']);

            return $callable['function'];
        }

        return null;
    }
    
    function pre_init_stats($params)
    {       
        include_once(ABSPATH . 'wp-includes/update.php');
        include_once(ABSPATH . '/wp-admin/includes/update.php');
    
        $stats = $this->cmsc_parse_action_params('pre_init_stats', $params, $this);
        extract($params);
		
        if (isset($params['doAdminInit'])) {
            do_action('admin_init');
        }		
		
        if ($params['refresh'] == 'transient') {
            global $wp_current_filter;
            $wp_current_filter[] = 'load-update-core.php';

            if (function_exists('wp_clean_update_cache')) {
                wp_clean_update_cache();
            }

            wp_version_check();
            wp_update_themes();

            // THIS IS INTENTIONAL, please do not delete one of the calls to wp_update_plugins(), it is required for
            // some custom plugins (read premium) to work with ManageWP :)
            // the second call is not going to trigger the remote post invoked from the wp_update_plugins call
            wp_update_plugins();

            array_pop($wp_current_filter);

            do_action('load-plugins.php');
        }

        // it is now safe to activate WPMU Dev filters again
        if (!empty($callablePluginFn)) {
            add_filter('site_transient_update_plugins', $callablePluginFn);
        }

        if (!empty($callableThemeFn)) {
            add_filter('site_transient_update_themes', $callableThemeFn);
        }		
        
		/** @var $wpdb wpdb */
        global $wpdb, $cmsc_wp_version, $cmsc_plugin_dir, $wp_version, $wp_local_package;
        
        $stats['worker_version']        = CMSC_WORKER_VERSION;
        $stats['wordpress_version']     = $wp_version;
        $stats['wordpress_locale_pckg'] = $wp_local_package;
        $stats['php_version']           = phpversion();
        $stats['mysql_version']         = $wpdb->db_version();
        $stats['wp_multisite']          = $this->cmsc_multisite;
        $stats['network_install']       = $this->network_admin_install;
        $stats['site_title']            = get_bloginfo('name');
        $stats['site_tagline']          = get_bloginfo('description');
        $stats['blog_public']           = get_option('blog_public');
        
        if ( !function_exists('get_filesystem_method') )
            include_once(ABSPATH . 'wp-admin/includes/file.php');
        $mmode = get_option('cmsc_maintenace_mode');
		
		if( !empty($mmode) && isset($mmode['active']) && $mmode['active'] == true){
			$stats['maintenance'] = true;
		}
        $stats['writable'] = $this->is_server_writable();
        
        return $stats;
    }
    
    function get($params)
    {
        global $wpdb, $cmsc_wp_version, $cmsc_plugin_dir, $_cmsc_item_filter;
       
        include_once(ABSPATH . 'wp-includes/update.php');
        include_once(ABSPATH . '/wp-admin/includes/update.php');
        
        $stats = $this->cmsc_parse_action_params('get', $params, $this);
		$update_check = array();
        $num          = extract($params);
        if ($refresh == 'transient') {
            $update_check = apply_filters('mwp_premium_update_check', $update_check);
            if (!empty($update_check)) {
                foreach ($update_check as $update) {
                    if (is_array($update['callback'])) {
                        $update_result = call_user_func(
                            array(
                                $update['callback'][0],
                                $update['callback'][1],
                            )
                        );
                    } else {
                        if (is_string($update['callback'])) {
                            $update_result = call_user_func($update['callback']);
                        }
                    }
                }
            }
        }
        
        if ($this->cmsc_multisite) {
            $stats = $this->get_multisite($stats);
        }
       
       	update_option('cmsc_stats_filter', $params['item_filter']['get_stats']);
		$stats = apply_filters('cmsc_stats_filter', $stats);
        return $stats;
    }
    
    function get_multisite($stats = array())
    {
        global $current_user, $wpdb;
        $user_blogs = get_blogs_of_user( $current_user->ID );
		$network_blogs = $wpdb->get_results( "select `blog_id`, `site_id` from `{$wpdb->blogs}`" );
		if ($this->network_admin_install == '1' && is_super_admin()) {
			if (!empty($network_blogs)) {
                $blogs = array();
                foreach ( $network_blogs as $details) {
                    if($details->site_id == $details->blog_id)
						continue;
					else {
						$data = get_blog_details($details->blog_id);
						if(in_array($details->blog_id, array_keys($user_blogs)))
							$stats['network_blogs'][] = $data->siteurl;
						else {
                            $user = get_users(array(
                                'blog_id' => $details->blog_id,
                                'number' => 1
                            ));
                            if (!empty($user))
                                $stats['other_blogs'][$data->siteurl] = $user[0]->user_login;
						}
					}
                }
            }
        }
        return $stats;
    }
    
    function get_comments_stats()
    {
        $num_pending_comments  = 3;
        $num_approved_comments = 3;
        $pending_comments      = get_comments('status=hold&number=' . $num_pending_comments);
        foreach ($pending_comments as &$comment) {
            $commented_post      = get_post($comment->comment_post_ID);
            $comment->post_title = $commented_post->post_title;
        }
        $stats['comments']['pending'] = $pending_comments;      
        
        $approved_comments = get_comments('status=approve&number=' . $num_approved_comments);
        foreach ($approved_comments as &$comment) {
            $commented_post      = get_post($comment->comment_post_ID);
            $comment->post_title = $commented_post->post_title;
        }
        $stats['comments']['approved'] = $approved_comments;
        
        return $stats;
    }
    
    function get_initial_stats()
    {
        global $cmsc_plugin_dir;
        
        $stats = array();
        
        $stats['email']           = get_option('admin_email');
        $stats['no_openssl']      = $this->get_random_signature();
        $stats['content_path']    = WP_CONTENT_DIR;
        $stats['worker_path']     = $cmsc_plugin_dir;
        $stats['worker_version']  = CMSC_WORKER_VERSION;
        $stats['site_title']      = get_bloginfo('name');
        $stats['site_tagline']    = get_bloginfo('description');
		$stats['db_name']    	  = $this->get_active_db();
        $stats['site_home']       = get_option('home');
        $stats['admin_url']       = admin_url();
        $stats['wp_multisite']    = $this->cmsc_multisite;
        $stats['network_install'] = $this->network_admin_install;
 		$stats['cms']			  = "wordpress";

        if ($this->cmsc_multisite) {
            $details = get_blog_details($this->cmsc_multisite);
            if (isset($details->site_id)) {
                $details = get_blog_details($details->site_id);
                if (isset($details->siteurl))
                    $stats['network_parent'] = $details->siteurl;
            }
        }
        if (!function_exists('get_filesystem_method'))
            include_once(ABSPATH . 'wp-admin/includes/file.php');
        
        $stats['writable'] = $this->is_server_writable();
        
        return $stats;
    }
	
	function get_active_db(){
		global $wpdb;
	    $sql='SELECT DATABASE() as db_name';

	    $sqlresult = $wpdb->get_row($sql);
		$active_db=$sqlresult->db_name;
		
	   	return $active_db;

	}
    
    function get_hit_count()
    {
        return get_option('user_hit_count');
    }
    
    function set_notifications($params)
    {
        if (empty($params))
            return false;
        
        extract($params);
        
        if (!isset($delete)) {
            $cmsc_notifications = array(
                'plugins' => $plugins,
                'themes' => $themes,
                'wp' => $wp,
                'backups' => $backups,
                'url' => $url,
                'notification_key' => $notification_key
            );
            update_option('cmsc_notifications', $cmsc_notifications);
        } else {
            delete_option('cmsc_notifications');
        }
        
        return true;
        
    }
    
    //Cron update check for notifications
    function check_notifications()
    {
        global $wp_version;
        
        $cmsc_notifications = get_option('cmsc_notifications', true);
        
        $args         = array();
        $updates           = array();
        $send = 0;
        if (is_array($cmsc_notifications) && $cmsc_notifications != false) {
            include_once(ABSPATH . 'wp-includes/update.php');
            include_once(ABSPATH . '/wp-admin/includes/update.php');
            extract($cmsc_notifications);
            
            //Check wordpress core updates
            if ($wp) {
                @wp_version_check();
                if (function_exists('get_core_updates')) {
                    $wp_updates = get_core_updates();
                    if (!empty($wp_updates)) {
                        $current_transient = $wp_updates[0];
                        if ($current_transient->response == "development" || version_compare($wp_version, $current_transient->current, '<')) {
                            $current_transient->current_version = $wp_version;
                            $updates['core_updates']            = $current_transient;
                        } else
                            $updates['core_updates'] = array();
                    } else
                        $updates['core_updates'] = array();
                }
            }
            
            //Check plugin updates
            if ($plugins) {
                @wp_update_plugins();
                $this->get_installer_instance();
                $updates['upgradable_plugins'] = $this->installer_instance->get_upgradable_plugins();
            }
            
            //Check theme updates
            if ($themes) {
                @wp_update_themes();
                $this->get_installer_instance();
                
                $updates['upgradable_themes'] = $this->installer_instance->get_upgradable_themes();
            }
            
            if ($backups) {
                $this->get_backup_instance();
                $backups            = $this->backup_instance->get_backup_stats();
                $updates['backups'] = $backups;
                foreach ($backups as $task_name => $backup_results) {
                    foreach ($backup_results as $k => $backup) {
                        if (isset($backups[$task_name][$k]['server']['file_path'])) {
                            unset($backups[$task_name][$k]['server']['file_path']);
                        }
                    }
                }
                $updates['backups'] = $backups;
            }
            
            
            if (!empty($updates)) {
                $args['body']['updates'] = $updates;
                $args['body']['notification_key'] = $notification_key;
                $send = 1;
            }
            
        }
        
        
        $alert_data = get_option('cmsc_pageview_alerts',true);
        if(is_array($alert_data) && $alert_data['alert']){
        	$pageviews = get_option('user_hit_count');
        	$args['body']['alerts']['pageviews'] = $pageviews;
        	$args['body']['alerts']['site_id'] = $alert_data['site_id'];
        	if(!isset($url)){
        		$url = $alert_data['url'];
        	}
        	$send = 1;
        }
        
        if($send){
        	if (!class_exists('WP_Http')) {
                include_once(ABSPATH . WPINC . '/class-http.php');
            }
        	$result       = wp_remote_post($url, $args);
        	
        	if (is_array($result) && $result['body'] == 'cmsc_delete_alert') {
        		delete_option('cmsc_pageview_alerts');
        	}
        }  
        
        
    }
    
    function cmp_posts_worker($a, $b)
    {
        return ($a->post_date < $b->post_date);
    }
    
    function trim_content($content = '', $length = 200)
    {
        if (function_exists('mb_strlen') && function_exists('mb_substr'))
            $content = (mb_strlen($content) > ($length + 3)) ? mb_substr($content, 0, $length) . '...' : $content;
        else
            $content = (strlen($content) > ($length + 3)) ? substr($content, 0, $length) . '...' : $content;
        
        return $content;
    }
}

?>