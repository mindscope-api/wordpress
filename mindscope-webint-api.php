<?php
/**
Plugin Name: Mindscope WebIntegration API
description: >-This plugin allows the website to post jobs and register candidates to Mindscope ATS.
Version: 1.0
Author: Qasim Khalid
License: GPLv2 or later
Text Domain: mindscope-webint-api
*/

defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

add_shortcode( "job_list", "job_list_func" );
add_shortcode( "job_post", "job_post_func" );
add_shortcode( "job_form", "job_form_func" );
add_shortcode( "job_posting", "static_job_post_func" );
add_shortcode( "update_mindscope_api_data", "update_mindscope_api_data_func" );

//require_once 'vendor/autoload.php';
//require_once 'php/form_action.php';

require_once 'vendor/autoload.php';

register_activation_hook( __FILE__ , 'mindscope_webint_api_install');
register_deactivation_hook( __FILE__, 'mindscope_webint_api_remove') ;

add_action('init', 'reset_cache');
add_action( "admin_menu", "mindscope_webint_api_menu_func" );
add_action( 'admin_post_update_mindscope_webint_api_settings', 'mindscope_webint_api_handle_save' );
add_action( 'admin_post_mindscope_webint_api_form', 'mindscope_webint_api_form_handle_save');
add_action( 'admin_post_nopriv_mindscope_webint_api_form', 'mindscope_webint_api_form_handle_save');
add_action( 'admin_post_mindscope_webint_api_signin_form', 'mindscope_webint_api_signin_form_handle_save');
add_action( 'admin_post_nopriv_mindscope_webint_api_signin_form', 'mindscope_webint_api_signin_form_handle_save');
add_action( 'wp_ajax_mindscope_generate_job_list', 'mindscope_ajax_job_list_function' );
add_action( 'wp_ajax_nopriv_mindscope_generate_job_list', 'mindscope_ajax_job_list_function' );
add_action( 'wp_ajax_mindscope_save_page_number', 'mindscope_ajax_save_page_number' );
add_action( 'wp_ajax_nopriv_mindscope_save_page_number', 'mindscope_ajax_save_page_number' );


global $mindscope_api_db_version;
$mindscope_api_db_version = "1.2";

function update_mindscope_api_data_func()
{
	$delete_all_posts = $_GET['delete_all'];
	
	if ($delete_all_posts) {
		delete_all_mindscope_job_posts();
	}
	else {
		if (!get_option('mindscope_webint_api_company_name') ||
				!get_option('mindscope_webint_api_website_url') ||
				!get_option('mindscope_webint_api_logo_url') ||
				!get_option('mindscope_webint_api_google_auth_filename') ||
				!get_option('mindscope_webint_api_sitemap_url'))
		{
			return;
		}
		
		if (!get_option('mindscope_api_job_page_update_datetime'))
		{
			update_option('mindscope_api_job_page_update_datetime', "2010-01-01 00:00:00", TRUE);
		}
		
		$saved_update_datetime = get_option("mindscope_api_job_page_update_datetime");
		
		$update_datetime = strtotime($saved_update_datetime);
		$update_datetime_add = $update_datetime+(60);
		$update_datetime_final = date("Y-m-d H:i:s", $update_datetime_add);
		$current_datetime_string = date("Y-m-d H:i:s");

		$date_expire = new DateTime($update_datetime_final);
		$date_now = new DateTime($current_datetime_string);
		
		if (get_option('mindscope_api_job_create_running') != 'true' && ($date_now > $date_expire))
		{
			ini_set('max_execution_time', 600);
			ini_set('default_socket_timeout', 600);
			set_time_limit(600);
			
			update_option('mindscope_api_job_create_running', 'true', TRUE);
			
			try 
			{
				create_job_pages();
				update_job_pages();
				delete_job_pages();
				
				$dt_now = date("Y-m-d H:i:s");
				update_option('mindscope_api_job_page_update_datetime', $dt_now, TRUE);
				
				$sitemapurl = get_option('mindscope_webint_api_sitemap_url');
				$f = file_get_contents('https://www.google.com/ping?sitemap=' . $sitemapurl);
			}
			catch (Exception $e)
			{
				//console_log($e);
			}
			
			update_option('mindscope_api_job_create_running', 'false', TRUE);
		}
	}
}

function delete_all_mindscope_job_posts()
{
	global $wpdb;
	
	$posts = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}mindscope_api_jobpages");
	if (count($posts) != 0)
	{
		foreach ($posts as $post)
		{
			if (wp_delete_post($post->postid, TRUE)) {
				$wpdb->delete("{$wpdb->prefix}mindscope_api_jobpages", array('postid' => $post->postid), array("%d"));
			}
		}
	}
}

function reset_cache()
{
	if( !session_id() )
	{
		session_start();
	}
	
	$job_list_cache_dir = WP_CONTENT_DIR . '/endurance-page-cache/faq/_index.html';
  
	if (file_exists($job_list_cache_dir))
	{
	  $timestamp = filemtime($job_list_cache_dir);
	  $mod_date_string = date("F d Y H:i:s.", $timestamp);

	  $filter_datetime = strtotime($mod_date_string);
	  $filter_datetime_add = $filter_datetime+(60*1440);
	  //$filter_datetime_add = $filter_datetime+(60*1);
	  $filter_datetime_final = date("Y-m-d H:i:s", $filter_datetime_add);

	  $current_datetime_string = date("Y-m-d H:i:s");

	  $date_expire = new DateTime($filter_datetime_final);
	  $date_now = new DateTime($current_datetime_string);

	  if ($date_expire < $date_now)
	  {
		//reset filters if cache is being reset  
		update_option("mindscope_webint_api_filter_datetime", "2010-01-01 00:00:00", TRUE);
		unlink($job_list_cache_dir);
	  }
	}
	
	//test_page_creation_function();
}

function get_google_job_post_javascript($joborder)
{
	$company_name = get_option('mindscope_webint_api_company_name');
	$website_url = get_option('mindscope_webint_api_website_url');
	$logo_url = get_option('mindscope_webint_api_logo_url');
	
	$job_description = strip_tags($joborder->jobFunction, "<br><p><ul><li><h1><h2><h3><h4><h5><strong><em><b>");
	
	$web_pub_date = date_parse($value->webPublicationDate);
	$web_pub_date_short = "";
	if ($web_pub_date["error_count"] == 0)
	{
		$web_pub_date_short = $web_pub_date["year"] . "-" . $web_pub_date["month"] . "-" . $web_pub_date["day"];
	}
	
	$google_javascript = '';
	
	$google_javascript .= '<script type="application/ld+json">';
	
	$job_post_json = array(
		"@context" => "https://schema.org/",
		"@type" => "JobPosting",
		"title" => $joborder->position,
		"description" => $job_description,
		"identifier" => array(
					"@type" => "PropertyValue",
					"name" => $company_name,
					"value" => strval($joborder->jobOrderId)
				),
		"datePosted" => $web_pub_date_short,
		"hiringOrganization" => array(
					"@type" => "Organization",
					"name" => $company_name,
					"sameAs" => $website_url,
					"logo" => $logo_url
				),
		"jobLocation" => array(
					"@type" => "Place",
					"address" => array(
							"@type" => "PostalAddress",
							"addressLocality" => $joborder->city,
							"addressRegion" => $joborder->province
					)
				)
	);
	
	$google_javascript .= json_encode($job_post_json);
	
	$google_javascript .= '</script>';
	
	return $google_javascript;
}

function static_job_post_func ( $args )
{
	try
	{
		load_script_jquery();
		load_script_bootstrap();
		load_style_mindscope_webint();
		
		//load_script_mindscope_webint();
		
		//$the_id = $_GET['id'];
		$the_id = $args['id'];
		$filter = json_encode(array('filter'=>array('JobOrderID' => $the_id)), JSON_FORCE_OBJECT);
		$job_app_page_link = get_permalink(get_option('mindscope_webint_api_job_app_page'));
		
		$job_details = cura_search_joborder($filter);
		
		$url_encoded_title = urlencode($job_details->jobOrder[0]->position);

		$job_func = $job_details->jobOrder[0]->jobFunction;
		$job_req = $job_details->jobOrder[0]->jobRequirement;
		$display_requirements = "";
		
		if (empty($job_req))
		{
			$display_requirements = "style='display: none;'";
		}
		
		$google_javascript = get_google_job_post_javascript($job_details->jobOrder[0]);

		$return_html = "
		{$google_javascript}
		<div class='container' style='max-width: 900px; margin-left: auto; margin-right: auto;'>
			<div class='row'>
				<div class='col-12 d-none d-sm-block'>
					<h2 class='h4-big api-color' style='word-wrap: break-word;'>{$job_details->jobOrder[0]->position}</h2>
				</div>
			</div>
			<div class='row'>
				<div class='col-12 d-block d-sm-none'>
					<h2 class='h4-medium api-color' style='word-wrap: break-word;'>{$job_details->jobOrder[0]->position}</h2>
				</div>
			</div>
			<div class='row'>
				<div class='col-sm-3'>
					<p><small>Division</small></p>
					<p class='font-weight-bold'>{$job_details->jobOrder[0]->jobOrderDivision}</p>
				</div>
				<div class='col-sm-3'>
					<p><small>Employment Type</small></p>
					<p class='font-weight-bold'><i class='far fa-calendar-alt' style='margin-right: 5px;'></i>{$job_details->jobOrder[0]->employmentType}</p>
				</div>
				<div class='col-sm-3'>
					<p><small>Location</small></p>
					<p class='font-weight-bold'><i class='fas fa-map-marker-alt' style='margin-right: 5px;'></i>{$job_details->jobOrder[0]->city}, {$job_details->jobOrder[0]->province}</p>
				</div>
				<div class='col-sm-3'>
					<a class='btn btn-primary btn-lg api-background-color api-border-color text-color-white' style='margin-top: 15px;' href='{$job_app_page_link}?id={$the_id}&job={$url_encoded_title}' role='button'>Apply</a>	
				</div>
			</div>
			<div class='row'>
				<div class='col'>
					<hr/>
				</div>
			</div>
			<br>
			<div class='row'>
				<div class='col'>
					<h4>Job Description:</h4>
				</div>
			</div>
			<div class='row'>
				<div class='col'>
					{$job_func}
				</div>
			</div>
			<div class='row'>
				<div class='col' {$display_requirements}>
					<h4>Job Requirements:</h4>
				</div>
			</div>
			<div class='row'>
				<div class='col' {$display_requirements}>
					{$job_req}
				</div>
			</div>
			<div class='row'>
				<div class='col-sm-3'>
				</div>
				<div class='col-sm-3'>
				</div>
				<div class='col-sm-3'>
				</div>
				<div class='col-sm-3'>
					<a class='btn btn-primary btn-lg api-background-color api-border-color text-color-white' href='{$job_app_page_link}?id={$the_id}&job={$url_encoded_title}' role='button'>Apply</a>
				</div>
			</div>
		</div>
		";
		
		//console_log($job_details);
		
		return $return_html;
	}
	catch (Exception $e)
	{
		//return "<p class='text-danger'>ERROR: Unable to get job information</p>";
		
		return "
<div class='card'>
  <div class='card-body'>
    <h5 class='card-title text-danger'>ERROR</h5>
    <p class='card-text'>Unable to get job information</p>
  </div>
</div>";
	}
	//console_log($args);
	
	//return $args['id'];
}

