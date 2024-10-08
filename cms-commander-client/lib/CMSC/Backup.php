<?php
/*************************************************************
 * 
 * backup.class.php
 * 
 * Manage Backups
 * 
 * 
 * Copyright (c) 2011 Prelovac Media
 * www.prelovac.com
 **************************************************************/
if(basename($_SERVER['SCRIPT_FILENAME']) == "backup.class.php"):
    echo "Sorry but you cannot browse this file directly!";
    exit;
endif;

/**
 * The main class for processing database and full backups on CMSCommander worker.
 * 
 * @copyright 	2011-2012 Prelovac Media
 * @version 	3.9.24
 * @package 	CMSCommander
 * @subpackage 	backup
 *
 */
class CMSC_Backup extends CMSC_Core {
    public $site_name;
    public $statuses;
    public $tasks;
    public $s3;
    public $ftp;
    public $dropbox;
    public $google_drive;
	
    private static $zip_errors = array(
        0   => 'No error',
        1   => 'No error',
        2   => 'Unexpected end of zip file',
        3   => 'A generic error in the zipfile format was detected',
        4   => 'zip was unable to allocate itself memory',
        5   => 'A severe error in the zipfile format was detected',
        6   => 'Entry too large to be split with zipsplit',
        7   => 'Invalid comment format',
        8   => 'zip -T failed or out of memory',
        9   => 'The user aborted zip prematurely',
        10  => 'zip encountered an error while using a temp file. Please check if there is enough disk space',
        11  => 'Read or seek error',
        12  => 'zip has nothing to do',
        13  => 'Missing or empty zip file',
        14  => 'Error writing to a file. Please check if there is enough disk space',
        15  => 'zip was unable to create a file to write to',
        16  => 'bad command line parameters',
        17  => 'no error',
        18  => 'zip could not open a specified file to read',
        159 => 'File size limit exceeded',
    );

    private static $unzip_errors = array(
        0  => 'No error',
        1  => 'One or more warning errors were encountered, but processing completed successfully anyway',
        2  => 'A generic error in the zipfile format was detected',
        3  => 'A severe error in the zipfile format was detected.',
        4  => 'unzip was unable to allocate itself memory.',
        5  => 'unzip was unable to allocate memory, or encountered an encryption error',
        6  => 'unzip was unable to allocate memory during decompression to disk',
        7  => 'unzip was unable allocate memory during in-memory decompression',
        8  => 'unused',
        9  => 'The specified zipfiles were not found',
        10 => 'Bad command line parameters',
        11 => 'No matching files were found',
        50 => 'The disk is (or was) full during extraction',
        51 => 'The end of the ZIP archive was encountered prematurely.',
        80 => 'The user aborted unzip prematurely.',
        81 => 'Testing or extraction of one or more files failed due to unsupported compression methods or unsupported decryption.',
        82 => 'No files were found due to bad decryption password(s)',
    );	
    
    /**
     * Initializes site_name, statuses, and tasks attributes.
     */
    function __construct() {
        parent::__construct();
        $this->site_name = str_replace(array("_", "/", "~", ":"), array("", "-", "-", "-"), rtrim($this->remove_http(get_bloginfo('url')), "/"));
        $this->statuses  = array(
            'db_dump'      => 1,
            'db_zip'       => 2,
            'files_zip'    => 3,
            's3'           => 4,
            'dropbox'      => 5,
            'ftp'          => 6,
            'email'        => 7,
            'google_drive' => 8,
            'sftp'         => 9,
            'finished'     => 100,
        );

        $this->w3tc_flush();
		
        $this->tasks = get_option('cmsc_backup_tasks');
    }
    
    /**
     * Tries to increase memory limit to 384M and execution time to 600s.
     * 
     * @return 	array	an array with two keys for execution time and memory limit (0 - if not changed, 1 - if succesfully)
     */
    function set_memory() {  
	
        $changed = array('execution_time' => 0, 'memory_limit' => 0);
        ignore_user_abort(true);
        $tryLimit = 384;

        $limit = cmsc_format_memory_limit(ini_get('memory_limit'));

        $matched = preg_match('/^(\d+) ([KMG]?B)$/', $limit, $match);

        if ($matched
            && (
                ($match[2] === 'GB')
                || ($match[2] === 'MB' && (int) $match[1] >= $tryLimit)
            )
        ) {
            // Memory limits are satisfied.
        } else {
            ini_set('memory_limit', $tryLimit.'M');
            $changed['memory_limit'] = 1;
        }
        if (!cmsc_is_safe_mode() && ((int) ini_get('max_execution_time') < 4000) && (ini_get('max_execution_time') !== '0')) {
            ini_set('max_execution_time', 4000);
            set_time_limit(4000);
            $changed['execution_time'] = 1;
        }

        return $changed;
  	}
   	
  	/**
  	 * Returns backup settings from local database for all tasks
  	 * 
  	 * @return 	mixed|boolean	
  	 */
    function get_backup_settings() {
	
        $backup_settings = get_option('cmsc_backup_tasks');
		
        if (!empty($backup_settings)) {
            return $backup_settings;
        } else {
            return false;
        }
    }
    
    /**
     * Sets backup task defined from master, if task name is "Backup Now" this function fires processing backup.
     * 
     * @param 	mixed 			$params	parameters sent from master
     * @return 	mixed|boolean	$this->tasks variable if success, array with error message if error has ocurred, false if $params are empty
     */
    function set_backup_task($params) {
        //$params => [$task_name, $args, $error]
        if (!empty($params)) {
        	
        	//Make sure backup cron job is set
        	if (!wp_next_scheduled('cmsc_backup_tasks')) {
				wp_schedule_event( time(), 'tenminutes', 'cmsc_backup_tasks' );
			}
        	
            extract($params);
			
            //$before = $this->get_backup_settings();
            $before = $this->tasks;
            if (!$before || empty($before)) {
                $before = array();
            }
            
            if (isset($args['remove'])) {
                unset($before[$task_name]);
                $return = array(
                    'removed' => true
                );
            } else {
                if (isset($params['account_info']) && is_array($params['account_info'])) { //only if sends from master first time(secure data)
                    $args['account_info'] = $account_info;
                }
                
                $before[$task_name]['task_args'] = $args;
                if (strlen($args['schedule']))
                    $before[$task_name]['task_args']['next'] = $this->schedule_next($args['type'], $args['schedule']);
                
                $return = $before[$task_name];
            }
			
			if(is_array($before[$task_name]['task_results'])) {
				$tcount = count($before[$task_name]['task_results']);
			} else {
				$tcount = 1;
			}
            
            //Update with error
            if (isset($error)) {
                if (is_array($error)) {
                    $before[$task_name]['task_results'][$tcount - 1]['error'] = $error['error'];
                } else {
                    $before[$task_name]['task_results'][$tcount - 1]['error'] = $error;
                }
            }
            
            if (isset($time) && $time) { //set next result time before backup
                if (is_array($before[$task_name]['task_results'])) {
                    $before[$task_name]['task_results'] = array_values($before[$task_name]['task_results']);
                }
                $before[$task_name]['task_results'][$tcount]['time'] = $time;
            }
            
            $this->update_tasks($before);

            if ($task_name == 'Backup Now') {
                $resultUuid      = !empty($params['resultUuid']) ? $params['resultUuid'] : false;			
            	$result          = $this->backup($args, $task_name, $resultUuid);
                $backup_settings = $this->tasks;
                
                if (is_array($result) && array_key_exists('error', $result)) {
                	$return = $result;
                } else {
                    $return = $backup_settings[$task_name];
                }
            }
            return $return;
        }
        
        return false;
    }
    
    /**
     * Checks if scheduled task is ready for execution,
     * if it is ready master sends google_drive_token, failed_emails, success_emails if are needed.
     * 
     * @return void
     */
    function check_backup_tasks() {
    	$this->check_cron_remove();
        
		$this->_log("CMSC - checking tasks...");		
		
    	$failed_emails = array();
        $settings = $this->tasks;
        if (is_array($settings) && !empty($settings)) {
            foreach ($settings as $task_name => $setting) {
			
				$this->_log($task_name . " time: ". time() . " next: " . $setting['task_args']['next']);		
			
				if (isset($setting['task_args']['next']) && $setting['task_args']['next'] < time()) {
				
					$this->_log(" time to run ");					
					
                    //if ($setting['task_args']['next'] && $_GET['force_backup']) {
                    if ($setting['task_args']['url'] && $setting['task_args']['task_id'] && $setting['task_args']['site_key']) {

						if(!empty($setting['task_args']['url']) && !empty($setting['task_args']['task_id'])) { // CMSC ADDED
							//Check orphan task
							$check_data = array(
								'task_name' => $task_name,
								'task_id' => $setting['task_args']['task_id'],
								'site_key' => $setting['task_args']['site_key'],
								'worker_version' => CMSC_WORKER_VERSION
							);
							
							if (isset($setting['task_args']['account_info']['cmsc_google_drive']['google_drive_token'])) {
								$check_data['cmsc_google_drive_refresh_token'] = true;
							}						
						
							$check = $this->validate_task($check_data, $setting['task_args']['url']);	
						} else {
							$check = "";
						}

                         if($check == 'paused' || $check == 'deleted'){
                            continue;
                        } 
						
						//$worker_upto_3_9_22 = (CMSC_WORKER_VERSION <= '3.9.22');  // worker version is less or equals to 3.9.22

                        // This is the patch done in worker 3.9.22 because old worked provided message in the following format:
                        // token - not found or token - {...json...}
                        // The new message is a serialized string with google_drive_token or message.                        
                        /*if ($worker_upto_3_9_22) {  // CMSC ADDED
	                        $potential_token = substr($check, 8);
	                        if (substr($check, 0, 8) == 'token - ' && $potential_token != 'not found') {
	                        	$this->tasks[$task_name]['task_args']['account_info']['cmsc_google_drive']['google_drive_token'] = $potential_token;
	                        	$settings[$task_name]['task_args']['account_info']['cmsc_google_drive']['google_drive_token'] = $potential_token;
	                        	$setting['task_args']['account_info']['cmsc_google_drive']['google_drive_token'] = $potential_token;
	                        }
                        } else {
                        	$potential_token = isset($check['google_drive_token']) ? $check['google_drive_token'] : false;
                        	if ($potential_token) {
                        		$this->tasks[$task_name]['task_args']['account_info']['cmsc_google_drive']['google_drive_token'] = $potential_token;
	                        	$settings[$task_name]['task_args']['account_info']['cmsc_google_drive']['google_drive_token'] = $potential_token;
	                        	$setting['task_args']['account_info']['cmsc_google_drive']['google_drive_token'] = $potential_token;
                        	}
                        }*/
                        
                    }

                    $update = array(
                        'task_name' => $task_name,
                        'args' => $settings[$task_name]['task_args']  
                    );
                    
                    if ($check != 'paused') {
                    	$update['time'] = time();
                    }
                    
                    //Update task with next schedule
                    $this->set_backup_task($update);
                    
                    if($check == 'paused'){
                    	continue;
                    }					
					
                	$result = $this->backup($setting['task_args'], $task_name);
                    $error  = '';
					
                    if (is_array($result) && array_key_exists('error', $result)) {
					
                    	$error = $result;
                    	$this->set_backup_task(array(
                    		'task_name' => $task_name,
                    		'args' => $settings[$task_name]['task_args'],
                    		'error' => $error
                    	));
						$this->_log($error);
                    } else {

                        if (!empty($setting['task_args']['account_info'])) {
						
							$this->_log("CMSC - we are in remote processing...");
						
							if(is_array($this->tasks[$task_name]['task_results'])) {
								$tcount = count($this->tasks[$task_name]['task_results']);
							} else {
								$tcount = 1;
							}						
						
                            // Old way through sheduling.
                            // wp_schedule_single_event(time(), 'cmsc_scheduled_remote_upload', array('args' => array('task_name' => $task_name)));
                            //$nonce = substr(wp_hash(wp_nonce_tick() . 'cmsc-backup-nonce' . 0, 'nonce'), -12, 10);
							$nonce = wp_create_nonce("cmsc-backup-nonce");
                            $cron_url = site_url('index.php');
                            $backup_file = $this->tasks[$task_name]['task_results'][$tcount - 1]['server']['file_url'];
                            $del_host_file = $this->tasks[$task_name]['task_args']['del_host_file'];
                            $public_key = get_option('_cmsc_public_key');
							
                            $args = array(
                                'body' => array(
                                    'backup_cron_action' => 'cmsc_remote_upload',
                                    'args' => json_encode(array('task_name' => $task_name, 'backup_file' => $backup_file, 'del_host_file' => $del_host_file)),
                                    'cmsc_backup_nonce' => $nonce,
                                    'public_key' => $public_key,
                                ),
                                'timeout' => 600,
                                'blocking' => false,
                                'sslverify' => apply_filters('https_local_ssl_verify', true)
                            );
                            $return = wp_remote_post($cron_url, $args);
							
							$this->_log("CMSC - remote upload started...");
							$this->_log($return);
							$this->_log($this->tasks[$task_name]['task_results']);
							
							if(is_wp_error( $return )) {
								$this->_log("CMSC - error .. trigger action directly");
								$this->_log(json_decode($args["body"]["args"]));

								$args = array("task_name" => $task_name, "backup_file" => $backup_file, "del_host_file" => $del_host_file);
								
								//$test = $this->remote_backup_now($args);$this->_log($test);
								do_action('cmsc_remote_upload', $args);

								$this->_log($this->tasks[$task_name]['task_results'][$tcount - 1]);								
							}
                        }					
                    }
                    
                    break; //Only one backup per cron
                }
            }
        }
        
    }
    
    /**
     * Runs backup task invoked from CMSCommander master.
     * 
     * @param string 				$task_name 				name of backup task
     * @param string|bool[optional]	$google_drive_token 	false if backup destination is not Google Drive, json of Google Drive token if it is remote destination (default: false)
     * @return mixed										array with backup statistics if successful, array with error message if not
     */
    function task_now($task_name, $google_drive_token = false, $resultUuid = false) {
	
		if ($google_drive_token) {
			$this->tasks[$task_name]['task_args']['account_info']['cmsc_google_drive']['google_drive_token'] = $google_drive_token;
		}
    	
		$settings = $this->tasks;
    	if(!array_key_exists($task_name,$settings)){
    	 	return array('error' => $task_name." does not exist.");
    	} else {
    	 	$setting = $settings[$task_name];
    	}    
    	
		$this->set_backup_task(array(
			'task_name' => $task_name,
			'args' => $settings[$task_name]['task_args'],
			'time' => time()
		));
		
		//Run backup              
		$result = $this->backup($setting['task_args'], $task_name, $resultUuid);

		//Check for error
		if (is_array($result) && array_key_exists('error', $result)) {
			$this->set_backup_task(array(
				'task_name' => $task_name,
				'args' => $settings[$task_name]['task_args'],
				'error' => $result
			));
			
			return $result;
		} else {
			return $this->get_backup_stats();
		}
    }
    
    /**
     * Backup a full wordpress instance, including a database dump, which is placed in cmsc_db dir in root folder.
     * All backups are compressed by zip and placed in wp-content/cmscommander/backups folder.
     *
     * @param	string					$args			arguments passed from master 
     * [type] -> db, full,
     * [what] -> daily, weekly, monthly,
     * [account_info] -> remote destinations ftp, amazons3, dropbox, google_drive, email with their parameters
     * [include] -> array of folders from site root which are included to backup (wp-admin, wp-content, wp-includes are default)
     * [exclude] -> array of files of folders to exclude, relative to site's root
     * @param	bool|string[optional]	$task_name		the name of backup task, which backup is done (default: false)
     * @return	bool|array								false if $args are missing, array with error if error has occured, ture if is successful
     */
    function backup($args, $task_name = false, $resultUuid = false) {
	
        if (!$args || empty($args)) {
            return false;
        }
        
        extract($args); //extract settings
        
		if (!empty($account_info)) {
			$found = false;
			$destinations = array('cmsc_ftp','cmsc_sftp' , 'cmsc_amazon_s3', 'cmsc_dropbox', 'cmsc_google_drive', 'cmsc_email');
			foreach($destinations as $dest) {
				$found = $found || (isset($account_info[$dest]));
			}	            
			if (!$found) {
				$error_message = 'Remote destination is not supported, please update your client plugin.';
				
				return array(
					'error' => $error_message
				);
			}
		}
        
        //Try increase memory limit	and execution time
      	$this->set_memory();
        
        //Remove old backup(s)
        $removed = $this->remove_old_backups($task_name);
        if (is_array($removed) && isset($removed['error'])) {
        	$error_message = $removed['error'];
			
        	return $removed;
        }
        
        $new_file_path = CMSC_BACKUP_DIR;
        
        if (!file_exists($new_file_path)) {
           if (!mkdir($new_file_path, 0755, true)) {
                return array(
                    'error' => 'Permission denied, make sure you have write permissions to the wp-content folder.',
                );
            }
        }
        
        @file_put_contents($new_file_path . '/index.php', ''); //safe
        
        //Prepare .zip file name  
        $hash        = md5(microtime(true).uniqid('',true).substr(str_shuffle("0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ"), 0, rand(20,60)));
        $label       = !empty($type) ? $type : 'manual';
        $backup_file = $new_file_path . '/' . $this->site_name . '_' . $label . '_' . $what . '_' . date('Y-m-d') . '_' . $hash . '.zip';
        $backup_url  = WP_CONTENT_URL . '/cmscommander/backups/' . $this->site_name . '_' . $label . '_' . $what . '_' . date('Y-m-d') . '_' . $hash . '.zip';
        
        $begin_compress = microtime(true);
        
        //Optimize tables?
        if (isset($optimize_tables) && !empty($optimize_tables)) {
            $this->optimize_tables();
        }
       	
        //What to backup - db or full?
        if (trim($what) == 'db') {
            $db_backup = $this->backup_db_compress($task_name, $backup_file);
            if (is_array($db_backup) && array_key_exists('error', $db_backup)) {
            	$error_message = $db_backup['error'];
				
            	return array(
            		'error' => $error_message
            	);
            }
        } elseif (trim($what) == 'full') {
            if (!$exclude) {
            	$exclude = array();
            }
            if (!$include) {
            	$include = array();
            }
        	$content_backup = $this->backup_full($task_name, $backup_file, $exclude, $include);
            if (is_array($content_backup) && array_key_exists('error', $content_backup)) {
            	$error_message = $content_backup['error'];
				
            	return array(
                    'error' => $error_message
                );
            }
        }
        
        $end_compress = microtime(true);

        //Update backup info
        if ($task_name) {
            //backup task (scheduled)
            $backup_settings = $this->tasks;
            $paths           = array();
            $size            = ceil(filesize($backup_file) / 1024);
            $duration        = round($end_compress - $begin_compress, 2);
            
            if ($size > 1000) {
                $paths['size'] = ceil($size / 1024) . "MB";
            } else {
                $paths['size'] = $size . 'KB';
            }
			
            $paths['duration'] = $duration . 's';
            if ($resultUuid) {
                $paths['resultUuid'] = $resultUuid;
            }   
			
            if ($task_name != 'Backup Now') {
                $paths['server'] = array(
                    'file_path' => $backup_file,
                    'file_url' => $backup_url
                );
            } else {
                $paths['server'] = array(
                    'file_path' => $backup_file,
                    'file_url' => $backup_url
                );
            }
            
            if (isset($backup_settings[$task_name]['task_args']['account_info']['cmsc_ftp'])) {
                $paths['ftp'] = basename($backup_url);
            }
            
            if (isset($backup_settings[$task_name]['task_args']['account_info']['cmsc_sftp'])) {
                $paths['sftp'] = basename($backup_url);
            }			
            if (isset($backup_settings[$task_name]['task_args']['account_info']['cmsc_amazon_s3'])) {
                $paths['amazons3'] = basename($backup_url);
            }
            
            if (isset($backup_settings[$task_name]['task_args']['account_info']['cmsc_dropbox'])) {
                $paths['dropbox'] = basename($backup_url);
            }
            
            if (isset($backup_settings[$task_name]['task_args']['account_info']['cmsc_email'])) {
                $paths['email'] = basename($backup_url);
            }
            
            if (isset($backup_settings[$task_name]['task_args']['account_info']['cmsc_google_drive'])) {
                $paths['google_drive'] = array(
                    'file'    => basename($backup_url),
                    'file_id' => ''
                );
            }
            
            $temp          = $backup_settings[$task_name]['task_results'];
            $temp          = @array_values($temp);
            $paths['time'] = time();
            
			if(is_array($temp)) {
				$tcount = count($temp);
			} else {
				$tcount = 1;
			}				
			
            if ($task_name != 'Backup Now') {
                $paths['status']        = $temp[$tcount - 1]['status'];
                $temp[$tcount - 1] = $paths;                
            } else {
                $temp[$tcount] = $paths;
            }
			
            $backup_settings[$task_name]['task_results'] = $temp;
			$this->_log("-- Backup Task Finished-- "); /// CMSC LOG			
            $this->update_tasks($backup_settings);
 
        }
        
        // If there are not remote destination, set up task status to finished
        if (@count($backup_settings[$task_name]['task_args']['account_info']) == 0) {
        	$this->update_status($task_name, $this->statuses['finished'], true);
        }
        
        return true;
    }
    
