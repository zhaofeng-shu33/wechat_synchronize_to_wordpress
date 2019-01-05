<?php
/*
Plugin Name: wechat synchronize
Plugin URI: https://github.com/zhaofeng-shu33/wechat_synchronize_to_wordpress
Description: synchronize wechat articles to wordpress website
Author: zhaofeng-shu33
Version: 0.3
Author URI: https://github.com/zhaofeng-shu33
*/
/**
 * @file wechat_synchronize.php
 */
require_once "synchronize_api.php";
require_once 'insert_by_url.php';
if ( ! defined( 'ABSPATH' ) ) exit;

if (is_admin()) {
	add_action('admin_menu', 'wsync_admin_menu');
}

//! \brief initialize admin menu as submenu under **Settings**
function wsync_admin_menu(){
    add_options_page('wsync options', 'wsync', 'manage_options', 'wsync-unique-identifier', 'wsync_plugin_options');
    add_action('admin_init', 'wsync_register_settings');
}

//! \brief register setting data for persistent storage
function wsync_register_settings(){
    register_setting('wsync-settings-group', 'appid');
    register_setting('wsync-settings-group', 'appsecret');
    add_option('access_token');
}

//! \brief  load the frontend page
function wsync_plugin_options(){
    require_once 'setting-page.php';
}

//! \brief  basic config setting function
function wsync_set_config(){
    $changePostTime = isset($_POST['change_post_time']) && $_POST['change_post_time'] == 'true';
    $postStatus     = isset($_POST['post_status']) && in_array($_POST['post_status'], array('publish', 'pending', 'draft')) ?
                                            $_POST['post_status'] : 'publish';
    $keepStyle      = isset($_POST['keep_style']) && $_POST['keep_style'] == 'keep';
    $keepSource      = isset($_POST['keep_source']) ? $_POST['keep_source'] == 'keep': true;    
	$debug          = isset($_POST['debug']) ? $_POST['debug'] == 'on' : true;
    $config = array(
		'changePostTime'  => $changePostTime,
		'postStatus'   => $postStatus,
		'keepStyle'     => $keepStyle,
        'keepSource' => $keepSource,
        'setFeatureImage' => true,
        'debug' => $debug
    );    
    return $config;
}

function wsync_split_url($url_list_string){
    $url_list = explode("\n", $url_list_string);
    foreach($url_list as &$url){
        $url = esc_url($url);
    }
    return $url_list;
}
//! \brief ajax callback main function
function wsync_process_request(){
    $sync_history = isset($_POST['wsync_history']) ? $_POST['wsync_history'] == 'wsync_Yes' : false;
    if($sync_history){
        if(isset($_POST['offset'])){
            $num = isset($_POST['num']) ? intval($_POST['num']) : 20;
            if($num <=0 || $num >20){
                $return_array = array('status_code' => -10, 'err_msg' => 'invalid num given');            
            }
            $offset = intval($_POST['offset']);
            $return_array = wsync_get_history_url_by_offset($offset, $num);            
        }
        else{ //if no offset parameter, get the whole history url list
            $return_array = wsync_get_history_url();
        }
    }
    else{ //    don't synchronize history articles, read url list from post data
        $urls_str = isset($_POST['given_urls']) ? $_POST['given_urls'] : '';
        if($urls_str != ''){
            $url_list = wsync_split_url($urls_str);
            $config = wsync_set_config();
            $return_array = wsync_insert_by_urls($url_list, $config);
            if(isset($_POST['url_id']) && $config['debug']){
                $return_array['url_id'] = esc_textarea($_POST['url_id']);
            }
        }
        else{
            $return_array = array('status_code' => -9, 'err_msg' => 'no urls are given');
        }
    }
    echo json_encode($return_array);
    wp_die();
}
add_action( 'wp_ajax_wsync_process_request', 'wsync_process_request' );

?>