function add_new_job_page($joborder, $post_id, $job_desc_page) {
	global $wpdb;
	
	$page_content = $job_desc_page->post_content;
	$page_content = str_replace('[job_post]', '[job_posting id="'. $joborder->jobOrderId . '"]', $page_content);
	//$page_content = "[job_list]";
	
	$_p = array();
	$_p['post_title'] = $joborder->position;
	$_p['post_status'] = $job_desc_page->post_status;
	$_p['post_type'] = $job_desc_page->post_type;
	$_p['comment_status'] = $job_desc_page->comment_status;
	$_p['ping_status'] = $job_desc_page->ping_status;
	$_p['post_content'] = $page_content;
	$_p['post_parent'] = $job_desc_page->ID;
	//$_p['post_category'] = array(1);
	//$_p['page_template'] = $job_desc_page->page_template;
	
	$added_page_id = wp_insert_post($_p);
	
	if ($added_page_id != 0) {
		
		$status_code = call_google_index_api("URL_UPDATED", get_permalink($added_page_id));
		
		if (!is_numeric($status_code)) {
			$status_code = 0;
		}
		
		$wpdb->insert(
			$wpdb->prefix . 'mindscope_api_jobpages',
			array(
				'jobid' => $joborder->jobOrderId,
				'url' => get_permalink($added_page_id),
				'createdatetime' => current_time( 'mysql' ),
				'updatedatetime' => current_time( 'mysql' ),
				'postid' => $added_page_id,
				'indexapiresponsecode' => $status_code
			),
			array(
				'%d',
				'%s',
				'%s',
				'%s',
				'%d',
				'%d'
			)
		);
		
		$taxonomies = get_object_taxonomies($job_desc_page->post_type); // returns array of taxonomy names for post type, ex array("category", "post_tag");
		foreach ($taxonomies as $taxonomy) {
			$post_terms = wp_get_object_terms($post_id, $taxonomy, array('fields' => 'slugs'));
			wp_set_object_terms($added_page_id, $post_terms, $taxonomy, false);
		}
		
		//console_log('post id: ' . $post_id);
		$post_meta_infos = $wpdb->get_results("SELECT meta_key, meta_value FROM $wpdb->postmeta WHERE post_id=$post_id");
		if (count($post_meta_infos)!=0) {
			$sql_query = "INSERT INTO $wpdb->postmeta (post_id, meta_key, meta_value) ";
			foreach ($post_meta_infos as $meta_info) {
				$meta_key = $meta_info->meta_key;
				if( $meta_key == '_wp_old_slug' ) continue;
				$job_post_short_code = '[job_posting id="@jobId"]';
				$job_post_short_code = str_replace('@jobId', $joborder->jobOrderId, $job_post_short_code);
				$meta_value = addslashes(str_replace('[job_post]', $job_post_short_code, $meta_info->meta_value));
				$sql_query_sel[]= "SELECT $added_page_id, '$meta_key', '$meta_value'";
			}
			$sql_query.= implode(" UNION ALL ", $sql_query_sel);
			//console_log($sql_query);
			$wpdb->query($sql_query);
		}	
		
		$brief_description = strip_tags(($joborder->briefDescription));
		if (strlen($brief_description) > 120)
		{
			$brief_description = substr($brief_description, 0, 120);
		}
		
		update_post_meta( $added_page_id, '_yoast_wpseo_title', $joborder->position );
		update_post_meta( $added_page_id, '_yoast_wpseo_metadesc', $brief_description );
	}
}

function update_existing_job_page($joborder, $post_id)
{
	$brief_description = strip_tags(($joborder->briefDescription));
	if (strlen($brief_description) > 120)
	{
		$brief_description = substr($brief_description, 0, 120);
	}
	
	update_post_meta( $post_id, '_yoast_wpseo_title', $joborder->position );
	update_post_meta( $post_id, '_yoast_wpseo_metadesc', $brief_description );
}

function delete_existing_job_page($post_id, $url)
{
	global $wpdb;
	
	if ($post_id != null) {
		wp_delete_post( $post_id, TRUE );
		
		$wpdb->delete("{$wpdb->prefix}mindscope_api_jobpages", array('postid' => $post_id), array("%d"));
		
		$status_code = call_google_index_api("URL_DELETED", $url);
	}
}

function call_google_index_api($action, $url) {
	$status_code = 0;
	
	if (!empty($action) && !empty($url))
	{
		$client = new Google_Client();
		
		$auth_file_name = get_option('mindscope_webint_api_google_auth_filename');
		$auth_file_path = WP_CONTENT_DIR . '/plugins/mindscope-webint-api/' . $auth_file_name;

		$client->setAuthConfig($auth_file_path);
		$client->addScope('https://www.googleapis.com/auth/indexing');

		$httpClient = $client->authorize();
		$endpoint = 'https://indexing.googleapis.com/v3/urlNotifications:publish';

		// Define contents here. The structure of the content is described in the next step.
		$content = '{
		"url": "' . $url . '",
		"type": "' . $action . '"
		}';

		$response = $httpClient->post($endpoint, [ 'body' => $content ]);
		$status_code = $response->getStatusCode();
	}
	
	return $status_code;
}

function create_job_pages() {
	global $wpdb;
	
	$filter = json_encode(array('Filter'=>(
											array('JobOrderOpen'=>'Yes', 'JobOrderStatus'=>array('open', 'hold', 'hot'))),
								'Order'=> array(
												array('Field'=>'webPublicationDate', 'direction'=>'Desc'),
												array('Field'=>'Division', 'direction'=>'Asc')),
								'DisplayFields'=>
											array('position', 'briefDescription')));
	$job_list = cura_search_joborder($filter);
	$post_id = get_option('mindscope_webint_api_job_desc_page');
	$job_desc_page = get_post($post_id);
	
	if ($job_desc_page != null) {
		
		$page_count = 0;
		foreach ($job_list->jobOrder as $value)
		{
			if ($page_count < 50)
			{
				if ($value->position != "")
				{
					$job_exists = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}mindscope_api_jobpages WHERE jobid = {$value->jobOrderId}");
					//console_log($job_exists);
					//if (0 == 0 ) {
					if ($job_exists == 0 ) {
						add_new_job_page($value, $post_id, $job_desc_page);
						$page_count++;
					}
				}
			}
		}
	}
}

function update_job_pages() {
	global $wpdb;
	
	$filter = json_encode(array('Filter'=>(
											array('JobOrderOpen'=>'Yes', 'JobOrderStatus'=>array('open', 'hold', 'hot'))),
								'Order'=> array(
												array('Field'=>'webPublicationDate', 'direction'=>'Desc'),
												array('Field'=>'Division', 'direction'=>'Asc')),
								'DisplayFields'=>
											array('position', 'briefDescription')));
	$job_list = cura_search_joborder($filter);


	foreach ($job_list->jobOrder as $value)
	{
		if ($value->position != "")
		{
			$job_page = $wpdb->get_row("SELECT postid FROM {$wpdb->prefix}mindscope_api_jobpages WHERE jobid = {$value->jobOrderId}");
			//console_log($job_exists);
			//if (0 == 0 ) {
			if ($job_page->postid > 0 ) {
				update_existing_job_page($value, $job_page->postid);
			}
		}
	}
}

function delete_job_pages() {
	global $wpdb;

	$filter = json_encode(array('Filter'=>(
											array('JobOrderOpen'=>'Yes', 'JobOrderStatus'=>array('open', 'hold', 'hot'))),
								'Order'=> array(
												array('Field'=>'webPublicationDate', 'direction'=>'Desc'),
												array('Field'=>'Division', 'direction'=>'Asc')),
								'DisplayFields'=>
											array('position', 'briefDescription')));
	$job_list = cura_search_joborder($filter);
	
	$job_list_A_array;
	foreach ($job_list->jobOrder as $value)
	{
		$job_list_A_array[$value->jobOrderId] = $value->position;
	}
	
	$job_posts = $wpdb->get_results("SELECT jobid, postid, url FROM wp_mindscope_api_jobpages");
	
	foreach ($job_posts as $post)
	{
		if ($job_list_A_array[$post->jobid] == null) {
			delete_existing_job_page( $post->postid, $post->url ); 
		}
	}
}