    /**
     * Backup a full wordpress instance, including a database dump, which is placed in cmsc_db dir in root folder.
     * All backups are compressed by zip and placed in wp-content/cmscommander/backups folder.
     * 
     * @param	string			$task_name		the name of backup task, which backup is done
     * @param	string			$backup_file	relative path to file which backup is stored
     * @param	array[optional]	$exclude		the list of files and folders, which are excluded from backup (default: array())
     * @param	array[optional]	$include		the list of folders in wordpress root which are included to backup, expect wp-admin, wp-content, wp-includes, which are default (default: array())
     * @return	bool|array						true if backup is successful, or an array with error message if is failed
     */
    function backup_full($task_name, $backup_file, $exclude = array(), $include = array()) {
	
    	$this->update_status($task_name, $this->statuses['db_dump']);
        $db_result = $this->backup_db();
        
        if ($db_result == false) {
            return array(
                'error' => 'Failed to backup database.'
            );
        } else {
            if (is_array($db_result) && isset($db_result['error'])) {
                return array(
                    'error' => $db_result['error'],
                );
            }
        }
        
        $this->update_status($task_name, $this->statuses['db_dump'], true);
        $this->update_status($task_name, $this->statuses['db_zip']);
        
        @file_put_contents(CMSC_BACKUP_DIR.'/cmsc_db/index.php', '');
        $zip_db_result = $this->zip_backup_db($task_name, $backup_file);
		
        if (!$zip_db_result) {
        	$zip_archive_db_result = false;
        	if (class_exists("ZipArchive")) {
        		$this->_log("DB zip, fallback to ZipArchive");
        		$zip_archive_db_result = $this->zip_archive_backup_db($task_name, $db_result, $backup_file);
			}
			
			if (!$zip_archive_db_result) {
				$this->_log("DB zip, fallback to PclZip");
				$pclzip_db_result = $this->pclzip_backup_db($task_name, $backup_file);
				if (!$pclzip_db_result) {
					@unlink(CMSC_BACKUP_DIR.'/cmsc_db/index.php');
					@unlink(CMSC_BACKUP_DIR.'/mwp_db/info.json');
					@unlink($db_result);
					@rmdir(CMSC_DB_DIR);
					 
                    if ($archive->error_code != '') {
                        $archive->error_code = 'pclZip error ('.$archive->error_code.'): .';
                    }
					
					return array(
						'error' => 'Failed to zip database. ' . $archive->error_code . $archive->error_string
					);
				}
			}
        }
        
        @unlink(CMSC_BACKUP_DIR.'/cmsc_db/index.php');
		@unlink(CMSC_BACKUP_DIR.'/mwp_db/info.json');
        @unlink($db_result);
        @rmdir(CMSC_DB_DIR);
        $uploadDir = wp_upload_dir();
        $remove = array(
		trim(basename(WP_CONTENT_DIR)) . "/" . md5('cmsc-worker') . "/cmsc_backups",
            trim(basename(WP_CONTENT_DIR))."/managewp/backups",
            trim(basename(WP_CONTENT_DIR))."/infinitewp/backups",
            trim(basename(WP_CONTENT_DIR))."/".md5('mmb-worker')."/mwp_backups",
            trim(basename(WP_CONTENT_DIR))."/backupwordpress",
            trim(basename(WP_CONTENT_DIR))."/contents/cache",
            trim(basename(WP_CONTENT_DIR))."/content/cache",
            trim(basename(WP_CONTENT_DIR))."/cache",
            trim(basename(WP_CONTENT_DIR))."/old-cache",
            trim(basename(WP_CONTENT_DIR))."/uploads/backupbuddy_backups",
            trim(basename(WP_CONTENT_DIR))."/w3tc",
            trim(basename(WP_CONTENT_DIR))."/cmscommander/backups",
            trim(basename(WP_CONTENT_DIR))."/gt-cache",
            trim(basename(WP_CONTENT_DIR))."/wfcache",
            trim(basename(WP_CONTENT_DIR))."/bps-backup",
            trim(basename(WP_CONTENT_DIR))."/old-cache",
            trim(basename(WP_CONTENT_DIR))."/updraft",
            trim(basename(WP_CONTENT_DIR))."/nfwlog/cache",
            trim(basename(WP_CONTENT_DIR))."/upgrade",
            trim(basename(WP_CONTENT_DIR))."/wishlist-backup",
            trim(basename(WP_CONTENT_DIR))."/wptouch-data/infinity-cache/",
            trim(basename(WP_CONTENT_DIR)."/".basename($uploadDir['basedir']))."/ithemes-security/backups",
            trim(basename(WP_CONTENT_DIR)."/".basename($uploadDir['basedir']))."/mainwp/backup",
            trim(basename(WP_CONTENT_DIR)."/".basename($uploadDir['basedir']))."/sucuri",
            trim(basename(WP_CONTENT_DIR)."/".basename($uploadDir['basedir']))."/aiowps_backups",
            trim(basename(WP_CONTENT_DIR)."/".basename($uploadDir['basedir']))."/gravity_forms",
            trim(basename(WP_CONTENT_DIR)."/".basename($uploadDir['basedir']))."/mainwp",
            trim(basename(WP_CONTENT_DIR)."/".basename($uploadDir['basedir']))."/snapshots",
            trim(basename(WP_CONTENT_DIR)."/".basename($uploadDir['basedir']))."/wp-clone",
            trim(basename(WP_CONTENT_DIR)."/".basename($uploadDir['basedir']))."/wp-clone",
            trim(basename(WP_CONTENT_DIR)."/".basename($uploadDir['basedir']))."/wp_system",
            trim(basename(WP_CONTENT_DIR)."/".basename($uploadDir['basedir']))."/wpcf7_captcha",
            trim(basename(WP_CONTENT_DIR)."/".basename($uploadDir['basedir']))."/wc-logs",
            trim(basename(WP_CONTENT_DIR)."/".basename($uploadDir['basedir']))."/pb_backupbuddy",
            trim(basename(WP_CONTENT_DIR))."/mysql.sql",
            "error_log",
            "error.log",
            "debug.log",
            "WS_FTP.LOG",
            "security.log",
            "dbcache",
            "pgcache",
            "objectcache",	     
        );
        $exclude = array_merge($exclude, $remove);
        
        $this->update_status($task_name, $this->statuses['db_zip'], true);
        $this->update_status($task_name, $this->statuses['files_zip']);
        	
        //if (function_exists('proc_open') && $this->zipExists()) {
            $zip_result = $this->zip_backup($task_name, $backup_file, $exclude, $include);
        //} else {
        //    $zip_result = false;
        //}
        
        if (isset($zip_result['error'])) {
        	return $zip_result;
        }
        
        if (!$zip_result) {
        	$zip_archive_result = false;
        	if (class_exists("ZipArchive")) {
        		$this->_log("Files zip fallback to ZipArchive");
        		$zip_archive_result = $this->zip_archive_backup($task_name, $backup_file, $exclude, $include);
        	}
        	
        	if (!$zip_archive_result) {
        		$this->_log("Files zip fallback to PclZip");
        		$pclzip_result = $this->pclzip_backup($task_name, $backup_file, $exclude, $include);
        		if (!$pclzip_result) {
        			@unlink(CMSC_BACKUP_DIR.'/cmsc_db/index.php');
        			@unlink($db_result);
        			@rmdir(CMSC_DB_DIR);
        			 
        			if (!$pclzip_result) {
        				@unlink($backup_file);
						
        				return array(
        					'error' => 'Failed to zip files. pclZip error (' . $archive->error_code . '): .' . $archive->error_string
        				);
        			}
        		}
        	}
        }
        
        //Reconnect
        $this->wpdb_reconnect();
        
        $this->update_status($task_name, $this->statuses['files_zip'], true);
		
        return true;
    }
    
    /**
     * Zipping database dump and index.php in folder cmsc_db by system zip command, requires zip installed on OS.
     * 
     * @param 	string 	$task_name		the name of backup task
     * @param 	string 	$backup_file	absolute path to zip file
     * @return 	bool					is compress successful or not
     */
    function zip_backup_db($task_name, $backup_file) {
    	$disable_comp = $this->tasks[$task_name]['task_args']['disable_comp'];
    	$comp_level   = $disable_comp ? '-0' : '-1';
    	$zip = $this->get_zip();
    	//Add database file
    	chdir(CMSC_BACKUP_DIR);
    	$command = "$zip -q -r $comp_level $backup_file 'cmsc_db'";
    	
    	ob_start();
    	$this->_log("Executing $command");
    	$result = $this->cmsc_exec($command);
    	ob_get_clean();
    	
    	return $result;
    }
    
    /**
     * Zipping database dump and index.php in folder cmsc_db by ZipArchive class, requires php zip extension.
     *
     * @param 	string 	$task_name		the name of backup task
     * @param	string	$db_result		relative path to database dump file
     * @param 	string 	$backup_file	absolute path to zip file
     * @return 	bool					is compress successful or not
     */
    function zip_archive_backup_db($task_name, $db_result, $backup_file) {
	
    	$disable_comp = $this->tasks[$task_name]['task_args']['disable_comp'];
        /** @handled class */
        $zip = new ZipArchive();
        /** @handled constant */
        $result = $zip->open($backup_file, ZipArchive::OVERWRITE); // Tries to open $backup_file for acrhiving
    	if ($result === true) {
    		$result = $result && $zip->addFile(CMSC_BACKUP_DIR.'/cmsc_db/index.php', "cmsc_db/index.php"); // Tries to add cmsc_db/index.php to $backup_file
    		$result = $result && $zip->addFile($db_result, "cmsc_db/" . basename($db_result)); // Tries to add db dump form cmsc_db dir to $backup_file
    		$result = $result && $zip->close(); // Tries to close $backup_file
    	} else {
    		$result = false;
    	}
    	
    	return $result; // true if $backup_file iz zipped successfully, false if error is occured in zip process
    }
    
    /**
     * Zipping database dump and index.php in folder cmsc_db by PclZip library.
     *
     * @param 	string 	$task_name		the name of backup task
     * @param 	string 	$backup_file	absolute path to zip file
     * @return 	bool					is compress successful or not
     */
    function pclzip_backup_db($task_name, $backup_file) {
	
    	$disable_comp = $this->tasks[$task_name]['task_args']['disable_comp'];
    	define('PCLZIP_TEMPORARY_DIR', CMSC_BACKUP_DIR . '/');
    	require_once ABSPATH . '/wp-admin/includes/class-pclzip.php';
    	$zip = new PclZip($backup_file);
    	 
    	if ($disable_comp) {
            $result = $zip->add(CMSC_BACKUP_DIR."/cmsc_db/", PCLZIP_OPT_REMOVE_PATH, CMSC_BACKUP_DIR, PCLZIP_OPT_NO_COMPRESSION) !== 0;
		} else {
            $result = $zip->add(CMSC_BACKUP_DIR."/cmsc_db/", PCLZIP_OPT_REMOVE_PATH, CMSC_BACKUP_DIR) !== 0;
		}
    	
    	return $result;
    }
    
    /**
     * Zipping whole site root folder and append to backup file with database dump
     * by system zip command, requires zip installed on OS.
     *
     * @param 	string 	$task_name		the name of backup task
     * @param 	string 	$backup_file	absolute path to zip file
     * @param	array	$exclude		array of files of folders to exclude, relative to site's root
     * @param	array	$include		array of folders from site root which are included to backup (wp-admin, wp-content, wp-includes are default)
     * @return 	array|bool				true if successful or an array with error message if not
     */
    function zip_backup($task_name, $backup_file, $exclude, $include) {
    	global $zip_errors;
    	$sys = substr(PHP_OS, 0, 3);
    	
    	//Exclude paths
    	$exclude_data = "-x";
    	
    	$exclude_file_data = '';
    	
    	if (!empty($exclude)) {
    		foreach ($exclude as $data) {
    			if (is_dir(ABSPATH . $data)) {
    				if ($sys == 'WIN')
    					$exclude_data .= " $data/*.*";
    				else
    					$exclude_data .= " '$data/*'";
    			} else {
    				if ($sys == 'WIN'){
    					if(file_exists(ABSPATH . $data)){
    						$exclude_data .= " $data";
    						$exclude_file_data .= " $data";
    					}
    				} else {
    					if(file_exists(ABSPATH . $data)){
    						$exclude_data .= " '$data'";
    						$exclude_file_data .= " '$data'";
    					}
    				}
    			}
    		}
    	}
    	
    	if($exclude_file_data){
    		$exclude_file_data = "-x".$exclude_file_data;
    	}
    	
    	//Include paths by default
    	$add = array(
    		trim(WPINC),
    		trim(basename(WP_CONTENT_DIR)),
    		"wp-admin"
    	);
    	
    	$include_data = ". -i";
    	foreach ($add as $data) {
    		if ($sys == 'WIN')
    			$include_data .= " $data/*.*";
    		else
    			$include_data .= " '$data/*'";
    	}
    	
    	//Additional includes?
    	if (!empty($include) && is_array($include)) {
    		foreach ($include as $data) {
    			if ($data) {
    				if ($sys == 'WIN')
    					$include_data .= " $data/*.*";
    				else
    					$include_data .= " '$data/*'";
    			}
    		}
    	}
    	
    	$disable_comp = $this->tasks[$task_name]['task_args']['disable_comp'];
    	$comp_level   = $disable_comp ? '-0' : '-1';
    	$zip = $this->get_zip();
    	chdir(ABSPATH);
    	ob_start();
    	$command  = "$zip -q -j $comp_level $backup_file .* * $exclude_file_data";
    	$this->_log("Executing $command");
        if($exclude_data==="-x")
        {
            $exclude_data="";
        }		
    	$result_f = $this->cmsc_exec($command, false, true);
    	if (!$result_f || $result_f == 18) { // disregard permissions error, file can't be accessed
    		$command  = "$zip -q -r $comp_level $backup_file $include_data $exclude_data";
    		$result_d = $this->cmsc_exec($command, false, true);
    		$this->_log("Executing $command");
    		if ($result_d && $result_d != 18) {
    			@unlink($backup_file);
    			if ($result_d > 0 && $result_d < 18)
    				return array(
    					'error' => 'Failed to archive files (' . $zip_errors[$result_d] . ') .'
    				);
    			else {
    				if ($result_d === -1) return false;
    				return array(
    					'error' => 'Failed to archive files.'
    				);
    			}
    		}
    	} else {
    		return false;
    	}
    	
    	ob_get_clean();
    	
    	return true;
    }
    
    /**
     * Zipping whole site root folder and append to backup file with database dump
     * by ZipArchive class, requires php zip extension.
     *
     * @param 	string 	$task_name		the name of backup task
     * @param 	string 	$backup_file	absolute path to zip file
     * @param	array	$exclude		array of files of folders to exclude, relative to site's root
     * @param	array	$include		array of folders from site root which are included to backup (wp-admin, wp-content, wp-includes are default)
     * @return 	array|bool				true if successful or an array with error message if not
     */
    function zip_archive_backup($task_name, $backup_file, $exclude, $include, $overwrite = false) {
	
		$filelist = $this->get_backup_files($exclude, $include);
		$disable_comp = $this->tasks[$task_name]['task_args']['disable_comp'];
		if (!$disable_comp) {
			$this->_log("Compression is not supported by ZipArchive");
		}
		
		$zip = new ZipArchive();
		if ($overwrite) {
			$result = $zip->open($backup_file, ZipArchive::OVERWRITE); // Tries to open $backup_file for acrhiving
		} else {
			$result = $zip->open($backup_file); // Tries to open $backup_file for acrhiving
		}
		if ($result === true) {
			foreach ($filelist as $file) {
                $pathInZip = strpos($file, ABSPATH) === false ? basename($file) : str_replace(ABSPATH, '', $file);
                $result    = $result && $zip->addFile($file, $pathInZip); // Tries to add a new file to $backup_file
			}
			$result = $result && $zip->close(); // Tries to close $backup_file
		} else {
			$result = false;
		}
		
		return $result; // true if $backup_file iz zipped successfully, false if error is occured in zip process
    }
    
