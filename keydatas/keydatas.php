<?php
/*
Plugin Name: 简数采集平台
Plugin URI: http://www.keydatas.com/caiji/wordpress-cms-caiji
Description: 简数采集器(keydatas.com)是一个通用、简单、智能、在线的网页数据采集器，功能强大，操作简单。支持按关键词采集；集成AI大模型接口、翻译等服务；图片下载支持存储到阿里云OSS、七牛、腾讯云对象存储等。
Version: 2.6.2
Author: keydatas
Author URI: http://www.keydatas.com
License: GPLv2 or later
Text Domain: keydatas
*/

function keydatas_successRsp($data = "", $msg = "") {
    keydatas_rsp(1,0, $data, $msg);
}
function keydatas_failRsp($code = 0, $data = "", $msg = "") {
    keydatas_rsp(0,$code, $data, $msg);
}

function keydatas_rsp($result = 1,$code = 0, $data = "", $msg = "") {
	die(json_encode(array("rs" => $result, "code" => $code, "data" => $data, "msg" => urlencode($msg))));
}
function keydatas_genRandomIp(){
	$randIP = "".mt_rand(0,255).".".mt_rand(0,255).".".mt_rand(0,255).".".mt_rand(0,255);
	return $randIP;
}

function  keydatas_getPostValSafe($paraName = ""){
  $postVal="";
	if(isset($_POST[$paraName])){
		$postVal=sanitize_text_field($_POST[$paraName]);
	}
	return $postVal;
}
/**
 * 生成0~1随机小数
 * @param  Int   $min
 * @param  Int   $max
 * @return Float
 */
function keydatas_randFloat($min=0, $max=1){
    return $min + mt_rand()/mt_getrandmax() * ($max-$min);
}

if (is_admin()) {
   //将函数连接到添加菜单
    add_action('admin_menu', 'keydatas_add_menu');
}

//在后台管理界面添加菜单
function keydatas_add_menu() {
    if (function_exists('add_menu_page')) {
		$setting_menu_slug='keydatas/publish-setting.php';
        add_menu_page('简数采集平台', '简数采集平台', 'administrator', $setting_menu_slug, '', plugins_url('images/icon.png',__FILE__));
    }
}
add_action('init', 'keydatas_post_doc');
function keydatas_myplugin_activate() {
}
// 寄存一个插件函数，该插件函数在插件被激活时运行
register_activation_hook(__FILE__, 'keydatas_myplugin_activate');