function mindscope_webint_api_install() {
	global $wpdb;
	global $mindscope_api_db_version;
	$installed_ver = get_option( "mindscope_api_db_version" );
	
	//console_log($installed_ver);
	//console_log("test");
	
	if ( $installed_ver != $mindscope_api_db_version ) {
		$table_name = $wpdb->prefix . 'mindscope_api_jobpages';
		
		$charset_collate = $wpdb->get_charset_collate();
		
		$sql = "CREATE TABLE $table_name (
			id mediumint(9) NOT NULL AUTO_INCREMENT,
			jobid int UNIQUE NOT NULL,
			postid int NOT NULL,
			url text NULL,
			createdatetime datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
			updatedatetime datetime NULL,
			indexapiresponsecode int NULL,
			PRIMARY KEY  (id)
		) $charset_collate;";
		
		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
		dbDelta( $sql );
		
		update_option('mindscope_api_db_version', $mindscope_api_db_version);
	}
	
        //global $wpdb;

        // $the_page_title = 'Job Details';
        // $the_page_name = 'job-details';

        // // the menu entry...
        // delete_option("mindscope_webtint_api_jobdetail_page_title");
        // add_option("mindscope_webtint_api_jobdetail_page_title", $the_page_title, '', 'yes');
        // // the slug...
        // delete_option("mindscope_webtint_api_jobdetail_page_name");
        // add_option("mindscope_webtint_api_jobdetail_page_name", $the_page_name, '', 'yes');
        // // the id...
        // delete_option("mindscope_webtint_api_jobdetail_page_id");
        // add_option("mindscope_webtint_api_jobdetail_page_id", '0', '', 'yes');

        // $the_page = get_page_by_title( $the_page_title );

        // if ( ! $the_page ) {

            // // Create post object
            // $_p = array();
            // $_p['post_title'] = $the_page_title;
            // $_p['post_content'] = "[job_post]";
            // $_p['post_status'] = 'publish';
            // $_p['post_type'] = 'page';
            // $_p['comment_status'] = 'closed';
            // $_p['ping_status'] = 'closed';
            // $_p['post_category'] = array(1); // the default 'Uncatrgorised'

            // // Insert the post into the database
            // $the_page_id = wp_insert_post( $_p );

        // }
        // else {
            // // the plugin may have been previously active and the page may just be trashed...

            // $the_page_id = $the_page->ID;

            // //make sure the page is not trashed...
            // $the_page->post_status = 'publish';
            // $the_page_id = wp_update_post( $the_page );

        // }

        // delete_option( 'mindscope_webtint_api_jobdetail_page_id' );
        // add_option( 'mindscope_webtint_api_jobdetail_page_id', $the_page_id );

}

function mindscope_webint_api_remove() {

	// global $wpdb;

	// $the_page_title = get_option( "mindscope_webtint_api_jobdetail_page_title" );
	// $the_page_name = get_option( "mindscope_webtint_api_jobdetail_page_name" );

	// //  the id of our page...
	// $the_page_id = get_option( 'mindscope_webtint_api_jobdetail_page_id' );
	// if( $the_page_id ) {

		// wp_delete_post( $the_page_id ); // this will trash, not delete

	// }

	// delete_option("mindscope_webtint_api_jobdetail_page_title");
	// delete_option("mindscope_webtint_api_jobdetail_page_name");
	// delete_option("mindscope_webtint_api_jobdetail_page_id");

}

function mindscope_webint_api_menu_func() {
	add_submenu_page( "options-general.php",  // Which menu parent
                  "Mindscope WebIntegration API",            // Page title
                  "Mindscope WebIntegration API",            // Menu title
                  "manage_options",       // Minimum capability (manage_options is an easy way to target administrators)
                  "mindscope-webint-api",            // Menu slug
                  "mindscope_webint_api_options"     // Callback that prints the markup
               );
}

function mindscope_webint_api_options() {
	if ( !current_user_can( "manage_options" ) )  {
      wp_die( __( "You do not have sufficient permissions to access this page." ) );
   }
   
   if ( isset($_GET['status']) && $_GET['status']=='success') { 
    ?>
        <div id="message" class="updated notice is-dismissible">
            <p><?php _e("Settings updated!", "mindscope-webint-api"); ?></p>
            <button type="button" class="notice-dismiss">
                <span class="screen-reader-text"><?php _e("Dismiss this notice.", "mindscope-webint-api"); ?></span>
            </button>
        </div>
    <?php
    }
	
	?>
    <form method="post" action="<?php echo admin_url( 'admin-post.php'); ?>">

        <input type="hidden" name="action" value="update_mindscope_webint_api_settings" />

        <h3><?php _e("Mindscope WebIntegration API Info", "mindscope-webint-api"); ?></h3>
        <p>
        <label><?php _e("Service URL:", "mindscope-webint-api"); ?></label>
        <input class="" type="text" name="api_url" value="<?php echo get_option('mindscope_webtint_api_url'); ?>" />
        </p>

		<p>
        <label><?php _e("Account ID:", "mindscope-webint-api"); ?></label>
        <input class="" type="text" name="api_account_id" value="<?php echo get_option('mindscope_webtint_api_accountid'); ?>" />
        </p>
		
        <p>
        <label><?php _e("Account Key:", "mindscope-webint-api"); ?></label>
        <input class="" type="text" name="api_key" value="<?php echo get_option('mindscope_webtint_api_key'); ?>" />
        </p>
		
		<p>
		<label><?php _e("Job Description Page:", "mindscope-webint-api"); ?></label>
		<select name="api_job_desc_page">
		<?php
			$pages = get_pages($args);
			$selected_page_id = get_option('mindscope_webint_api_job_desc_page');
			
			foreach($pages as $value) 
			{
				if ($value->ID == $selected_page_id)
				{
					echo "<option value='" . $value->ID . "' selected>" . $value->post_title . "</option>";
				}
				else
				{
					echo "<option value='" . $value->ID . "'>" . $value->post_title . "</option>";
				}
			}
		?>
		</select>
		</p>
		
		<p>
		<label><?php _e("Job Application Page:", "mindscope-webint-api"); ?></label>
		<select name="api_job_app_page">
		<?php
			$pages = get_pages($args);
			$selected_page_id = get_option('mindscope_webint_api_job_app_page');
			
			foreach($pages as $value) 
			{
				if ($value->ID == $selected_page_id)
				{
					echo "<option value='" . $value->ID . "' selected>" . $value->post_title . "</option>";
				}
				else
				{
					echo "<option value='" . $value->ID . "'>" . $value->post_title . "</option>";
				}
			}
		?>
		</select>
		</p>
		
		 <p>
        <label><?php _e("reCAPTCHA Site Key:", "mindscope-webint-api"); ?></label>
        <input class="" type="text" name="recaptcha_site_key" value="<?php echo get_option('mindscope_webtint_api_recaptcha_site_key'); ?>" />
        </p>
		
		<p>
        <label><?php _e("reCAPTCHA Site Secret:", "mindscope-webint-api"); ?></label>
        <input class="" type="text" name="recaptcha_site_secret" value="<?php echo get_option('mindscope_webtint_api_recaptcha_site_secret'); ?>" />
        </p>
		
		<p>
		<label><?php _e("Company Name:", "mindscope-webint-api"); ?></label>
		<input class="" type="text" name="company_name" value="<?php echo get_option('mindscope_webint_api_company_name'); ?>" />
		</p>
		
		<p>
		<label><?php _e("Website URL:", "mindscope-webint-api"); ?></label>
		<input class="" type="text" name="website_url" value="<?php echo get_option('mindscope_webint_api_website_url'); ?>" />
		</p>
		
		<p>
		<label><?php _e("Logo URL:", "mindscope-webint-api"); ?></label>
		<input class="" type="text" name="logo_url" value="<?php echo get_option('mindscope_webint_api_logo_url'); ?>" />
		</p>
		
		<p>
		<label><?php _e("Google Indexing API Autho Filename:", "mindscope-webint-api"); ?></label>
		<input class="" type="text" name="auth_filename" value="<?php echo get_option('mindscope_webint_api_google_auth_filename'); ?>" />
		</p>
		
		<p>
		<label><?php _e("Sitemap XML URL:", "mindscope-webint-api"); ?></label>
		<input class="" type="text" name="sitemap_url" value="<?php echo get_option('mindscope_webint_api_sitemap_url'); ?>" />
		</p>
		
        <input class="button button-primary" type="submit" value="<?php _e("Save", "mindscope-webint-api"); ?>" />

    </form>
	
	<?php
}