    /**
     * Zipping whole site root folder and append to backup file with database dump
     * by PclZip library.
     *
     * @param 	string 	$task_name		the name of backup task
     * @param 	string 	$backup_file	absolute path to zip file
     * @param	array	$exclude		array of files of folders to exclude, relative to site's root
     * @param	array	$include		array of folders from site root which are included to backup (wp-admin, wp-content, wp-includes are default)
     * @return 	array|bool				true if successful or an array with error message if not
     */
    function pclzip_backup($task_name, $backup_file, $exclude, $include) {
	
    	define('PCLZIP_TEMPORARY_DIR', CMSC_BACKUP_DIR . '/');
	    require_once ABSPATH . '/wp-admin/includes/class-pclzip.php';
		/** @handled class */
	    $zip = new PclZip($backup_file);
    	$add = array(
    		trim(WPINC),
    		trim(basename(WP_CONTENT_DIR)),
    		"wp-admin"
    	);
		
        if (!file_exists(ABSPATH.'wp-config.php')
            && file_exists(dirname(ABSPATH).'/wp-config.php')
            && !file_exists(dirname(ABSPATH).'/wp-settings.php')
        ) {
            $include[] = '../wp-config.php';
        }

        $path = wp_upload_dir();
        $path = $path['path'];
        if (strpos($path, WP_CONTENT_DIR) === false && strpos($path, ABSPATH) === 0) {
            $add[] = ltrim(substr($path, strlen(ABSPATH)), ' /');
        }		
    	
    	$include_data = array();
    	if (!empty($include)) {
    		foreach ($include as $data) {
    			if ($data && file_exists(ABSPATH . $data)) {
    				$include_data[] = ABSPATH . $data . '/';
				}	
    		}
    	}
    	$include_data = array_merge($add, $include_data);
    	
    	if ($handle = opendir(ABSPATH)) {
    		while (false !== ($file = readdir($handle))) {
    			if ($file != "." && $file != ".." && !is_dir($file) && file_exists(ABSPATH . $file)) {
    				$include_data[] = ABSPATH . $file;
    			}
    		}
    		closedir($handle);
    	}
    	
    	$disable_comp = $this->tasks[$task_name]['task_args']['disable_comp'];
    	
    	if ($disable_comp) {
    		$result = $zip->add($include_data, PCLZIP_OPT_REMOVE_PATH, ABSPATH, PCLZIP_OPT_NO_COMPRESSION) !== 0;
    	} else {
    		$result = $zip->add($include_data, PCLZIP_OPT_REMOVE_PATH, ABSPATH) !== 0;
    	}
    	
    	$exclude_data = array();
    	if (!empty($exclude)) {
    		foreach ($exclude as $data) {
    			if (file_exists(ABSPATH . $data)) {
                    if (is_dir(ABSPATH.$data)) {
                        $exclude_data[] = $data.'/';
                    } else {
                        $exclude_data[] = $data;
                    }
    			}
    		}
    	}
    	$result = $result && $zip->delete(PCLZIP_OPT_BY_NAME, $exclude_data);
    	
    	return $result;
    }
    
    /**
     * Gets an array of relative paths of all files in site root recursively.
     * By default, there are all files from root folder, all files from folders wp-admin, wp-content, wp-includes recursively.
     * Parameter $include adds other folders from site root, and excludes any file or folder by relative path to site's root.
     * 
     * @param 	array 	$exclude	array of files of folders to exclude, relative to site's root
     * @param 	array 	$include	array of folders from site root which are included to backup (wp-admin, wp-content, wp-includes are default)
     * @return 	array				array with all files in site root dir
     */
    function get_backup_files($exclude, $include) {
	
    	$add = array(
    		trim(WPINC),
    		trim(basename(WP_CONTENT_DIR)),
    		"wp-admin"
    	);
    	
    	$include = array_merge($add, $include);
        foreach ($include as &$value) {
            $value = rtrim($value, '/');
        }
		
	    $filelist = array();
	    if ($handle = opendir(ABSPATH)) {
	    	while (false !== ($file = readdir($handle))) {
                if ($file !== '..' && is_dir($file) && file_exists(ABSPATH.$file) && !(in_array($file, $include))) {
                    $exclude[] = $file;
                }
	    	}
	    	closedir($handle);
	    }
	    $exclude[] = 'error_log';
		
    	$filelist = get_all_files_from_dir(ABSPATH, $exclude);
    	
        if (!file_exists(ABSPATH.'wp-config.php')
            && file_exists(dirname(ABSPATH).'/wp-config.php')
            && !file_exists(dirname(ABSPATH).'/wp-settings.php')
        ) {
            $filelist[] = dirname(ABSPATH).'/wp-config.php';
        }

        $path = wp_upload_dir();
        $path = $path['path'];
        if (strpos($path, WP_CONTENT_DIR) === false && strpos($path, ABSPATH) === 0) {
            $mediaDir = ABSPATH.ltrim(substr($path, strlen(ABSPATH)), ' /');
            if (is_dir($mediaDir)) {
                $allMediaFiles = get_all_files_from_dir($mediaDir);
                $filelist      = array_merge($filelist, $allMediaFiles);
            }
        }		
		
    	return $filelist;
    }
    
    /**
     * Backup a database dump of WordPress site.
     * All backups are compressed by zip and placed in wp-content/cmscommander/backups folder.
     *
     * @param	string		$task_name			the name of backup task, which backup is done
     * @param	string		$backup_file		relative path to file which backup is stored
     * @return	bool|array						true if backup is successful, or an array with error message if is failed
     */
    function backup_db_compress($task_name, $backup_file) {
	
    	$this->update_status($task_name, $this->statuses['db_dump']);
    	$db_result = $this->backup_db();
    	
    	if ($db_result == false) {
    		return array(
    			'error' => 'Failed to backup database.'
    		);
        } else {
            if (is_array($db_result) && isset($db_result['error'])) {
                return array(
                    'error' => $db_result['error'],
                );
            }
        }
    	
    	$this->update_status($task_name, $this->statuses['db_dump'], true);
    	$this->update_status($task_name, $this->statuses['db_zip']);
    	@file_put_contents(CMSC_BACKUP_DIR.'/cmsc_db/index.php', '');
    	$zip_db_result = $this->zip_backup_db($task_name, $backup_file);
    	
    	if (!$zip_db_result) {
    		$zip_archive_db_result = false;
    		if (class_exists("ZipArchive")) {
    			$this->_log("DB zip, fallback to ZipArchive");
    			$zip_archive_db_result = $this->zip_archive_backup_db($task_name, $db_result, $backup_file);
    		}
    		
    		if (!$zip_archive_db_result) {
    			$this->_log("DB zip, fallback to PclZip");
    			$pclzip_db_result = $this->pclzip_backup_db($task_name, $backup_file);
    			if (!$pclzip_db_result) {
    				@unlink(CMSC_BACKUP_DIR.'/cmsc_db/index.php');
    				@unlink($db_result);
    				@rmdir(CMSC_DB_DIR);
    				
    				return array(
    					'error' => 'Failed to zip database. pclZip error (' . $archive->error_code . '): .' . $archive->error_string
    				);
    			}
    		}
    	}
    	
    	@unlink(CMSC_BACKUP_DIR.'/cmsc_db/index.php');
    	@unlink($db_result);
    	@rmdir(CMSC_DB_DIR);
    	
    	$this->update_status($task_name, $this->statuses['db_zip'], true);
    	
    	return true;
    }
    
    /**
     * Creates database dump and places it in cmsc_db folder in site's root.
     * This function dispatches if OS mysql command does not work calls a php alternative.
     *
     * @return	string|array	path to dump file if successful, or an array with error message if is failed
     */
    function backup_db() {
	
        $db_folder = CMSC_DB_DIR . '/';
        if (!file_exists($db_folder)) {
            if (!mkdir($db_folder, 0755, true)) {
                return array(
                    'error' => 'Error creating database backup folder ('.$db_folder.'). Make sure you have correct write permissions.',
                );
            }
        }
        
        $file   = $db_folder . DB_NAME . '.sql';
        $result = $this->backup_db_dump($file); // try mysqldump always then fallback to php dump
        return $result;
    }
    
    /**
     * Creates database dump by system mysql command.
     * 
     * @param 	string	$file	absolute path to file in which dump should be placed
     * @return	string|array	path to dump file if successful, or an array with error message if is failed
     */
    function backup_db_dump($file) {
        global $wpdb;
        $paths   = $this->check_mysql_paths();
        $brace   = (substr(PHP_OS, 0, 3) == 'WIN') ? '"' : '';
         //should use --result-file=file_name instead of >
        $host = '--host="';
        $hostname = '';
        $socketname = '';
        if(strpos(DB_HOST,':')!==false)
        {
			if(function_exists("split")) {
				$host_sock = split(':',DB_HOST);
			} else {
				$host_sock = explode(':',DB_HOST);	
			}
            $hostname = $host_sock[0];
            $socketname = $host_sock[1];
            $port = intval($host_sock[1]);
            if($port===0){
                $command = "%s --force --host=%s --socket=%s --user=%s --password=%s --add-drop-table --skip-lock-tables %s --result-file=%s";
                $command = sprintf($command, $paths['mysqldump'], escapeshellarg($hostname), escapeshellarg($socketname), escapeshellarg(DB_USER), escapeshellarg(DB_PASSWORD), escapeshellarg(DB_NAME),escapeshellarg($file));

            }
            else
            {
                $command = "%s --force --host=%s --port=%s --user=%s --password=%s --add-drop-table --skip-lock-tables %s --result-file=%s";
                $command = sprintf($command, $paths['mysqldump'], escapeshellarg($hostname),escapeshellarg($port), escapeshellarg(DB_USER), escapeshellarg(DB_PASSWORD), escapeshellarg(DB_NAME),escapeshellarg($file));

            }
            //$command = sprintf($command, $paths['mysqldump'], escapeshellarg($hostname), escapeshellarg($socketname), escapeshellarg(DB_USER), escapeshellarg(DB_PASSWORD), escapeshellarg(DB_NAME),escapeshellarg($file));
        }
        else
        {
            $hostname = DB_HOST;
            $command = "%s --force --host=%s --user=%s --password=%s --add-drop-table --skip-lock-tables %s --result-file=%s";
            $command = sprintf($command, $paths['mysqldump'], escapeshellarg($hostname), escapeshellarg(DB_USER), escapeshellarg(DB_PASSWORD), escapeshellarg(DB_NAME),escapeshellarg($file));
        }       
		
		//$command = $brace . $paths['mysqldump'] . $brace . ' --force --host="' . DB_HOST . '" --user="' . DB_USER . '" --password="' . DB_PASSWORD . '" --add-drop-table --skip-lock-tables "' . DB_NAME . '" > ' . $brace . $file . $brace;
        ob_start();
        $result = $this->cmsc_exec($command);
        ob_get_clean();
        
        if (!$result) { // Fallback to php
        	$this->_log("DB dump fallback to php");
            $result = $this->backup_db_php($file);
            return $result;
        }
        
        if (filesize($file) == 0 || !is_file($file) || !$result) {
            @unlink($file);
            return false;
        } else {
            return $file;
        }
    }
    
    /**
     * Creates database dump by php functions.
     *
     * @param 	string	$file	absolute path to file in which dump should be placed
     * @return	string|array	path to dump file if successful, or an array with error message if is failed
     */
	function backup_db_php($file) {
	
        global $wpdb;
        $tables = $wpdb->get_results('SHOW TABLES', ARRAY_N);
        foreach ($tables as $table) {
            //drop existing table
            $dump_data    = "DROP TABLE IF EXISTS $table[0];";
            file_put_contents($file, $dump_data, FILE_APPEND);
            //create table
            $create_table = $wpdb->get_row("SHOW CREATE TABLE $table[0]", ARRAY_N);
            $dump_data = "\n\n" . $create_table[1] . ";\n\n";
            file_put_contents($file, $dump_data, FILE_APPEND);
            
            $count = $wpdb->get_var("SELECT count(*) FROM $table[0]");
            if ($count > 100) {
                $count = ceil($count / 100);
            } else {
                if ($count > 0) {
                    $count = 1;
                }
            }              
            
            for ($i = 0; $i < $count; $i++) {
                $low_limit = $i * 100;
                $qry       = "SELECT * FROM $table[0] LIMIT $low_limit, 100";
                $rows      = $wpdb->get_results($qry, ARRAY_A);
                if (is_array($rows)) {
                    foreach ($rows as $row) {
                        //insert single row
                        $dump_data = "INSERT INTO $table[0] VALUES(";
                        $num_values = count($row);
                        $j          = 1;
                        foreach ($row as $value) {
                            $value = addslashes($value);
                            $value = preg_replace("/\n/Ui", "\\n", $value);
                            $num_values == $j ? $dump_data .= "'" . $value . "'" : $dump_data .= "'" . $value . "', ";
                            $j++;
                            unset($value);
                        }
                        $dump_data .= ");\n";
                        file_put_contents($file, $dump_data, FILE_APPEND);
                    }
                }
            }
            $dump_data = "\n\n\n";
            file_put_contents($file, $dump_data, FILE_APPEND);
            
            unset($rows);
            unset($dump_data);
        }
        
        if (filesize($file) == 0 || !is_file($file)) {
            @unlink($file);
			
            return array(
                'error' => 'Database backup failed. Try to enable MySQL dump on your server.'
            );
        }
        
        return $file;
    }
    
