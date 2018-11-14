<?php
if(!class_exists('simple_html_dom_node')){
	require_once("php-simple-html-dom/simple_html_dom.php");
}
function ws_insert_by_url($urls) {
    if ( is_admin() ) {
        require_once(ABSPATH . 'wp-admin/includes/admin.php');
    }
	global $wpdb;
	//添加下载图片地址到本地功能
	$schedule       = isset($_REQUEST['schedule']) && intval($_REQUEST['schedule']) == 1;
	$sprindboard    = isset($_REQUEST['springboard']) ?
						$_REQUEST['springboard'] :
						'http://read.html5.qq.com/image?src=forum&q=4&r=0&imgflag=7&imageUrl=';
	// 微信原作者
	$changeAuthor   = false;
	// 改变发布时间
	$changePostTime = isset($_REQUEST['change_post_time']) && $_REQUEST['change_post_time'] == 'true';
	// 默认是直接发布
	$postStatus     = isset($_REQUEST['post_status']) && in_array($_REQUEST['post_status'], array('publish', 'pending', 'draft')) ?
						$_REQUEST['post_status'] : 'publish';
	// 保留文章样式
	$keepStyle      = isset($_REQUEST['keep_style']) && $_REQUEST['keep_style'] == 'keep';
	// 文章分类，默认是未分类（1）
	$postCate       = isset($_REQUEST['post_cate']) ? intval($_REQUEST['post_cate']) : 1;
	$postCate       = array($postCate);
	// 文章类型，默认是post
	$postType       = isset($_REQUEST['post_type']) ? $_REQUEST['post_type'] : 'post';
    //	$debug          = isset($_REQUEST['debug']) ? $_REQUEST['debug'] : false;
	$force          = isset($_REQUEST['force']) ? $_REQUEST['force'] : true;

	$postId         = null;
	$urls           = str_replace('https', 'http', $urls);
    global $file;
	foreach ($urls as $url) {
		if (strpos($url, 'http://mp.weixin.qq.com/s') !== false || strpos($url, 'https://mp.weixin.qq.com/s') !== false) {
			$url =  trim($url);
		}
		if (!$url) {
			continue;
		}
		if (function_exists('file_get_contents')) {
            // file_put_contents($file, "function_exists: file_get_contents" . "\n", FILE_APPEND);
			$html = @file_get_contents($url);
		} else {
			$GLOBALS['errMsg'][] = '不支持file_get_contents';
			break;
		}
		if ($html == '') {
            $url = str_replace('http://', 'https://', $url);
            file_put_contents($file, "URL: " . $url . "\n", FILE_APPEND);
			$ch = curl_init();
			$timeout = 30;
			curl_setopt($ch, CURLOPT_URL, $url);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
			curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
			$html = curl_exec($ch);
			curl_close($ch);
		}
		if (!$html) {
			$GLOBALS['errMsg'][] = array(
				'url' => $url,
				'msg' => '无法获取此条URL内容'
			);
			continue;
		}
		// 是否移除原文样式
		if (!$keepStyle) {
			$html = preg_replace('/style\=\"[^\"]*\"/', '', $html);
		}
		$dom  = str_get_html($html);
		// 文章标题
		preg_match('/(msg_title = ")([^\"]+)"/', $html, $matches);
		$_REQUEST['post_title'] = trim($matches[2]);
		$title = $_REQUEST['post_title'];
        
        file_put_contents($file, "Title:" . $title . "\n", FILE_APPEND);
		// 确保有标题
		if (!$title) {
			$GLOBALS['errMsg'][] = array(
				'url' => $url,
				'msg' => '此条URL没有文章标题'
			);
			continue;
		}
		// 同步任务检查标题是否重复，若重复则跳过
        file_put_contents($file, "post id :" . post_exists($title) . "\n", FILE_APPEND);
		if (post_exists($title) != 0) {
			$GLOBALS['errMsg'][] = array(
				'url' => $url,
				'msg' => '标题重复'
			);
			continue;
		}
		// 处理图片及视频资源
		$imageDoms = $dom->find('img');
		$videoDoms = $dom->find('.video_iframe');
		foreach ($imageDoms as $imageDom) {
			$dataSrc = $imageDom->getAttribute('data-src');
			if (!$dataSrc) {
				continue;
			}
			$src  = $sprindboard . $dataSrc;
			$imageDom->setAttribute('src', $src);
		}
		foreach ($videoDoms as $videoDom) {
			$dataSrc = $videoDom->getAttribute('data-src');
			// 视频不用跳板
			$videoDom->setAttribute('src', $dataSrc);
		}
		// 发布日期
		if ($changePostTime) {
			$postDate = date('Y-m-d H:i:s', current_time('timestamp'));
		} else {
			preg_match('/(publish_time = ")([^\"]+)"/', $html, $matches);
			$postDate = isset($matches[2]) ? $matches[2] : current_time('timestamp');
			$postDate = date('Y-m-d H:i:s', strtotime($postDate));
		}
		// 提取用户信息
		$url      = parse_url($url);
		$query    = isset($url['query']) ? $url['query'] : '';
		$queryArr = explode('&', $query);
		$bizVal   = '';
		$cates = array();
		foreach ($queryArr as $item) {
			if (!$item) {
				continue;
			}
			list($key, $val) = explode('=', $item, 3);
			if ($key == '__biz') {
				//  用户唯一标识
				$bizVal = $val;
			}
			if ($key == 'cates') {
				$cates = explode(',', $val);
			}
		}
		// 如果链接中不含有biz参数，则选择当前的时间戳作为用户名和密码
		if ($bizVal == '') {
			$bizVal = time();
		}

		// 是否改变作者，默认是当前登录作者
		// $userName = $dom->find('#post-user', 0)->plaintext;
		// $userName = esc_html($userName);
		if ($changeAuthor) {
			// 创建用户
			$userId   = wp_create_user($bizVal, $bizVal);
			// 用户已存在
			if ($userId) {
				if ($userId->get_error_code() == 'existing_user_login') {
					$userData = get_user_by('login', $bizVal);
				} else if(is_integer($userId) > 0) {
					$userData = get_userdata($userId);
				} else {
					// 错误情况
					continue;
				}
				// 默认是投稿者
				$userData->add_role('contributor');
				$userData->remove_role('subscriber');
				$userData->display_name = $userName;
				$userData->nickname     = $userName;
				$userData->first_name   = $userName;
				wp_update_user($userData);
				$userId = $userData->ID;
			} else {
				// 默认博客作者
				$userId = get_current_user_id();
			}
		} else {
			// 默认博客作者
			$userId = get_current_user_id();
		}

		if ($schedule) {
			$userId = 1;
			if ($cates) {
				$cateIds = array();
				foreach ($cates as $cate) {
					$term = get_term_by('name', $cate, 'category');
					if ($term) {
						$cateIds[] = $term->term_id;
					} else {
					}
				}
				$postCate = $cateIds;
			}
		}


		$post = array(
			'post_title'    => $title,
			'post_content'  => "",
			'post_status'   => $postStatus,
			'post_date'     => $postDate,
			'post_modified' => $postDate,
			'post_author'   => $userId,
			'post_category' => $postCate,
			'post_type'	    => $postType
		);
		$postId = @wp_insert_post($post);
        file_put_contents($file, "add new post id to db:" . $postId . "\n", FILE_APPEND);
		// 公众号设置featured image
		$setFeaturedImage  = get_option('bp_featured_image', 'yes') == 'yes';
		if ($setFeaturedImage) {
			preg_match('/(msg_cdn_url = ")([^\"]+)"/', $html, $matches);
			$redirectUrl = 'http://read.html5.qq.com/image?src=forum&q=4&r=0&imgflag=7&imageUrl=';
			$coverImageSrc = $redirectUrl . $matches[2];
			$tmpFile = download_url($coverImageSrc);
			if (is_string($tmpFile)) {
				$prefixName = get_option('ws_image_name_prefix', 'ws-plugin');
				$fileName = $prefixName . '-' . time() . '.jpeg';
				$fileArr  = array(
					'name'     => $fileName,
					'tmp_name' => $tmpFile
				);
				$id = @media_handle_sideload($fileArr, $postId);
                file_put_contents($file, "add new feature image id to db:" . $id . "\n", FILE_APPEND);
				if (!is_wp_error($id)) {
					@set_post_thumbnail($postId, $id);
				}
			}
		}
		unset($html);
		// 下载图片到本地
		ws_downloadImage($postId, $dom);
	}
	$GLOBALS['done'] = true;
	return $postId;
}
function ws_downloadImage($postId, $dom) {
	// 提取图片
	$images            = $dom->find('img');
	$schedule          = isset($_REQUEST['schedule']) && intval($_REQUEST['schedule']) == 1;
	$version           = '2-4-2';
	// 文章标题
	$title             = $_REQUEST['post_title'];
	$centeredImage     = get_option('bp_image_centered', 'no') == 'yes';
    global $file;
	foreach ($images as $image) {
		$src  = $image->getAttribute('src');
		$type = $image->getAttribute('data-type');
		if (!$src) {
			continue;
		}
		if (strstr($src, 'res.wx.qq.com')) {
			continue;
		}
		$class = $image->getAttribute('class');
		if ($centeredImage) {
			$class .= ' aligncenter';
			$image->setAttribute('class', $class);
		}
		$src = preg_replace('/^\/\//', 'http://', $src, 1);
		if (!$type || $type == 'other') {
			$type = 'jpeg';
		}
        file_put_contents($file, 'pic src: ' . $src . "\n", FILE_APPEND);
        $pic_array = explode("/",$src);
        $pic_name =  $pic_array[count($pic_array)-2];		
		$fileName = 'ws-plugin-' . $pic_name . '.' . $type;		
        $id = post_exists($fileName) ;
        if($id==0){
    		$tmpFile = download_url($src);

		    $fileArr = array(
			    'name' => $fileName,
			    'tmp_name' => $tmpFile
		    );
    		$id = @media_handle_sideload($fileArr, $postId);
            file_put_contents($file, "add new media to db, with id=" . $id . "\n", FILE_APPEND);
        }
		if (is_wp_error($id)) {
			$GLOBALS['errMsg'][] = array(
				'src'  => $src,
				'file' => $fileArr,
				'msg'  => $id
			);
			@unlink($tmpFile);
			continue;
		} else {
			$imageInfo = wp_get_attachment_image_src($id, 'full');
			$src       = $imageInfo[0];
			$image->setAttribute('src', $src);
			$image->setAttribute('alt', $title);
			$image->setAttribute('title', $title);
		}
	}
	$userName = $dom->find('#profileBt a', 0);
     if($userName){
      $userName = $userName->plaintext;
     }
     else{ // handle 转载
         $userName = $dom->find('.original_account_nickname', 0)->plaintext;
     }
	$userName = esc_html($userName);
    file_put_contents($file, "article user name:" . $userName . "\n", FILE_APPEND);
	// 保留来源
	$keepSource     = isset($_REQUEST['keep_source']) && $_REQUEST['keep_source'] == 'keep';
	$content = $dom->find('#js_content', 0)->innertext;
	$content = preg_replace('/data\-([a-zA-Z0-9\-])+\=\"[^\"]*\"/', '', $content);
	$content = preg_replace('/src=\"(http:\/\/read\.html5\.qq\.com)([^\"])*\"/', '', $content);
	$content = preg_replace('/class=\"([^\"])*\"/', '', $content);
	$content = preg_replace('/id=\"([^\"])*\"/', '', $content);
	if ($keepSource) {
		$source =
				"<blockquote class='keep-source'>" .
				"<p>始发于微信公众号：{$userName}</p>" .
				"</blockquote>";
		$content .= $source;
	}
	// 保留文章样式
	$content = trim($content);
    file_put_contents($file, $content . "\n", FILE_APPEND);
	$return_postID = wp_update_post(array(
		'ID' => $postId,
		'post_content' =>  $content
	));
    if(is_wp_error($return_postID)){
        file_put_contents($file, 'Error message' . $return_postID->get_error_message() . "\n", FILE_APPEND);
    }
    file_put_contents($file, 'update POST ID' .$postId . "\n", FILE_APPEND);
    
}
?>