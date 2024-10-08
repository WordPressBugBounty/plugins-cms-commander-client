<?php
/*************************************************************
 * 
 * post.class.php
 * 
 * Create remote post
 * 
 * 
 * Copyright (c) 2011 Prelovac Media
 * www.prelovac.com
 **************************************************************/
if(basename($_SERVER['SCRIPT_FILENAME']) == "post.class.php"):
    exit;
endif;
class CMSC_Post extends CMSC_Core
{
    function __construct()
    {
        parent::__construct();
    }
    
    function create($args)
    {
    	global $wpdb;
    	
        /**
         * algorithm
         * 1. create post using wp_insert_post (insert tags also here itself)
         * 2. use wp_create_categories() to create(not exists) and insert in the post
         * 3. insert meta values
         */
        
        include_once ABSPATH . 'wp-admin/includes/taxonomy.php';
        include_once ABSPATH . 'wp-admin/includes/image.php';
        include_once ABSPATH . 'wp-admin/includes/file.php';
        
        $post_struct = $args['post_data'];
        
        $post_data         = $post_struct['post_data'];
        $new_custom        = $post_struct['post_extras']['post_meta'];
        $post_categories   = explode(',', $post_struct['post_extras']['post_categories']);
        $post_atta_img     = $post_struct['post_extras']['post_atta_images'];
        $post_upload_dir   = $post_struct['post_extras']['post_upload_dir'];
        $post_checksum     = $post_struct['post_extras']['post_checksum'];
        $post_featured_img = $post_struct['post_extras']['featured_img'];
        
        $upload = wp_upload_dir();
        
		// ADDED to edit post if ID specified.
		if(!empty($args['post_id'])) {$post_data["ID"] = (int) $args['post_id'];}
	
		// ADDED to get author ID from username
		if(!is_numeric($post_data["post_author"])) {
			$authoruser = get_userdatabylogin( $post_data["post_author"] );
			if($authoruser != false) {
				$post_data["post_author"] = (int) $authoruser->ID;
			} else {
				// Create User
				$uclass = new CMSC_User(); 
				$uresp = $uclass->add_user(array("user" => array("username" => $post_data["post_author"], "email" => $post_data["post_author"].rand(1,999)."@somedomain.com", "password" => "cmscommander".rand(1,999), "firstname" => "", "role" => "editor")));
                if(is_wp_error($uresp)){
					return array('error' => "Author name does not exist and automatic user creation failed: " . $uresp->get_error_message());
                } elseif(!empty($uresp["error"])) {
					return array('error' => "Author name does not exist and automatic user creation failed.");
				} else {
					$post_data["post_author"] = $uresp;
				}
			}
		}		
		
        // create dynamic url RegExp
        $cmsc_base_url   = parse_url($post_upload_dir['url']);
        $cmsc_regexp_url = $cmsc_base_url['host'] . $cmsc_base_url['path'];
        $rep            = array(
            '/',
            '+',
            '.',
            ':',
            '?'
        );
        $with           = array(
            '\/',
            '\+',
            '\.',
            '\:',
            '\?'
        );
        $cmsc_regexp_url = str_replace($rep, $with, $cmsc_regexp_url);
        
        // rename all src ../wp-content/ with hostname/wp-content/
        $cmsc_dot_url     = '..' . $cmsc_base_url['path'];
        $cmsc_dot_url     = str_replace($rep, $with, $cmsc_dot_url);
        $dot_match_count = preg_match_all('/(<a[^>]+href=\"([^"]+)\"[^>]*>)?(<\s*img.[^\/>]*src="([^"]*' . $cmsc_dot_url . '[^\s]+\.(jpg|jpeg|png|gif|bmp))"[^>]*>)/ixu', $post_data['post_content'], $dot_get_urls, PREG_SET_ORDER);
        if ($dot_match_count > 0) {
            foreach ($dot_get_urls as $dot_url) {
                $match_dot                 = '/' . str_replace($rep, $with, $dot_url[4]) . '/';
                $replace_dot               = 'http://' . $cmsc_base_url['host'] . substr($dot_url[4], 2, strlen($dot_url[4]));
                $post_data['post_content'] = preg_replace($match_dot, $replace_dot, $post_data['post_content']);
                
                if ($dot_url[1] != '') {
                    $match_dot_a               = '/' . str_replace($rep, $with, $dot_url[2]) . '/';
                    $replace_dot_a             = 'http://' . $cmsc_base_url['host'] . substr($dot_url[2], 2, strlen($dot_url[2]));
                    $post_data['post_content'] = preg_replace($match_dot_a, $replace_dot_a, $post_data['post_content']);
                }
            }
        }
  
        //to find all the images
        $match_count = preg_match_all('/(<a[^>]+href=\"([^"]+)\"[^>]*>)?(<\s*img.[^\/>]*src="([^"]+' . $cmsc_regexp_url . '[^\s]+\.(jpg|jpeg|png|gif|bmp))"[^>]*>)/ixu', $post_data['post_content'], $get_urls, PREG_SET_ORDER);
        if ($match_count > 0) {
            $attachments  = array();
            $post_content = $post_data['post_content'];
            
            foreach ($get_urls as $get_url_k => $get_url) {
                // unset url in attachment array
                foreach ($post_atta_img as $atta_url_k => $atta_url_v) {
                    $match_patt_url = '/' . str_replace($rep, $with, substr($atta_url_v['src'], 0, strrpos($atta_url_v['src'], '.'))) . '/';
                    if (preg_match($match_patt_url, $get_url[4])) {
                        unset($post_atta_img[$atta_url_k]);
                    }
                }              
                $pic_from_other_site = $get_urls[$get_url_k][4];
                if(strpos($pic_from_other_site,'cmscommander.com') === false && strpos($pic_from_other_site,'flickr') === false && strpos($pic_from_other_site,'pixabay.com') === false){
                   continue;
                }	
				
                if (isset($get_urls[$get_url_k][6])) { // url have parent, don't download this url
                    if ($get_url[1] != '') {
                        // change src url
                        $s_cmsc_mp = '/' . str_replace($rep, $with, $get_url[4]) . '/';
                        
                        $s_img_atta   = wp_get_attachment_image_src($get_urls[$get_url_k][6]);
                        $s_cmsc_rp     = $s_img_atta[0];
                        $post_content = preg_replace($s_cmsc_mp, $s_cmsc_rp, $post_content);
                        // change attachment url
                        if (preg_match('/attachment_id/i', $get_url[2])) {
                            $cmsc_mp       = '/' . str_replace($rep, $with, $get_url[2]) . '/';
                            $cmsc_rp       = get_bloginfo('wpurl') . '/?attachment_id=' . $get_urls[$get_url_k][6];
                            $post_content = preg_replace($cmsc_mp, $cmsc_rp, $post_content);
                        }
                    }
                    continue;
                }
                
                $no_thumb = '';
                /*if (preg_match('/-\d{3}x\d{3}\.[a-zA-Z0-9]{3,4}$/', $get_url[4])) {
                    $no_thumb = preg_replace('/-\d{3}x\d{3}\.[a-zA-Z0-9]{3,4}$/', '.'.$get_url[5], $get_url[4]);
                } else {
                    $no_thumb = $get_url[4];
                }*/
                $no_thumb = $get_url[4];
                
                if(isset($upload['error']) && !empty($upload['error'])){
                	return array('error' => $upload['error']);
                }
                $file_name = basename($no_thumb);
                $tmp_file  = download_url($no_thumb);
                
                if(is_wp_error($tmp_file)){
                	return array('error' => $tmp_file->get_error_message());
                }
                
                $attach_upload['url']  = $upload['url'] . '/' . $file_name;
                $attach_upload['path'] = $upload['path'] . '/' . $file_name;
                $renamed               = @rename($tmp_file, $attach_upload['path']);
                if ($renamed === true) {
                    $match_pattern   = '/' . str_replace($rep, $with, $get_url[4]) . '/';
                    $replace_pattern = $attach_upload['url'];
                    $post_content    = preg_replace($match_pattern, $replace_pattern, $post_content);
                    if (preg_match('/-\d{3}x\d{3}\.[a-zA-Z0-9]{3,4}$/', $get_url[4])) {
                        $match_pattern = '/' . str_replace($rep, $with, preg_replace('/-\d{3}x\d{3}\.[a-zA-Z0-9]{3,4}$/', '.' . $get_url[5], $get_url[4])) . '/';
                        $post_content  = preg_replace($match_pattern, $replace_pattern, $post_content);
                    }
                    
                    $attachment = array(
                        'post_title' => $file_name,
                        'post_content' => '',
                        'post_type' => 'attachment',
                        //'post_parent' => $post_id,
                        'post_mime_type' => 'image/' . $get_url[5],
                        'guid' => $attach_upload['url']
                    );
                    
                    // Save the data
                    
                    $attach_id = wp_insert_attachment($attachment, $attach_upload['path']);
                    
                    $attachments[$attach_id] = 0;
                    
                    // featured image
                    if ($post_featured_img != '') {
                        $feat_img_url = '';
                        if (preg_match('/-\d{3}x\d{3}\.[a-zA-Z0-9]{3,4}$/', $post_featured_img)) {
                            $feat_img_url = substr($post_featured_img, 0, strrpos($post_featured_img, '.') - 8);
                        } else {
                            $feat_img_url = substr($post_featured_img, 0, strrpos($post_featured_img, '.'));
                        }
                        $m_feat_url = '/' . str_replace($rep, $with, $feat_img_url) . '/';
                        if (preg_match($m_feat_url, $get_url[4])) {
                            $post_featured_img       = '';
                            $attachments[$attach_id] = $attach_id;
                        }
                    }
                    
                    // set $get_urls value[6] - parent atta_id
                    foreach ($get_urls as $url_k => $url_v) {
                        if ($get_url_k != $url_k) {
                            $s_get_url = '';
                            if (preg_match('/-\d{3}x\d{3}\.[a-zA-Z0-9]{3,4}$/', $url_v[4])) {
                                $s_get_url = substr($url_v[4], 0, strrpos($url_v[4], '.') - 8);
                            } else {
                                $s_get_url = substr($url_v[4], 0, strrpos($url_v[4], '.'));
                            }
                            $m_patt_url = '/' . str_replace($rep, $with, $s_get_url) . '/';
                            if (preg_match($m_patt_url, $get_url[4])) {
                                array_push($get_urls[$url_k], $attach_id);
                            }
                        }
                    }                  
                    
                    $some_data = wp_generate_attachment_metadata($attach_id, $attach_upload['path']);
                    wp_update_attachment_metadata($attach_id, $some_data);      
                    
                    // changing href of a tag
                    if ($get_url[1] != '') {
                        $cmsc_mp = '/' . str_replace($rep, $with, $get_url[2]) . '/';
                        if (preg_match('/attachment_id/i', $get_url[2])) {
                            $cmsc_rp       = get_bloginfo('wpurl') . '/?attachment_id=' . $attach_id;
                            $post_content = preg_replace($cmsc_mp, $cmsc_rp, $post_content);
                        }
                    }
                } else {
                	@unlink($tmp_file);
                	return array('error' => "Cannot create attachment file in ".$attach_upload['path']." Please set correct permissions.");
                	
                }
                @unlink($tmp_file);
            }
                       
            $post_data['post_content'] = $post_content;            
        }
		if (is_array($post_atta_img) && !empty($post_atta_img)) {	
            foreach ($post_atta_img as $img) {
                $file_name             = basename($img['src']);
                 
                if(isset($upload['error']) && !empty($upload['error'])){
                	return array('error' => $upload['error']);
                }
                
                $tmp_file              = download_url($img['src']);
                if(is_wp_error($tmp_file)){
                	return array('error' => $tmp_file->get_error_message());
                }
                
                $attach_upload['url']  = $upload['url'] . '/' . $file_name;
                $attach_upload['path'] = $upload['path'] . '/' . $file_name;
                $renamed               = @rename($tmp_file, $attach_upload['path']);
                if ($renamed === true) {
                    $atta_ext = end(explode('.', $file_name));
                    
                    $attachment = array(
                        'post_title' => $file_name,
                        'post_content' => '',
                        'post_type' => 'attachment',
                        //'post_parent' => $post_id,
                        'post_mime_type' => 'image/' . $atta_ext,
                        'guid' => $attach_upload['url']
                    );
                    
                    // Save the data
                    $attach_id = wp_insert_attachment($attachment, $attach_upload['path']);
                    wp_update_attachment_metadata($attach_id, wp_generate_attachment_metadata($attach_id, $attach_upload['path']));
                    $attachments[$attach_id] = 0;
                    
                    // featured image
                    if ($post_featured_img != '') {
                        $feat_img_url = '';
                        if (preg_match('/-\d{3}x\d{3}\.[a-zA-Z0-9]{3,4}$/', $post_featured_img)) {
                            $feat_img_url = substr($post_featured_img, 0, strrpos($post_featured_img, '.') - 8);
                        } else {
                            $feat_img_url = substr($post_featured_img, 0, strrpos($post_featured_img, '.'));
                        }
                        $m_feat_url = '/' . str_replace($rep, $with, $feat_img_url) . '/';
                        if (preg_match($m_feat_url, $img['src'])) {
                            $post_featured_img       = '';
                            $attachments[$attach_id] = $attach_id;
                        }
                    }
                    
                } else {
                	@unlink($tmp_file);
                	return array('error' => "Cannot create attachment file in ".$attach_upload['path']." Please set correct permissions.");
                }
                @unlink($tmp_file);
            }
        }
        
        //Prepare post data and temporarily remove content filters before insert post
				$user = $this->cmsc_get_user_info( $args['username'] );
				if($user && $user->ID){
					$post_data['post_author'] = $user->ID;
				}
				//remove filter which can brake scripts or html
       	remove_filter('content_save_pre', 'wp_filter_post_kses'); 
        
        //check for edit post
        $post_result = 0;
        if(isset($post_data['cmsc_post_edit']) && $post_data['cmsc_post_edit']){
        	if($post_data['cmsc_match_by'] == 'title'){
        		$match_by = "post_title = '".$post_data['post_title']."'"; 
        	} else {
        		$match_by = "post_name = '".$post_data['post_name']."'";
        	}
        	
        	$query = "SELECT ID FROM $wpdb->posts WHERE $match_by AND post_status NOT IN('inherit','auto-draft','draft') LIMIT 1";
        	
        	$post_result = $wpdb->get_var($query);       	
        }

        if($post_result){
        	//update existing post
        	$post_data['ID'] = $post_result;
        	$post_id = wp_update_post($post_data);
			    
			    //check for previous attachments    	
			    $atta_allimages =& get_children('post_type=attachment&post_parent=' . $post_id);
	        if (!empty($atta_allimages)) {
	            foreach ($atta_allimages as $image) {
	                wp_delete_attachment($image->ID);
	            }
			}        	
        } else {
        	if($post_data['cmsc_post_edit'] && $post_data['cmsc_force_publish']){
        	 $post_id = wp_insert_post($post_data);
        	} elseif($post_data['cmsc_post_edit'] && !$post_data['cmsc_force_publish']) {
        		return array('error' => "Post not found.");
        	} else {
        		$post_id = wp_insert_post($post_data);
        	}       	
        }

		if (is_array($attachments) && !empty($attachments)) {
			foreach ($attachments as $atta_id => $featured_id) {
				$result = wp_update_post(array(
					'ID' => $atta_id,
					'post_parent' => $post_id
				));
				if ($featured_id > 0) {
					$new_custom['_thumbnail_id'] = array(
						$featured_id
					);
				}
			}
		}
		
        // featured image
        if ($post_featured_img != '') {
            $file_name             = basename($post_featured_img);
            if(isset($upload['error']) && !empty($upload['error'])){
                	return array('error' => $upload['error']);
                }
            $tmp_file              = download_url($post_featured_img);
            if(is_wp_error($tmp_file)){
                	return array('error' => $tmp_file->get_error_message());
                }
            $attach_upload['url']  = $upload['url'] . '/' . $file_name;
            $attach_upload['path'] = $upload['path'] . '/' . $file_name;
            $renamed               = @rename($tmp_file, $attach_upload['path']);
            if ($renamed === true) {
                $atta_ext = end(explode('.', $file_name));
                
                $attachment = array(
                    'post_title' => $file_name,
                    'post_content' => '',
                    'post_type' => 'attachment',
                    'post_parent' => $post_id,
                    'post_mime_type' => 'image/' . $atta_ext,
                    'guid' => $attach_upload['url']
                );
                
                // Save the data
                $attach_id = wp_insert_attachment($attachment, $attach_upload['path']);
                wp_update_attachment_metadata($attach_id, wp_generate_attachment_metadata($attach_id, $attach_upload['path']));
                $new_custom['_thumbnail_id'] = array(
                    $attach_id
                );
            } else {
            	@unlink($tmp_file);
                	return array('error' => "Cannot create attachment file in ".$attach_upload['path']." Please set correct permissions.");
            }
            @unlink($tmp_file);
        }
        
        if ($post_id && is_array($post_categories)) {
            //insert categories
            
            $cat_ids = wp_create_categories($post_categories, $post_id);
        }
        
        
        //get current custom fields
        $cur_custom  = get_post_custom($post_id);
        //check which values doesnot exists in new custom fields
        $diff_values = array_diff_key($cur_custom, $new_custom);
        
        if (is_array($diff_values))
            foreach ($diff_values as $meta_key => $value) {
                delete_post_meta($post_id, $meta_key);
            }
        //insert new post meta
        foreach ($new_custom as $meta_key => $value) {
            if (strpos($meta_key, '_cmsc') === 0 || strpos($meta_key, '_edit') === 0) {
                continue;
            } else {
                update_post_meta($post_id, $meta_key, $value[0]);
            }
        }
        return $post_id;
    }
    
    
    function change_status($args)
    {

    	global $wpdb;
    	$post_id = $args['post_id'];
    	$status = $args['status']; 
    	$success = false; 
    	
    	if(in_array($status, array('draft', 'publish', 'trash'))){
            $edited_status = array(
				'ID' => $post_id,
                'post_status' => $status
            );
            $success = wp_update_post($edited_status);
    	}

        return $success;
    }