    /**
     * Restores full WordPress site or database only form backup zip file.
     * 
     * @param	array		array of arguments passed to backup restore
     * [task_name] -> name of backup task
     * [result_id] -> id of baskup task result, which should be restored
     * [google_drive_token] -> json of Google Drive token, if it is remote destination
     * @return	bool|array	true if successful, or an array with error message if is failed
     */
    function restore($args) {
	
        global $wpdb;
        if (empty($args)) {
            return false;
        }
        
        extract($args);
        if (isset($google_drive_token)) {
        	$this->tasks[$task_name]['task_args']['account_info']['cmsc_google_drive']['google_drive_token'] = $google_drive_token;
        }
        $this->set_memory();
		  
        $unlink_file = true; //Delete file after restore
        
        //Detect source
        if ($backup_url) {
            //This is for clone (overwrite)
            include_once ABSPATH . 'wp-admin/includes/file.php';
            $backup_file = download_url($backup_url);
            if (is_wp_error($backup_file)) {
                return array(
                    'error' => 'Unable to download backup file ('.$backup_file->get_error_message().')'
                );
            }
            $what = 'full';
        } else {
            $tasks = $this->tasks;
            $task  = $tasks[$task_name];
            if (isset($task['task_results'][$result_id]['server'])) {
                $backup_file = $task['task_results'][$result_id]['server']['file_path'];
                $unlink_file = false; //Don't delete file if stored on server
            } elseif (isset($task['task_results'][$result_id]['ftp'])) {
                $ftp_file            = $task['task_results'][$result_id]['ftp'];
                $args                = $task['task_args']['account_info']['cmsc_ftp'];
                $args['backup_file'] = $ftp_file;
                $backup_file         = $this->get_ftp_backup($args);
                
                if ($backup_file == false) {
                    return array(
                        'error' => 'Failed to download file from FTP.'
                    );
                }
            }elseif (isset($task['task_results'][$result_id]['sftp'])) {
                $ftp_file            = $task['task_results'][$result_id]['sftp'];
                $args                = $task['task_args']['account_info']['cmsc_sftp'];
                $args['backup_file'] = $ftp_file;
                $backup_file         = $this->get_sftp_backup($args);

                if ($backup_file == false) {
                    return array(
                        'error' => 'Failed to download file from SFTP.'
                    );
                }				
            } elseif (isset($task['task_results'][$result_id]['amazons3'])) {
                $amazons3_file       = $task['task_results'][$result_id]['amazons3'];
                $args                = $task['task_args']['account_info']['cmsc_amazon_s3'];
                $args['backup_file'] = $amazons3_file;
                $backup_file         = $this->get_amazons3_backup($args);
                
                if ($backup_file == false) {
                    return array(
                        'error' => 'Failed to download file from Amazon S3.'
                    );
                }
            } elseif(isset($task['task_results'][$result_id]['dropbox'])){
            	$dropbox_file        = $task['task_results'][$result_id]['dropbox'];
                $args                = $task['task_args']['account_info']['cmsc_dropbox'];
                $args['backup_file'] = $dropbox_file;
                $backup_file         = $this->get_dropbox_backup($args);
                
                if ($backup_file == false) {
                    return array(
                        'error' => 'Failed to download file from Dropbox.'
                    );
                }
            } elseif (isset($task['task_results'][$result_id]['google_drive'])) {
                if (is_array($task['task_results'][$result_id]['google_drive'])) {
                    $googleDriveFile   = $task['task_results'][$result_id]['google_drive']['file'];
                    $googleDriveFileId = $task['task_results'][$result_id]['google_drive']['file_id'];
                } else {
                    $googleDriveFile   = $task['task_results'][$result_id]['google_drive'];
                    $googleDriveFileId = "";
                }
                $args                = $task['task_args']['account_info']['cmsc_google_drive'];
                $args['backup_file'] = $googleDriveFile;
                $args['file_id']     = $googleDriveFileId;
                $backup_file         = $this->get_google_drive_backup($args);
                
                if (is_array($backup_file) && isset($backup_file['error'])) {
                	return array(
                		'error' => 'Failed to download file from Google Drive, reason: ' . $backup_file['error']
                	);
                } elseif ($backup_file == false) {
                    return array(
                        'error' => 'Failed to download file from Google Drive.'
                    );
                }
            }
            
            $what = $tasks[$task_name]['task_args']['what'];
        }
        
        $this->wpdb_reconnect();
        
        if ($backup_file && file_exists($backup_file)) {
            if ($overwrite) {
                //Keep old db credentials before overwrite
                if (!copy(ABSPATH . 'wp-config.php', ABSPATH . 'cmsc-temp-wp-config.php')) {
                    @unlink($backup_file);
                    return array(
                        'error' => 'Error creating wp-config file.
                                    Please check if your WordPress installation folder has correct permissions to allow  writing files.
                                    In most cases permissions should be 755 but occasionally it\'s required to put 777.
                                    If you are unsure on how to do this yourself, you can ask your hosting provider for help.'
                    );
                }
                
                $db_host     = DB_HOST;
                $db_user     = DB_USER;
                $db_password = DB_PASSWORD;
                $home        = rtrim(get_option('home'), "/");
                $site_url    = get_option('site_url');
                
                $clone_options                       = array();
                if (trim($clone_from_url) || trim($cmsc_clone)) {
                    $clone_options['_cmsc_nossl_key']  = get_option('_cmsc_nossl_key');
                    $clone_options['_cmsc_public_key'] = get_option('_cmsc_public_key');
                    $clone_options['_action_message_id'] = get_option('_action_message_id');
                }
                $clone_options['upload_path'] = get_option('upload_path');
                $clone_options['upload_url_path'] = get_option('upload_url_path');
                
                $clone_options['cmsc_backup_tasks'] = maybe_serialize(get_option('cmsc_backup_tasks'));
                $clone_options['cmsc_notifications'] = maybe_serialize(get_option('cmsc_notifications'));
                $clone_options['cmsc_pageview_alerts'] = maybe_serialize(get_option('cmsc_pageview_alerts'));
            } else {
            	$restore_options                       = array();
            	$restore_options['cmsc_notifications'] = get_option('cmsc_notifications');
            	$restore_options['cmsc_pageview_alerts'] = get_option('cmsc_pageview_alerts');
            	$restore_options['user_hit_count'] = get_option('user_hit_count');
                $restore_options['cmsc_backup_tasks'] = get_option('cmsc_backup_tasks');			}
            
            chdir(ABSPATH);
            $unzip   = $this->get_unzip();
            $command = "$unzip -o $backup_file";
            ob_start();
            $result = $this->cmsc_exec($command);
            ob_get_clean();
            
            if (!$result) { //fallback to pclzip
            	$this->_log("Files uznip fallback to pclZip");
                define('PCLZIP_TEMPORARY_DIR', CMSC_BACKUP_DIR . '/');
                require_once ABSPATH . '/wp-admin/includes/class-pclzip.php';
                $archive = new PclZip($backup_file);
                $result  = $archive->extract(PCLZIP_OPT_PATH, ABSPATH, PCLZIP_OPT_REPLACE_NEWER);
            }
            
            if ($unlink_file) {
                @unlink($backup_file);
            }
            
            if (!$result) {
                return array(
                    'error' => 'Failed to unzip files. pclZip error (' . $archive->error_code . '): .' . $archive->error_string
                );
            }
            
            $db_result = $this->restore_db(); 
            
            if (!$db_result) {
                return array(
                    'error' => 'Error restoring database.'
                );
            } else if(is_array($db_result) && isset($db_result['error'])){
            		return array(
                    'error' => $db_result['error']
                );
            }
            
        } else {
            return array(
                'error' => 'Error restoring. Cannot find backup file.'
            );
        }
        
        $this->wpdb_reconnect();
        
        //Replace options and content urls
        if ($overwrite) {
            //Get New Table prefix
            $new_table_prefix = trim($this->get_table_prefix());
            //Retrieve old wp_config
            @unlink(ABSPATH . 'wp-config.php');
            //Replace table prefix
            $lines = file(ABSPATH . 'cmsc-temp-wp-config.php');
            
            foreach ($lines as $line) {
                if (strstr($line, '$table_prefix')) {
                    $line = '$table_prefix = "' . $new_table_prefix . '";' . PHP_EOL;
                }
                file_put_contents(ABSPATH . 'wp-config.php', $line, FILE_APPEND);
            }
            
            @unlink(ABSPATH . 'cmsc-temp-wp-config.php');
            
            //Replace options
            $query = "SELECT option_value FROM " . $new_table_prefix . "options WHERE option_name = 'home'";
            $old   = $wpdb->get_var($query);
            $old   = rtrim($old, "/");
            $query = "UPDATE " . $new_table_prefix . "options SET option_value = %s WHERE option_name = 'home'";
            $wpdb->query($wpdb->prepare($query, $home));
            $query = "UPDATE " . $new_table_prefix . "options  SET option_value = %s WHERE option_name = 'siteurl'";
            $wpdb->query($wpdb->prepare($query, $home));
            //Replace content urls
            $regexp1 = 'src="(.*)'.$old.'(.*)"';
            $regexp2 = 'href="(.*)'.$old.'(.*)"';
            $query = "UPDATE " . $new_table_prefix . "posts SET post_content = REPLACE (post_content, %s,%s) WHERE post_content REGEXP %s OR post_content REGEXP %s";
            $wpdb->query($wpdb->prepare($query, array($old, $home, $regexp1, $regexp2)));

			$old2 = str_replace("http://", "http://www.", $old);
			$home2 = str_replace("http://", "http://www.", $home);
            $regexp1 = 'src="(.*)'.$old2.'(.*)"';
            $regexp2 = 'href="(.*)'.$old2.'(.*)"';			
            $wpdb->query($wpdb->prepare($query, array($old2, $home2, $regexp1, $regexp2)));
						
         //return array( 'error' => $wpdb->prepare($query, array($old2, $home2, $regexp1, $regexp2)) );		
// UPDATE wp_posts SET post_content = REPLACE (post_content, 'http://winaprizehome.com.au','http://allbusinesssoftware.com') WHERE post_content REGEXP 'src=\"(.*)http://winaprizehome.com.au(.*)\"' OR post_content REGEXP 'href=\"(.*)http://winaprizehome.com.au(.*)\"'		
			
            
            if (trim($new_password)) {
                $new_password = wp_hash_password($new_password);
            }
            if (!trim($clone_from_url) && !trim($cmsc_clone)) {
                if ($new_user && $new_password) {
                    $query = "UPDATE " . $new_table_prefix . "users SET user_login = %s, user_pass = %s WHERE user_login = %s";
                    $wpdb->query($wpdb->prepare($query, $new_user, $new_password, $old_user));
                }
            } else {
                if ($clone_from_url) {
                    if ($new_user && $new_password) {
                        $query = "UPDATE " . $new_table_prefix . "users SET user_pass = %s WHERE user_login = %s";
                        $wpdb->query($wpdb->prepare($query, $new_password, $new_user));
                    }
                }
                
                if ($cmsc_clone) {
                    if ($admin_email) {
                        //Clean Install
                        $query = "UPDATE " . $new_table_prefix . "options SET option_value = %s WHERE option_name = 'admin_email'";
                        $wpdb->query($wpdb->prepare($query, $admin_email));
                        $query     = "SELECT * FROM " . $new_table_prefix . "users LIMIT 1";
                        $temp_user = $wpdb->get_row($query);
                        if (!empty($temp_user)) {
                            $query = "UPDATE " . $new_table_prefix . "users SET user_email=%s, user_login = %s, user_pass = %s WHERE user_login = %s";
                            $wpdb->query($wpdb->prepare($query, $admin_email, $new_user, $new_password, $temp_user->user_login));
                        }
                        
                    }
                }
            }
            
            if (is_array($clone_options) && !empty($clone_options)) {
                foreach ($clone_options as $key => $option) {
                    if (!empty($key)) {
                        $query = "SELECT option_value FROM " . $new_table_prefix . "options WHERE option_name = %s";
                        $res   = $wpdb->get_var($wpdb->prepare($query, $key));
                        if ($res == false) {
                            $query = "INSERT INTO " . $new_table_prefix . "options  (option_value,option_name) VALUES(%s,%s)";
                            $wpdb->query($wpdb->prepare($query, $option, $key));
                        } else {
                            $query = "UPDATE " . $new_table_prefix . "options  SET option_value = %s WHERE option_name = %s";
                            $wpdb->query($wpdb->prepare($query, $option, $key));
                        }
                    }
                }
            }
			
			// CMSC ADDED
			if(!empty($or_blogname)) {
				$query = "UPDATE " . $new_table_prefix . "options  SET option_value = '$or_blogname' WHERE option_name = 'blogname'";
				$wpdb->query($wpdb->prepare($query));			
			}
 			if(!empty($or_blogdescription)) {
				$query = "UPDATE " . $new_table_prefix . "options  SET option_value = '$or_blogdescription' WHERE option_name = 'blogdescription'";
				$wpdb->query($wpdb->prepare($query));			
			}
			if(!empty($or_admin_email)) {
				$query = "UPDATE " . $new_table_prefix . "options  SET option_value = '$or_admin_email' WHERE option_name = 'admin_email'";
				$wpdb->query($wpdb->prepare($query));			
			}					
            
            //Remove hit count
            $query = "DELETE FROM " . $new_table_prefix . "options WHERE option_name = 'user_hit_count'";
           	$wpdb->query($query);
            
            //Restore previous backups
            $wpdb->query("UPDATE " . $new_table_prefix . "options SET option_value = ".serialize($current_tasks_tmp)." WHERE option_name = 'cmsc_backup_tasks'");	
			
            //Check for .htaccess permalinks update
            $this->replace_htaccess($home);
        } else {
        	//restore worker options
            if (is_array($restore_options) && !empty($restore_options)) {
                foreach ($restore_options as $key => $option) {
                    $result = $wpdb->update( $wpdb->options, array( 'option_value' => maybe_serialize($option) ), array( 'option_name' => $key ) );
                }
            }
        }
        
        return true;
    }
    
    /**
     * This function dispathces database restoring between mysql system command and php functions.
     * If system command fails, it calls the php alternative.
     *
     * @return	bool|array	true if successful, array with error message if not
     */
    function restore_db() {
        global $wpdb;
        $paths     = $this->check_mysql_paths();
        $file_path = ABSPATH . 'cmsc_db';
        @chmod($file_path,0755);
        $file_name = glob($file_path . '/*.sql');
        $file_name = $file_name[0];
        
        if(!$file_name){
        	return array('error' => 'Cannot access database file.');
        }
        
        $port = 0;
        $host = DB_HOST;

        if (strpos($host, ':') !== false){
            list($host, $port) = explode(':', $host);
        }
        $socket = false;

        if (strpos($host, '/') !== false || strpos($host, '\\') !== false) {
            $socket = true;
        }

        if ($socket) {
            $connection = sprintf('--socket=%s', escapeshellarg($host));
        } else {
            $connection = sprintf('--host=%s --port=%s', escapeshellarg($host), escapeshellarg($port));
        }

        $command = "%s %s --user=%s --password=%s --default-character-set=%s %s < %s";
        $command = sprintf($command, escapeshellarg($paths['mysql']), $connection, escapeshellarg(DB_USER), escapeshellarg(DB_PASSWORD), escapeshellarg('utf8'), escapeshellarg(DB_NAME), escapeshellarg($file_name));

        ob_start();
        $result = $this->cmsc_exec($command);
        ob_get_clean();
		
        if (!$result) {
		
			$brace     = (substr(PHP_OS, 0, 3) == 'WIN') ? '"' : '';
			$command   = $brace . $paths['mysql'] . $brace . ' --host="' . DB_HOST . '" --user="' . DB_USER . '" --password="' . DB_PASSWORD . '" --default-character-set="utf8" ' . DB_NAME . ' < ' . $brace . $file_name . $brace;

			ob_start();
			$result = $this->cmsc_exec($command);
			ob_get_clean();

			if (!$result) {
				//try php
				
				$this->_log('DB restore fallback to PHP');
				return $this->restore_db_php($file_name);
			}		

            //try php
            //return $this->restore_db_php($file_name);
        }
        
        @unlink($file_name);
        return true;
    }
    
    /**
     * Restores database dump by php functions.
     * 
     * @param 	string	$file_name	relative path to database dump, which should be restored
     * @return	bool				is successful or not
     */ 
    function restore_db_php($file_name) {
	
        global $wpdb;

        $current_query = '';
        // Read in entire file
//        $lines = file($file_name);
        $fp = @fopen($file_name, 'r');
        if (!$fp) {
            throw new Exception("Failed restoring database: could not open dump file ($file_name)");
        }
        while (!feof($fp)) {
            $line = fgets($fp);

            // Skip it if it's a comment
            if (substr($line, 0, 2) == '--' || $line == '') {
                continue;
            }

            // Add this line to the current query
            $current_query .= $line;
            // If it has a semicolon at the end, it's the end of the query
            if (substr(trim($line), -1, 1) == ';') {
                // Perform the query
                $trimmed = trim($current_query, " ;\n");
                if (!empty($trimmed)) {
                    $result = $wpdb->query($current_query);
                    if ($result === false) {
                        @fclose($fp);
                        @unlink($file_name);
                        throw new Exception("Error while restoring database on ($current_query) $wpdb->last_error");
                    }
                }
                // Reset temp variable to empty
                $current_query = '';
            }
        }
        @fclose($fp);
        @unlink($file_name);
    }
    
    /**
     * Retruns table_prefix for this WordPress installation.
     * It is used by restore.
     * 
     * @return 	string	table prefix from wp-config.php file, (default: wp_)
     */
    function get_table_prefix() {
	
        $lines = file(ABSPATH . 'wp-config.php');
        foreach ($lines as $line) {
            if (strstr($line, '$table_prefix')) {
                $pattern = "/(\'|\")[^(\'|\")]*/";
                preg_match($pattern, $line, $matches);
                $prefix = substr($matches[0], 1);
				
                return $prefix;
                break;
            }
        }
        return 'wp_'; //default
    }
    
    /**
     * Change all tables to InnoDB engine, and executes mysql OPTIMIZE TABLE for each table.
     * 
     * @return 	bool	optimized successfully or not
     */
    function optimize_tables() {
	
        global $wpdb;
        $query  = 'SHOW TABLE STATUS';
        $tables = $wpdb->get_results($query, ARRAY_A);
		$table_string = '';
        foreach ($tables as $table) {
            $table_string .= $table['Name'] . ",";
        }
        
        $table_string = rtrim($table_string, ",");
        $optimize     = $wpdb->query("OPTIMIZE TABLE $table_string");

        return (bool)$optimize;
    }
    
    /**
     * Returns mysql and mysql dump command path on OS.
     * 
     * @return 	array	array with system mysql and mysqldump command, blank if does not exist
     */
    function check_mysql_paths() {
        global $wpdb;
        $paths = array(
            'mysql' => '',
            'mysqldump' => ''
        );
        if (substr(PHP_OS, 0, 3) == 'WIN') {
            $mysql_install = $wpdb->get_row("SHOW VARIABLES LIKE 'basedir'");
            if ($mysql_install) {
                $install_path       = str_replace('\\', '/', $mysql_install->Value);
                $paths['mysql']     = $install_path . 'bin/mysql.exe';
                $paths['mysqldump'] = $install_path . 'bin/mysqldump.exe';
            } else {
                $paths['mysql']     = 'mysql.exe';
                $paths['mysqldump'] = 'mysqldump.exe';
            }
        } else {
            $paths['mysql'] = $this->cmsc_exec('which mysql', true);
            if (empty($paths['mysql']))
                $paths['mysql'] = 'mysql'; // try anyway
            
            $paths['mysqldump'] = $this->cmsc_exec('which mysqldump', true);
            if (empty($paths['mysqldump'])){
                $paths['mysqldump'] = 'mysqldump'; // try anyway
                $baseDir = $wpdb->get_var('select @@basedir');
                if ($baseDir) {
                    $paths['mysqldump'] = $baseDir.'/bin/mysqldump';
                }
            }       
		}
        
        return $paths;
    }
    
    /**
     * Check if exec, system, passthru functions exist
     * 
     * @return 	string|bool	exec if exists, then system, then passthru, then false if no one exist
     */
    function check_sys() {
        if ($this->cmsc_function_exists('exec'))
            return 'exec';
        
        if ($this->cmsc_function_exists('system'))
            return 'system';
        
        if ($this->cmsc_function_exists('passhtru'))
            return 'passthru';
        
        return false;
    }
    
    /**
     * Executes an external system command.
     * 
     * @param 	string 			$command	external command to execute
     * @param 	bool[optional] 	$string		return as a system output string (default: false)
     * @param 	bool[optional] 	$rawreturn	return as a status of executed command
     * @return 	bool|int|string				output depends on parameters $string and $rawreturn, -1 if no one execute function is enabled
     */
    function cmsc_exec($command, $string = false, $rawreturn = false) {
        if ($command == '')
            return false;
        
        if ($this->cmsc_function_exists('exec')) {
            $log = @exec($command, $output, $return);
            	$this->_log("Type: exec");
            	$this->_log("Command: ".$command);
            	$this->_log("Return: ".$return);
            if ($string)
                return $log;
            if ($rawreturn)
                return $return;
            
            return $return ? false : true;
        } elseif ($this->cmsc_function_exists('system')) {
            $log = @system($command, $return);
            	$this->_log("Type: system");
            	$this->_log("Command: ".$command);
            	$this->_log("Return: ".$return);
            if ($string)
                return $log;
            
            if ($rawreturn)
                return $return;
            
            return $return ? false : true;
        } elseif ($this->cmsc_function_exists('passthru') && !$string) {
            $log = passthru($command, $return);
            $this->_log("Type: passthru");
            $this->_log("Command: ".$command);
            	$this->_log("Return: ".$return);
            if ($rawreturn)
                return $return;
            
            return $return ? false : true;
        }
        
        if ($rawreturn)
        	return -1;
        
        return false;
    }
    
    /**
     * Returns a path to system command for zip execution.
     * 
     * @return	string	command for zip execution
     */
    function get_zip() {
        $zip = $this->cmsc_exec('which zip', true);
        if (!$zip)
            $zip = "zip";
        return $zip;
    }
    
    /**
     * Returns a path to system command for unzip execution.
     *
     * @return	string	command for unzip execution
     */
    function get_unzip() {
        $unzip = $this->cmsc_exec('which unzip', true);
        if (!$unzip)
            $unzip = "unzip";
        return $unzip;
    }
    
    /**
     * Returns all important information of worker's system status to master.
     * 
     * @return	mixed	associative array with information of server OS, php version, is backup folder writable, execute function, zip and unzip command, execution time, memory limit and path to error log if exists
     */
    function check_backup_compat() {
    	$reqs = array();
        if (strpos($_SERVER['DOCUMENT_ROOT'], '/') === 0) {
            $reqs['Server OS']['status'] = 'Linux (or compatible)';
            $reqs['Server OS']['pass']   = true;
        } else {
            $reqs['Server OS']['status'] = 'Windows';
            $reqs['Server OS']['pass']   = true;
            $pass                        = false;
        }
        $reqs['PHP Version']['status'] = phpversion();
        if ((float) phpversion() >= 5.1) {
            $reqs['PHP Version']['pass'] = true;
        } else {
            $reqs['PHP Version']['pass'] = false;
            $pass                        = false;
        }
        
        if (is_writable(WP_CONTENT_DIR)) {
            $reqs['Backup Folder']['status'] = "writable";
            $reqs['Backup Folder']['pass']   = true;
        } else {
            $reqs['Backup Folder']['status'] = "not writable";
            $reqs['Backup Folder']['pass']   = false;
        }
        
        $file_path = CMSC_BACKUP_DIR;
        $reqs['Backup Folder']['status'] .= ' (' . $file_path . ')';
        
        if ($func = $this->check_sys()) {
            $reqs['Execute Function']['status'] = $func;
            $reqs['Execute Function']['pass']   = true;
        } else {
            $reqs['Execute Function']['status'] = "not found";
            $reqs['Execute Function']['info']   = "(will try PHP replacement)";
            $reqs['Execute Function']['pass']   = false;
        }
        
        $reqs['Zip']['status'] = $this->get_zip();
        $reqs['Zip']['pass'] = true;
        $reqs['Unzip']['status'] = $this->get_unzip();
        $reqs['Unzip']['pass'] = true;
        
        $paths = $this->check_mysql_paths();
        
        if (!empty($paths['mysqldump'])) {
            $reqs['MySQL Dump']['status'] = $paths['mysqldump'];
            $reqs['MySQL Dump']['pass']   = true;
        } else {
            $reqs['MySQL Dump']['status'] = "not found";
            $reqs['MySQL Dump']['info']   = "(will try PHP replacement)";
            $reqs['MySQL Dump']['pass']   = false;
        }
        
        $exec_time                        = ini_get('max_execution_time');
        $reqs['Execution time']['status'] = $exec_time ? $exec_time . "s" : 'unknown';
        $reqs['Execution time']['pass']   = true;
        
        $mem_limit                      = ini_get('memory_limit');
        $reqs['Memory limit']['status'] = $mem_limit ? $mem_limit : 'unknown';
        $reqs['Memory limit']['pass']   = true;
        
        $changed = $this->set_memory();
        if($changed['execution_time']){
        	$exec_time                        = ini_get('max_execution_time');
        	$reqs['Execution time']['status'] .= $exec_time ? ' (will try '.$exec_time . 's)' : ' (unknown)';
        }
        if($changed['memory_limit']){
        	$mem_limit                      = ini_get('memory_limit');
        	$reqs['Memory limit']['status'] .= $mem_limit ? ' (will try '.$mem_limit.')' : ' (unknown)';
        }
        
        if(defined('CMSC_SHOW_LOG') && CMSC_SHOW_LOG == true){
	        $md5 = get_option('cmsc_log_md5');
	        if ($md5 !== false) {
	        	global $cmsc_plugin_url;
	        	$md5 = "<a href='$cmsc_plugin_url/log_$md5' target='_blank'>$md5</a>";
	        } else {
	        	$md5 = "not created";
	        }
	        $reqs['Backup Log']['status'] = $md5;
	        $reqs['Backup Log']['pass']   = true;
        }
        
        return $reqs;
    }
    