function mindscope_webint_api_handle_save() 
{
	// Get the options that were sent
   $url = (!empty($_POST["api_url"])) ? $_POST["api_url"] : NULL;
   $account_id = (!empty($_POST["api_account_id"])) ? $_POST["api_account_id"] : NULL;
   $key = (!empty($_POST["api_key"])) ? $_POST["api_key"] : NULL;
   $job_desc_page = (!empty($_POST["api_job_desc_page"])) ? $_POST["api_job_desc_page"] : NULL;
   $job_app_page = (!empty($_POST["api_job_app_page"])) ? $_POST["api_job_app_page"] : NULL;
   $recaptcha_site_key = (!empty($_POST["recaptcha_site_key"])) ? $_POST["recaptcha_site_key"] : NULL;
   $recaptcha_site_secret = (!empty($_POST["recaptcha_site_secret"])) ? $_POST["recaptcha_site_secret"] : NULL;
   $company_name = (!empty($_POST["company_name"])) ? $_POST["company_name"] : NULL;
   $website_url = (!empty($_POST["website_url"])) ? $_POST["website_url"] : NULL;
   $logo_url = (!empty($_POST["logo_url"])) ? $_POST["logo_url"] : NULL;
   $auth_filename = (!empty($_POST["auth_filename"])) ? $_POST["auth_filename"] : NULL;
   $sitemap_url = (!empty($_POST["sitemap_url"])) ? $_POST["sitemap_url"] : NULL;
   
   // Update the values
   update_option( "mindscope_webtint_api_url", $url, TRUE );
   update_option( "mindscope_webtint_api_accountid", $account_id, TRUE );
   update_option( "mindscope_webtint_api_key", $key, TRUE );
   update_option( "mindscope_webint_api_job_desc_page", $job_desc_page, TRUE );
   update_option( "mindscope_webint_api_job_app_page", $job_app_page, TRUE );
   update_option( "mindscope_webtint_api_recaptcha_site_key", $recaptcha_site_key, TRUE );
   update_option( "mindscope_webtint_api_recaptcha_site_secret", $recaptcha_site_secret, TRUE );
   update_option( "mindscope_webint_api_company_name", $company_name, TRUE );
   update_option( "mindscope_webint_api_website_url", $website_url, TRUE );
   update_option( "mindscope_webint_api_logo_url", $logo_url, TRUE );
   update_option( "mindscope_webint_api_google_auth_filename", $auth_filename, TRUE );
   update_option( "mindscope_webint_api_sitemap_url", $sitemap_url, TRUE );
   
   // Redirect back to settings page
   // The ?page=github corresponds to the "slug" 
   // set in the fourth parameter of add_submenu_page() above.
   $redirect_url = get_bloginfo("url") . "/wp-admin/options-general.php?page=mindscope-webint-api&status=success";
   header("Location: ".$redirect_url);
   exit;
}

function mindscope_webint_api_signin_form_handle_save()
{
	$email = (!empty($_POST["signinEmail"])) ? $_POST["signinEmail"] : NULL;
	$password = (!empty($_POST["signinPassword"])) ? $_POST["signinPassword"] : NULL;
	
	$filter = json_encode(array('username'=>$email, 'password'=>$password), JSON_FORCE_OBJECT);
	
	$candidate_details = cura_get_candidate_id($filter);
	
	// console_log($filter);
	// console_log($candidate_details);
	
	if ($candidate_details->status != "error")
	{
		session_start();
		$_SESSION["mwa_logged_in"] = "true";
		$job_app_page_link = get_permalink(get_option('mindscope_webint_api_job_app_page'));
		$job_app_page_link .= "?status=success";
		header("Location: " .$job_app_page_link);
		exit;
	}
	else
	{
		$job_app_page_link = get_permalink(get_option('mindscope_webint_api_job_app_page'));
		$job_app_page_link .= "?status=error";
		header("Location: " .$job_app_page_link);
		exit;
	}
}

function mindscope_webint_api_form_handle_save() 
{
	$allowed_file_types = array('doc', 'docx', 'pdf', 'txt', 'rtf', 'html', 'htm', 'odf');
	
	if (isset($_POST['parseResume']))
	{
		try
		{
			$resume_file = $_FILES['resume_file'];
			$resume_ext = pathinfo($resume_file['name'], PATHINFO_EXTENSION);
			if (!in_array($resume_ext, $allowed_file_types))
			{
				echo json_encode(array('success'=>'invalid'));
				exit;
			}
			$upload_overrides = array( 'test_form' => false ); 
			$uploaded_file = wp_handle_upload($resume_file, $upload_overrides);
			
			$b64_resume = base64_encode(file_get_contents($uploaded_file['file']));
			$file_name = pathinfo($uploaded_file['file'], PATHINFO_FILENAME);
			$file_ext = pathinfo($uploaded_file['file'], PATHINFO_EXTENSION);
			
			$resume_filter = json_encode(
			array(
			'document'=>(array(array('documentTypeName'=>'Original Resume', 'documentname'=>$file_name, 'documentextension'=>$file_ext, 'documentValue'=>$b64_resume)))
			));
			
			$file_response = cura_parse_resume($resume_filter);
			
			if ($file_response->status == "success")
			{
				$candidate_data = array(
					'firstname'=>$file_response->content[0]->StructuredXMLResume->ContactInfo->PersonName->GivenName[0],
					'middlename'=>$file_response->content[0]->StructuredXMLResume->ContactInfo->PersonName->MiddleName[0],
					'lastname'=>$file_response->content[0]->StructuredXMLResume->ContactInfo->PersonName->FamilyName[0]->Value,
					'email'=>$file_response->content[0]->StructuredXMLResume->ContactInfo->ContactMethod->InternetEmailAddress[0]->Value,
					'phone'=>$file_response->content[0]->StructuredXMLResume->ContactInfo->ContactMethod->Telephone[0]->FormattedNumber,
					'address1'=>$file_response->content[0]->StructuredXMLResume->ContactInfo->ContactMethod->PostalAddress[0]->DeliveryAddress->AddressLine[0],
					'address2'=>$file_response->content[0]->StructuredXMLResume->ContactInfo->ContactMethod->PostalAddress[0]->DeliveryAddress->AddressLine[1],
					'zippostal'=>$file_response->content[0]->StructuredXMLResume->ContactInfo->ContactMethod->PostalAddress[0]->PostalCode,
					'provincestate'=>$file_response->content[0]->StructuredXMLResume->ContactInfo->ContactMethod->PostalAddress[0]->Region[0],
					'city'=>$file_response->content[0]->StructuredXMLResume->ContactInfo->ContactMethod->PostalAddress[0]->Municipality
				);
				
				unlink($uploaded_file['file']);
				echo json_encode($candidate_data);
				exit;
			}
			
			unlink($uploaded_file['file']);
			echo json_encode(array('success'=>'false'));
			exit;
		}
		catch (Exception $e)
		{
			echo json_encode(array('success'=>'false'));
			exit;
		}
	}
	else if (!isset($_POST['g-recaptcha-response']))
	{
		echo json_encode(array('success'=>'invalidcaptcha'));
		exit;
	}
	else if (isset($_POST['mwa_form_apply']) && isset($_POST['job_id']))
	{
		$recaptcha_site_secret = get_option("mindscope_webtint_api_recaptcha_site_secret");
		$url = 'https://www.google.com/recaptcha/api/siteverify';
        $data = ['secret'   => $recaptcha_site_secret,
                 'response' => $_POST['g-recaptcha-response'],
                 'remoteip' => $_SERVER['REMOTE_ADDR']];

        $options = [
            'http' => [
                'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
                'method'  => 'POST',
                'content' => http_build_query($data) 
            ]
        ];

        $context  = stream_context_create($options);
        $result = file_get_contents($url, false, $context);
        
		if (!(json_decode($result)->success))
		{
			echo json_encode(array('success'=>'invalidcaptcha'));
			exit;
		}
		
		//handle candidate data
		$firstname = (!empty($_POST["txt_firstname"])) ? sanitize_text_field($_POST["txt_firstname"]) : NULL;
		$lastname = (!empty($_POST["txt_lastname"])) ? sanitize_text_field($_POST["txt_lastname"]) : NULL;
		$middlename = (!empty($_POST["txt_middlename"])) ? sanitize_text_field($_POST["txt_middlename"]) : NULL;
		$email = (!empty($_POST["txt_emailaddress"])) ? sanitize_email($_POST["txt_emailaddress"]) : NULL;
		$phone = (!empty($_POST["txt_phoneNumber"])) ? sanitize_text_field($_POST["txt_phoneNumber"]) : NULL;
		$address1 = (!empty($_POST["txt_address1"])) ? sanitize_text_field($_POST["txt_address1"]) : NULL;
		$address2 = (!empty($_POST["txt_address2"])) ? sanitize_text_field($_POST["txt_address2"]) : NULL;
		$city = (!empty($_POST["txt_city"])) ? sanitize_text_field($_POST["txt_city"]) : NULL;
		$provincestate = (!empty($_POST["sel_provincesate"])) ? sanitize_text_field($_POST["sel_provincesate"]) : NULL;
		$zippostal = (!empty($_POST["txt_zippostal"])) ? sanitize_text_field($_POST["txt_zippostal"]) : NULL;
		$job_id = (!empty($_POST["job_id"])) ? sanitize_text_field($_POST["job_id"]) : NULL;
		
		//handle file
		$resume_file = $_FILES['resume_file'];
		$upload_overrides = array( 'test_form' => false ); 
		$uploaded_file = wp_handle_upload($resume_file, $upload_overrides);
		
		$resume_ext = pathinfo($resume_file['name'], PATHINFO_EXTENSION);
		if (!in_array($resume_ext, $allowed_file_types))
		{
			echo json_encode(array('success'=>'invalid'));
			exit;
		}
		
		$filter = json_encode(
		array('firstName'=>$firstname,
			  'lastName'=>$lastname,
			  'middleName'=>$middlename,
			  'emailAddress'=>$email,
			  'sendConfirmationEmail'=>'Yes',
			  'address'=>(array(array('address'=>$address1, 'address2'=>$address2, 'city'=>$city, 'provinceId'=>$provincestate, 'PostalCode'=>$zippostal, 'default'=>'Yes'))),
			  'phone'=>(array(array('value'=>$phone, 'default'=>'Yes'))),
			  'job'=>(array(array('jobId'=>$job_id)))
		));
		
		$candidate = cura_register_candidate($filter);
		
		if ($candidate->status == "success")
		{
			//console_log($uploaded_file['file']);
			$b64_resume = base64_encode(file_get_contents($uploaded_file['file']));
			$file_name = pathinfo($uploaded_file['file'], PATHINFO_FILENAME);
			$file_ext = pathinfo($uploaded_file['file'], PATHINFO_EXTENSION);
			
			$resume_filter = json_encode(
			array(
			'candidateId'=>$candidate->candidateId,
			'document'=>(array(array('documentTypeId'=>'1', 'name'=>$file_name, 'extension'=>$file_ext, 'value'=>$b64_resume, 'parseProfile'=>'Yes')))
			));
			
			$file_response = cura_add_document($resume_filter);
			
			if ($file_response->status == "success")
			{
				unlink($uploaded_file['file']);
			}
			
			echo json_encode(array('success'=>'true'));
			exit;
		}
	}
	
	echo json_encode(array('success'=>'false'));
	exit;
	// $redirect_url = get_bloginfo("url") . "/wp-admin/options-general.php?page=mindscope-webint-api&status=success";
	// header("Location: ".$redirect_url);
	// exit;
}