    /*
     * Function which gets posts from worker depending on arguments.
     * If FROM and TO dates are provided and range, range has bigger priority to date FROM.
     * This means if there are less posts between FROM and TO than range provided,
     * this function omit date from and returns last range number posts to date TO.
     * 
     * @param array $args arguments passed to function
     * @arg string filter_posts search phrase for post titles
     * @arg string cmsc_get_posts_date_from date in format(Y-m-d H:i:s) when posts are publishes from
     * @arg string cmsc_get_posts_date_to date in format(Y-m-d H:i:s) when posts are publishes to
     * @arg string cmsc_get_posts_range range number of returned posts
     * @arg string cmsc_get_posts_publish on or off
     * @arg string cmsc_get_posts_pending on or off
     * @arg string cmsc_get_posts_private on or off
     * @arg string cmsc_get_posts_future on or off
     * @arg string cmsc_get_posts_draft on or off
     * @arg string cmsc_get_posts_trash on or off
     * @return array posts related to args
     */	
    function get_posts($args)
    {
        global $wpdb;

        $where = '';

        extract($args);

        if (!empty($filter_posts)) {
            $where .= " AND (post_title LIKE '%".esc_sql($filter_posts)."%' OR post_content LIKE '%".esc_sql($filter_posts)."%')";
        }

        if (!empty($cmsc_get_posts_date_from) && !empty($cmsc_get_posts_date_to)) {
            $where .= " AND post_date BETWEEN '".esc_sql($cmsc_get_posts_date_from)."' AND '".esc_sql($cmsc_get_posts_date_to)."'";
        } else {
            if (!empty($cmsc_get_posts_date_from) && empty($cmsc_get_posts_date_to)) {
                $where .= " AND post_date >= '".esc_sql($cmsc_get_posts_date_from)."'";
            } else {
                if (empty($cmsc_get_posts_date_from) && !empty($cmsc_get_posts_date_to)) {
                    $where .= " AND post_date <= '".esc_sql($cmsc_get_posts_date_to)."'";
                }
            }
        }
        $post_array    = array();
        $post_statuses = array('publish', 'pending', 'private', 'future', 'draft', 'trash');
        foreach ($args as $checkbox => $checkbox_val) {
            if ($checkbox_val == "on") {
                $post_array[] = "'".str_replace("cmsc_get_posts_", "", $checkbox)."'";
            }
        }
        if (!empty($post_array)) {
            $where .= " AND post_status IN (".implode(",", $post_array).")";
        }

        $limit = ($cmsc_get_posts_range) ? ' LIMIT '.esc_sql($cmsc_get_posts_range) : ' LIMIT 500';

        $sql_query = "$wpdb->posts  WHERE post_status!='auto-draft' AND post_status!='inherit' AND post_type='post' ".$where." ORDER BY post_date DESC";

        $total              = array();
        $posts              = array();
        $posts_info         = $wpdb->get_results("SELECT * FROM ".$sql_query.$limit);
        $user_info          = $this->getUsersIDs();
        $post_cats          = $this->getPostCats();
        $post_tags          = $this->getPostCats('post_tag');
		if (is_array($posts_info) && !empty($posts_info)) {$total['total_num'] = count($posts_info);}

        foreach ($posts_info as $post_info) {
            $cats = array();
            foreach ($post_cats[$post_info->ID] as $cat_array => $cat_array_val) {
                $cats[] = array('name' => $cat_array_val);
            }

            $tags = array();
            if (!empty($post_tags[$post_info->ID])) {
                foreach ($post_tags[$post_info->ID] as $tag_array => $tag_array_val) {
                    $tags[] = array('name' => $tag_array_val);
                }
            }

            $posts[] = array(
                'post_id'        => $post_info->ID,
                'post_title'     => htmlspecialchars($post_info->post_title),
                'post_name'      => $post_info->post_name,
                'post_author'    => array('author_id' => $post_info->post_author, 'author_name' => $user_info[$post_info->post_author]),
                'post_date'                           => $post_info->post_date,
                'post_modified'                       => $post_info->post_modified,
                'post_status'                         => $post_info->post_status,
                'post_type'                           => $post_info->post_type,
                'guid'                                => $post_info->guid,
                'post_password'                       => $post_info->post_password,
                'ping_status'                         => $post_info->ping_status,
                'comment_status'                      => $post_info->comment_status,
                'comment_count'                       => $post_info->comment_count,
                'cats'                                => $cats,
                'tags'                                => $tags,

            );
        }

        return array('posts' => $posts, 'total' => $total);
    }	
	