    /**
     * Uploads backup file from server to email.
     * A lot of email service have limitation to 10mb.
     * 
     * @param 	array 	$args	arguments passed to the function
     * [email] -> email address which backup should send to
     * [task_name] -> name of backup task
     * [file_path] -> absolute path of backup file on local server
     * @return 	bool|array		true is successful, array with error message if not
     */
    function email_backup($args) {
	
        $email = $args['email'];
        
        if (!is_email($email)) {
            return array(
                'error' => 'Your email (' . $email . ') is not correct'
            );
        }
        $backup_file = $args['file_path'];
        $task_name   = isset($args['task_name']) ? $args['task_name'] : '';
        if (file_exists($backup_file)) {
            $attachments = array(
                $backup_file
            );
            $headers     = 'From: CMSCommander <no-reply@cmscommander.com>' . "\r\n";
            $subject     = "CMSCommander - " . $task_name . " - " . $this->site_name;
            ob_start();
            $result = wp_mail($email, $subject, $subject, $headers, $attachments);
            ob_end_clean();
        } else {
            return array(
                'error' => 'The backup file ('.$backup_file.') does not exist.',
            );
        }
        
        if (!$result) {
            return array(
                'error' => 'Email not sent. Maybe your backup is too big for email or email server is not available on your website.'
            );
        }
        return true;
    }
	
    /**
     * Uploads backup file from server to remote sftp server.
     *
     * @param 	array 	$args	arguments passed to the function
     * [sftp_username] -> sftp username on remote server
     * [sftp_password] -> sftp password on remote server
     * [sftp_hostname] -> sftp hostname of remote host
     * [sftp_remote_folder] -> folder on remote site which backup file should be upload to
     * [sftp_site_folder] -> subfolder with site name in ftp_remote_folder which backup file should be upload to
     * [sftp_passive] -> passive mode or not
     * [sftp_ssl] -> ssl or not
     * [sftp_port] -> number of port for ssl protocol
     * [backup_file] -> absolute path of backup file on local server
     * @return 	bool|array		true is successful, array with error message if not
     */
    function sftp_backup($args) {
        extract($args);
     //   file_put_contents("sftp_log.txt","sftp_backup",FILE_APPEND);
        $port = $sftp_port ? $sftp_port : 22; //default port is 22
        //   file_put_contents("sftp_log.txt","sftp port:".$sftp_port,FILE_APPEND);
        $sftp_hostname = $sftp_hostname?$sftp_hostname:"";
        //    file_put_contents("sftp_log.txt","sftp host:".$sftp_hostname,FILE_APPEND);
        $sftp_username = $sftp_username?$sftp_username:"";
        //   file_put_contents("sftp_log.txt","sftp user:".$sftp_username,FILE_APPEND);
        $sftp_password = $sftp_password?$sftp_password:"";
        //     file_put_contents("sftp_log.txt","sftp pass:".$sftp_password,FILE_APPEND);
        //      file_put_contents("sftp_log.txt","Creating NetSFTP",FILE_APPEND);
        $sftp = new Net_SFTP($sftp_hostname,$port);
        //       file_put_contents("sftp_log.txt","Created NetSFTP",FILE_APPEND);
        $remote = $sftp_remote_folder ? trim($sftp_remote_folder,"/")."/" : '';
        if (!$sftp->login($sftp_username, $sftp_password)) {
                  file_put_contents("sftp_log.txt","sftp login failed in sftp_backup",FILE_APPEND);
            return array(
                'error' => 'SFTP login failed for ' . $sftp_username . ', ' . $sftp_password,
                'partial' => 1
            );
        }
        file_put_contents("sftp_log.txt","making remote dir",FILE_APPEND);
        $sftp->mkdir($remote);
        file_put_contents("sftp_log.txt","made remote dir",FILE_APPEND);
        if ($sftp_site_folder) {
            $remote .= '/' . $this->site_name;
        }
        $sftp->mkdir($remote);
        file_put_contents("sftp_log.txt","making {$sftp_remote_folder} dir",FILE_APPEND);
        $sftp->mkdir($sftp_remote_folder);
        file_put_contents("sftp_log.txt","made {$sftp_remote_folder} dir",FILE_APPEND);
        file_put_contents("sftp_log.txt","starting upload",FILE_APPEND);
        $upload = $sftp->put( $remote.'/' . basename($backup_file),$backup_file, NET_SFTP_LOCAL_FILE);
        file_put_contents("sftp_log.txt","finish upload",FILE_APPEND);
        $sftp->disconnect();

        if ($upload === false) {
            file_put_contents("sftp_log.txt","sftp upload failed",FILE_APPEND);
            return array(
                'error' => 'Failed to upload file to SFTP. Please check your specified path.',
                'partial' => 1
            );
        }

        return true;
    }	
    
    /**
     * Uploads backup file from server to remote ftp server.
     *
     * @param 	array 	$args	arguments passed to the function
     * [ftp_username] -> ftp username on remote server
     * [ftp_password] -> ftp password on remote server
     * [ftp_hostname] -> ftp hostname of remote host
     * [ftp_remote_folder] -> folder on remote site which backup file should be upload to
     * [ftp_site_folder] -> subfolder with site name in ftp_remote_folder which backup file should be upload to
     * [ftp_passive] -> passive mode or not
     * [ftp_ssl] -> ssl or not
     * [ftp_port] -> number of port for ssl protocol
     * [backup_file] -> absolute path of backup file on local server
     * @return 	bool|array		true is successful, array with error message if not
     */
    function ftp_backup($args) {
        extract($args);
        
        $port = $ftp_port ? $ftp_port : 21; //default port is 21
        if ($ftp_ssl) {
            if (function_exists('ftp_ssl_connect')) {
                $conn_id = ftp_ssl_connect($ftp_hostname,$port);
                if ($conn_id === false) {
                	return array(
                			'error' => 'Failed to connect to ' . $ftp_hostname,
                			'partial' => 1
                	);
                }
            } else {
                return array(
                    'error' => 'FTPS disabled: Please enable ftp_ssl_connect in PHP',
                    'partial' => 1
                );
            }
        } else {
            if (function_exists('ftp_connect')) {
                $conn_id = ftp_connect($ftp_hostname,$port);
                if ($conn_id === false) {
                    return array(
                        'error' => 'Failed to connect to ' . $ftp_hostname,
                        'partial' => 1
                    );
                }
            } else {
                return array(
                    'error' => 'FTP disabled: Please enable ftp_connect in PHP',
                    'partial' => 1
                );
            }
        }
        $login = @ftp_login($conn_id, $ftp_username, $ftp_password);
        if ($login === false) {
            return array(
                'error' => 'FTP login failed for ' . $ftp_username . ', ' . $ftp_password,
                'partial' => 1
            );
        }
        
        if($ftp_passive){
			@ftp_pasv($conn_id,true);
		}
			
        @ftp_mkdir($conn_id, $ftp_remote_folder);
        if ($ftp_site_folder) {
            $ftp_remote_folder .= '/' . $this->site_name;
        }
        @ftp_mkdir($conn_id, $ftp_remote_folder);
    	
        $upload = @ftp_put($conn_id, $ftp_remote_folder . '/' . basename($backup_file), $backup_file, FTP_BINARY);
        
        if ($upload === false) { //Try ascii
            $upload = @ftp_put($conn_id, $ftp_remote_folder . '/' . basename($backup_file), $backup_file, FTP_ASCII);
        }
        @ftp_close($conn_id);
        
        if ($upload === false) {
            return array(
                'error' => 'Failed to upload file to FTP. Please check your specified path.',
                'partial' => 1
            );
        }
        
        return true;
    }
    
    /**
     * Deletes backup file from remote ftp server.
     *
     * @param 	array 	$args	arguments passed to the function
     * [ftp_username] -> ftp username on remote server
     * [ftp_password] -> ftp password on remote server
     * [ftp_hostname] -> ftp hostname of remote host
     * [ftp_remote_folder] -> folder on remote site which backup file should be deleted from
     * [ftp_site_folder] -> subfolder with site name in ftp_remote_folder which backup file should be deleted from
     * [backup_file] -> absolute path of backup file on local server
     * @return 	void
     */
    function remove_ftp_backup($args) {
        extract($args);
        
        $port = $ftp_port ? $ftp_port : 21; //default port is 21
        if ($ftp_ssl && function_exists('ftp_ssl_connect')) {
            $conn_id = ftp_ssl_connect($ftp_hostname,$port);
        } else if (function_exists('ftp_connect')) {
            $conn_id = ftp_connect($ftp_hostname,$port);
        }
        
        if ($conn_id) {
            $login = @ftp_login($conn_id, $ftp_username, $ftp_password);
            if ($ftp_site_folder)
                $ftp_remote_folder .= '/' . $this->site_name;
            
            if($ftp_passive){
				@ftp_pasv($conn_id,true);
			}
					
            $delete = ftp_delete($conn_id, $ftp_remote_folder . '/' . $backup_file);
            
            ftp_close($conn_id);
        }
    }
	
    /**
     * Deletes backup file from remote sftp server.
     *
     * @param 	array 	$args	arguments passed to the function
     * [sftp_username] -> sftp username on remote server
     * [sftp_password] -> sftp password on remote server
     * [sftp_hostname] -> sftp hostname of remote host
     * [sftp_remote_folder] -> folder on remote site which backup file should be deleted from
     * [sftp_site_folder] -> subfolder with site name in ftp_remote_folder which backup file should be deleted from
     * [backup_file] -> absolute path of backup file on local server
     * @return 	void
     */
    function remove_sftp_backup($args) {
        extract($args);
        file_put_contents("sftp_log.txt","sftp remove_sftp_backup",FILE_APPEND);
        $port = $sftp_port ? $sftp_port : 22; //default port is 21
        $sftp_hostname = $sftp_hostname?$sftp_hostname:"";
        $sftp_username = $sftp_username?$sftp_username:"";
        $sftp_password = $sftp_password?$sftp_password:"";
        $sftp = new Net_SFTP($sftp_hostname);
        if (!$sftp->login($sftp_username, $sftp_password)) {
            file_put_contents("sftp_log.txt","sftp login failed in remove_sftp_backup",FILE_APPEND);
            return false;
        }
        $remote = $sftp_remote_folder ? trim($sftp_remote_folder,"/")."/" :'';
// copies filename.local to filename.remote on the SFTP server
        if(isset($backup_file) && isset($remote) && $backup_file!=="")
            $upload = $sftp->delete( $remote . '/' . $backup_file);
        $sftp->disconnect();
    }	
    
    /**
     * Downloads backup file from server from remote ftp server to root folder on local server.
     *
     * @param 	array 	$args	arguments passed to the function
     * [ftp_username] -> ftp username on remote server
     * [ftp_password] -> ftp password on remote server
     * [ftp_hostname] -> ftp hostname of remote host
     * [ftp_remote_folder] -> folder on remote site which backup file should be downloaded from
     * [ftp_site_folder] -> subfolder with site name in ftp_remote_folder which backup file should be downloaded from
     * [backup_file] -> absolute path of backup file on local server
     * @return 	string|array	absolute path to downloaded file is successful, array with error message if not
     */
    function get_ftp_backup($args) {
        extract($args);
        
        $port = $ftp_port ? $ftp_port : 21; //default port is 21
        if ($ftp_ssl && function_exists('ftp_ssl_connect')) {
            $conn_id = ftp_ssl_connect($ftp_hostname,$port);
          
        } else if (function_exists('ftp_connect')) {
            $conn_id = ftp_connect($ftp_hostname,$port);
            if ($conn_id === false) {
                return false;
            }
        } 
        $login = @ftp_login($conn_id, $ftp_username, $ftp_password);
        if ($login === false) {
            return false;
        }
        
        if ($ftp_site_folder)
            $ftp_remote_folder .= '/' . $this->site_name;
        
        if($ftp_passive){
			@ftp_pasv($conn_id,true);
		}
        
        $temp = ABSPATH . 'cmsc_temp_backup.zip';
        $get  = ftp_get($conn_id, $temp, $ftp_remote_folder . '/' . $backup_file, FTP_BINARY);
        if ($get === false) {
            return false;
        }
        
        ftp_close($conn_id);
        
        return $temp;
    }
	
    /**
     * Downloads backup file from server from remote ftp server to root folder on local server.
     *
     * @param 	array 	$args	arguments passed to the function
     * [ftp_username] -> ftp username on remote server
     * [ftp_password] -> ftp password on remote server
     * [ftp_hostname] -> ftp hostname of remote host
     * [ftp_remote_folder] -> folder on remote site which backup file should be downloaded from
     * [ftp_site_folder] -> subfolder with site name in ftp_remote_folder which backup file should be downloaded from
     * [backup_file] -> absolute path of backup file on local server
     * @return 	string|array	absolute path to downloaded file is successful, array with error message if not
     */
    function get_sftp_backup($args) {
        extract($args);
        file_put_contents("sftp_log.txt","get_sftp_backup",FILE_APPEND);

        $port = $sftp_port ? $sftp_port : 22; //default port is 21        $sftp_hostname = $sftp_hostname?$sftp_hostname:"";
        file_put_contents("sftp_log.txt","sftp port:".$sftp_port,FILE_APPEND);
        $sftp_username = $sftp_username?$sftp_username:"";
        $sftp_password = $sftp_password?$sftp_password:"";
        file_put_contents("sftp_log.txt","sftp host:".$sftp_hostname.";username:".$sftp_username.";password:".$sftp_password,FILE_APPEND);
        $sftp = new Net_SFTP($sftp_hostname);
        if (!$sftp->login($sftp_username, $sftp_password)) {
            file_put_contents("sftp_log.txt","sftp login failed in get_sftp_backup",FILE_APPEND);
            return false;
        }
        $remote = $sftp_remote_folder ? trim($sftp_remote_folder,"/")."/" : '';


        if ($ftp_site_folder)
            $remote .= '/' . $this->site_name;

        $temp = ABSPATH . 'cmsc_temp_backup.zip';
        $get = $sftp->get($remote . '/' . $backup_file,$temp);
        $sftp->disconnect();
        if ($get === false) {
            file_put_contents("sftp_log.txt","sftp get failed in get_sftp_backup",FILE_APPEND);
            return false;
        }

        return $temp;
    }	
    
    /**
     * Uploads backup file from server to Dropbox.
     *
     * @param 	array 	$args	arguments passed to the function
     * [consumer_key] -> consumer key of CMSCommander Dropbox application
     * [consumer_secret] -> consumer secret of CMSCommander Dropbox application
     * [oauth_token] -> oauth token of user on CMSCommander Dropbox application
     * [oauth_token_secret] -> oauth token secret of user on CMSCommander Dropbox application
     * [dropbox_destination] -> folder on user's Dropbox account which backup file should be upload to
     * [dropbox_site_folder] -> subfolder with site name in dropbox_destination which backup file should be upload to
     * [backup_file] -> absolute path of backup file on local server
     * @return 	bool|array		true is successful, array with error message if not
    */
	
 function dropbox_backup($args){
        //extract($args);
		
		$this->_log('IN DROPBOX FUNCTION');

		if(!empty($args['oauth2_token'])) {
			$this->_log('received OAUTH2 token');
		
			global $cmsc_plugin_dir;
			require_once $cmsc_plugin_dir . '/lib/Dropbox2/API.php';
			require_once $cmsc_plugin_dir . '/lib/Dropbox2/Exception.php';
			require_once $cmsc_plugin_dir . '/lib/Dropbox2/OAuth/Consumer/ConsumerAbstract.php';
			require_once $cmsc_plugin_dir . '/lib/Dropbox2/OAuth/Consumer/Curl.php';
			
			$oauth = new CMSC_Dropbox_OAuth_Consumer_Curl($args['consumer_key'], $args['consumer_secret']);
			
			$oauth->setToken($args['oauth2_token']);			

			$dropbox = new CMSC_Dropbox_API($oauth);
					
			/*$args['dropbox_destination'] = '/'.ltrim($args['dropbox_destination'], '/');
			if ($args['dropbox_site_folder'] == true) {
				$args['dropbox_destination'] .= '/'.$this->site_name.'/'.basename($args['backup_file']);
			} else {
				$args['dropbox_destination'] .= '/'.basename($args['backup_file']);
			}*/
		
			if(!empty($args['dropbox_destination'])) {$args['dropbox_destination'] = '/'.ltrim($args['dropbox_destination'], '/');}
			if ($args['dropbox_site_folder'] == true) {
				$args['dropbox_destination'] .= '/'.$this->site_name;
			} else {
				$args['dropbox_destination'] .= '/';
			}
			
			$this->_log('...starting Dropbox upload.');
			
			$this->_log("Dropbox destination: ".$args['dropbox_destination']);
			$this->_log("Dropbox bu file: ".$args['backup_file']);			
			
			try {
				$dropbox->putFile($args['backup_file'], $args['dropbox_destination'], true);
			} catch (Exception $e) {
				$this->_log("Dropbox error: ".$e->getMessage());
				return array(
					'error' => $e->getMessage(),
					'partial' => 1
				);
			}			
			return true;		
		} else { // OLD V1 BACKUP
			try {
				$dropbox = cmsc_dropbox_oauth_factory($args['consumer_key'], $args['consumer_secret'], $args['oauth_token'], $args['oauth_token_secret']);
			} catch (Exception $e) {

				return array(
					'error'   => $e->getMessage(),
					'partial' => 1,
				);
			}

			$args['dropbox_destination'] = '/'.ltrim($args['dropbox_destination'], '/');

			if ($args['dropbox_site_folder'] == true) {
				$args['dropbox_destination'] .= '/'.$this->site_name.'/'.basename($args['backup_file']);
			} else {
				$args['dropbox_destination'] .= '/'.basename($args['backup_file']);
			}

			$fileSize = filesize($args['backup_file']);
			$start    = microtime(true);

			try {
				$callback = null;

				$dropbox->uploadFile($args['dropbox_destination'], Dropbox_WriteMode::force(), fopen($args['backup_file'], 'r'), $fileSize, $callback);
			} catch (Exception $e) {

				return array(
					'error'   => $e->getMessage(),
					'partial' => 1,
				);
			}

			return true;		
		}    
    }
    
