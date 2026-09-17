<?php
/*
Plugin Name: 简数采集器
Plugin URI: http://www.keydatas.com/caiji/wordpress-cms-caiji
Description: 简数采集器(keydatas.com)是一个通用、简单、智能、在线的网页数据采集器，功能强大，操作简单。支持按关键词采集；集成AI大模型接口、翻译等服务；图片下载支持存储到阿里云OSS、七牛、腾讯云对象存储等。
Version: 2.8.1
Author: keydatas
Author URI: http://www.keydatas.com
License: GPLv2 or later
Text Domain: keydatas
*/

//图片下载上限（2.8.1新增）：单张最大12MB、单张最长30秒。
//用!defined()保护，主机若有特殊需要可在wp-config.php里直接覆盖，不必等插件发版。
if (!defined('KEYDATAS_IMG_MAX_BYTES')) {
	define('KEYDATAS_IMG_MAX_BYTES', 12 * 1024 * 1024);
}
if (!defined('KEYDATAS_IMG_TIMEOUT')) {
	define('KEYDATAS_IMG_TIMEOUT', 30);
}

//采集端是把响应体直接当JSON解析的：任何PHP警告、提示或其它插件的echo混进去都会让解析失败，
//而采集端失败后会重试，从而产生重复文章/商品。
//所以只要是采集请求，就从「插件文件被加载」这一刻起屏蔽错误输出并开一层输出缓冲。
//必须放在文件作用域而不是keydatas_post_doc()内部：按active_plugins顺序排在本插件之后的插件
//（woocommerce就是）会在我们的init回调之前执行并可能输出，只有在加载时就开缓冲才拦得住。
//非采集请求完全不受影响。
if (isset($_GET['__kds_flag']) && is_string($_GET['__kds_flag']) && '' !== $_GET['__kds_flag']) {
	@ini_set('display_errors', '0');   //错误只进日志，不进响应体
	@ini_set('log_errors', '1');       //有些主机display_errors=1但log_errors=0，别把信息丢了
	@ob_start();
}

function keydatas_successRsp($data = "", $msg = "") {
    keydatas_rsp(1,0, $data, $msg);
}
function keydatas_failRsp($code = 0, $data = "", $msg = "") {
    keydatas_rsp(0,$code, $data, $msg);
}

function keydatas_rsp($result = 1,$code = 0, $data = "", $msg = "") {
	//丢弃此前所有非预期输出（PHP警告、其它插件的echo），保证响应体是纯JSON。
	$junk = '';
	while (ob_get_level() > 0) {
		$level_before = ob_get_level();
		$buf = ob_get_clean();
		//判据必须是「层数有没有降」，不能看返回值：
		//缓冲层不可删除时（ob_start()传了不含REMOVABLE的flags，个别压缩/缓存插件会这么干），
		//有的PHP版本返回false，PHP 8.0.26实测返回的是缓冲内容本身——按返回值判断会漏。
		//层数没降就说明这层删不掉，再循环就是死循环，把请求耗到超时，
		//那正是本函数要消灭的「采集端拿到空响应后重试」。
		if (ob_get_level() >= $level_before) {
			break;
		}
		if (is_string($buf) && '' !== $buf && strlen($junk) < 500) {
			$junk .= substr($buf, 0, 500 - strlen($junk));
		}
	}
	if ('' !== $junk) {
		//这条主要用于其它插件的echo。
		error_log('keydatas: discarded output before JSON: ' . preg_replace('/\s+/', ' ', $junk));
	}
	$json = keydatas_json_encode(array("rs" => $result, "code" => $code, "data" => $data, "msg" => urlencode($msg)));
	if (!headers_sent()) {
		header('Content-Type: application/json; charset=utf-8');
	}
	die($json);
}

/**
 * 生成响应JSON，保证任何情况下返回的都是「一段合法JSON」。
 *
 * json_encode遇到非法UTF-8会返回false，直接die(false)会输出空响应体；
 * 采集端拿到空响应体等同于失败，会重试，于是又产生重复数据。
 * wp_json_encode()（WP 4.1+，与本插件声明的Requires at least一致）内部已对非法UTF-8
 * 做替换，优先用它；都没有办法时手工拼一段一定合法的兜底JSON。
 *
 * @param mixed $data 响应数据
 * @return string 合法JSON字符串
 */