function job_form_func( $atts )
{
	try
	{
		load_script_mindscope_webint_form();
		load_script_bootstrap_form();
		
		$return_html = "";
		$status = $_GET["status"];
		$job_id = $_GET["id"];
		$job_title = $_GET["job"];
		$error_text = "";
		$recaptcha_site_key = get_option("mindscope_webtint_api_recaptcha_site_key");
		
		if ($status == "success")
		{
			$return_html = "
<div class='card'>
  <div class='card-body'>
    <h5 class='card-title text-success'>Success!</h5>
    <p class='card-text'>You have applied to the job.</p>
  </div>
</div>";
		}
		else
		{
			$job_app_page_link = plugins_url('/php/form_action.php', __FILE__);
			$post_link = admin_url( 'admin-post.php');
			$ajax_post_link = admin_url( 'admin-ajax.php' );
			$url_decoded_title = urldecode($job_title);
			
			$job_information = "";
			if ($job_id != null)
			{
				$job_information = "<p class='card-text' >Applying to Job: <strong>{$url_decoded_title} (ID#: {$job_id})</strong></p>";
			}
			
			$stateprovince_filter = json_encode(array('tableName'=>'provinceState'));
			$stateprovince_list = cura_get_table_values($stateprovince_filter);
			$stateprovince_option_list = "";
			
			foreach ($stateprovince_list->provinceState as $value)
			{
				$stateprovince_option_list .= "<option value='{$value->id}'>{$value->value}</option>";
			}
			
			$country_filter = json_encode(array('tableName'=>'country'));
			$country_list = cura_get_table_values($country_filter);
			$country_option_list = "";
			
			foreach ($country_list->country as $value)
			{
				$country_option_list .= "<option value='{$value->id}'>{$value->value}</option>";
			}
			
			$spinner_url = plugin_dir_url( __FILE__ ) . 'images/spinner.gif'; 
			
			$register_html = "
<div id='mwaFormStatus' class='card' style='display: none; max-width: 900px; margin-left: auto; margin-right: auto;'>
  <div class='card-body'>
    <h5 class='card-title text-success'>Success!</h5>
    <p class='card-text'>You have applied to the job.</p>
  </div>
</div>			
<div id='mwaFormMain' class='card' style='max-width: 900px; margin-left: auto; margin-right: auto;' >
	<h5 class='card-header api-background-color text-color-white'>Application Form</h5>
	<div class='card-body'>
		{$job_information}
		<br>
		<p id='invalidFileType' class='card-text text-danger' style='display: none;'>Invalid file type. Please upload a valid resume file.</p>
		<p id='invalidCaptcha' class='card-text text-danger' style='display: none;'>reCAPTCHA could not be verified.</p>
		<p id='formError' class='card-text text-danger' style='display: none;'>Something went wrong. Please try again later.</p>
		<form id='mwaform' class='needs-validation' novalidate action='{$post_link}' method='post' enctype='multipart/form-data'>
		  <input type='hidden' name='action' value='mindscope_webint_api_form' />
		  <input type='hidden' name='job_id' value='{$job_id}' />
		  <input type='hidden' id='ajax_post_url' value='{$post_link}' />
		  <div class='form-group row'>
			<div class='col-10'>
				<div class='custom-file' >
					<input name='resume_file' type='file' class='custom-file-input' id='validatedCustomFile' required>
					<label class='custom-file-label d-flex' for='validatedCustomFile' style='font-weight: normal; overflow: hidden; text-overflow: ellipsis; display: none;' >Apply with resume...</label>
					<div class='invalid-feedback'>Please attach your resume</div>
				</div>
			</div>
			<div class='col-2' style='background-color: white;'>
			<img id='gifResumeSpinner' src='{$spinner_url}' height='30' width='30' style='margin-left: -10px; display: none;'>
			</div>
		  </div>
		  <br>
		  <div class='form-row'>
			<div class='form-group col-md-6'>
				  <label for='txtFirstName'>First Name</label>
				  <input name='txt_firstname' type='text' class='form-control' id='txtFirstName' name='firstname' placeholder='' required>
				  <div class='invalid-feedback'>
					Please provide your first name.
				  </div>
			</div>
			<div class='form-group col-md-6'>
				<label for='txtLastName'>Last Name</label>
				<input name='txt_lastname' type='text' class='form-control' id='txtLastName' name='lastname' placeholder='' required>
				<div class='invalid-feedback'>
					Please provide your last name.
			  </div>
			</div>
		  </div>
		  <div class='form-row'>
			  <div class='form-group col-md-6'>
				<label for='txtEmailAddress'>Email</label>
				<input name='txt_emailaddress' type='email' class='form-control' id='txtEmailAddress' placeholder='' required>
				<div class='invalid-feedback'>
					Please provide a valid email.
				</div>
			  </div>
			  <div class='form-group col-md-6'>
				<label for='txtPhoneNumber'>Phone</label>
				<input name='txt_phoneNumber' type='text' class='form-control' id='txtPhoneNumber' placeholder='' required>
				<div class='invalid-feedback'>
					Please provide a valid phone number.
				</div>
			  </div>
		  </div>
		  <div class='form-row'>
			<div class='form-group col-md-6'>
			  <label for='txtCity'>City</label>
			  <input name='txt_city' type='text' class='form-control' id='txtCity' required>
			  <div class='invalid-feedback'>
				Please provide a city.
			  </div>
			</div>
			<div class='form-group col-md-6'>
			  <label for='inputProvinceState'>State</label>
			  <select name='sel_provincesate' id='inputProvinceState' class='form-control' required>
				<option value='' selected>Choose...</option>
				{$stateprovince_option_list}
			  </select>
			  <div class='invalid-feedback'>
				Please select a state.
			  </div>
			</div>
		  </div>
		  <div class='form-group row'>
			<div class='col-sm-10'>
			  <br>
			  <div class='g-recaptcha' data-sitekey='{$recaptcha_site_key}' data-size='compact' style='background-color: white'></div>
			  <br />
			  <button id='btnMwaFormSubmit' type='submit' class='btn btn-primary btn-lg' name='mwa_form_apply' style='background-color: #64BAC5 !important; border-color: #64BAC5 !important;'>
				<img id='gifSubmitSpinner' src='{$spinner_url}' height='25' width='25' style='display: none;'>
				Apply
			  </button>
			</div>
		  </div>
		</form>
	</div>
</div>	
			";
			
			$apply_html="";
			
			$return_html = $register_html;
		}
		
		return $return_html;
	}
	catch (Exception $e)
	{
		//return "<p class='text-danger'>ERROR: Unable to load form</p>";
		
		return "
<div class='card'>
  <div class='card-body'>
    <h5 class='card-title text-danger'>ERROR</h5>
    <p class='card-text'>Unable to load form</p>
  </div>
</div>";
	}
}