    /**
     * Deletes backup file from Dropbox to root folder on local server.
     *
     * @param 	array 	$args	arguments passed to the function
     * [consumer_key] -> consumer key of CMSCommander Dropbox application
     * [consumer_secret] -> consumer secret of CMSCommander Dropbox application
     * [oauth_token] -> oauth token of user on CMSCommander Dropbox application
     * [oauth_token_secret] -> oauth token secret of user on CMSCommander Dropbox application
     * [dropbox_destination] -> folder on user's Dropbox account which backup file should be downloaded from
     * [dropbox_site_folder] -> subfolder with site name in dropbox_destination which backup file should be downloaded from
     * [backup_file] -> absolute path of backup file on local server
     * @return 	void
     */
    public function remove_dropbox_backup($args) {

		if(!empty($args['oauth2_token'])) {

			$this->_log('...in v3 delete.');

			global $cmsc_plugin_dir;
			require_once $cmsc_plugin_dir . '/lib/Dropbox2/API.php';
			require_once $cmsc_plugin_dir . '/lib/Dropbox2/Exception.php';
			require_once $cmsc_plugin_dir . '/lib/Dropbox2/OAuth/Consumer/ConsumerAbstract.php';
			require_once $cmsc_plugin_dir . '/lib/Dropbox2/OAuth/Consumer/Curl.php';
			
			$oauth = new CMSC_Dropbox_OAuth_Consumer_Curl($args['consumer_key'], $args['consumer_secret']);
			
			$oauth->setToken($args['oauth2_token']);			

			$dropbox = new CMSC_Dropbox_API($oauth);	

			$this->_log('...initiated classes.');
		
			/*$args['dropbox_destination'] = '/'.ltrim($args['dropbox_destination'], '/');

			if ($args['dropbox_site_folder'] == true) {
				$args['dropbox_destination'] .= '/'.$this->site_name;
			}*/	
			
			if(!empty($args['dropbox_destination'])) {$args['dropbox_destination'] = '/'.ltrim($args['dropbox_destination'], '/');}
			if ($args['dropbox_site_folder'] == true) {
				$args['dropbox_destination'] .= '/'.$this->site_name;
			} else {
				$args['dropbox_destination'] .= '';
			}	
			
			$this->_log('..deleing file: '.$args['dropbox_destination'].'/'.$args['backup_file']);
			
			try {
				$dropbox->delete($args['dropbox_destination'].'/'.$args['backup_file']);
			} catch (Exception $e) {
				$this->_log('..deleing exceptione: '.$e->getMessage());
				return;
			}
			
		} else {
	
			try {
				$dropbox = cmsc_dropbox_oauth_factory($args['consumer_key'], $args['consumer_secret'], $args['oauth_token'], $args['oauth_token_secret']);
			} catch (Exception $e) {

				return;
			}

			$args['dropbox_destination'] = '/'.ltrim($args['dropbox_destination'], '/');

			if ($args['dropbox_site_folder'] == true) {
				$args['dropbox_destination'] .= '/'.$this->site_name;
			}

			try {
				$dropbox->delete($args['dropbox_destination'].'/'.$args['backup_file']);
			} catch (Exception $e) {

			}
		}	
    }
	
	/**
	 * Downloads backup file from Dropbox to root folder on local server.
	 *
	 * @param 	array 	$args	arguments passed to the function
	 * [consumer_key] -> consumer key of CMSCommander Dropbox application
	 * [consumer_secret] -> consumer secret of CMSCommander Dropbox application
	 * [oauth_token] -> oauth token of user on CMSCommander Dropbox application
	 * [oauth_token_secret] -> oauth token secret of user on CMSCommander Dropbox application
	 * [dropbox_destination] -> folder on user's Dropbox account which backup file should be deleted from
	 * [dropbox_site_folder] -> subfolder with site name in dropbox_destination which backup file should be deleted from
	 * [backup_file] -> absolute path of backup file on local server
	 * @return 	bool|array		absolute path to downloaded file is successful, array with error message if not
	 */
	function get_dropbox_backup($args) {
	
		if(!empty($args['oauth2_token'])) {

			global $cmsc_plugin_dir;
			require_once $cmsc_plugin_dir . '/lib/Dropbox2/API.php';
			require_once $cmsc_plugin_dir . '/lib/Dropbox2/Exception.php';
			require_once $cmsc_plugin_dir . '/lib/Dropbox2/OAuth/Consumer/ConsumerAbstract.php';
			require_once $cmsc_plugin_dir . '/lib/Dropbox2/OAuth/Consumer/Curl.php';
			
			$oauth = new CMSC_Dropbox_OAuth_Consumer_Curl($args['consumer_key'], $args['consumer_secret']);
			
			$oauth->setToken($args['oauth2_token']);			

			$dropbox = new CMSC_Dropbox_API($oauth);			
		
			$args['dropbox_destination'] = '/'.ltrim($args['dropbox_destination'], '/');

			if ($args['dropbox_site_folder'] == true) {
				$args['dropbox_destination'] .= '/'.$this->site_name;
			}

			$file = $args['dropbox_destination'].'/'.$args['backup_file'];
			$temp = ABSPATH.'cmsc_temp_backup.zip';		
		
			try {
				$fh = fopen($temp, 'wb');

				if (!$fh) {
					throw new RuntimeException(sprintf('Temporary file (%s) is not writable', $temp));
				}

				$dropbox->getFile($file, $fh);
				$result = fclose($fh);

				if (!$result) {
					throw new Exception('Unable to close file handle.');
				}
			} catch (Exception $e) {

				return array(
					'error'   => $e->getMessage(),
					'partial' => 1,
				);
			}

			return $temp;				
		
		} else {
			try {
				$dropbox = cmsc_dropbox_oauth_factory($args['consumer_key'], $args['consumer_secret'], $args['oauth_token'], $args['oauth_token_secret']);
			} catch (Exception $e) {

				return array(
					'error'   => $e->getMessage(),
					'partial' => 1,
				);
			}

			$args['dropbox_destination'] = '/'.ltrim($args['dropbox_destination'], '/');

			if ($args['dropbox_site_folder'] == true) {
				$args['dropbox_destination'] .= '/'.$this->site_name;
			}

			$file = $args['dropbox_destination'].'/'.$args['backup_file'];
			$temp = ABSPATH.'cmsc_temp_backup.zip';

			$start = microtime(true);
			try {
				$fh = fopen($temp, 'wb');

				if (!$fh) {
					throw new RuntimeException(sprintf('Temporary file (%s) is not writable', $temp));
				}

				$dropbox->getFile($file, $fh);
				$result = fclose($fh);

				if (!$result) {
					throw new Exception('Unable to close file handle.');
				}
			} catch (Exception $e) {

				return array(
					'error'   => $e->getMessage(),
					'partial' => 1,
				);
			}

			return $temp;		
		}
    }
	
	/**
	 * Uploads backup file from server to Amazon S3.
	 *
	 * @param 	array 	$args	arguments passed to the function
	 * [as3_bucket_region] -> Amazon S3 bucket region
	 * [as3_bucket] -> Amazon S3 bucket
	 * [as3_access_key] -> Amazon S3 access key
	 * [as3_secure_key] -> Amazon S3 secure key
	 * [as3_directory] -> folder on user's Amazon S3 account which backup file should be upload to
	 * [as3_site_folder] -> subfolder with site name in as3_directory which backup file should be upload to
	 * [backup_file] -> absolute path of backup file on local server
	 * @return 	bool|array		true is successful, array with error message if not
	 */
    function amazons3_backup($args) {
	
        if ($args['as3_site_folder'] == true) {
            $args['as3_directory'] .= '/'.$this->site_name;
        }
        $endpoint        = isset($args['as3_bucket_region']) ? $args['as3_bucket_region'] : 's3.amazonaws.com';
        $fileSize        = filesize($args['backup_file']);
        $start           = microtime(true);

        try {
            $s3 = new S3_Client(trim($args['as3_access_key']), trim(str_replace(' ', '+', $args['as3_secure_key'])), false, $endpoint);
            $s3->setExceptions(true);

            $s3->putObjectFile($args['backup_file'], $args['as3_bucket'], $args['as3_directory'].'/'.basename($args['backup_file']), S3_Client::ACL_PRIVATE);
        } catch (Exception $e) {

            return array(
                'error' => 'Failed to upload to Amazon S3 ('.$e->getMessage().').',
            );
        }

        return true;
    }
    
    /**
     * Deletes backup file from Amazon S3.
     *
     * @param 	array 	$args	arguments passed to the function
     * [as3_bucket_region] -> Amazon S3 bucket region
     * [as3_bucket] -> Amazon S3 bucket
     * [as3_access_key] -> Amazon S3 access key
     * [as3_secure_key] -> Amazon S3 secure key
     * [as3_directory] -> folder on user's Amazon S3 account which backup file should be deleted from
     * [as3_site_folder] -> subfolder with site name in as3_directory which backup file should be deleted from
     * [backup_file] -> absolute path of backup file on local server
     * @return 	void
     */
    function remove_amazons3_backup($args) {
        if ($args['as3_site_folder'] == true) {
            $args['as3_directory'] .= '/'.$this->site_name;
        }
        $endpoint = isset($args['as3_bucket_region']) ? $args['as3_bucket_region'] : 's3.amazonaws.com';

        try {
            $s3 = new S3_Client(trim($args['as3_access_key']), trim(str_replace(' ', '+', $args['as3_secure_key'])), false, $endpoint);
            $s3->setExceptions(true);
            $s3->deleteObject($args['as3_bucket'], $args['as3_directory'].'/'.$args['backup_file']);
        } catch (Exception $e) {
            // @todo what now?
        }
    }
    
    /**
     * Downloads backup file from Amazon S3 to root folder on local server.
     *
     * @param 	array 	$args	arguments passed to the function
     * [as3_bucket_region] -> Amazon S3 bucket region
     * [as3_bucket] -> Amazon S3 bucket
     * [as3_access_key] -> Amazon S3 access key
     * [as3_secure_key] -> Amazon S3 secure key
     * [as3_directory] -> folder on user's Amazon S3 account which backup file should be downloaded from
     * [as3_site_folder] -> subfolder with site name in as3_directory which backup file should be downloaded from
     * [backup_file] -> absolute path of backup file on local server
     * @return	bool|array		absolute path to downloaded file is successful, array with error message if not
     */
    function get_amazons3_backup($args) {
        $endpoint = isset($args['as3_bucket_region']) ? $args['as3_bucket_region'] : 's3.amazonaws.com';

        if ($args['as3_site_folder'] == true) {
            $args['as3_directory'] .= '/'.$this->site_name;
        }
        $start = microtime(true);
        try {
            $s3 = new S3_Client($args['as3_access_key'], str_replace(' ', '+', $args['as3_secure_key']), false, $endpoint);
            $s3->setExceptions(true);
            $temp = ABSPATH.'cmsc_temp_backup.zip';

            $s3->getObject($args['as3_bucket'], $args['as3_directory'].'/'.$args['backup_file'], $temp);
        } catch (Exception $e) {

            return array(
                'error' => 'Error while downloading the backup file from Amazon S3: '.$e->getMessage(),
            );
        }

        return $temp;
    }
    
    /**
     * Uploads backup file from server to Google Drive.
     *
     * @param 	array 	$args	arguments passed to the function
     * [google_drive_token] -> user's Google drive token in json form
     * [google_drive_directory] -> folder on user's Google Drive account which backup file should be upload to
     * [google_drive_site_folder] -> subfolder with site name in google_drive_directory which backup file should be upload to
     * [backup_file] -> absolute path of backup file on local server
     * @return 	bool|array		true is successful, array with error message if not
     */
    function google_drive_backup($args) {
        //cmsc_register_autoload_google();
		$this->_log("Google Drive ... in function");		
		// {"access_token":"ya29.dQDGsJtLyibsWCEAAAD7EKZKPhDnVZj04aJ732mDZUTKt3NliTTykd3pBhAVktM4LuFKCc_W6mFB0Yhu5OY","token_type":"Bearer","expires_in":3600,"refresh_token":"1\/FXLvop0keUEDDHJMhltZ5KvY7E0AzlQe3kh92Jk5U68","created":1409766937}
			
		/* new autoload */	
		global $cmsc_plugin_dir;
		$spl = spl_autoload_functions();
		if (is_array($spl)) {
			if (in_array('wpbgdc_autoloader', $spl)) spl_autoload_unregister('wpbgdc_autoloader');
			if (in_array('google_api_php_client_autoload', $spl)) spl_autoload_unregister('google_api_php_client_autoload');
		}

		if (!class_exists('Google_Config') || !class_exists('Google_Client') || !class_exists('Google_Service_Drive') || !class_exists('Google_Http_Request')) {
			require_once($cmsc_plugin_dir.'/lib/Google2/autoload.php'); 
		}

		if (!class_exists('CMSC_Google_Http_MediaFileUpload')) {
			require_once($cmsc_plugin_dir.'/lib/google-extensions.php'); 
			$this->_log("Google Drive ... loaded");
		}
		/* new autoload */	
	
		$config = new Google_Config();
		$config->setClassConfig('Google_IO_Abstract', 'request_timeout_seconds', 60);
		if (!function_exists('curl_version') || !function_exists('curl_exec')) {
			$config->setClassConfig('Google_Http_Request', 'disable_gzip', true);
		}	
	
        //$googleClient = new Google_ApiClient($config);
        $googleClient = new Google_Client($config);
        $googleClient->setAccessToken($args['google_drive_token']);
		$googleClient->setClientId($args['google_drive_client_id']);
		$googleClient->setClientSecret($args['google_drive_client_secret']);		
		
		// ADDED	
		$io = $googleClient->getIo();
		$this->_log("GD - initiate settings");				
		$setopts = array();

		if (is_a($io, 'Google_IO_Curl')) {
			$this->_log("GD - Google_IO_Curl");
			$setopts[CURLOPT_SSL_VERIFYPEER] = true;
			$setopts[CURLOPT_CAINFO] = $cmsc_plugin_dir.'/lib/cacert.pem';
			$setopts[CURLOPT_TIMEOUT] = 60;
			$setopts[CURLOPT_CONNECTTIMEOUT] = 15;
			//$setopts[CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V4;
		} elseif (is_a($io, 'Google_IO_Stream')) {
			$this->_log("GD - Google_IO_Stream");
			$setopts['timeout'] = 60;
			$setopts['cafile'] = $cmsc_plugin_dir.'/lib/cacert.pem';
			$setopts['disable_verify_peer'] = true;
		}

		$io->setOptions($setopts);
		// ADDED

        $googleDrive = new Google_Service_Drive($googleClient);

        try {
            $about        = $googleDrive->about->get();
            $rootFolderId = $about->getRootFolderId();
        } catch (Exception $e) {

            return array(
                'error' => 'Error while fetching Google Drive root folder ID: '.$e->getMessage(),
            );
        }

        try {
            $rootFiles = $googleDrive->files->listFiles(array("q" => "title='".addslashes($args['google_drive_directory'])."' and '$rootFolderId' in parents and trashed = false"));
        } catch (Exception $e) {

            return array(
                'error' => 'Error while loading Google Drive backup directory: '.$e->getMessage(),
            );
        }	

        if ($rootFiles->offsetExists(0)) {
            $backupFolder = $rootFiles->offsetGet(0);
        } else {
            try {
                $newBackupFolder = new Google_Service_Drive_DriveFile();
                $newBackupFolder->setTitle($args['google_drive_directory']);
                $newBackupFolder->setMimeType('application/vnd.google-apps.folder');

                if ($rootFolderId) {
                    $parent = new Google_Service_Drive_ParentReference();
                    $parent->setId($rootFolderId);
                    $newBackupFolder->setParents(array($parent));
                }

                $backupFolder = $googleDrive->files->insert($newBackupFolder);
            } catch (Exception $e) {

                return array(
                    'error' => 'Error while creating Google Drive backup directory: '.$e->getMessage(),
                );
            }
        }
 
        if ($args['google_drive_site_folder']) {		
		
            try {
                $siteFolderTitle = $this->site_name;
                $backupFolderId  = $backupFolder->getId();
                $driveFiles      = $googleDrive->files->listFiles(array("q" => "title='".addslashes($siteFolderTitle)."' and '$backupFolderId' in parents and trashed = false"));
            } catch (Exception $e) {

                return array(
                    'error' => 'Error while fetching Google Drive site directory: '.$e->getMessage(),
                );
            }

            if ($driveFiles->offsetExists(0)) {
                $siteFolder = $driveFiles->offsetGet(0);
            } else {
                try {
                    $_backup_folder = new Google_Service_Drive_DriveFile();
                    $_backup_folder->setTitle($siteFolderTitle);
                    $_backup_folder->setMimeType('application/vnd.google-apps.folder');

                    if (isset($backupFolder)) {
                        $_backup_folder->setParents(array($backupFolder));
                    }

                    $siteFolder = $googleDrive->files->insert($_backup_folder, array());
                } catch (Exception $e) {

                    return array(
                        'error' => 'Error while creating Google Drive site directory: '.$e->getMessage(),
                    );
                }
            }
        } else {
            $siteFolder = $backupFolder;
        }		

        $file_path  = explode('/', $args['backup_file']);
        $backupFile = new Google_Service_Drive_DriveFile();
        $backupFile->setTitle(end($file_path));
        $backupFile->setDescription('Backup file of site: '.$this->site_name.'.');

        if ($siteFolder != null) {
            $backupFile->setParents(array($siteFolder));
        }
        $googleClient->setDefer(true);
        // Deferred client returns request object.
        /** @var Google_Http_Request $request */
        $request   = $googleDrive->files->insert($backupFile);
        $chunkSize = 1024 * 1024 * 4;

        $media    = new Google_Http_MediaFileUpload($googleClient, $request, 'application/zip', null, true, $chunkSize);
        $fileSize = filesize($args['backup_file']);
        $media->setFileSize($fileSize);

		$this->_log("Google Drive ... size:". $fileSize);
		
        // Upload the various chunks. $status will be false until the process is
        // complete.
        $status           = false;
        $handle           = fopen($args['backup_file'], 'rb');
        $started          = microtime(true);
        $lastNotification = $started;
        $lastProgress     = 0;
        $threshold        = 1;
        $uploaded         = 0;
        $started          = microtime(true);
        while (!$status && !feof($handle)) {
            $chunk        = fread($handle, $chunkSize);
            $newChunkSize = strlen($chunk);

            if (($elapsed = microtime(true) - $lastNotification) > $threshold) {
                $lastNotification = microtime(true);

                $lastProgress = $uploaded;
                echo ".";
                flush();
            }
            $uploaded += $newChunkSize;
            $status = $media->nextChunk($chunk);
        }
        fclose($handle);
		
		$this->_log("Google Drive ... finish");

        if (!$status instanceof Google_Service_Drive_DriveFile) {

            return array(
                'error' => 'Upload to Google Drive was not successful.',
            );
        }
        $this->tasks[$args['task_name']]['task_results'][$args['task_result_key']]['google_drive']['file_id'] = $status->getId();		
			
        return true;
    }
    
