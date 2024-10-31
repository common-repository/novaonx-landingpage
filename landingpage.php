<?php
/*
Plugin Name: NovaonX Landingpage
Plugin URI: https://landingpage.novaonx.com/
Description: Connector to access content from NovaonX Landingpage service. (Landingpage: Landingpage Page Platform for Advertiser)
Author: NovaonX
Author URI: http://novaonx.com/
Version: 1.0
*/
require plugin_dir_path( __FILE__ ) . 'add-template.php';

if ( isset( $_SERVER['HTTP_ORIGIN'] ) ) {
	header( "Access-Control-Allow-Origin: {$_SERVER['HTTP_ORIGIN']}" );
	header( 'Access-Control-Allow-Credentials: true' );
	header( 'Access-Control-Max-Age: 86400' ); // cache for 1 day
}

if ( $_SERVER['REQUEST_METHOD'] == 'OPTIONS' ) {
	if ( isset( $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'] ) ) {
		header( "Access-Control-Allow-Methods: GET, POST, OPTIONS" );
	}
	if ( isset( $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'] ) ) {
		header( "Access-Control-Allow-Headers: {$_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']}" );
	}
	exit( 0 );
}

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


class NX_LandingPage {
	protected static $_instance = null;

	protected $_notices = array();

	public static function instance() {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}

		return self::$_instance;
	}

	public function __construct() {
		add_action( 'init', array( $this, 'init_endpoint' ) );
		add_action( 'admin_notices', array( $this, 'admin_notices' ), 15 );
		add_action( 'admin_menu', array( $this, 'add_landingpage_menu_item' ) );
		add_action( 'wp_ajax_landingpage_save_config', array( $this, 'save_config' ) );
		add_action( 'wp_ajax_landingpage_publish_ldp', array( $this, 'publish_lp' ) );

		register_activation_hook( __FILE__, array( $this, 'activation_process' ) );
		register_deactivation_hook( __FILE__, array( $this, 'deactivation_process' ) );
	}

	public function activation_process() {
		$this->init_endpoint();
		flush_rewrite_rules();
	}

	public function deactivation_process() {
		flush_rewrite_rules();
	}

	/* add hook and do action */
	public function init_endpoint() {
		add_filter( 'query_vars', array( $this, 'add_query_vars' ), 0 );
		add_action( 'parse_request', array( $this, 'sniff_requests' ) );
		add_rewrite_rule( '^landingpage/api', 'index.php?landingpage_api=1', 'top' );
	}

	public function add_query_vars( $vars ) {
		$vars[] = 'landingpage_api';

		return $vars;
	}

	/**
	 * Returns a string representation of Landing Page icon in SVG format.
	 *
	 * @return string string representation of SVG icon
	 */
	public function get_menu_icon() {
		return 'data:image/svg+xml;base64,PD94bWwgdmVyc2lvbj0iMS4wIiBlbmNvZGluZz0idXRmLTgiPz4NCjwhLS0gR2VuZXJhdG9yOiBBZG9iZSBJbGx1c3RyYXRvciAyMi4wLjEsIFNWRyBFeHBvcnQgUGx1Zy1JbiAuIFNWRyBWZXJzaW9uOiA2LjAwIEJ1aWxkIDApICAtLT4NCjxzdmcgdmVyc2lvbj0iMS4xIiBpZD0iTGF5ZXJfMSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIiB4bWxuczp4bGluaz0iaHR0cDovL3d3dy53My5vcmcvMTk5OS94bGluayIgeD0iMHB4IiB5PSIwcHgiDQoJIHZpZXdCb3g9IjAgMCAxMDAgMTAwIiBzdHlsZT0iZW5hYmxlLWJhY2tncm91bmQ6bmV3IDAgMCAxMDAgMTAwOyIgeG1sOnNwYWNlPSJwcmVzZXJ2ZSI+DQo8c3R5bGUgdHlwZT0idGV4dC9jc3MiPg0KCS5zdDB7ZmlsbDojMzUzNTM1O30NCjwvc3R5bGU+DQo8Zz4NCgk8cGF0aCBjbGFzcz0ic3QwIiBkPSJNMzYuNCw3OS40Yy0wLjUtMi4zLTItMS45LTItMS45Yy0zLjgsMS41LTcuNCwyLjEtMTAuNiwyLjJjLTIuOC04LjEtNC41LTE4LjMtNC42LTI5LjRjMC0wLjMsMC0wLjcsMC0xDQoJCWMwLTAuMywwLTAuNSwwLTAuOGMwLjEtMTAuOSwxLjctMjAuOSw0LjUtMjljLTAuNywwLTEuNC0wLjEtMi4xLTAuMWMwLjksMCwxLjksMCwyLjksMC4xYzE4LjIsMS44LDM1LjEsMjIuNiwzNS4xLDIyLjYNCgkJYzAuNywwLjYsMS42LDAuMiwxLjYsMC4yYzAuOC0wLjQsMC44LTEuMSwwLjgtMS4xYzAuMi0yMS43LTcuMS0zMy4yLTExLjgtMzcuNWMtMy4zLTMuMy02LjQtMy42LTYuOC0zLjYNCgkJYy02LjgsMC4yLTM2LDguNC00Mi4yLDM4LjljMC0wLjEsMC4xLTAuMiwwLjEtMC40QzAuNCw0Mi41LDAsNDYuMywwLDUwLjJjMCw5LjksMi4zLDE3LjcsNS43LDI0YzAsMCwwLDAsMCwwDQoJCWMwLjMsMC41LDAuNSwxLDAuOCwxLjVjNywxMi42LDI1LjEsMjQuMSw0MC40LDI0LjNjMC4yLDAsMC4zLTAuMSwwLjItMC4yQzM5LjEsOTEuMSwzNy4yLDgyLjksMzYuNCw3OS40eiBNMjEuNyw3OS44DQoJCWMtMS4yLDAtMi40LTAuMi0zLjUtMC4zQzE5LjMsNzkuNywyMC41LDc5LjgsMjEuNyw3OS44eiBNMTkuNiwxOS43YzAuNi0wLjEsMS4yLTAuMSwxLjgtMC4xQzIwLjcsMTkuNiwyMC4xLDE5LjYsMTkuNiwxOS43eg0KCQkgTTE4LjYsMTkuOGMtMC4zLDAtMC42LDAuMS0wLjksMC4xQzE4LDE5LjksMTguMywxOS44LDE4LjYsMTkuOHogTTYuMyw3NC43YzAuMSwwLjEsMC4yLDAuMSwwLjMsMC4yQzYuNSw3NC44LDYuNCw3NC43LDYuMyw3NC43eg0KCQkgTTYsNzQuNEM2LDc0LjQsNiw3NC40LDYsNzQuNEM2LDc0LjQsNiw3NC40LDYsNzQuNHogTTkuNyw3Ni44Yy0wLjYtMC4zLTEuMi0wLjYtMS43LTAuOUM4LjUsNzYuMSw5LjEsNzYuNCw5LjcsNzYuOHogTTcuOSw3NS43DQoJCWMtMC4yLTAuMS0wLjQtMC4zLTAuNi0wLjRDNy40LDc1LjQsNy43LDc1LjYsNy45LDc1Ljd6IE03LjIsNzUuM0M3LDc1LjIsNi45LDc1LjEsNi43LDc1QzYuOSw3NS4xLDcsNzUuMiw3LjIsNzUuM3ogTTEyLjIsNzcuOQ0KCQljMC44LDAuMywxLjcsMC42LDIuNywwLjlDMTMuOSw3OC41LDEzLDc4LjIsMTIuMiw3Ny45eiBNOS45LDc2LjhjMC43LDAuMywxLjQsMC43LDIuMiwxQzExLjMsNzcuNSwxMC41LDc3LjIsOS45LDc2Ljh6IE0xOC4xLDc5LjUNCgkJYy0xLjEtMC4yLTIuMS0wLjQtMy4xLTAuN0MxNS45LDc5LjEsMTcsNzkuMywxOC4xLDc5LjV6IE0yMS45LDc5LjhjMC42LDAsMS4yLDAsMS44LDBDMjMuMSw3OS44LDIyLjUsNzkuOCwyMS45LDc5Ljh6Ii8+DQoJPHBhdGggY2xhc3M9InN0MCIgZD0iTTEwMCw0OS44YzAtOS45LTIuMy0xNy43LTUuNy0yNGMwLDAsMCwwLDAsMGMtMC4zLTAuNS0wLjUtMS0wLjgtMS41Qzg2LjUsMTEuNyw2OC40LDAuMyw1My4xLDANCgkJYy0wLjIsMC0wLjMsMC4xLTAuMiwwLjJjOC4xLDguNywxMCwxNi45LDEwLjgsMjAuM2MwLjUsMi4zLDIsMS45LDIsMS45YzMuOC0xLjUsNy40LTIuMSwxMC42LTIuMmMyLjgsOC4xLDQuNSwxOC4zLDQuNiwyOS40DQoJCWMwLDAuMywwLDAuNywwLDFjMCwwLjMsMCwwLjUsMCwwLjhjLTAuMSwxMC45LTEuNywyMC45LTQuNSwyOWMwLjcsMCwxLjQsMC4xLDIuMSwwLjFjLTAuOSwwLTEuOSwwLTIuOS0wLjENCgkJYy0xOC4yLTEuOC0zNS4xLTIyLjYtMzUuMS0yMi42Yy0wLjctMC42LTEuNi0wLjItMS42LTAuMmMtMC44LDAuNC0wLjgsMS4xLTAuOCwxLjFjLTAuMiwyMS43LDcuMSwzMy4yLDExLjgsMzcuNQ0KCQljMy4zLDMuMyw2LjQsMy42LDYuOCwzLjZjNi44LTAuMiwzNi04LjQsNDIuMi0zOC45YzAsMC4xLTAuMSwwLjItMC4xLDAuNEM5OS42LDU3LjYsMTAwLDUzLjcsMTAwLDQ5Ljh6IE05My44LDI1LjQNCgkJYy0wLjEtMC4xLTAuMy0wLjItMC40LTAuM0M5My42LDI1LjIsOTMuNywyNS4zLDkzLjgsMjUuNHogTTk0LjEsMjUuNmMtMC4xLTAuMS0wLjItMC4xLTAuMy0wLjJDOTMuOSwyNS41LDk0LDI1LjYsOTQuMSwyNS42eg0KCQkgTTkwLjIsMjMuMmMwLjcsMC4zLDEuMiwwLjcsMS43LDFDOTEuNSwyMy45LDkwLjksMjMuNiw5MC4yLDIzLjJ6IE05Mi4xLDI0LjNjMC41LDAuMywwLjksMC42LDEuMiwwLjgNCgkJQzkzLDI0LjgsOTIuNiwyNC42LDkyLjEsMjQuM3ogTTg3LjgsMjIuMWMtMC44LTAuMy0xLjctMC42LTIuNy0wLjlDODYuMSwyMS41LDg3LDIxLjgsODcuOCwyMi4xeiBNOTAuMiwyMy4yDQoJCWMtMC43LTAuMy0xLjQtMC43LTIuMi0xQzg4LjcsMjIuNSw4OS41LDIyLjgsOTAuMiwyMy4yeiBNNzguMSwyMC4yYy0wLjYsMC0xLjIsMC0xLjgsMEM3Ni45LDIwLjIsNzcuNSwyMC4yLDc4LjEsMjAuMnogTTc4LjMsMjAuMg0KCQljMS4yLDAsMi4zLDAuMiwzLjQsMC4zQzgwLjcsMjAuMyw3OS41LDIwLjIsNzguMywyMC4yeiBNODUsMjEuMmMtMS0wLjMtMi0wLjUtMy4xLTAuN0M4MywyMC43LDg0LjEsMjAuOSw4NSwyMS4yeiBNODIuMiw4MC4xDQoJCWMtMC4zLDAuMS0wLjUsMC4xLTAuOCwwLjFDODEuNyw4MC4yLDgyLDgwLjEsODIuMiw4MC4xeiBNODAuNCw4MC4zYy0wLjYsMC4xLTEuMiwwLjEtMS44LDAuMUM3OS4zLDgwLjQsNzkuOSw4MC40LDgwLjQsODAuM3oiLz4NCjwvZz4NCjwvc3ZnPg0K';
	}

	public function sniff_requests() {
		global $wp;
		$isLandingpage = isset( $wp->query_vars['landingpage_api'] ) ? $wp->query_vars['landingpage_api'] : null;			
		
		if ( ! is_null( $isLandingpage ) && $isLandingpage === "1"  ) {
			if ($_SERVER['REQUEST_METHOD'] === 'GET' || $_SERVER['REQUEST_METHOD'] === 'get') {
				wp_send_json( array(
					'code'    => 403
				) );
				exit();
			}
			$params      = filter_input_array( INPUT_POST );

			$api_key     = isset( $params['token'] ) ? sanitize_text_field($params['token']) : null;
			$action      = isset( $params['action'] ) ? sanitize_text_field($params['action']) : null;
			$url         = isset( $params['url'] ) ? sanitize_text_field($params['url']) : null;
			$title       = isset( $params['title'] ) ? sanitize_text_field($params['title']) : null;
			$html = isset( $params['html'] ) ? $params['html'] : '';

			$type       = isset( $params['type'] ) ? sanitize_text_field($params['type']) : null;

			$config = get_option( 'landingpage_config', '');
			if ( $api_key !== $config['api_key'] ) {					
				wp_send_json( array(
					'code'    => 203
				) );
				exit();
			}
			switch ( $action ) {
				case 'create':
					if ( $this->get_id_by_slug($url) ) {
						wp_send_json( array(
							'code'    => 205
						) );
					}
					kses_remove_filters();
					if($type==null){
						$post_type = 'page';
					}else{
						$post_type = $type;
					}
					$id = wp_insert_post(
						array(
							'post_title'=>$title, 
							'post_name'=>$url, 
							'post_type'=>$post_type, 
							'post_content'=> trim($html), 
							'post_status' => 'publish',
							'filter' => true ,
							'page_template'  => 'null-template.php'
						)
					);
					if($id){
						wp_send_json( array(
							'code'    => 200,
							'url'    => get_permalink($id)
						) );
					}else{
						wp_send_json( array(
							'code'    => 400,
							'message' => __( 'Create failed, please try again.' )
						) );
					}
					break;
				
				case 'update':
					if ( ! $this->get_id_by_slug($url) ) {
						wp_send_json( array(
							'code'    => 400,
							'message' => __( 'URL does not exist' )
						) );
					}else{
						$id = $this->get_id_by_slug($url);
						$post = array(
							'ID' => $id,
							'post_title'=>$title, 
							'post_content' => trim($html), 
						);
						kses_remove_filters();
						$result = wp_update_post($post, true);
						if($result){
							wp_send_json( array(
								'code'    => 200,
								'url'    => get_permalink($id)
							) );
						}else{
							wp_send_json( array(
								'code'    => 400,
								'message' => __( 'Update failed, please try again.' )
							) );
						}
					}
					break;

				case 'delete':
					if ( ! $this->get_id_by_slug($url) ) {
						wp_send_json( array(
							'code'    => 400,
							'message' => __( 'URL does not exist' )
						) );
					}else{
						$id = $this->get_id_by_slug($url);
						$result = wp_delete_post($id);
						if($result){
							wp_send_json( array(
								'code'    => 200
							) );
						}else{
							wp_send_json( array(
								'code'    => 400,
								'message' => __( 'Delete failed, please try again.' )
							) );
						}
					}
					break;
				case 'checkurl':
					if ( ! $this->get_id_by_slug($url) ) {
						wp_send_json( array(
							'code'    => 206
						) );
					}else{
						wp_send_json( array(
							'code'    => 205
						) );
					}
					break;
				case 'checktoken':
					if ( $api_key === $config['api_key'] ) {
						wp_send_json( array(
							'code'    => 191
						) );
					}
					break;	
				default:
					wp_send_json( array(
						'code'    => 400,
						'message' => __( 'LandingPage action is not set or incorrect.' )
					) );
					break;
			}
		}
	}


	protected function get_id_by_slug($page_slug) {
		$page = get_page_by_path($page_slug,'OBJECT', ['post','page','product','property']);
		if ($page) {
			return $page->ID;
		} else {
			return null;
		}
	} 



	public function get_option( $id, $default = '' ) {
		$options = get_option( 'landingpage_config', array() );
		if ( isset( $options[ $id ] ) && $options[ $id ] != '' ) {
			return $options[ $id ];
		} else {
			return $default;
		}
	}

	public function admin_notices() {
		foreach ( $this->_notices as $notice_key => $notice ) {
			echo "<div class='" . esc_attr( $notice['class'] ) . "'><p>";
			echo wp_kses( $notice['message'], array( 'a' => array( 'href' => array() ) ) );
			echo '</p></div>';
		}
	}

	public function add_landingpage_menu_item() {
		$iconSvg = $this->get_menu_icon();
		add_menu_page( __( "NovaonX Landingpage" ), __( "NovaonX Landingpage" ), "manage_options", "landingpage-config", array(
			$this,
			'landingpage_settings_page'
		), $iconSvg, 30 );
	}

	public function landingpage_settings_page() {
		?>
			<div class="wrap">
				<h2 class="title">Kết Nối tới NovaonX Landingpage</h2><br>
				<form id="landingpage_config" class="landingui-panel">
					<!-- <h3><strong>Config API Key</strong></h3> -->
					<table class="form-table">
						<tr>
							<th scope="row">
								<label for="website_landingpage">API URL</label>
							</th>
							<td style="position: relative">
								<input onClick="this.select();" readonly="readonly" id="copy-apiurl" name="website_landingpage" id="website_landingpage" type="text" class="regular-text input" 
								style="height: 40px;background: #FFFFFF;border: 1px solid #CFCFCF;box-sizing: border-box;border-radius: 4px;" 
								value="<?php echo get_home_url(); ?>">
								<div  id="button-apiurl"
								style="position: absolute;top: 21px;background: #ECEFF5;border-radius: 4px;left: 293px;width: 64px;cursor: pointer;outline: none !important;padding-top: 00px;padding-bottom: 0px;text-align: center;padding: 6px 0;">Copy</div>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="api_key">API KEY</label>
							</th>
							<td style="position: relative; display: flex">
								<?php
									$config = get_option( 'landingpage_config', array());
									
									if(!isset($config['api_key']) || trim($config['api_key']) == ''){
										$config['api_key'] = $this->generateRandomString(32);
										update_option( 'landingpage_config', $config );
									}

								?>
								<input onClick="this.select();" readonly="readonly" style="height: 40px;background: #FFFFFF;border: 1px solid #CFCFCF;box-sizing: border-box;border-radius: 4px;"  name="api_key" id="api_key"   type="text" class="regular-text landingui input"
										value="<?php echo $this->get_option( 'api_key', '' ); ?>">
										<button type="button" id="landingpage_new_api" class="landingui button-primary" style="width:120px; height:40px; margin-left: 20px">API KEY mới</button>
										<div id="button-apikey" style="position: absolute;top: 21px;cursor: pointer;background: #ECEFF5;border-radius: 4px;left: 293px;width: 64px;outline: none !important;padding-top: 00px;padding-bottom: 0px;text-align: center;padding: 6px 0;">Copy</div>
								</td>
						</tr>
					</table>
				</form>
				<form class="landingui-panel" id="landingpage-publish-form">
					<h3><strong>Xuất bản Landingpage thủ công</strong></h3>
					<table class="form-table">
						<tr>
							<th scope="row">
								<label for="api_key">Landingpage KEY</label>
							</th>
							<td>
								<input
								style="
									background: #FFFFFF;
									border: 1px solid #CFCFCF;
									box-sizing: border-box;
									border-radius: 4px;
									height: 40px;"
									name="landingpage_key" id="landingpage_key" type="text" class="regular-text landingui input" placeholder="Your LandingPage Key" style=""><br/>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="d"> </label>
							</th>
							<td >
								<button type="button" id="landingpage_publish" class="button button-primary landingui button primary" style="height: 40px; width: 120px">Xuất bản</button>
							</td>
						</tr>
					</table>
					
				</form>
				<script>
					(function ($) {
						
						function generateRandomString(length = 10) {
							var text = "";
							var possible = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789";
							for (var i = 0; i < length; i++)
							text += possible.charAt(Math.floor(Math.random() * possible.length));
							return text;
						}
						
						$(document).ready(function () {
							$('#landingpage_save_option').on('click', function (event) {
								var data = JSON.stringify($('#landingpage_config').serializeArray());
								
								$.ajax({
									url: ajaxurl,
									type: 'POST',
									data: {
										action: 'landingpage_save_config',
										data: data
									},
									success: function (response) {
										alert('Save Success');
									}
								});
								event.preventDefault();
							});



							$("#landingpage_new_api").click(function(){
								var api = generateRandomString(32);
								$("#landingpage_config #api_key").val(api);

								//save after create new api key
								var data = JSON.stringify($('#landingpage_config').serializeArray());
								$.ajax({
									url: ajaxurl,
									type: 'POST',
									data: {
										action: 'landingpage_save_config',
										data: data
									},
									success: function (response) {
										alert("API key đã được thay đổi. Bạn vui lòng chỉnh sửa lại các cấu hình đã cài đặt trước đó trên NovaonX Landingpage!");
									}
								});
							});

							$("#button-apiurl").click( function () {
									document.getElementById("copy-apiurl").select()
									document.execCommand("copy");
								});
							$("#button-apikey").click(function () {
									document.getElementById("api_key").select()
									document.execCommand("copy");
								});

							$('#landingpage_publish').on('click', function (event) {
								event.preventDefault();
								var landingPageKey = $('#landingpage_key').val();
								if (landingPageKey == '') {
									alert('Please enter your LandingPage Key!');
									return false;
								}
								$.ajax({
									url: ajaxurl,
									type: 'POST',
									data: {
										action: 'landingpage_publish_ldp',
										landingpage_key: landingPageKey
									},
									success: function (res) {
										alert(res.message);
									}
								});
								event.preventDefault();

							});

						});
					})(jQuery);
				</script>
			</div>
			<?php
	}

	public function save_config() {
		$data   = sanitize_text_field($_POST['data']);
		$data   = json_decode( stripslashes( $data ) );
		$option = array();

		foreach ( $data as $key => $value ) {
			$option[ $value->name ] = $value->value;
		}
		update_option( 'landingpage_config', $option );
		die;
	}

	public function publish_lp() {
		$landingpageKey     = isset( $_POST['landingpage_key'] ) ? sanitize_text_field($_POST['landingpage_key']) : null;
		if ($landingpageKey) {
			$ldpKey = trim($landingpageKey);
			// $response = wp_remote_get( 'https://pub-dev.novaonx.com/wordpress/landingpageKey/'.$ldpKey );
			$response = wp_remote_get( 'https://novaonx.net/wordpress/landingpageKey/'.$ldpKey );
			$jsonString = wp_remote_retrieve_body( $response );
			if ($jsonString) {
				$response = json_decode($jsonString);
				
				if (isset($response->code) && $response->code == 200) {
					$data = $response->data;
					
					
					if (!isset($data->url) || $data->url == '') {
						wp_send_json( array(
							'code'    => 403,
							'message' => __( 'Request is invalid!' )
						) ); exit;
					}

					$pageId = $this->get_id_by_slug($data->url);
					if (!$pageId) {
						kses_remove_filters();
						
						$id = wp_insert_post(
							array(
								'post_title'=>$data->title . ' - Novaon LandingPage', 
								'post_name'=>$data->url, 
								'post_type'=>'page', 
								'post_content'=> trim($data->html), 
								'post_status' => 'publish',
								'filter' => true ,
								'page_template'  => 'null-template.php'
							)
						);
						if ($id) {
							wp_send_json( array(
								'code'    => 200,
								'message' => __( "Publish successfully! Page URL: " . site_url() . '/' . $data->url)
							) ); exit;
						}
					} else {
						$id = $this->get_id_by_slug($url);
						$post = array(
							'ID' => $pageId,
							'post_title'=>$data->title . ' - NovaonX LandingPage', 
							'post_content' => trim($data->html), 
						);
						kses_remove_filters();
						$result = wp_update_post($post, true);
						wp_send_json( array(
								'code'    => 200,
								'message' => __( "Publish successfully! Page URL: " . site_url() . '/' . $data->url)
							) ); exit;
					}
				} else {
					wp_send_json( array(
						'code'    => 500,
						'message' => __( $response->message )
					) ); exit;
				}
			}

			wp_send_json( array(
				'code'    => 500,
				'message' => __( 'Request is invalid!' )
			) ); exit;
		}
	}

	public function generateRandomString($length = 10) {
		$characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
		$charactersLength = strlen($characters);
		$randomString = '';
		for ($i = 0; $i < $length; $i++) {
			$randomString .= $characters[rand(0, $charactersLength - 1)];
		}
		return $randomString;
	}

}

function NX_LandingPage() {
	return NX_LandingPage::instance();
}

NX_LandingPage();


?>