function job_post_func( $atts ) 
{
	try
	{
		load_script_jquery();
		load_script_bootstrap();
		load_style_mindscope_webint();
		
		//load_script_mindscope_webint();
		
		$the_id = $_GET['id'];
		$filter = json_encode(array('filter'=>array('JobOrderID' => $the_id)), JSON_FORCE_OBJECT);
		$job_app_page_link = get_permalink(get_option('mindscope_webint_api_job_app_page'));
		
		$job_details = cura_search_joborder($filter);
		
		$url_encoded_title = urlencode($job_details->jobOrder[0]->position);

		$job_func = $job_details->jobOrder[0]->jobFunction;
		$job_req = $job_details->jobOrder[0]->jobRequirement;
		$display_requirements = "";
		
		if (empty($job_req))
		{
			$display_requirements = "style='display: none;'";
		}

		$return_html = "
		<div class='container' style='max-width: 900px; margin-left: auto; margin-right: auto;'>
			<div class='row'>
				<div class='col-12 d-none d-sm-block'>
					<h2 class='h4-big api-color' style='word-wrap: break-word;'>{$job_details->jobOrder[0]->position}</h2>
				</div>
			</div>
			<div class='row'>
				<div class='col-12 d-block d-sm-none'>
					<h2 class='h4-medium api-color' style='word-wrap: break-word;'>{$job_details->jobOrder[0]->position}</h2>
				</div>
			</div>
			<div class='row'>
				<div class='col-sm-3'>
					<p><small>Division</small></p>
					<p class='font-weight-bold'>{$job_details->jobOrder[0]->jobOrderDivision}</p>
				</div>
				<div class='col-sm-3'>
					<p><small>Employment Type</small></p>
					<p class='font-weight-bold'><i class='far fa-calendar-alt' style='margin-right: 5px;'></i>{$job_details->jobOrder[0]->employmentType}</p>
				</div>
				<div class='col-sm-3'>
					<p><small>Location</small></p>
					<p class='font-weight-bold'><i class='fas fa-map-marker-alt' style='margin-right: 5px;'></i>{$job_details->jobOrder[0]->city}, {$job_details->jobOrder[0]->province}</p>
				</div>
				<div class='col-sm-3'>
					<a class='btn btn-primary btn-lg api-background-color api-border-color text-color-white' style='margin-top: 15px;' href='{$job_app_page_link}?id={$the_id}&job={$url_encoded_title}' role='button'>Apply</a>	
				</div>
			</div>
			<div class='row'>
				<div class='col'>
					<hr/>
				</div>
			</div>
			<br>
			<div class='row'>
				<div class='col'>
					<h4>Job Description:</h4>
				</div>
			</div>
			<div class='row'>
				<div class='col'>
					{$job_func}
				</div>
			</div>
			<div class='row'>
				<div class='col' {$display_requirements}>
					<h4>Job Requirements:</h4>
				</div>
			</div>
			<div class='row'>
				<div class='col' {$display_requirements}>
					{$job_req}
				</div>
			</div>
			<div class='row'>
				<div class='col-sm-3'>
				</div>
				<div class='col-sm-3'>
				</div>
				<div class='col-sm-3'>
				</div>
				<div class='col-sm-3'>
					<a class='btn btn-primary btn-lg api-background-color api-border-color text-color-white' href='{$job_app_page_link}?id={$the_id}&job={$url_encoded_title}' role='button'>Apply</a>
				</div>
			</div>
		</div>
		";
		
		//console_log($job_details);
		
		return $return_html;
	}
	catch (Exception $e)
	{
		//return "<p class='text-danger'>ERROR: Unable to get job information</p>";
		
		return "
<div class='card'>
  <div class='card-body'>
    <h5 class='card-title text-danger'>ERROR</h5>
    <p class='card-text'>Unable to get job information</p>
  </div>
</div>";
	}
}

function generate_job_card ($value, $url)
{
	$return_list = "";
	
	if ($url != null)
	{
		//$job_desc_page_link = get_permalink(get_option('mindscope_webint_api_job_desc_page'));
	
		$lower_city = strtolower($value->city);
		$lower_province = strtolower($value->province);
		$web_pub_date = date_parse($value->webPublicationDate);
		$brief_description = strip_tags(($value->briefDescription));
		if (strlen($brief_description) > 350)
		{
			$brief_description = substr($brief_description, 0, 350) . "...";
		}

		$web_pub_date_short = "";
		if ($web_pub_date["error_count"] == 0)
		{
			$web_pub_date_short = $web_pub_date["month"] . "/" . $web_pub_date["day"] . "/" . $web_pub_date["year"];
		}
		
		$return_list .= "<div class='paginate'>";
		$return_list .= "<div class='card job-list-card api-border-color' style='min-height: 202px;'>";
		//$return_list .= "<div class='card-header'>{$value->jobOrderId}</div>";
		$return_list .= "<div class='card-body'>";
		//$return_list .= "<div class='card-title d-flex' style='margin-bottom: 0px;'><h3 class='api-color api-font-medium'><a href='{$job_desc_page_link}?id={$value->jobOrderId}'>{$value->position}</a></h3><span class='ml-auto d-none d-lg-block' style='margin-top: -12px;'><small>Posted: {$web_pub_date_short}<i class='far fa-calendar-alt' style='margin-left: 5px;'></i></small></span></div>";
		$return_list .= "
		<div class='card-title' style='margin-bottom: 0px;'>
		<div class='container d-none d-lg-block' style='padding-left: 0px; padding-right: 0px; padding-top: 0px;'>
			<div class='row'>
				<div class='col-9'>
					<h3 class='api-color'><a href='{$url}'>{$value->position}</a></h3>
				</div>
				<div class='col-3' style='text-align: right;'>
					<small class='d-none d-lg-block'>Posted: {$web_pub_date_short}<i class='far fa-calendar-alt' style='margin-left: 5px;'></i></small>
				</div>
			</div>
			<div class='row'>
				<div class='col-12'>
					<p class='font-weight-light text-capitalize'><i class='fas fa-map-marker-alt' style='margin-right: 5px;'></i>{$lower_city}, {$lower_province}</p>
				</div>
			</div>
		</div>
		<div class='container d-block d-lg-none' style='padding-left: 0px; padding-right: 0px;'>
			<div class='row'>
				<div class='col-12' style='padding-left: 0px; padding-right: 0px;'>
					<h3 class='api-color'><a href='{$url}'>{$value->position}</a></h3>
				</div>
			</div>
			<div class='row'>
				<div class='col-12' style='padding-left: 0px; padding-right: 0px; margin-top: -10px;'>
					<small><i class='far fa-calendar-alt' style='margin-right: 5px;'></i>{$web_pub_date_short}</small>
				</div>
			</div>
			<div class='row'>
				<div class='col-12' style='padding-left: 0px; padding-right: 0px; margin-top: 5px;'>
					<p class='font-weight-light text-capitalize'><i class='fas fa-map-marker-alt' style='margin-right: 5px;'></i>{$lower_city}, {$lower_province}</p>
				</div>
			</div>
		</div>
		</div>";
		//$return_list .= "<span class='ml-auto d-block d-lg-none' ><small><i class='far fa-calendar-alt' style='margin-right: 5px;'></i>{$web_pub_date_short}</small></span>";
		//$return_list .= "<p class='font-weight-light text-capitalize' style='margin-bottom: 0px;'><i class='fas fa-map-marker-alt' style='margin-right: 5px;'></i>{$lower_city}</p>";
		//$return_list .= "<p class='font-weight-light text-capitalize'>&nbsp;</p>";
		//$return_list .= "<p class='card-text'>Change234 This is some example text. This is some example text. This is some example text. This is some example text. This is some example text. This is some example text. This is some example text. This is some example text. This is some example text. This is some example text. This is some example text. This is some example text.</p>";
		$return_list .= "<p class='card-text api-font-small' style='font-size: 18px; margin-top: 25px;'>{$brief_description}</p>";
		$return_list .= "</div>";
		$return_list .= "</div>";
		$return_list .= "<br>";
		$return_list .= "</div>";
	}
	
    return $return_list;
}

function mindscope_ajax_save_page_number()
{
	$page_number = (!empty($_POST["page_number"])) ? $_POST["page_number"] : NULL;
	
	$_SESSION["JobListPageNumber"] = $page_number;
	
	echo $page_number;
}

function mindscope_ajax_job_list_function()
{
	global $wpdb;
	$use_static_pages = true;
	
	// if (get_option('mindscope_api_job_create_running') != 'true')
	// {
		// update_option('mindscope_api_job_create_running', 'true', TRUE);
		// create_job_pages();
		// update_job_pages();
		// delete_job_pages();
		// update_option('mindscope_api_job_create_running', 'false', TRUE);
	// }
	
	update_mindscope_api_data_func();
	
	$division = (!empty($_POST["division"])) ? $_POST["division"] : NULL;
	$state = (!empty($_POST["state"])) ? $_POST["state"] : NULL;
	$employment = (!empty($_POST["employment"])) ? $_POST["employment"] : NULL;
	$keyword = (!empty($_POST["keyword"])) ? $_POST["keyword"] : NULL;
	
	$session_pagenumber = $_SESSION["JobListPageNumber"];
	$pagenumber_html = "";
	
	if (!empty($session_pagenumber))
	{
		$pagenumber_html = "<input type='hidden' id='session_page_number' value='{$session_pagenumber}' />";
	}
	
	$filter = json_encode(array('Filter'=>(
											array('JobOrderDivisionSet'=>$division, 'ProvinceStateNameSet'=>$state, 'EmploymentTypeNameSet'=>$employment, 'Keyword'=> $keyword, 'JobOrderOpen'=>'Yes', 'JobOrderStatus'=>array('open', 'hold', 'hot'))),
								'Order'=> array(
												array('Field'=>'webPublicationDate', 'direction'=>'Desc'),
												array('Field'=>'Division', 'direction'=>'Asc')),
								'DisplayFields'=>
											array('position', 'city', 'briefDescription', 'webPublicationDate', 'jobOrderDivision', 'province', 'employmentType')));
	$job_list = cura_search_joborder($filter);
	$job_desc_page_link = get_permalink(get_option('mindscope_webint_api_job_desc_page'));
		
	$return_list .= "<h5>@TotalJobs Jobs found</h5>";
	
	$total_jobs = 0;
	
	$job_posts = $wpdb->get_results("SELECT jobid, postid, url FROM wp_mindscope_api_jobpages", "OBJECT_K");
	
	foreach ($job_list->jobOrder as $value)
	{
		if ($value->position != "")
		{
			$job_card = "";
			
			if ($use_static_pages)
			{
				$job_card = generate_job_card($value, $job_posts[$value->jobOrderId]->url);
			}
			else
			{
				$job_card = generate_job_card($value, $job_desc_page_link . "?id=" . $value->jobOrderId);
			}
			
			if (!empty($job_card))
			{
				$return_list .= $job_card;
				$total_jobs++;
			}
			
		}
	}
	
	$return_list = str_replace("@TotalJobs", $total_jobs, $return_list);
	$return_list .= "<div id='pagingBottom' class='text-right paged-jobs'></div> {$pagenumber_html}";
	
	echo $return_list;
	exit;
}