    /**
     * Deletes backup file from Google Drive.
     *
     * @param 	array 	$args	arguments passed to the function
     * [google_drive_token] -> user's Google drive token in json form
     * [google_drive_directory] -> folder on user's Google Drive account which backup file should be deleted from
     * [google_drive_site_folder] -> subfolder with site name in google_drive_directory which backup file should be deleted from
     * [backup_file] -> absolute path of backup file on local server
     * @return	void
     */
    function remove_google_drive_backup($args) {
        //cmsc_register_autoload_google();

		/* new autoload */	
		global $cmsc_plugin_dir;
		$spl = spl_autoload_functions();
		if (is_array($spl)) {
			if (in_array('wpbgdc_autoloader', $spl)) spl_autoload_unregister('wpbgdc_autoloader');
			if (in_array('google_api_php_client_autoload', $spl)) spl_autoload_unregister('google_api_php_client_autoload');
		}

		if (!class_exists('Google_Config') || !class_exists('Google_Client') || !class_exists('Google_Service_Drive') || !class_exists('Google_Http_Request')) {
			require_once($cmsc_plugin_dir.'/lib/Google2/autoload.php'); 
		}

		if (!class_exists('CMSC_Google_Http_MediaFileUpload')) {
			require_once($cmsc_plugin_dir.'/lib/google-extensions.php'); 
			$this->_log("Google Drive ... loaded");
		}
		/* new autoload */			
		
        try {
            $googleClient = new Google_Client();
            $googleClient->setAccessToken($args['google_drive_token']);
			$googleClient->setClientId($args['google_drive_client_id']);
			$googleClient->setClientSecret($args['google_drive_client_secret']);					
        } catch (Exception $e) {

            return;
        }

        $driveService = new Google_Service_Drive($googleClient);

        if (!empty($args['file_id'])) {
            try {
                $driveService->files->delete($args['file_id']);
            } catch (Exception $e) {
            }

            return;
        }
        try {
            $about          = $driveService->about->get();
            $root_folder_id = $about->getRootFolderId();
        } catch (Exception $e) {

            return;
        }

        try {
            $listFiles = $driveService->files->listFiles(array("q" => "title='".addslashes($args['google_drive_directory'])."' and '$root_folder_id' in parents and trashed = false"));
            /** @var Google_Service_Drive_DriveFile[] $files */
            $files = $listFiles->getItems();
        } catch (Exception $e) {

            return;
        }
        if (isset($files[0])) {
            $managewpFolder = $files[0];
        } else {
            return;
        }

        if ($args['google_drive_site_folder']) {
            try {
                $subFolderTitle   = $this->site_name;
                $managewpFolderId = $managewpFolder->getId();
                $listFiles        = $driveService->files->listFiles(array("q" => "title='".addslashes($subFolderTitle)."' and '$managewpFolderId' in parents and trashed = false"));
                $files            = $listFiles->getItems();
            } catch (Exception $e) {
                /*return array(
                    'error' => $e->getMessage(),
                );*/
            }
            if (isset($files[0])) {
                $backup_folder = $files[0];
            }
        } else {
            /** @var Google_Service_Drive_DriveFile $backup_folder */
            $backup_folder = $managewpFolder;
        }

        if (isset($backup_folder)) {
            try {
                $backup_folder_id = $backup_folder->getId();
                $listFiles        = $driveService->files->listFiles(array("q" => "title='".addslashes($args['backup_file'])."' and '$backup_folder_id' in parents and trashed = false"));
                $files            = $listFiles->getItems();
            } catch (Exception $e) {
                /*return array(
                    'error' => $e->getMessage(),
                );*/
            }
            if (isset($files[0])) {
                try {
                    $driveService->files->delete($files[0]->getId());
                } catch (Exception $e) {
                }
            } else {
            }
        } else {
        }
    }
    
    /**
     * Downloads backup file from Google Drive to root folder on local server.
     *
     * @param 	array 	$args	arguments passed to the function
     * [google_drive_token] -> user's Google drive token in json form
     * [google_drive_directory] -> folder on user's Google Drive account which backup file should be downloaded from
     * [google_drive_site_folder] -> subfolder with site name in google_drive_directory which backup file should be downloaded from
     * [backup_file] -> absolute path of backup file on local server
     * @return	bool|array		absolute path to downloaded file is successful, array with error message if not
     */
    function get_google_drive_backup($args) {
        //cmsc_register_autoload_google();
		
		/* new autoload */	
		global $cmsc_plugin_dir;
		$spl = spl_autoload_functions();
		if (is_array($spl)) {
			if (in_array('wpbgdc_autoloader', $spl)) spl_autoload_unregister('wpbgdc_autoloader');
			if (in_array('google_api_php_client_autoload', $spl)) spl_autoload_unregister('google_api_php_client_autoload');
		}

		if (!class_exists('Google_Config') || !class_exists('Google_Client') || !class_exists('Google_Service_Drive') || !class_exists('Google_Http_Request')) {
			require_once($cmsc_plugin_dir.'/lib/Google2/autoload.php'); 
		}

		if (!class_exists('CMSC_Google_Http_MediaFileUpload')) {
			require_once($cmsc_plugin_dir.'/lib/google-extensions.php'); 
			$this->_log("Google Drive ... loaded");
		}
		/* new autoload */			
		
        $googleClient = new Google_Client();
        $googleClient->setAccessToken($args['google_drive_token']);		
		$googleClient->setClientId($args['google_drive_client_id']);
		$googleClient->setClientSecret($args['google_drive_client_secret']);		
		
        $driveService = new Google_Service_Drive($googleClient);

        try {
            $about        = $driveService->about->get();
            $rootFolderId = $about->getRootFolderId();
        } catch (Exception $e) {

            return array(
                'error' => 'Error while connecting to Google Drive: '.$e->getMessage(),
            );
        }
        if (empty($args['file_id'])) {
            try {
                $backupFolderFiles = $driveService->files->listFiles(array(
                    'q' => sprintf("title='%s' and '%s' in parents and trashed = false", addslashes($args['google_drive_directory']), $rootFolderId),
                ));
            } catch (Exception $e) {

                return array(
                    'error' => 'Error while looking for backup directory: '.$e->getMessage(),
                );
            }

            if (!$backupFolderFiles->offsetExists(0)) {

                return array(
                    'error' => sprintf("The backup directory (%s) does not exist.", $args['google_drive_directory']),
                );
            }

            /** @var Google_Service_Drive_DriveFile $backupFolder */
            $backupFolder = $backupFolderFiles->offsetGet(0);

            if ($args['google_drive_site_folder']) {
                try {
                    $siteFolderFiles = $driveService->files->listFiles(array(
                        'q' => sprintf("title='%s' and '%s' in parents and trashed = false", addslashes($this->site_name), $backupFolder->getId()),
                    ));
                } catch (Exception $e) {

                    return array(
                        'error' => 'Error while looking for the site folder: '.$e->getMessage(),
                    );
                }

                if ($siteFolderFiles->offsetExists(0)) {
                    $backupFolder = $siteFolderFiles->offsetGet(0);
                }
            }

            try {
                $backupFiles = $driveService->files->listFiles(array(
                    'q' => sprintf("title='%s' and '%s' in parents and trashed = false", addslashes($args['backup_file']), $backupFolder->getId()),
                ));
            } catch (Exception $e) {

                return array(
                    'error' => 'Error while fetching Google Drive backup file: '.$e->getMessage(),
                );
            }

            if (!$backupFiles->offsetExists(0)) {
                return array(
                    'error' => sprintf('Backup file "%s" was not found on your Google Drive account.', $args['backup_file']),
                );
            }
            /** @var Google_Service_Drive_DriveFile $backupFile */
            $backupFile = $backupFiles->offsetGet(0);
        } else {
            try {
                /** @var Google_Service_Drive_DriveFile $backupFile */
                $backupFile = $driveService->files->get($args['file_id']);
            } catch (Exception $e) {

                return array(
                    'error' => 'Error while fetching Google Drive backup file: '.$e->getMessage(),
                );
            }
        }

        $downloadUrl      = $backupFile->getDownloadUrl();
        $downloadLocation = ABSPATH.'mwp_temp_backup.zip';
        $fileSize         = $backupFile->getFileSize();
        $downloaded       = 0;
        $chunkSize        = 1024 * 1024 * 4;
        $fh               = fopen($downloadLocation, 'w+');

        if (!is_resource($fh)) {
            return array(
                'error' => 'Temporary backup download location is not writable (location: "%s").',
                $downloadLocation,
            );
        }
        while ($downloaded < $fileSize) {
            $request = new Google_Http_Request($downloadUrl);
            $googleClient->getAuth()->sign($request);
            $toDownload = min($chunkSize, $fileSize - $downloaded);

            $request->setRequestHeaders($request->getRequestHeaders() + array('Range' => 'bytes='.$downloaded.'-'.($downloaded + $toDownload - 1)));
            $googleClient->getIo()->makeRequest($request);
            if ($request->getResponseHttpCode() !== 206) {

                return array(
                    'error' => sprintf('Google Drive service has returned an invalid response code (%s)', $request->getResponseHttpCode()),
                );
            }
            fwrite($fh, $request->getResponseBody());
            $downloaded += $toDownload;
        }
        fclose($fh);

        $fileMd5 = md5_file($downloadLocation);
        if ($backupFile->getMd5Checksum() !== $fileMd5) {

            return array(
                'error' => 'File downloaded was corrupted.',
            );
        }

        return $downloadLocation;
    }
    
    /**
     * Schedules the next execution of some backup task.
     * 
     * @param 	string 	$type		daily, weekly or monthly
     * @param 	string 	$schedule	format: task_time (if daily), task_time|task_day (if weekly), task_time|task_date (if monthly)
     * @return 	bool|int			timestamp if sucessful, false if not
     */
	function schedule_next($type, $schedule) {
        $schedule = explode("|", $schedule);
		
		if (empty($schedule))
            return false;
        switch ($type) {
            case 'daily':
                if (isset($schedule[1]) && $schedule[1]) {
                    $delay_time = $schedule[1] * 60;
                }
                
                $current_hour  = date("H");
                $schedule_hour = $schedule[0];
                if ($current_hour >= $schedule_hour)
                    $time = mktime($schedule_hour, 0, 0, date("m"), date("d") + 1, date("Y"));
                else
                    $time = mktime($schedule_hour, 0, 0, date("m"), date("d"), date("Y"));
                break;
            
            case 'weekly':
                if (isset($schedule[2]) && $schedule[2]) {
                    $delay_time = $schedule[2] * 60;
                }
                $current_weekday  = date('w');
                $schedule_weekday = $schedule[1];
                $current_hour     = date("H");
                $schedule_hour    = $schedule[0];
                
                if ($current_weekday > $schedule_weekday)
                    $weekday_offset = 7 - ($week_day - $task_schedule[1]);
                else
                    $weekday_offset = $schedule_weekday - $current_weekday;
                
                if (!$weekday_offset) { //today is scheduled weekday
                    if ($current_hour >= $schedule_hour)
                        $time = mktime($schedule_hour, 0, 0, date("m"), date("d") + 7, date("Y"));
                    else
                        $time = mktime($schedule_hour, 0, 0, date("m"), date("d"), date("Y"));
                } else {
                    $time = mktime($schedule_hour, 0, 0, date("m"), date("d") + $weekday_offset, date("Y"));
                }
                break;
            	
            case 'monthly':
                if (isset($schedule[2]) && $schedule[2]) {
                    $delay_time = $schedule[2] * 60;
                }
                $current_monthday  = date('j');
                $schedule_monthday = $schedule[1];
                $current_hour      = date("H");
                $schedule_hour     = $schedule[0];
                
                if ($current_monthday > $schedule_monthday) {
                    $time = mktime($schedule_hour, 0, 0, date("m") + 1, $schedule_monthday, date("Y"));
                } else if ($current_monthday < $schedule_monthday) {
                    $time = mktime($schedule_hour, 0, 0, date("m"), $schedule_monthday, date("Y"));
                } else if ($current_monthday == $schedule_monthday) {
                    if ($current_hour >= $schedule_hour)
                        $time = mktime($schedule_hour, 0, 0, date("m") + 1, $schedule_monthday, date("Y"));
                    else
                        $time = mktime($schedule_hour, 0, 0, date("m"), $schedule_monthday, date("Y"));
                    break;
                }
                
                break;
            
            default:
                break;
        }
        
        if (isset($delay_time) && $delay_time) {
            $time += $delay_time;
        }
        
        return $time;
    }
    
    /**
     * Parse task arguments for info on master.
     * 
     * @return mixed	associative array with stats for every backup task or error if backup is manually deleted on server
     */
    function get_backup_stats() {
        $stats = array();
        $tasks = $this->tasks;
		
        if (is_array($tasks) && !empty($tasks)) {
            foreach ($tasks as $task_name => $info) {
                if (is_array($info['task_results']) && !empty($info['task_results'])) {
                    foreach ($info['task_results'] as $key => $result) {
                        if (isset($result['server']) && !isset($result['error'])) {
                            if (isset($result['server']['file_path']) && !$info['task_args']['del_host_file']) {
	                        	if (!file_exists($result['server']['file_path'])) {
	                                $info['task_results'][$key]['error'] = 'Backup created but manually removed from server.';
	                            }
                            }
                        }
                    }
					//$stats[$task_name] = array_values($info['task_results']);
					$stats[$task_name] = $info['task_results'];
                }
            }
        }
        return $stats;
    }
    
    /**
     * Returns all backup tasks with information when the next schedule will be.
     * 
     * @return	mixed	associative array with timestamp with next schedule for every backup task
     */
    function get_next_schedules() {
        $stats = array();
        $tasks = $this->tasks;
        if (is_array($tasks) && !empty($tasks)) {
            foreach ($tasks as $task_name => $info) {
                $stats[$task_name] = isset($info['task_args']['next']) ? $info['task_args']['next'] : array();
            }
        }
        return $stats;
    }
    
    /**
     * Deletes all old backups from local server.
     * It depends on configuration on master (Number of backups to keep).
     * 
     * @param	string 	$task_name	name of backup task
     * @return	bool|void			true if there are backups for deletion, void if not
     */
    function remove_old_backups($task_name) {
	
        //Check for previous failed backups first
        $this->cleanup();
        
        //Remove by limit
        $backups = $this->tasks;
        if ($task_name == 'Backup Now') {
            $num = 0;
        } else {
            $num = 1;
        }
		
		$this->_log("- in remove old backups function...");		
		
		if(is_array($backups[$task_name]['task_results'])) {
			$tcount = count($backups[$task_name]['task_results']);
		} else {
			$tcount = 1;
		}			
		
        if (($tcount - $num) >= $backups[$task_name]['task_args']['limit']) {
            //how many to remove ?
            $remove_num = ($tcount - $num - $backups[$task_name]['task_args']['limit']) + 1;
            for ($i = 0; $i < $remove_num; $i++) {
                //Remove from the server
                if (isset($backups[$task_name]['task_results'][$i]['server'])) {
                    @unlink($backups[$task_name]['task_results'][$i]['server']['file_path']);
                }
                
                //Remove from ftp
                if (isset($backups[$task_name]['task_results'][$i]['ftp']) && isset($backups[$task_name]['task_args']['account_info']['cmsc_ftp'])) {
                    $ftp_file            = $backups[$task_name]['task_results'][$i]['ftp'];
                    $args                = $backups[$task_name]['task_args']['account_info']['cmsc_ftp'];
                    $args['backup_file'] = $ftp_file;
                    $this->remove_ftp_backup($args);
                }
                if (isset($backups[$task_name]['task_results'][$i]['sftp']) && isset($backups[$task_name]['task_args']['account_info']['cmsc_sftp'])) {
                    $sftp_file            = $backups[$task_name]['task_results'][$i]['fstp'];
                    $args                = $backups[$task_name]['task_args']['account_info']['cmsc_sftp'];
                    $args['backup_file'] = $sftp_file;
                    $this->remove_sftp_backup($args);
                }				
                
                if (isset($backups[$task_name]['task_results'][$i]['amazons3']) && isset($backups[$task_name]['task_args']['account_info']['cmsc_amazon_s3'])) {
                    $amazons3_file       = $backups[$task_name]['task_results'][$i]['amazons3'];
                    $args                = $backups[$task_name]['task_args']['account_info']['cmsc_amazon_s3'];
                    $args['backup_file'] = $amazons3_file;
                    $this->remove_amazons3_backup($args);
                }
                
                if (isset($backups[$task_name]['task_results'][$i]['dropbox']) && isset($backups[$task_name]['task_args']['account_info']['cmsc_dropbox'])) {
                    //To do: dropbox remove
                    $dropbox_file        = $backups[$task_name]['task_results'][$i]['dropbox'];
                    $args                = $backups[$task_name]['task_args']['account_info']['cmsc_dropbox'];
                    $args['backup_file'] = $dropbox_file;
		
                	$this->remove_dropbox_backup($args);
                }
                
                if (isset($backups[$task_name]['task_results'][$i]['google_drive']) && isset($backups[$task_name]['task_args']['account_info']['cmsc_google_drive'])) {
                    if (is_array($backups[$task_name]['task_results'][$i]['google_drive'])) {
                        $google_drive_file = $backups[$task_name]['task_results'][$i]['google_drive']['file'];
                        $google_file_id    = $backups[$task_name]['task_results'][$i]['google_drive']['file_id'];
                    } else {
                        $google_drive_file = $backups[$task_name]['task_results'][$i]['google_drive'];
                        $google_file_id    = "";
                    }
                    $args                = $backups[$task_name]['task_args']['account_info']['cmsc_google_drive'];
                    $args['backup_file'] = $google_drive_file;
                    $args['file_id']     = $google_file_id;
                    $this->remove_google_drive_backup($args);
                }
                
                //Remove database backup info
                unset($backups[$task_name]['task_results'][$i]);
            } //end foreach
            
            if (is_array($backups[$task_name]['task_results'])) {
                $backups[$task_name]['task_results'] = array_values($backups[$task_name]['task_results']);
            } else {
                $backups[$task_name]['task_results'] = array();
            }
            
            $this->update_tasks($backups);
            
            return true;
        }
    }
    