	function delete_post($args){
		if(!empty($args['post_id']) && !empty($args['action']))
		{
            if($args['action']=='delete' || $args['action']=='delete_restore'){
                $action = ($args['action'] == 'delete') ? 'delete' : 'publish';
                $edited_status = array('ID' => $args['post_id'],
                    'post_status' => $action
                );
                $success = wp_update_post($edited_status);
            }else if($args['action']=='delete_perm'){
                $success = wp_delete_post($args['post_id'], true);
            }

			return 'Post deleted.';
		}
		else
		{
			return 'No ID...';
		}
	}
	
	function delete_posts($args){
		extract($args);
        if($deleteaction == 'trash' || $deleteaction == 'draft' || $deleteaction == 'publish'){
            foreach($args as $key=>$val){
                if(!empty($val) && is_numeric($val)){
                    $edited_status = array('ID' => $val,
                        'post_status' => $deleteaction
                    );
                    $success = wp_update_post($edited_status);
                }
            }
        }elseif($deleteaction == 'delete'){
            foreach($args as $key=>$val){
                if(!empty($val) && is_numeric($val)){
                    $success = wp_delete_post($val, true);
                }
            }
        }
		return "Post deleted";
		
	}	
	
    /**
     * Function which gets pages from worker depending on arguments.
     * If FROM and TO dates are provided and range, range has bigger priority to date FROM.
     * This means if there are less pages between FROM and TO than range provided,
     * this function omit date from and returns last range number pages to date TO.
     *
     * @param array $args arguments passed to function
     *
     * @arg string filter_pages search phrase for page titles
     * @arg string cmsc_get_pages_date_from date in format(Y-m-d H:i:s) when pages are publishes from
     * @arg string cmsc_get_pages_date_to date in format(Y-m-d H:i:s) when pages are publishes to
     * @arg string cmsc_get_pages_range range number of returned pages
     * @arg string cmsc_get_pages_publish on or off
     * @arg string cmsc_get_pages_pending on or off
     * @arg string cmsc_get_pages_private on or off
     * @arg string cmsc_get_pages_future on or off
     * @arg string cmsc_get_pages_draft on or off
     * @arg string cmsc_get_pages_trash on or off
     * @return array pages related to args
     */
    function get_pages($args)
    {
        global $wpdb;

        $where = '';
        extract($args);
		
		// CMSC added - search custom post types
		if (!empty($post_type)) {
			$search_type = $post_type;
		} else {
			$search_type = "page";
		}	

        if (!empty($filter_pages)) {
            $where .= " AND (post_title LIKE '%".esc_sql($filter_pages)."%' OR post_content LIKE '%".esc_sql($filter_pages)."%')";
        }
        if (!empty($cmsc_get_pages_date_from) && !empty($cmsc_get_pages_date_to)) {
            $where .= " AND post_date BETWEEN '".esc_sql($cmsc_get_pages_date_from)."' AND '".esc_sql($cmsc_get_pages_date_to)."'";
        } else {
            if (!empty($cmsc_get_pages_date_from) && empty($cmsc_get_pages_date_to)) {
                $where .= " AND post_date >= '".esc_sql($cmsc_get_pages_date_from)."'";
            } else {
                if (empty($cmsc_get_pages_date_from) && !empty($cmsc_get_pages_date_to)) {
                    $where .= " AND post_date <= '".esc_sql($cmsc_get_pages_date_to)."'";
                }
            }
        }

        $post_array    = array();
        $post_statuses = array('publish', 'pending', 'private', 'future', 'draft', 'trash');
        foreach ($args as $checkbox => $checkbox_val) {
            if ($checkbox_val == "on") {
                $post_array[] = "'".str_replace("cmsc_get_pages_", "", $checkbox)."'";
            }
        }
        if (!empty($post_array)) {
            $where .= " AND post_status IN (".implode(",", $post_array).")";
        }

        $limit = ($cmsc_get_pages_range) ? ' LIMIT '.esc_sql($cmsc_get_pages_range) : ' LIMIT 500';

        $sql_query = "$wpdb->posts  WHERE post_status!='auto-draft' AND post_status!='inherit' AND post_type='".$search_type."' ".$where.' ORDER BY post_date DESC';

        $total              = array();
        $posts              = array();
        $posts_info         = $wpdb->get_results("SELECT * FROM ".$sql_query.$limit);
        $user_info          = $this->getUsersIDs();
		if (is_array($posts_info) && !empty($posts_info)) {$total['total_num'] = count($posts_info);}
		
        foreach ($posts_info as $post_info) {
            $posts[] = array(
                'post_id'        => $post_info->ID,
                'post_title'     => htmlspecialchars($post_info->post_title),
                'post_name'      => $post_info->post_name,
                'post_author'    => array('author_id' => $post_info->post_author, 'author_name' => $user_info[$post_info->post_author]),
                'post_date'                           => $post_info->post_date,
                'post_modified'                       => $post_info->post_modified,
                'post_status'                         => $post_info->post_status,
                'post_type'                           => $post_info->post_type,
                'guid'                                => $post_info->guid,
                'post_password'                       => $post_info->post_password,
                'ping_status'                         => $post_info->ping_status,
                'comment_status'                      => $post_info->comment_status,
                'comment_count'                       => $post_info->comment_count,

            );
        }

        return array('posts' => $posts, 'total' => $total);
    }	
	