function job_list_func( $atts ) 
{
	try
	{
		//session_start();
		
		load_script_jquery();
		//load_script_bootstrap();
		load_script_bootstrap_form();
		load_script_pagination();
		load_script_mindscope_webint_joblist();
		
		$session_keyword = "";
		
		$job_desc_page_link = get_permalink(get_option('mindscope_webint_api_job_desc_page'));
		$total_jobs = 0;
		
		$loading_dots_url = plugin_dir_url( __FILE__ ) . 'images/loading_dots.gif';
		
		$currentDate = strtotime($dt);
		$futureDate = $currentDate+(60*5);
		$formatDate = date("Y-m-d H:i:s", $futureDate);

		$division_arr = array();
		$state_arr = array();
		$employment_arr = array();
		$filter_from_storage = false;
		
		$saved_filter_datetime = get_option("mindscope_webint_api_filter_datetime");
		$saved_division_filter = get_option("mindscope_webint_api_division_filter");
		$saved_state_filter = get_option("mindscope_webint_api_state_filter");
		$saved_employment_filter = get_option("mindscope_webint_api_employment_filter");

		$division_filter_list_html = "";
		$state_filter_list_html = "";
		$employment_filter_list_html = "";
		
		if (!empty($saved_division_filter) && !empty($saved_state_filter) && !empty($saved_employment_filter) && !empty($saved_filter_datetime))
		{
			$filter_datetime = strtotime($saved_filter_datetime);
			$filter_datetime_add = $filter_datetime+(60*1440);
			$filter_datetime_final = date("Y-m-d H:i:s", $filter_datetime_add);
			$current_datetime_string = date("Y-m-d H:i:s");
		
			$date_expire = new DateTime($filter_datetime_final);
			$date_now = new DateTime($current_datetime_string);
			
			if ($date_now < $date_expire)
			{
				$filter_from_storage = true;
				
				$division_arr = json_decode($saved_division_filter);
				$state_arr = json_decode($saved_state_filter);
				$employment_arr = json_decode($saved_employment_filter);
			}
		}

		$ajax_post_link = admin_url( 'admin-ajax.php' );

		//$from_cache = ($from_session) ? 'true' : 'false';
		
		$from_cache = false;
		
		$return_list = "
<div id='api-job-list' class='container' style='display: none;'>
	<div class='row'>
		<div class='col-12 d-md-block d-lg-none'>
			<h4 class='api-color api-font-large'>Search for Jobs 1</h4>
		</div>
	</div>
	<div class='row' >
		<div class='col-md-1 d-none d-lg-block' >
		</div>
		<div class='col-md-10 d-none d-lg-block'>
			<h4 class='api-color api-font-large'>Search for Jobs 1</h4>
		</div>
		<div class='col-md-1 d-none d-lg-block' >
		</div>
	</div>
	<div class='row'>
		<div class='col-10 d-md-block d-lg-none'>
			<input id='searchInputMobile' class='form-control input-lg keyword-search-box' style='height: 50px !important;' id='inputlg' type='text' placeholder='Search Jobs with Keyword' value='{$session_keyword}'>
		</div>
		<div class='col-2 d-md-block d-lg-none' style='background-color: white;'>
			<button type='button' class='btn btn-primary api-background-color api-border-color btn-lg api-search-button' style='height: 50px !important; margin-left: -15px;'><i class='fas fa-search'></i></button>
		</div>
	</div>		
	<div class='row'>
		<div class='col-md-1 d-none d-lg-block' >
		</div>
		<div class='col-md-8 d-none d-lg-block' >
			<input id='searchInputDesktop' class='form-control input-lg keyword-search-box' style='height: 50px !important; margin-top: -20px;' id='inputlg' type='text' placeholder='Search Jobs with Keyword' value='{$session_keyword}'>
		</div>
		<div class='col-md-2 d-none d-lg-block' >
			<button type='button' class='btn btn-primary api-background-color api-border-color btn-lg api-search-button' style='height: 50px !important;'>Search <i class='fas fa-search'></i></button>
		</div>
		<div class='col-md-1 d-none d-lg-block' >
		</div>
	</div>		
<div class='row'>";

		$return_list .= "
<div id='apiFilters' class='col-md-3'> 
	<div class='accordion' id='filterAccordian'>
	  <div class='card' style='border: none;'>
		<div class='card-header api-background-color api-filter-header' style='height: 50px;' id='headingOne'>
			<h4 class='text-color-white' style='font-weight: normal;' data-toggle='collapse' data-target='#filterOne' aria-expanded='false' aria-controls='filterOne'>
				Divisions
				<i class='fas fa-caret-down float-right'></i>
			</h4>
		</div>

		<div id='filterOne' class='collapse show' aria-labelledby='headingOne' data-parent='#filterAccordian'>
		  <div class='card-body' style='max-height: 170px; overflow-y: auto;'>
			@DivisionFilter
		  </div>
		</div>
	  </div>
	</div>
	<div class='accordian' id='filter2accordian' style='margin-top: 30px;'>
	  <div class='card' style='border: none;'>
		<div class='card-header api-background-color api-filter-header' style='height: 50px;' id='headingOne'>
			<h4 class='text-color-white' style='font-weight: normal;' data-toggle='collapse' data-target='#filterTwo' aria-expanded='false' aria-controls='filterTwo'>
				States
				<i class='fas fa-caret-down float-right'></i>
			</h4>
		</div>

		<div id='filterTwo' class='collapse show' aria-labelledby='headingOne' data-parent='#filter2accordian'>
		  <div class='card-body' style='max-height: 170px; overflow-y: auto;'>
			@StateFilter
		  </div>
		</div>
	  </div>
	</div>
	<div class='accordian' id='filter3accordian'>	
	  <div class='card' style='margin-top: 30px; border: none;'>
		<div class='card-header api-background-color api-filter-header' style='height: 50px;' id='headingOne'>
			<h4 class='text-color-white' style='font-weight: normal;' data-toggle='collapse' data-target='#filterThree' aria-expanded='false' aria-controls='filterThree'>
				Employment Type
				<i class='fas fa-caret-down float-right'></i>
			</h4>
		</div>

		<div id='filterThree' class='collapse show' aria-labelledby='headingOne' data-parent='#filter3accordian'>
		  <div class='card-body' style='max-height: 170px; overflow-y: auto;'>
			@EmploymentFilter
		  </div>
		</div>
	  </div>
	</div>
</div>";

//<input type='hidden' id='from_cache' value='{$from_cache}' />

		$return_list .= "
<div class='col-md-8 col-md-pull-4'>		
<input type='hidden' id='ajax_post_url' value='{$ajax_post_link}' />
<input type='number' name='quantity' min='1' max='5'>
";


		$return_list .= "<img id='gifDotsLoader' src='{$loading_dots_url}' height='50' width='50' style='display: none; margin-top: 20px; margin-left: 15px;'><div id='mwa_job_list' style='max-width: 900px; margin-left: auto; margin-right: auto; margin-top: 30px;'>
		";
		
		//if (!$from_session)
		if (!$filter_from_storage)
		{
			$filter = json_encode(array
								 (
								 'Filter'=>
											array('JobOrderOpen'=>'Yes', 'JobOrderStatus'=>array('open', 'hold', 'hot')),
								 'DisplayFields'=>array('position', 'city', 'briefDescription', 'webPublicationDate', 'jobOrderDivision', 'province', 'employmentType'), 
								 'Order'=> array(
												array('Field'=>'webPublicationDate', 'direction'=>'Desc'),
												array('Field'=>'Division', 'direction'=>'Asc'))));	
			$job_list = cura_search_joborder($filter);
			foreach ($job_list->jobOrder as $value)
			{
				if ($value->position != "")
				{
					if (!in_array($value->jobOrderDivision, $division_arr))
					{
						if (!empty($value->jobOrderDivision))
						{
							array_push($division_arr, $value->jobOrderDivision);
						}
					}
					
					if (!in_array($value->province, $state_arr))
					{
						if (!empty($value->province))
						{
							array_push($state_arr, $value->province);
						}
					}
					
					if (!in_array($value->employmentType, $employment_arr))
					{
						if (!empty($value->employmentType))
						{
							array_push($employment_arr, $value->employmentType);
						}
					}
					
					//$return_list .= generate_job_card($value);
					$total_jobs++;
				}
			}
		}
		
		$checked_checkbox = "";
		$division_count = 0;
		sort($division_arr);
		foreach($division_arr as $value)
		{
			$division_filter_list_html .= "
			<div class='form-check custom-control custom-checkbox' style='margin-bottom: 7px;'>
			  <input class='custom-control-input division-options' type='checkbox' value='{$value}' id='divisionCheck{$division_count}' {$checked_checkbox}>
			  <label class='custom-control-label api-font-small' for='divisionCheck{$division_count}'>
			  {$value}
			  </label>
			</div>			
			";
			
			$division_count++;
		}
		
		$state_count = 0;
		sort($state_arr);
		foreach ($state_arr as $value)
		{
			$state_filter_list_html .= "
			<div class='form-check custom-control custom-checkbox' style='margin-bottom: 7px;'>
			  <input class='custom-control-input state-options' type='checkbox' value='{$value}' id='stateCheck{$state_count}' {$checked_checkbox}>
			  <label class='custom-control-label api-font-small' for='stateCheck{$state_count}'>
			  {$value}
			  </label>
			</div>			
			";
			
			$state_count++;
		}

		sort($employment_arr);
		$employment_count = 0;
		foreach ($employment_arr as $value)
		{
			$employment_filter_list_html .= "
			<div class='form-check custom-control custom-checkbox' style='margin-bottom: 7px;'>
			  <input class='custom-control-input employment-options' type='checkbox' value='{$value}' id='employmentCheck{$employment_count}' {$checked_checkbox}>
			  <label class='custom-control-label api-font-small' for='employmentCheck{$employment_count}'>
			  {$value}
			  </label>
			</div>
			";
			
			$employment_count++;
		}
		
		if (!$filter_from_storage)
		{
			//console_log('saving filters');
			$dt_now = date("Y-m-d H:i:s");
			update_option("mindscope_webint_api_division_filter", json_encode($division_arr), TRUE);
			update_option("mindscope_webint_api_state_filter", json_encode($state_arr), TRUE);
			update_option("mindscope_webint_api_employment_filter", json_encode($employment_arr), TRUE);
			update_option("mindscope_webint_api_filter_datetime", $dt_now, TRUE);
		}
		
		$return_list = str_replace("@DivisionFilter", $division_filter_list_html, $return_list);
		$return_list = str_replace("@StateFilter", $state_filter_list_html, $return_list);
		$return_list = str_replace("@EmploymentFilter", $employment_filter_list_html, $return_list);
		

		$return_list .= "<div id='pagingBottom' class='text-right paged-jobs'></div></div>";
		
		$return_list .= "</div></div></div>";
		
		$return_list = str_replace("@totaljobs", $total_jobs, $return_list);

		//<p class='text-danger'>{$error_text}</p>
	   return $return_list;
	}
	catch (Exception $e)
	{
		//return "<p class='text-danger'>ERROR: Unable to get job list</p>";
		
		return "
<div class='card'>
  <div class='card-body'>
    <h5 class='card-title text-danger'>ERROR</h5>
    <p class='card-text'>Unable to get job list</p>
  </div>
</div>";
	}
}