    /**
     * Deletes specified backup.
     * 
     * @param	array	$args	arguments passed to function
     * [task_name] -> name of backup task
     * [result_id] -> id of baskup task result, which should be restored
     * [google_drive_token] -> json of Google Drive token, if it is remote destination
     * @return	bool			true if successful, false if not
     */
    function delete_backup($args) {
	
        if (empty($args)) {
            return false;
        }
        extract($args);
		$task_name = stripslashes($task_name);
        if (isset($google_drive_token)) {
        	$this->tasks[$task_name]['task_args']['account_info']['cmsc_google_drive']['google_drive_token'] = $google_drive_token;
        }
        
        $tasks   = $this->tasks;
        $task    = $tasks[$task_name];
        $backups = $task['task_results'];
        $backup  = $backups[$result_id];
        
        if (isset($backup['server'])) {
            @unlink($backup['server']['file_path']);
        }
        
        //Remove from ftp
        if (isset($backup['ftp'])) {
            $ftp_file            = $backup['ftp'];
            $args                = $tasks[$task_name]['task_args']['account_info']['cmsc_ftp'];
            $args['backup_file'] = $ftp_file;
            $this->remove_ftp_backup($args);
        }
         if (isset($backup['sftp'])) {
            $ftp_file            = $backup['ftp'];
            $args                = $tasks[$task_name]['task_args']['account_info']['cmsc_sftp'];
            $args['backup_file'] = $ftp_file;
            $this->remove_sftp_backup($args);
        }
		
        if (isset($backup['amazons3'])) {
            $amazons3_file       = $backup['amazons3'];
            $args                = $tasks[$task_name]['task_args']['account_info']['cmsc_amazon_s3'];
            $args['backup_file'] = $amazons3_file;
            $this->remove_amazons3_backup($args);
        }
        
        if (isset($backup['dropbox'])) {
        	$dropbox_file        = $backup['dropbox'];
            $args                = $tasks[$task_name]['task_args']['account_info']['cmsc_dropbox'];
            $args['backup_file'] = $dropbox_file;
            $this->remove_dropbox_backup($args);
        }
        
        if (isset($backup['google_drive'])) {
            if (is_array($backup['google_drive'])) {
                $google_drive_file = $backup['google_drive']['file'];
                $google_file_id    = $backup['google_drive']['file_id'];
            } else {
                $google_drive_file = $backup['google_drive'];
                $google_file_id    = "";
            }
            $args                = $tasks[$task_name]['task_args']['account_info']['cmsc_google_drive'];
            $args['backup_file'] = $google_drive_file;
            $args['file_id']     = $google_file_id;
            $this->remove_google_drive_backup($args);
        }
        
        unset($backups[$result_id]);
        
		if (is_array($backups) && !empty($backups)) {	
            $tasks[$task_name]['task_results'] = $backups;
        } else {
            unset($tasks[$task_name]['task_results']);
        }
        
        $this->update_tasks($tasks);

        return true;
    }
    
    /**
     * Deletes all unneeded files produced by backup process.
     * 
     * @return	array	array of deleted files
     */
    function cleanup() {
	
        $tasks             = $this->tasks;
        $backup_folder     = WP_CONTENT_DIR . '/' . md5('cmsc-worker') . '/cmsc_backups/';
        $backup_folder_new = CMSC_BACKUP_DIR . '/';
        $files             = glob($backup_folder . "*");
        $new               = glob($backup_folder_new . "*");
        
        //Failed db files first
        $db_folder = CMSC_DB_DIR . '/';
        $db_files  = glob($db_folder . "*");
        if (is_array($db_files) && !empty($db_files)) {
            foreach ($db_files as $file) {
                @unlink($file);
            }
			@unlink(CMSC_BACKUP_DIR.'/cmsc_db/index.php');
            @unlink(CMSC_BACKUP_DIR.'/cmsc_db/info.json');			
            @rmdir(CMSC_DB_DIR);
        }
        
        //clean_old folder?
        if ((isset($files[0]) && basename($files[0]) == 'index.php' && count($files) == 1) || (empty($files))) {
            if (!empty($files)) {
        		foreach ($files as $file) {
                	@unlink($file);
            	}
            }
            @rmdir(WP_CONTENT_DIR . '/' . md5('cmsc-worker') . '/cmsc_backups');
            @rmdir(WP_CONTENT_DIR . '/' . md5('cmsc-worker'));
        }
        
        if (!empty($new)) {
        	foreach ($new as $b) {
            	$files[] = $b;
        	}
        }
        $deleted = array();
        
        if (is_array($files) && count($files)) {
            $results = array();
            if (!empty($tasks)) {
                foreach ((array) $tasks as $task) {
                    if (isset($task['task_results']) && count($task['task_results'])) {
                        foreach ($task['task_results'] as $backup) {
                            if (isset($backup['server'])) {
                                $results[] = $backup['server']['file_path'];
                            }
                        }
                    }
                }
            }
            
            $num_deleted = 0;
            foreach ($files as $file) {
                if (!in_array($file, $results) && basename($file) != 'index.php') {
                    @unlink($file);
                    $deleted[] = basename($file);
                    $num_deleted++;
                }
            }
        }
        
        return $deleted;
    }
    
    /**
     * Uploads to remote destination in the second step, invoked from master.
     * 
     * @param 	array 	$args	arguments passed to function
     * [task_name] -> name of backup task
     * @return 	array|void		void if success, array with error message if not
     */
    function remote_backup_now($args) {
		$this->set_memory();        
        if (!empty($args)) {
            extract($args);
        }
		
        $tasks     = $this->tasks;
        $task_name = stripslashes($task_name);
        $task      = $tasks[$task_name];

        if (!empty($task)) {
            extract($task['task_args']);
        }
		
        $results       = $task['task_results'];		
        $taskResultKey = null;
        $backup_file   = false;
		
        if (is_array($results) && count($results)) {
            foreach ($results as $key => $result) {
                if (array_key_exists('resultUuid', $result) && $result['resultUuid'] == $args['resultUuid']) {
                    $backup_file   = $result['server']['file_path'];
                    $taskResultKey = $key;
                    break;
                }
            }
            if (!$backup_file) {
                $backup_file   = $results[count($results) - 1]['server']['file_path'];
                $taskResultKey = count($results) - 1;
            }
        }		
		
        if ($backup_file && file_exists($backup_file)) {
            //FTP, Amazon S3, Dropbox or Google Drive			
            if (isset($account_info['cmsc_ftp']) && !empty($account_info['cmsc_ftp'])) {
            	$this->update_status($task_name, $this->statuses['ftp']);
            	$account_info['cmsc_ftp']['backup_file'] = $backup_file;
                $return                                 = $this->ftp_backup($account_info['cmsc_ftp']);
                $this->wpdb_reconnect();
                
                if (!(is_array($return) && isset($return['error']))) {
                	$this->update_status($task_name, $this->statuses['ftp'], true);
                	$this->update_status($task_name, $this->statuses['finished'], true);
                }
            }
			
            if (isset($account_info['cmsc_sftp']) && !empty($account_info['cmsc_sftp'])) {
                $this->update_status($task_name, $this->statuses['sftp']);
                $account_info['cmsc_sftp']['backup_file'] = $backup_file;
                $return                                 = $this->sftp_backup($account_info['cmsc_sftp']);
                $this->wpdb_reconnect();

                if (!(is_array($return) && isset($return['error']))) {
                    $this->update_status($task_name, $this->statuses['sftp'], true);
                    $this->update_status($task_name, $this->statuses['finished'], true);
                }
            }			
            
            if (isset($account_info['cmsc_amazon_s3']) && !empty($account_info['cmsc_amazon_s3'])) {
            	$this->update_status($task_name, $this->statuses['s3']);
            	$account_info['cmsc_amazon_s3']['backup_file'] = $backup_file;
                $return                                       = $this->amazons3_backup($account_info['cmsc_amazon_s3']);
                $this->wpdb_reconnect();
                
                if (!(is_array($return) && isset($return['error']))) {
                	$this->update_status($task_name, $this->statuses['s3'], true);
                	$this->update_status($task_name, $this->statuses['finished'], true);
                }
            }
            
            if (isset($account_info['cmsc_dropbox']) && !empty($account_info['cmsc_dropbox'])) {
            	$this->update_status($task_name, $this->statuses['dropbox']);
            	$account_info['cmsc_dropbox']['backup_file'] = $backup_file;
                $return                                     = $this->dropbox_backup($account_info['cmsc_dropbox']);
                $this->wpdb_reconnect();
                
                if (!(is_array($return) && isset($return['error']))) {
                	$this->update_status($task_name, $this->statuses['dropbox'], true);
                	$this->update_status($task_name, $this->statuses['finished'], true);
                }
            }
            
            if (isset($account_info['cmsc_email']) && !empty($account_info['cmsc_email'])) {
            	$this->update_status($task_name, $this->statuses['email']);
            	$account_info['cmsc_email']['task_name'] = $task_name;
            	$account_info['cmsc_email']['file_path'] = $backup_file;
                $return                                 = $this->email_backup($account_info['cmsc_email']);
                $this->wpdb_reconnect();
                
                if (!(is_array($return) && isset($return['error']))) {
                	$this->update_status($task_name, $this->statuses['email'], true);
                	$this->update_status($task_name, $this->statuses['finished'], true);
                }
            }	
            
            if (isset($account_info['cmsc_google_drive']) && !empty($account_info['cmsc_google_drive'])) {

				$this->_log("Google Drive start");
  			
            	$this->update_status($task_name, $this->statuses['google_drive']);
            	$account_info['cmsc_google_drive']['backup_file'] = $backup_file;	
                $account_info['cmsc_google_drive']['task_name']       = $task_name;
                $account_info['cmsc_google_drive']['task_result_key'] = $taskResultKey;	
	
            	$return = $this->google_drive_backup($account_info['cmsc_google_drive']);
			
            	$this->wpdb_reconnect();
			
				$this->_log($return);
            	
            	if (!isset($return['error'])) {
            		$this->update_status($task_name, $this->statuses['google_drive'], true);
            		$this->update_status($task_name, $this->statuses['finished'], true);
            	}
            }

            $tasks = $this->tasks;
            @file_put_contents(CMSC_BACKUP_DIR.'/cmsc_db/index.php', '');
            if ($return === true && $del_host_file) {
                @unlink($backup_file);
                unset($tasks[$task_name]['task_results'][count($tasks[$task_name]['task_results']) - 1]['server']);
            }
            $this->update_tasks($tasks);
            if (!isset($return['error'])) {
                $return = $this->tasks[$task_name]['task_results'][$taskResultKey];
            }	    
        } else {
            $return = array(
                'error' => 'Backup file not found on your server. Please try again.'
            );
        }
        
        return $return;
    }
    
    /**
     * Checks if scheduled backup tasks should be executed.
     * 
     * @param 	array 	$args			arguments passed to function
     * [task_name] -> name of backup task
     * [task_id] -> id of backup task
     * [$site_key] -> hash key of backup task
     * [worker_version] -> version of worker
     * [cmsc_google_drive_refresh_token] ->	should be Google Drive token be refreshed, true if it is remote destination of task
     * @param 	string 	$url			url on master where worker validate task
     * @return 	string|array|boolean	
     */
    function validate_task($args, $url) {
		return false;
		/*
        if (!class_exists('WP_Http')) {
            include_once(ABSPATH . WPINC . '/class-http.php');
        }

        $params         = array('timeout'=>100);
        $params['body'] = $args;
        $result         = wp_remote_post($url, $params);

		if (is_array($result) && $result['body']) {
			$response = unserialize($result['body']);
			if ($response['message'] == 'cmsc_delete_task') {
				$tasks = $this->tasks;
				unset($tasks[$args['task_name']]);
				$this->update_tasks($tasks);
				$this->cleanup();
				return 'deleted';
			} elseif ($response['message'] == 'cmsc_pause_task') {
				return 'paused';
			} elseif ($response['message'] == 'cmsc_do_task') {
				return $response;
			}
		}

	    return false;
		*/
    }
    
    /**
     * Updates status of backup task.
     * Positive number if completed, negative if not.
     * 
     * @param 	string 	$task_name	name of backup task
     * @param 	int 	$status		status which tasks should be updated to
     * (
     * 0 - Backup started,
     * 1 - DB dump,
     * 2 - DB ZIP,
     * 3 - Files ZIP,
     * 4 - Amazon S3,
     * 5 - Dropbox,
     * 6 - FTP,
     * 7 - Email,
     * 8 - Google Drive,
     * 100 - Finished
     * )
     * @param 	bool 	$completed	completed or not
     * @return	void
     */

    function update_status($task_name, $status, $completed = false) {

        if ($task_name != 'Backup Now') {
            $tasks = $this->tasks;
            $index = count($tasks[$task_name]['task_results']) - 1;			
            if (!is_array($tasks[$task_name]['task_results'][$index]['status'])) {
                $tasks[$task_name]['task_results'][$index]['status'] = array();
            }
            if (!$completed) {
                $tasks[$task_name]['task_results'][$index]['status'][] = (int) $status * (-1);
            } else {
                $status_index                                                       = count($tasks[$task_name]['task_results'][$index]['status']) - 1;
                $tasks[$task_name]['task_results'][$index]['status'][$status_index] = abs($tasks[$task_name]['task_results'][$index]['status'][$status_index]);
            }
            
            $this->update_tasks($tasks);
        }
    }
    
    /**
     * Update $this->tasks attribute and save it to wp_options with key cmsc_backup_tasks.
     * 
     * @param 	mixed 	$tasks	associative array with all tasks data
     * @return	void
     */
    function update_tasks($tasks) {
        $this->tasks = $tasks;

        $result = update_option('cmsc_backup_tasks', $tasks);
    }
    
    /**
     * Reconnects to database to avoid timeout problem after ZIP files.
     * 
     * @return void
     */
    function wpdb_reconnect() {
    	global $wpdb;
    	
        if (is_callable(array($wpdb, 'check_connection'))) {
            $wpdb->check_connection();

            return;
        }		
		
      	if(class_exists('wpdb') && function_exists('wp_set_wpdb_vars')){
      		@mysql_close($wpdb->dbh);
        	$wpdb = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
        	wp_set_wpdb_vars(); 
            if (function_exists('is_multisite')) {
                if (is_multisite()) {
                    $wpdb->set_blog_id(get_current_blog_id());
                }
            }			
      	}
    }
    
    /**
     * Replaces .htaccess file in process of restoring WordPress site.
     * 
     * @param 	string 	$url	url of current site
     * @return	void
     */
	function replace_htaccess($url) {
	    $file = @file_get_contents(ABSPATH.'.htaccess');
	    if ($file && strlen($file)) {
	        $args    = parse_url($url);        
	        $string  = rtrim($args['path'], "/");
	        $regex   = "/BEGIN WordPress(.*?)RewriteBase(.*?)\n(.*?)RewriteRule \.(.*?)index\.php(.*?)END WordPress/sm";
	        $replace = "BEGIN WordPress$1RewriteBase " . $string . "/ \n$3RewriteRule . " . $string . "/index.php$5END WordPress";
	        $file    = preg_replace($regex, $replace, $file);
	        @file_put_contents(ABSPATH.'.htaccess', $file);
	    }
	}
	
	/**
	 * Removes cron for checking scheduled tasks, if there are not any scheduled task.
	 * 
	 * @return	void
	 */
	function check_cron_remove() {
		if(empty($this->tasks) || (count($this->tasks) == 1 && isset($this->tasks['Backup Now'])) ){
			wp_clear_scheduled_hook('cmsc_backup_tasks');
			exit;
		}
	}
    
	/**
	 * Re-add tasks on website re-add.
	 * 
	 * @param 	array 	$params	arguments passed to function
	 * @return 	array			$params without backups
	 */
	public function readd_tasks($params = array()) {
		global $cmsc_core;
		
		if( empty($params) || !isset($params['backups']) )
			return $params;
		
		$before = array();
		$tasks = $params['backups'];
		if( !empty($tasks) ){
			$cmsc_backup = new CMSC_Backup();
			
			if( function_exists( 'wp_next_scheduled' ) ){
				if ( !wp_next_scheduled('cmsc_backup_tasks') ) {
					wp_schedule_event( time(), 'tenminutes', 'cmsc_backup_tasks' );
				}
			}
			
			foreach( $tasks as $task ){
				$before[$task['task_name']] = array();
				
				if(isset($task['secure'])){
					if($decrypted = $cmsc_core->_secure_data($task['secure'])){
						$decrypted = maybe_unserialize($decrypted);
						if(is_array($decrypted)){
							foreach($decrypted as $key => $val){
								if(!is_numeric($key))
									$task[$key] = $val;							
							}
							unset($task['secure']);
						} else 
							$task['secure'] = $decrypted;
					}
					
				}
				if (isset($task['account_info']) && is_array($task['account_info'])) { //only if sends from master first time(secure data)
					$task['args']['account_info'] = $task['account_info'];
				}
				
				$before[$task['task_name']]['task_args'] = $task['args'];
				$before[$task['task_name']]['task_args']['next'] = $cmsc_backup->schedule_next($task['args']['type'], $task['args']['schedule']);
			}
		}
		update_option('cmsc_backup_tasks', $before);
		
		unset($params['backups']);
		return $params;
	}
	
}

/*if( function_exists('add_filter') ) {
	add_filter( 'cmsc_website_add', 'CMSC_Backup::readd_tasks' );
}*/

if(!function_exists('get_all_files_from_dir')) {
	/**
	 * Get all files in directory
	 * 
	 * @param 	string 	$path 		Relative or absolute path to folder
	 * @param 	array 	$exclude 	List of excluded files or folders, relative to $path
	 * @return 	array 				List of all files in folder $path, exclude all files in $exclude array
	 */
	function get_all_files_from_dir($path, $exclude = array()) {
        if ($path[strlen($path) - 1] === "/") {
            $path = substr($path, 0, -1);
        }
		global $directory_tree, $ignore_array;
		$directory_tree = array();
		foreach ($exclude as $file) {
			if (!in_array($file, array('.', '..'))) {
                if ($file[0] === "/") {
                    $path = substr($file, 1);
                }
				$ignore_array[] = "$path/$file";
			}
		}
		get_all_files_from_dir_recursive($path);
		return $directory_tree;
	}
}

if (!function_exists('get_all_files_from_dir_recursive')) {
	/**
	 * Get all files in directory,
	 * wrapped function which writes in global variable
	 * and exclued files or folders are read from global variable
	 *
	 * @param 	string 	$path 	Relative or absolute path to folder
	 * @return 	void
	 */
	function get_all_files_from_dir_recursive($path) {
		if ($path[strlen($path) - 1] === "/") $path = substr($path, 0, -1);
		global $directory_tree, $ignore_array;
		$directory_tree_temp = array();
		$dh = @opendir($path);
		
		while (false !== ($file = @readdir($dh))) {
			if (!in_array($file, array('.', '..'))) {
				if (!in_array("$path/$file", $ignore_array)) {
					if (!is_dir("$path/$file")) {
						$directory_tree[] = "$path/$file";
					} else {
						get_all_files_from_dir_recursive("$path/$file");
					}
				}
			}
		}
		@closedir($dh);
	}
}

?>