function keydatas_post_doc() {
  global $wpdb;  
  $kds_flag="";
	if(isset($_GET['__kds_flag'])){
		$kds_flag=sanitize_text_field($_GET["__kds_flag"]);
	}
	if (!empty($kds_flag)){
		//$_REQ = keydatas_mergeRequest();
		$kds_password = get_option('keydatas_password', '');
		if (empty($kds_password)) {
			keydatas_failRsp(1403, "password empty", "提交的发布密码为空");
		}
		$post_password = keydatas_getPostValSafe('kds_password');
		if (empty($post_password) || $post_password != $kds_password) {
			keydatas_failRsp(1403, "password error", "提交的发布密码错误");
		}	

		//do post	
		if ($kds_flag == "post") {
		
			$title = keydatas_getPostValSafe("post_title");
			if (empty($title)) {
				keydatas_failRsp(1404, "title is empty", "标题不能为空");
			}		
			
			$content='';
			if(isset($_POST["post_content"])){
				$content =wp_kses_post($_POST["post_content"]);
			}
			if (empty($content)) {
				$content='';
			}
			
			//文章摘要
			$excerpt = keydatas_getPostValSafe("post_excerpt");
			if (empty($excerpt)) {
				$excerpt='';
			}
			//文章类型
			$postType = keydatas_getPostValSafe("post_type");
			if (empty($postType)) {
				$postType = 'post';
			}
			
			/*$postStatus = 'publish';
			if (isset($_POST["post_status"]) && in_array($_POST["post_status"], array('publish', 'draft'))) {
				$postStatus = $_POST["post_status"];
			}
			*/
			$postStatus = keydatas_getPostValSafe("post_status");
			if (empty($postStatus) || !in_array($postStatus, array('publish', 'draft'))) {
				$postStatus = 'publish';
			}
			
			//
			$commentStatus = keydatas_getPostValSafe("comment_status");
			if (empty($commentStatus) || !in_array($commentStatus, array('open', 'closed'))) {
				$commentStatus = 'open';
			}
			//文章密码,文章编辑才可为文章设定一个密码，凭这个密码才能对文章进行重新强加或修改
			$postPassword = keydatas_getPostValSafe("post_password");
			//if (isset($_POST["post_password"]) && $_POST["post_password"]) {
			if(empty($postPassword)){
				$postPassword = '';
			}

			$my_post = array(
				'post_password' => $postPassword,
				'post_status' => $postStatus,
				'comment_status' => $commentStatus,
				'post_author' => 1
			);
			if (!empty($title)) {
				$my_post['post_title'] =$title; //htmlspecialchars_decode($title);
			}
			if (!empty($content)) {
				$my_post['post_content'] = $content;
			}
			if(!empty($excerpt)){
				$my_post['post_excerpt'] = $excerpt;
			}
			if(!empty($postType)){
				$my_post['post_type'] = $postType;
			}
			//文章别名
			$postName = keydatas_getPostValSafe("post_name");	
			if (!empty($postName)) {
				$my_post['post_name'] = $postName;
			}
	
			///////////////目前主要用于lightsns
			$post_parent  = keydatas_getPostValSafe("__kdsExt_post_parent");//default 0
			if(!empty($post_parent)){
				try{
					$post_parent=intval($post_parent);
					if($post_parent>0){
						$my_post['post_parent']=$post_parent;
					}
				} catch (Exception $ex1) {
			 	}
			}
			
			//标题唯一校验
            $title_unique = get_option('keydatas_title_unique', false);
			//error_log('title:'.stripslashes($my_post['post_title']), 3, '/var/log/wp_test.log');
			if($title_unique){				
				//只返回id
                $post = $wpdb->get_row($wpdb->prepare("SELECT ID FROM $wpdb->posts WHERE post_title='%s' and post_status!='trash' and post_status!='inherit' ",stripslashes($my_post['post_title'])));
                if(!empty($post)){
					//这里可以补充图片
					keydatas_downloadImages();
					//返回访问路径
				  	keydatas_successRsp(array("url" => get_permalink($post->ID)."#相同标题文章已存在"));
                }
            }
			$post_date=keydatas_getPostValSafe("post_date");
			if (!empty($post_date)) {
				$post_date = intval($post_date);
				$my_post['post_date'] = date("Y-m-d H:i:s", $post_date);
			} else {
				$my_post['post_date'] = date("Y-m-d H:i:s", time());
			}

			$author = keydatas_getPostValSafe("post_author");
			if (!empty($author)) {
				//$author = htmlspecialchars_decode($author);
				if($author == "rand_users"){
					$randNum=keydatas_randFloat();
					//SELECT ID FROM $wpdb->users order by rand() limit 1
					$user_id = $wpdb->get_var("SELECT ID FROM $wpdb->users WHERE 
id >= ((SELECT MAX(id) FROM $wpdb->users)-(SELECT MIN(id) FROM $wpdb->users)) * ".$randNum."+ (SELECT MIN(id) FROM $wpdb->users) LIMIT 1");
					//error_log('rand_users:'.$user_id, 3, '/var/log/wp_test.log');
				}else{
					//用户名（登录名）					
					$user_id = username_exists($author);
				}
				$md5author = substr(md5($author), 8, 16);
				if(!$user_id){
					$user_id = username_exists($md5author);
				}
				
				if (!$user_id) {
					//$md5author = substr(md5($author), 8, 16);
					$random_password = wp_generate_password();
					$userdata = array(
						'user_login' => $md5author,
						'user_pass' => $random_password,
						'display_name' => $author,
					);
					$user_id = wp_insert_user($userdata);
					if (is_wp_error($user_id)) {
						$user_id = 0;
					}
				}
				if ($user_id) {
					$my_post['post_author'] = $user_id;
				}
			}//.. post_author end
			//分类目录
			$category = keydatas_getPostValSafe("post_category");
			if (!empty($category)) {
				$cates = explode(',',$category);
				if (is_array($cates)) {
					$post_cates = array();
					$term = null;
					foreach ($cates as $cate) {
						//是否为数字
						$cat_id=0;
						if(is_numeric($cate)&&intval($cate)>0){
							 $cat_name = get_cat_name($cate);
							// error_log('cat_name:'.$cat_name, 3, '/var/log/wp_test.log');
							 if(!empty($cat_name)){
							 	$cat_id=intval($cate);
							 }
						}
						if($cat_id>0){
							array_push($post_cates, $cat_id);
						}else{
							$term = term_exists($cate, "category");
							if ($term === 0 || $term === null) {
								$term = wp_insert_term($cate, "category");
							}						
							if ($term !== 0 && $term !== null && !is_wp_error($term)) {
								array_push($post_cates, intval($term["term_id"]));
							}
						}
					}
					if (count($post_cates) > 0) {
						$my_post['post_category'] = $post_cates;
					}
				}
			}

			$post_tag = keydatas_getPostValSafe("post_tag");
			if (!empty($post_tag)) {
				$tags = explode(',',$post_tag);
				if (is_array($tags)) {
					$post_tags = array();
					$term = null;
					foreach ($tags as $tag) {
						$term = term_exists($tag, "post_tag");
						if ($term === 0 || $term === null) {
							$term = wp_insert_term($tag, "post_tag");
						}
						if ($term !== 0 && $term !== null && !is_wp_error($term)) {
							array_push($post_tags, intval($term["term_id"]));
						}
					}
					if (count($post_tags) > 0) {
						$my_post['tags_input'] = $post_tags;
					}
				}
			}
			
			kses_remove_filters();
			$post_id = wp_insert_post($my_post);
			kses_init_filters();

			if (empty($post_id) || is_wp_error($post_id)) {
				keydatas_failRsp(1500, "post_id is Empty", "插入文章失败");
			}
			//缩略图处理
			$image_url =keydatas_getPostValSafe("__kds_feature_url");
			if (empty($image_url)) {
				$image_url = keydatas_getPostValSafe("post_thumbnail");
			}
			if (!empty($post_id) && !empty($image_url)) {
					$image_url_final=$image_url;
					
					if (substr($image_url, 0, 2) ==="//") {
						$image_url_final='http:'.$image_url;
					}else if(strpos($image_url, '/') === 0) {
						$image_url_final=get_home_url().$image_url;
					}	
					$upload_dir = wp_upload_dir();
					$image_data = file_get_contents($image_url_final);
					$suffix = "jpg";
					$filename = md5($image_url_final) . "." . $suffix;
					if (wp_mkdir_p($upload_dir['path'])) {
						$file = $upload_dir['path'] . '/' . $filename;
					} else {
						$file = $upload_dir['basedir'] . '/' . $filename;
					}

					file_put_contents($file, $image_data);
					if (file_exists($file)) {
						//error_log('file_exists:'.$filename, 3, '/var/log/wp_test.log');
						$wp_filetype = wp_check_filetype($filename, null);
						$attachment = array(
							'post_mime_type' => $wp_filetype['type'],
							'post_title' => sanitize_file_name($filename),
							'post_content' => '',
							'post_status' => 'inherit'
						);
						// attachment相关
						$attach_id = wp_insert_attachment($attachment, $file, $post_id);
						require_once(ABSPATH . 'wp-admin/includes/image.php');
						$attach_data = wp_generate_attachment_metadata($attach_id, $file);
						wp_update_attachment_metadata($attach_id, $attach_data);
						set_post_thumbnail($post_id, $attach_id);
					}
			}
			/////
			keydatas_downloadImages();
			
			
			//for tbk
			$keydatas_tbk_link_enble = get_option('keydatas_tbk_link_enble', false);
			if($keydatas_tbk_link_enble){
				$tbk_link = keydatas_getPostValSafe("tbk_link");
				if (!empty($tbk_link)) {
					add_post_meta($post_id, 'tbk_link', $tbk_link, true);
				}
			}
			//其它meta数据处理
			if (!empty($post_id)) {
				foreach ($_POST as $key => $value) { 
					if (strpos($key, '__kdsExt_') === 0) {
						$real_name=substr($key,9);
						if (!empty($real_name)) {
							//add_post_meta
							update_post_meta($post_id, $real_name, $value);//, true
							
						}
					}
				}  
			}			
			//keydatas_successRsp(array("url" => get_home_url() . "/?p=" . $post_id));
			keydatas_successRsp(array("url" =>get_permalink($post_id)));
		} else if ($kds_flag == "category") {
			//获取分类目录
			$ret = array();
			$postType = keydatas_getPostValSafe("type");
			if (!empty($postType) && $postType === "cate") {
				$cates = get_terms('category', 'orderby=count&hide_empty=0');
				foreach ($cates as $cate) {
					array_push($ret, array("value" => urlencode($cate->name), "text" => urlencode($cate->name)));
				}
			}
			keydatas_successRsp($ret);
		} else if ($kds_flag == "version") {
			//获取用户使用的Php和Wp版本信息
			global $wp_version;
			$versions = array(
				'php' => PHP_VERSION,
				'plugin' => '1.0',
				'wp' => $wp_version,
			);
			keydatas_successRsp($versions);
		}//.. do by kds_flag 
	}//... has __kds_flag end
}


function  keydatas_downloadImages(){
 $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'tiff', 'webp', 'ico'];  
 try{
	$downloadFlag = keydatas_getPostValSafe("__kds_download_imgs_flag");
	if (!empty($downloadFlag) && $downloadFlag== "true") {
		$docImgsStr = keydatas_getPostValSafe("__kds_docImgs");
		if (!empty($docImgsStr)) {
			$docImgs = explode(',',$docImgsStr);
			if (is_array($docImgs)) {
				$upload_dir = wp_upload_dir();
				foreach ($docImgs as $imgUrl) {
				 	// 清理和验证URL  
					$imgUrl = filter_var($imgUrl, FILTER_SANITIZE_URL);  
					if (!filter_var($imgUrl, FILTER_VALIDATE_URL)) {  
						continue; // 跳过非法的URL  
					} 
					// 尝试获取图片扩展名  
					$parsedUrl = parse_url($imgUrl);  
					$path = isset($parsedUrl['path']) ? $parsedUrl['path'] : '';  
					$extension = pathinfo($path, PATHINFO_EXTENSION);  

					// 检查扩展名是否在允许的图片格式中
					if (!in_array(strtolower($extension), $allowedExtensions)) {  
						continue; // 跳过非图片格式的URL  
					} 
					$urlItemArr = explode('/',$imgUrl);
					$itemLen=count($urlItemArr);
					if($itemLen>=3){
						//
						$fileRelaPath=$urlItemArr[$itemLen-3].'/'.$urlItemArr[$itemLen-2];
						$imgName=$urlItemArr[$itemLen-1];
						$finalPath=$upload_dir['basedir'] . '/'.$fileRelaPath;
						if (wp_mkdir_p($finalPath)) {
							$file = $finalPath . '/' . $imgName;
							if(!file_exists($file)){
								// 下载图片前，先检查HTTP响应头是否为图片  
                                $headers = @get_headers($imgUrl, 1);  
                                if (strpos($headers[0], '200 OK') !== false && strpos($headers['Content-Type'], 'image/') !== false) {  
								$doc_image_data = file_get_contents($imgUrl);
								if ($doc_image_data !== false) { 
									file_put_contents($file, $doc_image_data);
								}
								}
							}
						}
					}
				}//.for
			}//..is_array
		}				
	}
 } catch (Exception $ex) {
	
 }		
}

?>