function keydatas_json_encode($data) {
	if (function_exists('wp_json_encode')) {
		$json = wp_json_encode($data);
	} else {
		$json = json_encode($data);
	}
	if (false !== $json && '' !== $json && is_string($json)) {
		return $json;
	}
	return '{"rs":0,"code":0,"data":"","msg":"' . urlencode('响应编码失败') . '"}';
}
function keydatas_genRandomIp(){
	// $randIP = "".mt_rand(0,255).".".mt_rand(0,255).".".mt_rand(0,255).".".mt_rand(0,255);
	$randIP = "" . wp_rand(0, 255) . "." . wp_rand(0, 255) . "." . wp_rand(0, 255) . "." . wp_rand(0, 255);
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
    //return $min + mt_rand()/mt_getrandmax() * ($max-$min);
	 return $min + wp_rand() / mt_getrandmax() * ($max - $min);
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
//优先级20：必须晚于WooCommerce自身挂在init默认优先级(10)的初始化。
//本插件按active_plugins顺序排在woocommerce之前，同优先级下会先执行，导致商品路径在WC尚未
//初始化完成时就动手：$wpdb->wc_category_lookup未定义（创建product_cat时抛PHP警告并污染JSON）、
//WooCommerce的商品图片尺寸未注册。非采集请求本回调立即返回，优先级变化对其无任何影响。
add_action('init', 'keydatas_post_doc', 20);
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
			//加is_string守卫：post_content传成数组（post_content[]=x）时，PHP 8下wp_kses_post
			//内部会对数组做preg_match_all而抛TypeError（致命错误、HTML 500），PHP 7下则是
			//一串警告混进响应体。两种都会让采集端判定失败并重试。当作空正文处理即可。
			if(isset($_POST["post_content"]) && is_string($_POST["post_content"])){
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

			//WooCommerce商品流程标志（非商品请求在第一次比较即返回false）
			$wc_product = keydatas_is_wc_product($postType);
			//已由WooCommerce商品对象接管的meta名，供下面的__kdsExt_循环跳过
			$wc_handled_meta = array();
			
			/*$postStatus = 'publish';
			if (isset($_POST["post_status"]) && in_array($_POST["post_status"], array('publish', 'draft'))) {
				$postStatus = $_POST["post_status"];
			}
			*/
			//商品常用「待审核」状态，仅在商品流程下放开（非商品请求恒等于原白名单，行为不变）
			$allowedStatus = array('publish', 'draft');
			if ($wc_product) {
				$allowedStatus[] = 'pending';
			}
			$postStatus = keydatas_getPostValSafe("post_status");
			if (empty($postStatus) || !in_array($postStatus, $allowedStatus)) {
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
			} catch (Throwable $ex1) { }   //兜住PHP 7+的Error/TypeError（不继承Exception）
			}
			
			//标题唯一校验
            $title_unique = get_option('keydatas_title_unique', false);
			//error_log('title:'.stripslashes($my_post['post_title']), 3, '/var/log/wp_test.log');
			if($title_unique){				
				//只返回id
                if ( $wc_product ) {
                    //商品：只在同post_type内去重，避免被同名文章误判（否则rs=1但其实没建商品）
                    $post = $wpdb->get_row($wpdb->prepare("SELECT ID FROM $wpdb->posts WHERE post_title= %s and post_type= %s and post_status!='trash' and post_status!='inherit' ",stripslashes($my_post['post_title']),'product'));
                } else {
                    $post = $wpdb->get_row($wpdb->prepare("SELECT ID FROM $wpdb->posts WHERE post_title= %s and post_status!='trash' and post_status!='inherit' ",stripslashes($my_post['post_title'])));
                }
                if(!empty($post)){
					//这里可以补充图片
					keydatas_downloadImages();
					//返回访问路径
				  	keydatas_successRsp(array("url" => get_permalink($post->ID)."#相同标题文章已存在"));
                }
            }

			//商品SKU重复校验：SKU已存在视为已存在，跳过创建，返回已有商品链接
			//必须在wp_insert_post之前——「跳过创建」意味着什么都不建
			if ($wc_product) {
				$wc_sku = keydatas_getPostValSafe("__kdsExt__sku");
				if ('' !== $wc_sku) {
					$wc_exists_id = wc_get_product_id_by_sku($wc_sku);
					if ($wc_exists_id) {
						//与标题去重路径行为保持一致
						keydatas_downloadImages();
						keydatas_successRsp(array("url" => get_permalink($wc_exists_id) . "#相同SKU商品已存在"));
					}
				}
			}

			$post_date=keydatas_getPostValSafe("post_date");
			if (!empty($post_date)) {
				$post_date = intval($post_date);
				//$my_post['post_date'] = date("Y-m-d H:i:s", $post_date);
				$my_post['post_date'] = gmdate("Y-m-d H:i:s", $post_date);
			} else {
				//$my_post['post_date'] = date("Y-m-d H:i:s", time());
				$my_post['post_date'] = gmdate("Y-m-d H:i:s", time());
			}

			$author = keydatas_getPostValSafe("post_author");
			if (!empty($author)) {
				//$author = htmlspecialchars_decode($author);
				if($author == "rand_users"){
					$randNum=keydatas_randFloat();
					//SELECT ID FROM $wpdb->users order by rand() limit 1
					/** $user_id = $wpdb->get_var("SELECT ID FROM $wpdb->users WHERE 
id >= ((SELECT MAX(id) FROM $wpdb->users)-(SELECT MIN(id) FROM $wpdb->users)) * ".$randNum."+ (SELECT MIN(id) FROM $wpdb->users) LIMIT 1");			
					*/
					$user_id = $wpdb->get_var(
							$wpdb->prepare(
								"SELECT ID FROM $wpdb->users WHERE id >= ((SELECT MAX(id) FROM $wpdb->users)-(SELECT MIN(id) FROM $wpdb->users)) * %f + (SELECT MIN(id) FROM $wpdb->users) LIMIT 1",
								$randNum
							)
						);
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
			//WooCommerce适配：商品分类走product_cat分类法
			$cate_type = $wc_product ? "product_cat" : "category";
			//商品分类term_id暂存，插入后再写（WP核心的post_category只认category分类法）
			$wc_cate_ids = array();
			if (!empty($category)) {
				$cates = explode(',',$category);
				if (is_array($cates)) {
					$post_cates = array();
					$term = null;
					foreach ($cates as $cate) {
						//是否为数字
						$cat_id=0;
						if(is_numeric($cate)&&intval($cate)>0){
							if ( 'category' === $cate_type ) {
								//文章：老逻辑原样保留
								$cat_name = get_cat_name($cate);
								if(!empty($cat_name)){
									$cat_id=intval($cate);
								}
							} else {
								//商品：按product_cat分类法校验term_id（get_cat_name硬编码category，不能用）
								$cat_term = get_term(intval($cate), $cate_type);
								if ( ! is_wp_error($cat_term) && ! empty($cat_term) ) {
									$cat_id = intval($cate);
								}
							}
						}
						if($cat_id>0){
							array_push($post_cates, $cat_id);
						}else{
							$term = term_exists($cate, $cate_type);
							if ($term === 0 || $term === null) {
								$term = wp_insert_term($cate, $cate_type);
							}						
							if ($term !== 0 && $term !== null && !is_wp_error($term)) {
								array_push($post_cates, intval($term["term_id"]));
							}
						}
					}
					if (count($post_cates) > 0) {
						if ( 'category' === $cate_type ) {
							$my_post['post_category'] = $post_cates;
						} else {
							$wc_cate_ids = $post_cates;
						}
					}
				}
			}

			$post_tag = keydatas_getPostValSafe("post_tag");
			//WooCommerce适配：商品标签走product_tag分类法
			$tag_type = $wc_product ? "product_tag" : "post_tag";
			//商品标签term_id暂存，插入后再写
			$wc_tag_ids = array();
			if (!empty($post_tag)) {
				$tags = explode(',',$post_tag);
				if (is_array($tags)) {
					$post_tags = array();
					$term = null;
					foreach ($tags as $tag) {
						$term = term_exists($tag, $tag_type);
						if ($term === 0 || $term === null) {
							$term = wp_insert_term($tag, $tag_type);
						}
						if ($term !== 0 && $term !== null && !is_wp_error($term)) {
							array_push($post_tags, intval($term["term_id"]));
						}
					}
					if (count($post_tags) > 0) {
						if ( 'post_tag' === $tag_type ) {
							$my_post['tags_input'] = $post_tags;
						} else {
							$wc_tag_ids = $post_tags;
						}
					}
				}
			}
			
			kses_remove_filters();
			$post_id = wp_insert_post($my_post);
			kses_init_filters();

			//商品分类/标签落盘：WP核心的post_category/tags_input对product类型被静默忽略
			//（分类法硬编码为category/post_tag），必须在插入之后显式写入
			if ($wc_product && !empty($post_id) && !is_wp_error($post_id)) {
				//这里用$cate_type/$tag_type而不是写死product_cat/product_tag：这两个数组只在
				//「分类法不是category/post_tag」时才会被赋值，所以取值必然就是商品分类法，
				//分类法名始终只有$cate_type/$tag_type一个出处。
				if ( ! empty($wc_cate_ids) ) {
					wp_set_post_terms($post_id, $wc_cate_ids, $cate_type, false);
				}
				if ( ! empty($wc_tag_ids) ) {
					wp_set_post_terms($post_id, $wc_tag_ids, $tag_type, false);
				}
			}

			if (empty($post_id) || is_wp_error($post_id)) {
				keydatas_failRsp(1500, "post_id is Empty", "插入文章失败");
			}
			//缩略图处理（下载、查重、落盘逻辑统一在keydatas_import_image内）
			$image_url = keydatas_getPostValSafe("__kds_feature_url");
			if (empty($image_url)) {
				$image_url = keydatas_getPostValSafe("post_thumbnail");
			}
			$featured_attach_id = 0;
			if (!empty($post_id) && !empty($image_url)) {
				$featured_attach_id = keydatas_import_image($image_url, $post_id);
			}
			
			//商品相册
			//WooCommerce的_product_image_gallery存的是「逗号分隔的附件ID」，而采集端发来的是图片URL。
			//直接写进去会被WooCommerce按ID解析成0、相册静默为空，所以这里逐个导入成附件后再写回ID。
			//值全为纯数字时视为「本来就是ID」原样透传，兼容手工填ID的既有配置。
			if ($wc_product && !empty($post_id)) {
				//[\s,]+ 已经把分隔用的空白和逗号一并吃掉，所以不必再trim、也不会切出空串
				$gallery_parts = preg_split('/[\s,]+/', keydatas_getPostValSafe("__kdsExt__product_image_gallery"), -1, PREG_SPLIT_NO_EMPTY);
				$gallery_all_ids = !empty($gallery_parts);
				foreach ($gallery_parts as $gallery_part) {
					if (!ctype_digit($gallery_part)) {
						$gallery_all_ids = false;
						break;
					}
				}
				$gallery_ids = array();
				foreach ($gallery_parts as $gallery_part) {
					//按图片URL导入时，同一URL会被keydatas_import_image查重复用，不会重复下载
					$gallery_id = $gallery_all_ids ? intval($gallery_part) : keydatas_import_image($gallery_part, $post_id);
					if ($gallery_id > 0 && !in_array($gallery_id, $gallery_ids, true)) {
						$gallery_ids[] = $gallery_id;
					}
				}
				if (!empty($gallery_ids)) {
					update_post_meta($post_id, '_product_image_gallery', implode(',', $gallery_ids));
					//没传主图时用相册第一张兜底，否则商品在列表页和详情页只有占位图
					if (empty($featured_attach_id)) {
						$featured_attach_id = intval($gallery_ids[0]);
					}
				}
			}
			
			if (!empty($post_id) && !empty($featured_attach_id)) {
				set_post_thumbnail($post_id, $featured_attach_id);
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
			//WooCommerce商品字段同步（价格/促销价/SKU/库存等）
			//必须在下面__kdsExt_循环之前：WooCommerce靠「属性变更检测」决定是否生成_price与
			//刷新wc_product_meta_lookup；meta先被原样写进去会让属性看起来「没变」，_price永远不会生成。
			if ($wc_product && !empty($post_id) && !is_wp_error($post_id)) {
				//「已接管的meta名单」只在这一处产出：WC同步接管的，加上上面相册块自己写过的。
				//相册这行必须在函数调用之后——keydatas_wc_sync_product出错时会把名单清空返回，
				//写在调用之前就会被一并清掉，__kdsExt_循环又会把相册的URL原样盖回ID之上。
				$wc_handled_meta = keydatas_wc_sync_product($post_id);
				$wc_handled_meta['_product_image_gallery'] = true;
			}

			//其它meta数据处理
			if (!empty($post_id)) {
				foreach ($_POST as $key => $value) { 
					if (strpos($key, '__kdsExt_') === 0) {
						$real_name=substr($key,9);
						if (!empty($real_name)) {
							//已由WooCommerce商品对象接管的字段，跳过原样写库
							//（否则会用未经校验的脏值覆盖WC规范化后的值）
							if ( isset($wc_handled_meta[$real_name]) ) {
								continue;
							}
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
 $allowedExtensions = array('jpg', 'jpeg', 'png', 'gif', 'bmp', 'tiff', 'webp', 'ico');
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
					$imgUrl = trim($imgUrl);
					if (!keydatas_is_http_url($imgUrl)) {  
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
						//URL路径段里出现 .. 会把文件写到uploads目录之外，而wp_mkdir_p()拦不住：
						//它的防穿越检查排在 file_exists() 提前返回之后，而 <basedir>/../.. 是存在的。
						//正常图源不会给出这种地址，直接跳过。
						if ('..' === $urlItemArr[$itemLen-3] || '..' === $urlItemArr[$itemLen-2] || '..' === $imgName) {
							continue;
						}
						$finalPath=$upload_dir['basedir'] . '/'.$fileRelaPath;
						if (wp_mkdir_p($finalPath)) {
							$file = $finalPath . '/' . $imgName;
							if(!file_exists($file)){
								//下载到临时文件再改名：老实现是 get_headers + file_get_contents 打同一个URL，
								//两次TCP+TLS握手；且get_headers拿到的是重定向之前那段的状态行，会把重定向图源
								//判成非200而静默跳过。现在一次连接同时取回响应头与正文，并跟随重定向
								//（与主图/相册路径行为一致）；失败时不留下半截文件，下次推送还能重下。
								//临时名不用$imgName派生：它可能带查询串（a.jpg?w=300），在Windows上是非法文件名。
								$tmpFile = $finalPath . '/' . md5($imgUrl) . '-' . keydatas_tmp_suffix() . '.tmp';
								$fetch = keydatas_fetch_image_to_file($imgUrl, $tmpFile, KEYDATAS_IMG_MAX_BYTES, KEYDATAS_IMG_TIMEOUT, 200, 'image/');
								if (!empty($fetch['ok'])) {
									if (!@rename($tmpFile, $file)) {
										@unlink($tmpFile);
										keydatas_log_image_failure($imgUrl, '写盘失败');
									}
								} else {
									keydatas_log_image_failure($imgUrl, $fetch['why']);
								}
							}
						}
					}
				}//.for
			}//..is_array
		}				
	}
 } catch (Exception $ex) {
	
 } catch (Throwable $ex) { }   //兜住PHP 7+的Error/TypeError（不继承Exception）
}

/**
 * 判断是否是可下载的http/https地址。
 *
 * 原实现用 filter_var($url, FILTER_VALIDATE_URL) 校验，有两个问题：
 *  1. 该过滤器按DNS规范校验主机名，会拒绝「含下划线的主机名」（如 xxx_test.example.com）
 *     和中文等国际域名，导致正文图片被静默跳过、永不下载；
 *  2. 它并不校验协议，ftp://、gopher://、file:// 都能通过。
 * 另外原有的 filter_var($url, FILTER_SANITIZE_URL) 会把中文域名直接破坏成 http://.//x.png。
 * 这里统一改用 parse_url 显式限定 http/https。
 *
 * @param string $url
 * @return bool
 */
function keydatas_is_http_url($url) {
	$parsed = parse_url($url);
	if (empty($parsed) || empty($parsed['host'])) {
		return false;
	}
	if (!isset($parsed['scheme']) || !in_array(strtolower($parsed['scheme']), array('http', 'https'), true)) {
		return false;
	}
	return true;
}

/**
 * 按图片URL查找此前是否已经导入过附件，供下载前查重。
 *
 * 采集端常把同一张图反复发来（同一商品的主图与相册之间、不同商品之间，甚至文章与商品之间），
 * 每次都重新下载会白耗带宽，并在媒体库里堆出一批指向同一个文件的重复附件。
 *
 * 两级查找：
 *  1. 本版本起在附件上写入来源标记 _kds_source_url，精确匹配即可命中（走meta_key索引）；
 *  2. 升级前导入的图片没有该标记，但文件名固定是 md5(URL)（见老版本实现），
 *     按 _wp_attached_file 的basename兜底，避免升级后把老图又下一遍。
 * 命中后仍要确认文件确实还在磁盘上——被手工删过就当作没找到，让调用方重新下载。
 *
 * @param string $url 规范化后的最终URL（必须与下载时用的是同一个串，否则md5对不上）
 * @return int 附件ID；未找到返回0
 */
function keydatas_find_image_attachment($url) {
	if ('' === $url) {
		return 0;
	}
	$attach_id = 0;
	
	$found = get_posts(array(
		'post_type'      => 'attachment',
		'post_status'    => 'inherit',
		'posts_per_page' => 1,
		'fields'         => 'ids',
		'no_found_rows'  => true,
		'orderby'        => 'none',
		'meta_key'       => '_kds_source_url',
		'meta_value'     => $url,
	));
	if (!empty($found)) {
		$attach_id = intval($found[0]);
	}
	
	if (!$attach_id) {
		global $wpdb;
		$url_md5   = md5($url);
		$attach_id = intval($wpdb->get_var($wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value LIKE %s LIMIT 1",
			'%/' . $wpdb->esc_like($url_md5) . '.%'
		)));
	}
	
	if ($attach_id) {
		$file = get_attached_file($attach_id);
		if (!empty($file) && file_exists($file)) {
			//补写来源标记。升级前导入的附件没有这个标记，只能靠上面那条LIKE扫描找到，
			//而该查询的meta_value前缀是「%」、索引用不上；补一次以后同一URL就走索引精确命中。
			//必须放在file_exists通过之后：附件被手工删掉时不能补，否则下次会命中一个死ID。
			//走索引命中时值本来就相同，update_post_meta会自行短路，不会多写一次。
			update_post_meta($attach_id, '_kds_source_url', $url);
			return $attach_id;
		}
	}
	return 0;
}

/**
 * 内容类型 => 文件扩展名。
 * 只认下面这几种；认不出来的一律放弃，以免把非图片内容存进媒体库。
 *
 * 注意与正文图下载的$allowedExtensions不是同一份名单，别互相套用：
 * 那份按「URL的扩展名」判断，这份按「下载到的真实内容」判断，所以这里能多认下avif
 * （URL结尾是.png、内容其实是avif的情况，光看扩展名是认不出来的）。
 *
 * @param string $mime getimagesize()返回的mime
 * @return string 扩展名；不支持时返回空串
 */
function keydatas_image_ext_by_mime($mime) {
	$map = array(
		'image/jpeg'               => 'jpg',
		'image/png'                => 'png',
		'image/gif'                => 'gif',
		'image/bmp'                => 'bmp',
		'image/tiff'               => 'tiff',
		'image/webp'               => 'webp',
		'image/avif'               => 'avif',
		'image/x-icon'             => 'ico',
		'image/vnd.microsoft.icon' => 'ico',
	);
	return isset($map[$mime]) ? $map[$mime] : '';
}

/**
 * 记录一次图片下载失败。
 *
 * 老实现里失败是完全静默的：返回0、接口照样返回rs=1，客户和我们都无从知道图片丢了。
 * 但本插件装在大量客户站上，任何默认打开的、无节制的日志都会变成灾难，所以：
 *  - 用非autoload的transient限流，同一站点每小时最多写一条；
 *  - 单条含中文原因与URL，足以定位是哪个图源出问题；
 *  - 需要彻底关闭时，在wp-config.php里加 define('KEYDATAS_IMG_LOG', false); 即可。
 *
 * @param string $url 图片地址
 * @param string $why 中文失败原因
 */
function keydatas_log_image_failure($url, $why) {
	static $logged_this_request = false;
	if (defined('KEYDATAS_IMG_LOG') && !KEYDATAS_IMG_LOG) {
		return;
	}
	if ($logged_this_request) {
		return;   //本次请求已经记过，不再重复查transient
	}
	$logged_this_request = true;
	if (get_transient('keydatas_img_err_logged')) {
		return;
	}
	set_transient('keydatas_img_err_logged', 1, HOUR_IN_SECONDS);
	error_log('keydatas image error: ' . $why . ' | ' . substr($url, 0, 300));
}

/**
 * 生成图片临时文件名的后缀。
 *
 * uniqid() 是微秒级，两个并发的 php-fpm 子进程仍可能撞名，所以再带上进程号；
 * 而 getmypid() 出现在部分主机的 disable_functions 里，PHP 8 上直接调用会抛 Error——
 * 那会让采集端拿到空响应体并重试，正是本次要消灭的问题，所以先探测再调用。
 *
 * @return string
 */
function keydatas_tmp_suffix() {
	return uniqid() . '-' . (function_exists('getmypid') ? getmypid() : mt_rand());
}

/**
 * 把远程图片流式下载到临时文件：一次连接同时取回响应头与正文，内存占用与图片大小无关。
 *
 * 与老的 file_get_contents 相比，这是本次有意改变的四点（前两点是用户批准的）：
 *  1. 显式超时，不再依赖 php.ini 的 default_socket_timeout（默认60秒，且是按每次read计算，
 *     「慢滴」服务器每次都在超时前发一个字节就能无限拖住一个php-fpm进程）；
 *  2. 体积上限，超限立即中断；
 *  3. 分块落盘，不把整张图读进内存（老实现整份读入，一张大图就能吃掉128M内存上限）；
 *  4. 按Content-Length校验完整性，挡住「下载被截断的图被当成好图永久接受」
 *     ——老实现 file_put_contents 短写不会返回false，而getimagesize只读文件头，所以截断的图
 *     能通过校验、被改名落盘、注册成附件、打上来源标记，之后去重保证它永不被重新下载。
 *
 * 失败时保证不留下 $tmp_file。成功时 $tmp_file 已完整落盘并关闭，由调用方决定后续改名。
 *
 * @param string   $url         图片地址（本函数会再断言一次必须是http/https）
 * @param string   $tmp_file    临时文件路径，须与最终文件同目录（同文件系统，rename才不是拷贝）
 * @param int      $max_bytes   体积上限（字节）
 * @param int      $timeout     超时秒数
 * @param int|null $want_status 要求的最终HTTP状态码；传null表示「只要不是4xx/5xx就算成功」
 * @param string|null $ct_prefix 要求的Content-Type前缀；传null表示不检查
 * @return array ok(bool) why(string中文原因) bytes(int) ctype(string) code(int)
 */
function keydatas_fetch_image_to_file($url, $tmp_file, $max_bytes, $timeout, $want_status = null, $ct_prefix = null) {
	//协议断言不能省：下面 stream_context 里的 http 超时只对 http/https 包装器生效，
	//换成 ftp:// 之类会直接绕过 timeout 回落到 default_socket_timeout（实测60秒）。
	if (!keydatas_is_http_url($url)) {
		return array('ok' => false, 'why' => '不是http/https地址', 'bytes' => 0, 'ctype' => '', 'code' => 0);
	}

	//先克隆默认context再叠加我们的选项，绝不能直接新建一个只含timeout的context：
	//stream_context_create() 是「替换」而不是「合并」——实测传入一个只设了timeout的context后，
	//主机用 stream_context_set_default() 设过的 user_agent 直接消失（服务器侧收到空的User-Agent）。
	//同理，代理、自定义CA、request_fulluri 等也会被一并丢掉，那些站点的图片会静默停止下载。
	$ctx_opts = stream_context_get_options(stream_context_get_default());
	if (!isset($ctx_opts['http']) || !is_array($ctx_opts['http'])) {
		$ctx_opts['http'] = array();
	}
	$ctx_opts['http']['timeout']         = $timeout;
	$ctx_opts['http']['follow_location'] = 1;
	$ctx_opts['http']['max_redirects']   = 5;
	$ctx_opts['http']['ignore_errors']   = 1;   //让4xx/5xx也返回响应头，好记录真实原因
	$ctx = stream_context_create($ctx_opts);

	//墙钟从「连接之前」就开始计。放在fopen之后的话，DNS/连接/TLS/跟随重定向/收响应头
	//这几步都在计时之外，而context里的timeout只是「每次read」的上限，
	//一个accept后每次都在超时前吐一个字节的服务器能把这段拖到远超$timeout，
	//所以必须把已花掉的时间也算进这张图的预算里。
	$deadline = time() + $timeout;

	$fp = @fopen($url, 'rb', false, $ctx);
	if (!$fp) {
		return array('ok' => false, 'why' => '连接失败', 'bytes' => 0, 'ctype' => '', 'code' => 0);
	}

	//解析响应头。重定向会被跟随，所以 wrapper_data 是「多段响应首尾相接」的扁平数组，
	//每遇到一个 HTTP/ 状态行就进入新的一段：与状态行一同把 content-* 清空，最后留下的就是最终那段的。
	//（老实现用 get_headers($url,1)，它会把两段的同名头合并成数组，
	//  strpos(数组,...) 在PHP 8上抛TypeError致命错误，PHP 7上则是警告混进JSON。）
	$code = 0; $ctype = ''; $clen = null; $encoded = ''; $chunked = false;
	$meta = stream_get_meta_data($fp);
	if (isset($meta['wrapper_data']) && is_array($meta['wrapper_data'])) {
		foreach ($meta['wrapper_data'] as $line) {
			if (!is_string($line)) {
				continue;
			}
			if (preg_match('#^HTTP/\S+\s+(\d{3})#i', $line, $m)) {
				$code    = intval($m[1]);
				$ctype   = '';
				$clen    = null;
				$encoded = '';
				$chunked = false;
				continue;
			}
			$p = strpos($line, ':');
			if (false === $p) {
				continue;
			}
			$name = strtolower(trim(substr($line, 0, $p)));
			$val  = trim(substr($line, $p + 1));
			if ('content-type' === $name)     { $ctype   = $val; }
			if ('content-length' === $name)   { $clen    = $val; }
			if ('content-encoding' === $name) { $encoded = $val; }
			//分块传输时Content-Length按规范是无效的，而PHP的http包装器交给我们的是「已解码」的正文：
			//两者同时出现（有服务器这么干）时若还拿长度比对，会把一张完整的图判成传输中断。
			if ('transfer-encoding' === $name && false !== stripos($val, 'chunked')) { $chunked = true; }
		}
	}
	$fail = array('ok' => false, 'why' => '', 'bytes' => 0, 'ctype' => $ctype, 'code' => $code);
	if (null === $want_status) {
		//等价于老实现「file_get_contents 遇4xx/5xx返回false」的宽松判定
		if (0 === $code || $code >= 400) {
			@fclose($fp);
			$fail['why'] = (0 === $code) ? '无HTTP响应' : ('HTTP ' . $code);
			return $fail;
		}
	} else if ($code !== $want_status) {
		@fclose($fp);
		$fail['why'] = 'HTTP ' . $code;
		return $fail;
	}
	if (null !== $ct_prefix && 0 !== strpos($ctype, $ct_prefix)) {
		@fclose($fp);
		$fail['why'] = '非图片类型(' . (('' === $ctype) ? '无Content-Type' : $ctype) . ')';
		return $fail;
	}

	//声明的长度已经超限：不必下载，直接拒绝（省掉整段传输）
	if (null !== $clen && ctype_digit(trim($clen)) && intval($clen) > $max_bytes) {
		@fclose($fp);
		$fail['why'] = '超过大小上限' . intval($max_bytes / 1048576) . 'MB';
		return $fail;
	}

	$dst = @fopen($tmp_file, 'wb');
	if (!$dst) {
		@fclose($fp);
		$fail['why'] = '写盘失败';
		return $fail;
	}

	$written  = 0;
	$why      = '';
	while (!feof($fp)) {
		//context里的timeout是「每次read」的超时，挡不住每次都在超时前发一个字节的慢滴服务器，
		//所以这里再用墙钟兜一道，保证单张图的真实上限就是$timeout秒。
		if (time() > $deadline) {
			$why = '下载超时' . intval($timeout) . '秒';
			break;
		}
		$chunk = @fread($fp, 65536);
		if (false === $chunk) {
			$read_meta = stream_get_meta_data($fp);
			$why = (!empty($read_meta['timed_out'])) ? ('下载超时' . intval($timeout) . '秒') : '读取失败';
			break;
		}
		if ('' === $chunk) {
			$read_meta = stream_get_meta_data($fp);
			if (!empty($read_meta['timed_out'])) {
				$why = '下载超时' . intval($timeout) . '秒';
				break;
			}
			continue;
		}
		$written += strlen($chunk);
		if ($written > $max_bytes) {
			$why = '超过大小上限' . intval($max_bytes / 1048576) . 'MB';
			break;
		}
		if (false === @fwrite($dst, $chunk)) {
			$why = '写盘失败';
			break;
		}
	}
	$reached_eof = feof($fp);
	@fclose($fp);
	//fclose的返回值必须查：磁盘写满这类错误是在这里浮出来的
	$flush_ok = @fclose($dst);

	if ('' !== $why || !$flush_ok) {
		@unlink($tmp_file);
		$fail['why'] = ('' !== $why) ? $why : '写盘失败';
		return $fail;
	}
	if (!$reached_eof) {
		@unlink($tmp_file);
		$fail['why'] = '传输中断';
		return $fail;
	}
	if (0 === $written) {
		@unlink($tmp_file);
		$fail['why'] = '空响应';
		return $fail;
	}
	//feof()区分不了「正常结束」和「对端提前FIN」，两者都是eof，所以要拿Content-Length比对。
	//被压缩传输时长度不再是正文字节数，跳过这项校验；但identity是「没有压缩」，比对照样有效，
	//漏掉它等于给truncated开了一扇门（对端只要多送一个 Content-Encoding: identity 就能绕过）。
	$clen_usable = (null !== $clen) && !$chunked && ctype_digit(trim($clen))
		&& ('' === $encoded || 'identity' === strtolower(trim($encoded)));
	if ($clen_usable && intval($clen) !== $written) {
		@unlink($tmp_file);
		$fail['why'] = '传输中断(内容不完整)';
		return $fail;
	}

	return array('ok' => true, 'why' => '', 'bytes' => $written, 'ctype' => $ctype, 'code' => $code);
}

/**
 * 把一张远程图片导入媒体库，返回附件ID。
 *
 * 这是老「特色图片」代码的加强版，主图与商品相册共用：
 *  1. 先查重（keydatas_find_image_attachment），命中就直接复用，不发起下载；
 *  2. 扩展名按下载到的真实内容判定，不再硬编码 jpg。老实现无条件存成 .jpg，
 *     导致PNG/WebP源图在库里的mime是image/jpeg、扩展名与实际内容不符；
 *  3. 下载失败或内容不是图片时直接放弃，不再写空文件、不再注册空附件
 *     （老实现 file_get_contents 失败会写出0字节文件，然后照样建成附件）。
 *
 * 复用已有附件时不会改动它的归属（post_parent），否则会破坏原先引用它的文章。
 *
 * @param string $url       图片URL，支持 http/https、//host/x 与站内相对路径 /x
 * @param int    $parent_id 新附件的归属文章ID
 * @return int 附件ID；失败返回0
 */
function keydatas_import_image($url, $parent_id) {
	$url = trim($url);
	if ('' === $url) {
		return 0;
	}
	
	//URL规范化：补全协议相对地址与站内相对路径
	//（沿用老逻辑，保证md5结果与升级前一致，老图才认得出来）
	if (substr($url, 0, 2) === "//") {
		$image_url_final = 'http:' . $url;
	} else if (strpos($url, '/') === 0) {
		$image_url_final = get_home_url() . $url;
	} else {
		$image_url_final = $url;
	}
	//协议白名单统一交给keydatas_is_http_url（协议相对地址与站内相对路径在上面已补全）
	if (!keydatas_is_http_url($image_url_final)) {
		return 0;
	}
	
	//① 查重：已经导入过就直接复用，绝不重复下载
	$existing_id = keydatas_find_image_attachment($image_url_final);
	if ($existing_id) {
		return $existing_id;
	}
	
	$upload_dir = wp_upload_dir();
	if (wp_mkdir_p($upload_dir['path'])) {
		$dir = $upload_dir['path'];
	} else {
		$dir = $upload_dir['basedir'];
	}

	//② 流式下载到临时文件（一次连接取回响应头+正文，内存有界，超时与体积上限见助手）
	//临时名必须带随机后缀：采集端可能并发发同一张图，共用一个文件名会互相截断（见keydatas_tmp_suffix）
	$tmp_file = $dir . '/' . md5($image_url_final) . '-' . keydatas_tmp_suffix() . '.tmp';
	//主图/相册不检查Content-Type：老实现这里不做类型判断、只看下面的getimagesize，
	//保持同样的宽松度，否则今天能导入的图会消失。want_status传null等价于老实现
	//「file_get_contents 遇4xx/5xx返回false」的成功判定。
	$fetch = keydatas_fetch_image_to_file($image_url_final, $tmp_file, KEYDATAS_IMG_MAX_BYTES, KEYDATAS_IMG_TIMEOUT, null, null);
	if (empty($fetch['ok'])) {
		keydatas_log_image_failure($image_url_final, $fetch['why']);
		return 0;
	}

	//③ 由真实内容判定类型（getimagesize读内容，不看扩展名）
	$image_info = @getimagesize($tmp_file);
	if (!is_array($image_info) || empty($image_info['mime'])) {
		@unlink($tmp_file);
		keydatas_log_image_failure($image_url_final, '无法识别的图片格式');
		return 0;
	}
	$ext = keydatas_image_ext_by_mime($image_info['mime']);
	if ('' === $ext) {
		@unlink($tmp_file);
		keydatas_log_image_failure($image_url_final, '不支持的图片格式(' . $image_info['mime'] . ')');
		return 0;
	}

	//④ 定名落盘：文件名仍是 md5(URL)，只有扩展名随真实内容变化
	$filename = sanitize_file_name(md5($image_url_final) . '.' . $ext);
	$file     = $dir . '/' . $filename;
	if (!@rename($tmp_file, $file)) {
		if (!@copy($tmp_file, $file)) {
			@unlink($tmp_file);
			keydatas_log_image_failure($image_url_final, '写盘失败');
			return 0;
		}
		@unlink($tmp_file);
	}
	
	//⑤ 注册附件
	$attachment = array(
		'post_mime_type' => $image_info['mime'],
		'post_title'     => sanitize_file_name($filename),
		'post_content'   => '',
		'post_status'    => 'inherit',
	);
	$attach_id = wp_insert_attachment($attachment, $file, $parent_id);
	if (empty($attach_id) || is_wp_error($attach_id)) {
		//图片已经落盘、附件行却没建成：媒体库里查不到，日志里若也没有线索就无从排查。
		//（文件留着不删：改名是覆盖式的，下次同URL重发会把它盖掉。）
		keydatas_log_image_failure($image_url_final, '注册附件失败');
		return 0;
	}
	require_once(ABSPATH . 'wp-admin/includes/image.php');
	$attach_data = wp_generate_attachment_metadata($attach_id, $file);
	wp_update_attachment_metadata($attach_id, $attach_data);
	//来源标记：下次同一URL直接命中，不再下载
	update_post_meta($attach_id, '_kds_source_url', $image_url_final);
	
	return intval($attach_id);
}

/**
 * 本次请求是否走WooCommerce商品流程。
 *
 * 重要：只能在init之后（即keydatas_post_doc内部）调用，绝不能在插件文件顶层调用。
 * WordPress按active_plugins顺序include插件文件，本插件字母序在woocommerce之前，
 * 顶层class_exists('WooCommerce')可能为假；而到init时，WooCommerce的产品类型与
 * product_cat/product_tag分类法已于init优先级5注册完毕，wc_get_product()可安全调用。
 *
 * @param string $postType 本次请求的post_type
 * @return bool
 */
function keydatas_is_wc_product($postType) {
	if ('product' !== $postType) {
		return false;
	}
	if (!class_exists('WooCommerce') || !function_exists('wc_get_product')) {
		return false;
	}
	//WooCommerce半初始化状态下不进入，退回老行为
	if (!post_type_exists('product') || !taxonomy_exists('product_cat')) {
		return false;
	}
	return true;
}

/**
 * WooCommerce商品字段映射：wp_postmeta字段名 => WC商品对象属性名。
 * 键名与采集端「发送目标第三步」填写的字段名一致（采集端前面加__kdsExt_前缀）。
 * 只列本插件主动接管的字段；不在此表中的__kdsExt_字段仍按老逻辑原样写入meta。
 *
 * @return array
 */
function keydatas_wc_field_map() {
	return array(
		'_backorders'        => 'backorders',
		'_low_stock_amount'  => 'low_stock_amount',
		'_tax_status'        => 'tax_status',
		'_tax_class'         => 'tax_class',
		'_virtual'           => 'virtual',
		'_downloadable'      => 'downloadable',
		'_sold_individually' => 'sold_individually',
		'_weight'            => 'weight',
		'_length'            => 'length',
		'_width'             => 'width',
		'_height'            => 'height',
		'_purchase_note'     => 'purchase_note',
	);
}

/**
 * 把本次请求__kdsExt_携带的WooCommerce字段写入新商品对象并保存。
 *
 * 【调用时机极其关键】必须在__kdsExt_通用meta循环之前调用。
 * WooCommerce的WC_Data::set_prop()带变更检测：若某属性的meta已存在且值相同，则不会
 * 记入changes，也就不会进updated_props；而_price只在价格类属性(regular_price/
 * sale_price/product_type/date_on_sale_*)进入updated_props时才会被写入
 * （见WC_Product_Data_Store_CPT::handle_updated_props()）。因此若先让__kdsExt_原样
 * 写入meta再调用set_*()，会因「值没变」而不生成_price，商品将不可购买。
 *
 * 本函数只作用于本次请求刚创建的新商品（调用方保证），绝不处理已存在的文章/商品，
 * 因此不可能覆盖或丢失存量数据。
 *
 * 任何异常都不外抛——此时文章已经建好，商品字段尽力补齐并仍返回成功，
 * 因为采集端没有幂等机制，返回失败会诱发重试从而创建重复数据。
 *
 * @param int $post_id 新插入的商品post ID
 * @return array 已由WC接管的meta名（meta名 => true），供调用方跳过原样写库
 */
function keydatas_wc_sync_product($post_id) {
	$handled = array();
	try {
		$product = wc_get_product($post_id);
		if (!is_object($product) || !is_a($product, 'WC_Product')) {
			return $handled;
		}

		//收集本次请求的__kdsExt_值（getPostValSafe对数组值返回空串）
		$meta = array();
		foreach ($_POST as $key => $value) {
			if (strpos($key, '__kdsExt_') === 0) {
				$name = substr($key, 9);
				if ('' !== $name) {
					$meta[$name] = keydatas_getPostValSafe($key);
				}
			}
		}
		if (empty($meta)) {
			return $handled;
		}

		//这五个字段一律由本函数接管：只要本次请求带了，就不允许下面的__kdsExt_循环再拿未经
		//规范化的原值覆盖一次——哪怕下面判定它是脏数据、决定不写入。不在此列的字段不受影响，
		//仍走「原样写meta」的老路。
		foreach (array('_regular_price', '_sale_price', '_sku', '_manage_stock', '_stock') as $handled_key) {
			if (isset($meta[$handled_key])) {
				$handled[$handled_key] = true;
			}
		}

		//--- 价格：wc_format_decimal规范化，规范化后为空视为脏数据丢弃 ---
		$regular = isset($meta['_regular_price']) ? wc_format_decimal($meta['_regular_price']) : null;
		$sale    = isset($meta['_sale_price'])    ? wc_format_decimal($meta['_sale_price'])    : null;
		if ('' === $regular) {
			$regular = null;
		}
		if ('' === $sale) {
			$sale = null;
		}
		//促销价不低于原价时WooCommerce保存时本就会清空，这里提前丢弃以免_price与_sale_price打架
		if (null !== $sale && (null === $regular || (float)$sale >= (float)$regular)) {
			$sale = null;
		}
		if (null !== $regular) {
			$product->set_regular_price($regular);
		}
		if (null !== $sale) {
			$product->set_sale_price($sale);
		}

		//--- SKU：先查重。update_lookup_table用REPLACE INTO，重复会抢占已有商品的SKU行 ---
		if (isset($meta['_sku'])) {
			$sku = trim($meta['_sku']);
			//空的、以及与站内已有商品重复的SKU都直接丢弃，不阻断商品创建
			if ('' !== $sku && (!function_exists('wc_product_has_unique_sku') || wc_product_has_unique_sku(0, $sku))) {
				try {
					$product->set_sku($sku);
				} catch (Exception $ex) {
				} catch (Throwable $ex) { }   //兜住PHP 7+的Error/TypeError（不继承Exception）
			}
		}

		//--- 库存：manage_stock必须先设，否则validate_props()会丢弃库存量 ---
		if (isset($meta['_manage_stock'])) {
			$product->set_manage_stock(wc_string_to_bool($meta['_manage_stock']));
		}
		if (isset($meta['_stock'])) {
			if (is_numeric(trim($meta['_stock']))) {
				$product->set_stock_quantity($meta['_stock']);
			}
		}

		//--- 其余白名单字段：逐个设置，单个属性异常不影响其它属性 ---
		foreach (keydatas_wc_field_map() as $meta_key => $prop) {
			if (!isset($meta[$meta_key]) || isset($handled[$meta_key])) {
				continue;
			}
			$setter = 'set_' . $prop;
			if (!is_callable(array($product, $setter))) {
				continue;   //低版本WooCommerce没有该属性：不拦截，退回原样写meta
			}
			try {
				$product->$setter($meta[$meta_key]);
				$handled[$meta_key] = true;
			} catch (Exception $ex) {
			} catch (Throwable $ex) { }   //兜住PHP 7+的Error/TypeError（不继承Exception）
		}

		//--- 保存：生成_price、刷新wc_product_meta_lookup、写product_type术语 ---
		$product->save();
	} catch (Exception $ex) {
		//降级彻底：全部退回老的「原样写meta」行为
		error_log('keydatas woocommerce sync error: ' . $ex->getMessage());
		$handled = array();
	} catch (Throwable $ex) {
		//这个分支必须和上面的catch (Exception) 做同样的事，空吞是错的：
		//$handled里已经登记了_regular_price等五个字段，调用方的__kdsExt_循环会据此跳过它们，
		//于是这些值既没进WooCommerce对象（save()没跑到），也没退化成原始meta——
		//商品在后台看着正常却不可购买，而且丢掉_sku之后SKU那道幂等也失效，采集端重发就是重复商品。
		error_log('keydatas woocommerce sync error: ' . $ex->getMessage());
		$handled = array();
	}
	return $handled;
}

?>