	function delete_page($args){
		global $wpdb;
		if(!empty($args['post_id']) && !empty($args['action']))
		{
			if($args['action']=='delete')
			{
				$delete_query = "UPDATE $wpdb->posts SET post_status = 'trash' WHERE ID = ".$args['post_id'];
			}
			else if($args['action']=='delete_perm'){
				$delete_query = "DELETE FROM $wpdb->posts WHERE ID = ".$args['post_id'];
			}
			else if($args['action']=='delete_restore'){
				$delete_query = "UPDATE $wpdb->posts SET post_status = 'publish' WHERE ID = ".$args['post_id'];
			}
			$wpdb->get_results($delete_query);
		
			return 'Page deleted.';
		}
		else
		{
			return 'No ID...';
		}
	}
	
	function getPostCats($taxonomy = 'category')
	{
		global $wpdb;
		
		$cats = $wpdb->get_results("SELECT p.ID AS post_id, $wpdb->terms.name
FROM $wpdb->posts AS p
INNER JOIN $wpdb->term_relationships ON ( p.ID = $wpdb->term_relationships.object_id )
INNER JOIN $wpdb->term_taxonomy ON ( $wpdb->term_relationships.term_taxonomy_id = $wpdb->term_taxonomy.term_taxonomy_id
AND $wpdb->term_taxonomy.taxonomy = '".$taxonomy."' )
INNER JOIN $wpdb->terms ON ( $wpdb->term_taxonomy.term_id = $wpdb->terms.term_id )");
		
		foreach ( $cats as $post_val )
		{
			
			$post_cats[$post_val->post_id][] = $post_val->name;
		} 
		
		return $post_cats;
	}
	
	function getUsersIDs()
	{
		global $wpdb;
		$users_authors=array();
		$users = $wpdb->get_results("SELECT ID as user_id, display_name FROM $wpdb->users WHERE user_status=0");
		
		foreach ( $users as $user_key=>$user_val )
		{
			$users_authors[$user_val->user_id] = $user_val->display_name;
		} 
		
		return $users_authors;
	}
}
?>