function cura_get_joborders()
{
	$service_url = get_option( "mindscope_webtint_api_url" );
	$account_id = get_option( "mindscope_webtint_api_accountid" );
	$api_key = get_option ( "mindscope_webtint_api_key" );
	
	$client = new SoapClient($service_url . "?wsdl");
	$job_list = $client->CURAGetJobOrders(array('AccountID'=>$account_id, 'AccountKey'=>$api_key));
	$job_list_result = $job_list->CURAGetJobOrdersResult;
	
	$job_list_decoded = json_decode($job_list_result);
	
	return $job_list_decoded;
}

function cura_search_joborder($filter)
{	
	$service_url = get_option( "mindscope_webtint_api_url" );
	$account_id = get_option( "mindscope_webtint_api_accountid" );
	$api_key = get_option ( "mindscope_webtint_api_key" );
	
	$client = new SoapClient($service_url . "?wsdl");
	$job = $client->CURASearchJobOrders(array('AccountID'=>$account_id, 'AccountKey'=>$api_key, 'ProfileData'=>$filter));
	$job_result = $job->CURASearchJobOrdersResult;
	
	$job_decoded = json_decode($job_result);
	
	return $job_decoded;
}

function cura_get_candidate_id($filter)
{
	$service_url = get_option( "mindscope_webtint_api_url" );
	$account_id = get_option( "mindscope_webtint_api_accountid" );
	$api_key = get_option ( "mindscope_webtint_api_key" );
	
	$client = new SoapClient($service_url . "?wsdl");
	$candidate = $client->CURAGetCandidateId(array('AccountID'=>$account_id, 'AccountKey'=>$api_key, 'ProfileData'=>$filter));
	$candidate_result = $candidate->CURAGetCandidateIdResult;
	
	$candidate_decoded = json_decode($candidate_result);
	
	return $candidate_decoded;
}

function cura_register_candidate($filter)
{
	$service_url = get_option( "mindscope_webtint_api_url" );
	$account_id = get_option( "mindscope_webtint_api_accountid" );
	$api_key = get_option ( "mindscope_webtint_api_key" );
	
	ini_set('max_execution_time', 300);
	ini_set('default_socket_timeout', 300);
	set_time_limit(300);
	$client = new SoapClient($service_url . "?wsdl", array("default_socket_timeout" => 300));
	$candidate = $client->CURARegisterCandidate(array('AccountID'=>$account_id, 'AccountKey'=>$api_key, 'ProfileData'=>$filter));
	$candidate_result = $candidate->CURARegisterCandidateResult;
	
	//set_time_limit(300);
	//ini_set('max_execution_time', 300);
	$candidate_decoded = json_decode($candidate_result);
	
	return $candidate_decoded;
}

function cura_add_document($filter)
{
	$service_url = get_option( "mindscope_webtint_api_url" );
	$account_id = get_option( "mindscope_webtint_api_accountid" );
	$api_key = get_option ( "mindscope_webtint_api_key" );
	
	ini_set('max_execution_time', 300);
	ini_set('default_socket_timeout', 300);
	set_time_limit(300);
	$client = new SoapClient($service_url . "?wsdl", array("default_socket_timeout" => 300));
	$document = $client->CURAAddDocument(array('AccountID'=>$account_id, 'AccountKey'=>$api_key, 'ProfileData'=>$filter));
	$document_result = $document->CURAAddDocumentResult;
	
	$document_decoded = json_decode($document_result);
	
	return $document_decoded;
}

function cura_parse_resume($filter)
{
	$service_url = get_option( "mindscope_webtint_api_url" );
	$account_id = get_option( "mindscope_webtint_api_accountid" );
	$api_key = get_option ( "mindscope_webtint_api_key" );
	
	ini_set('max_execution_time', 300);
	ini_set('default_socket_timeout', 300);
	set_time_limit(300);
	$client = new SoapClient($service_url . "?wsdl", array("default_socket_timeout" => 300));
	$document = $client->CURAParseResume(array('AccountID'=>$account_id, 'AccountKey'=>$api_key, 'ProfileData'=>$filter));
	$document_result = $document->CURAParseResumeResult;
	
	$document_decoded = json_decode($document_result);
	
	return $document_decoded;
}

function cura_get_table_values($filter)
{
	$service_url = get_option( "mindscope_webtint_api_url" );
	$account_id = get_option( "mindscope_webtint_api_accountid" );
	$api_key = get_option ( "mindscope_webtint_api_key" );
	
	$client = new SoapClient($service_url . "?wsdl");
	$table_values = $client->CURAGetTableValues(array('AccountID'=>$account_id, 'AccountKey'=>$api_key, 'ProfileData'=>$filter));
	$table_values_result = $table_values->CURAGetTableValuesResult;
	
	$table_values_decoded = json_decode($table_values_result);
	
	return $table_values_decoded;
}

function load_script_jquery()
{
	wp_enqueue_script('jquery');
}

function load_script_bootstrap()
{
	wp_register_style('mwa_bootstrap', 'https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/css/bootstrap.min.css');
	wp_enqueue_style('mwa_bootstrap');
	
	wp_register_script('popperjs', 'https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.7/umd/popper.min.js');
	wp_enqueue_script('popperjs');
	
	wp_register_script('mwa_bootstrap', 'https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.min.js');
	wp_enqueue_script('mwa_bootstrap');
	
	wp_register_style('font_awesome', 'https://use.fontawesome.com/releases/v5.3.1/css/all.css');
	wp_enqueue_style('font_awesome');
}

function load_script_bootstrap_form()
{
	wp_register_style('mwa_bootstrap', plugins_url('/css/bootstrap.css', __FILE__));
	wp_enqueue_style('mwa_bootstrap');
	
	wp_register_script('popperjs', 'https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.7/umd/popper.min.js');
	wp_enqueue_script('popperjs');
	
	wp_register_script('mwa_bootstrap', 'https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.min.js');
	wp_enqueue_script('mwa_bootstrap');
	
	//https://use.fontawesome.com/releases/v5.3.1/css/all.css
	wp_register_style('font_awesome', 'https://use.fontawesome.com/releases/v5.3.1/css/all.css');
	wp_enqueue_style('font_awesome');
}

function load_style_mindscope_webint()
{
	wp_register_style('mindscope-webint-api', plugins_url('/css/mindscope-webint-api.css', __FILE__));
	wp_enqueue_style('mindscope-webint-api');
}

function load_script_mindscope_webint_joblist()
{
	wp_register_style('mindscope-webint-api', plugins_url('/css/mindscope-webint-api.css', __FILE__));
	wp_enqueue_style('mindscope-webint-api');
	
	// wp_register_script('page_test', plugins_url('/js/page_test.js', __FILE__), array());
	// wp_enqueue_script('page_test');
	
	wp_register_script('mindscope-webint-api-joblist', plugins_url('/js/mindscope-webint-api-joblist.js', __FILE__), array());
	wp_enqueue_script('mindscope-webint-api-joblist');
}

function load_script_mindscope_webint_form()
{
	wp_register_script('mwa_recaptcha', 'https://www.google.com/recaptcha/api.js');
	wp_enqueue_script('mwa_recaptcha');
	
	wp_register_script('mindscope-webint-api-form', plugins_url('/js/mindscope-webint-api-form.js', __FILE__), array());
	wp_enqueue_script('mindscope-webint-api-form');
	
	wp_register_script('mwa-form-plugin', plugins_url('/js/jquery.form.min.js', __FILE__), array());
	wp_enqueue_script('mwa-form-plugin');
	
	wp_register_style('mindscope-webint-api', plugins_url('/css/mindscope-webint-api.css', __FILE__));
	wp_enqueue_style('mindscope-webint-api');
	// wp_register_style('mindscope-webint-api', plugins_url('/css/mindscope-webint-api.css', __FILE__));
	// wp_enqueue_style('mindscope-webint-api');
}

function load_script_pagination()
{
	wp_register_script( 'pagination', plugins_url('/js/simplePagination.js', __FILE__), array());
	wp_enqueue_script('pagination');

	wp_register_style('simplePaginationCSS', plugins_url('/css/simplePagination.css', __FILE__));
	wp_enqueue_style('simplePaginationCSS');	
}

function console_log( $data ){
  echo '<script>';
  echo 'console.log('. json_encode( $data ) .')';
  echo '</script>';
}

?>
