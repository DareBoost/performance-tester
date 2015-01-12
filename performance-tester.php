<?php

defined( 'ABSPATH' ) or	die( 'Script kiddy uh?' );
/*
Plugin Name: Performance Tester
Plugin URI: https://www.dareboost.com
Description: Analyze your web page and get a quality and performance report. 
Version: 0.1.2
Author: Anthony Fourneau
Author URI: https://www.dareboost.com
License: Apache License v2
Text Domain: performance-tester
Domain Path: /languages/
*/

/*
 Copyright 2014 DareBoost (email: contact at dareboost.com)

Licensed under the Apache License, Version 2.0 (the "License");
you may not use this file except in compliance with the License.
You may obtain a copy of the License at

    http://www.apache.org/licenses/LICENSE-2.0

Unless required by applicable law or agreed to in writing, software
distributed under the License is distributed on an "AS IS" BASIS,
WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
See the License for the specific language governing permissions and
limitations under the License.
 */

class Performance_Tester{
	/**
	 * Version of the dareboost.com API 
         */
	const DBWP_API_VERSION = "0.1";

	/**
	 * Base URL of the service
	 */
	const DBWP_BASE_URL = "https://www.dareboost.com";

	/**
         * URL to use for call the API en retrieve data
         */
        const DBWP_API_URL = "/api/";

	/**
	 * The user token use for API call
	 */
	const DBWP_TOKEN = "5489c2b4e4b0b67c41430584";
	/**
	 * Use for translation
	 */
	const DBWP_TEXT_DOMAIN = "dbwpperformancetester";
	
	public function __construct(){
		add_action( 'plugins_loaded', array($this, 'dbwp_load_plugin_textdomain'));
		// toolbar
		add_action( 'wp_before_admin_bar_render', array($this, 'dbwp_add_admin_bar'));
		// Add DareBoost to the WP admin menu
		add_action('admin_menu', array($this, 'dbwp_add_admin_menu'));
		// Load scripts
		add_action('admin_enqueue_scripts',  array($this, 'dbwp_load_scripts'));
		// Load css
		add_action('admin_enqueue_scripts', array($this, 'dbwp_load_styles') );
		// Register ajax call to launch new analysis from API
		add_action('wp_ajax_new_analysis',  array($this, 'dbwp_new_analysis'));
		// Register ajax call to get the report from API
		add_action('wp_ajax_get_report', array($this, 'dbwp_get_report'));
	}

/********************************************************************************
 * 							Plugin configuration parts							*
 ********************************************************************************/
	
	/**
	 * Add a button on the admin menu
	 */
	public function dbwp_add_admin_menu(){
		global $dbwp_settings;
		// Create the link to WP menu 
		$dbwp_settings = add_menu_page('Performance Tester (by DareBoost)', 'Performance Tester', 'manage_options', 'Performance_Tester', array($this, 'dbwp_menu_html'));
	}
	
	/**
	 * Add a button on the WP tool bar to analyse the page
	 */
	public function dbwp_add_admin_bar(){
		global $wp_admin_bar;
		if ( current_user_can( 'manage_options' ) ) {
			//Add a link to the admin bar if the user has admin capabilities
			$wp_admin_bar->add_node(array(
					'id'    => 'dbwp_toolbarAdminLink',
					'title' => __('Analyze my homepage',self::DBWP_TEXT_DOMAIN),
					'href'	=> admin_url('admin.php?page=Performance_Tester') . '#launch',
					'meta'	=> array(
						'class' => 'dbwp_toolbarAdminRealLink'
					)
			));
		}
	}

	/**
	 * Load custom script needed for the plugin.
	 * ie : The ajax handler
	 */
	public function dbwp_load_scripts($hook){
		global $dbwp_settings;
		if( $hook != $dbwp_settings ){
			return;
		}
		wp_enqueue_script( 'jquery' );
		wp_enqueue_script('dbwp-ajax', plugin_dir_url(__FILE__) . 'js/dbwp-ajax.js', array('jquery'));
	}

	/** 
	 * Load custom style needed for the plugin
	 * @param unknown $hook
	 */
	public function dbwp_load_styles($hook){
		global $dbwp_settings;
		if( $hook != $dbwp_settings ){
			return;
		}
		wp_enqueue_style('dbwp-css', plugin_dir_url(__FILE__) . 'css/global.css' );
	}
	
	/**
	 * Load plugin text domain for translation
	 */
	public function dbwp_load_plugin_textdomain(){
		load_plugin_textdomain( self::DBWP_TEXT_DOMAIN, false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
	}
	
/********************************************************************************
 * 								Ajax parts										*
 ********************************************************************************/
	
	/**
	 * Launch the analysis of the home of the WordPress through the DareBoost API
	 */
	public function dbwp_new_analysis(){
		// get the lang of the wordpress
		$extractedLang = explode('-',get_bloginfo('language'));
		$lang = $extractedLang[0];
		// if the lang is not "fr" (french) we set it to "en" (english)
		if(strcasecmp($lang, "fr") != 0){
			$lang="en";
		}
		
		// get the url of the home page
		$data = array(
				"url" => get_site_url(),
				"lang" => $lang	
		);
		
		// Launch the analysis through the DareBoost API
		$json_response = $this->dbwp_call_api('/analysis/launch', $data);
		// We verify that we got a real answer
		$check = $this->dbwp_check_response($json_response);
		$result = array();
		if(!$check){ // The analysis is not launched due to error
			$result['error'] = true;
			if($check===false){
				$check=__('An unkown error occured! Please try to relaunch.', self::DBWP_TEXT_DOMAIN);
			}
			$result['message'] = '<p class="dbwp_error">'.$check.'</p>';
		}else{ // The analysis is launched
			$result['error'] = false;
			$result['message'] = '<p>' . __('Your analysis has been launched.', self::DBWP_TEXT_DOMAIN) . '</p>';
			$result['reportId'] = $json_response['reportId'];
		}
		
		// return the response in json format
		header('Content-Type: application/json');
		echo json_encode($result);
		die();
	}

	/**
	 * Get the report from a previous launch analysis through the DareBoost API
	 */
	public function dbwp_get_report(){
		// if we don't have a report id we can't retrieve it.
		if(!isset($_POST['reportId'])){
			die();
		}
		
		// call the DareBoost API
		$data = array("reportId" => $_POST['reportId']);
		$json_response = $this->dbwp_call_api('/analysis/report', $data);
		$check = $this->dbwp_check_response($json_response);
		$result = array();
		
		// We got an error
		if(!$check){
			$result['message'] = '<p class="dbwp_error">'.$check.'</p>';
			$result['isEnded'] = true;
		}
		// we have to to some verif, maybe the analysis is in progress
		elseif ($json_response['status'] == 202) {
			$result['message'] = '<p>'.$json_response['message'].'</p>';
			$result['isEnded'] = false;
		}
		// the analyis is ended correctly so we construct the reponse 
		else{
			$result = $this->dbwp_construct_report($json_response);
		}
		
		header('Content-Type: application/json');
		echo json_encode($result);
		die();
	}
	
/********************************************************************************
 * 						Internal mechanics parts								*
 ********************************************************************************/	
	/**
	 * Get the absolute api url	
 	 */	
	private function getApiUrl () {
		return self::DBWP_BASE_URL.self::DBWP_API_URL.self::DBWP_API_VERSION;
	} 

	/**
	 * Sorted by priority a tips array
	 * The highter priority is first
	 */
	private function reverseCmpTip($a, $b){
		if ($a['priority'] == $b['priority']) {
			return 0;
		}
		return ($a['priority'] < $b['priority']) ? 1 : -1;
	}
	
	/**
	 * Return true if the response is expected.
	 * Return error message if the request failed
	 * @param unknown $responseArray
	 */
	private function dbwp_check_response($json_response){
		if(!$json_response){
			return false;
		}
	
		// if the response status is higher or equal than 400, there is an error
		if($json_response['status'] >= 400) {
			return $json_response['message'];
		}
		return true;
	}
	
	/**
	 * Call DareBoost to retrieve the image represenation of score / loadtime / weight
	 * @param unknown $type
	 * @param unknown $value
	 * @return string|mixed
	 */
	private function dbwp_get_image_gauge_link($type, $value){
		// prepare curl with the url depending on the type
		$ch = curl_init(self::DBWP_BASE_URL.'/image/gauge/'.$type.'/'.$value.'/false');

		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		
		$response = curl_exec($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		if ( $httpCode != 200 || curl_errno($ch) !== 0 ) {
			curl_close($ch);
			return "";
		}
		curl_close($ch);
		
		$comp = strpos($response, self::DBWP_BASE_URL);
		
		if($comp === false || $comp != 0 ){
			return "";
		}
		
		return $response;
	}
	
	/**
	 * Call DareBoost API 
 	 *	 action to call depends of $route
 	 *
	 * @param unknown $route
	 * @param unknown $data
	 * @return boolean|mixed
	 */
	private function dbwp_call_api($route, $data){
		// we set the user token 
		$data["token"]  = self::DBWP_TOKEN;
		$data_string = json_encode($data);
		
		// init curl with the api url
		$ch = curl_init($this->getApiUrl().$route);
		
		// we make a post and send json data
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
		curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array(
				'Content-Type: application/json',
				'Content-Length: ' . strlen($data_string)
			)
		);
		
		// call curl and check error
		$response = curl_exec($ch);
		if ( curl_errno($ch) !== 0 ) {
			curl_close($ch);
			return false;
		}
		
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		if ( $httpCode != 200 ) {
			curl_close($ch);
			return false;
		}

		curl_close($ch);

		return json_decode($response, true);
	}

/********************************************************************************
 * 							Template parts										*
 ********************************************************************************/
	
	/**
	 * Create the initial admin page of the plugin
	 */
	public function dbwp_menu_html(){
		echo '<div id="dbwp_global">';
		echo '	<h1>'.get_admin_page_title().'</h1>';
		echo '	<div id="dbwp_analysisContainer">';
		echo '		<p>'. __('Analyze your webpage and get your web performance and quality report. Quickly access to the major metrics of the page and get tips to improve and speed up your website.',self::DBWP_TEXT_DOMAIN) . '</p>';
		// Add the form to launch analysis
		echo '		<form id="dbwp_form" method="post" action="">';
		echo '			<input type="submit" name="dbwp_submit" id="dbwp_submit" class="button-primary" value="' . __('Analyze my homepage',self::DBWP_TEXT_DOMAIN) .'"/>';
		echo '		</form>';
		echo '	</div>';
		// Thi is where result will be print
		echo '	<div id="dbwp_loadingAndResult">';
		echo '		<img src="' . admin_url('/images/wpspin_light.gif') . '" class="dbwp_waiting" id="dbwp_loading"/>';
		echo '		<div id="dbwp_result"></div>';
		echo '	</div>';
	
		// promo
		echo $this->dbwp_get_intro();
	
		echo '</div>';
	}
	
	/**
	 * Construct the html reponse from a valid report
	 * @param unknown $json_response
	 * @return multitype:string boolean
	 */
	private function dbwp_construct_report($json_response){
		$result = array();
	
		// if there is no report key on the response, something goes wrong
		if(!isset($json_response['report'])){
			$result['message'] = '<p class="dbwp_error">'. $json_response['message'].'</p>' ;
			$result['isEnded'] = true;
			return $result;
		}
	
		// sort the tips by priority.
		usort($json_response['report']['tips'], array($this, 'reverseCmpTip'));
	
		$performanceTimingsMessage = $this->dbpw_get_performance_timings($json_response);
	
		// date of the execution, not use for the moment
		// 		$formatedMessage .= '<p><span class="dbwp_bold">' . __('Date d\'execution le : ',self::DBWP_TEXT_DOMAIN) . '</span>' . date_i18n("F j, Y, g:i a", $json_response['report']['date']/1000) . '</p>';
	
		// link to access the report
		$formatedMessage = '<div>';
		$formatedMessage .= '	<div class="dbwp_containerPart">';
		$formatedMessage .= '		<p>';
		$formatedMessage .= 			__('Access to your full web performance and quality report which gather hundreds of metrics and tips to improve and speed up your website',self::DBWP_TEXT_DOMAIN) ;
		$formatedMessage .= '		</p>';
		$formatedMessage .= '	</div>';

		$formatedMessage .= $this->dbwp_get_gauge($json_response);

		$formatedMessage .= '	<div class="dbwp_containerPart">';
		$formatedMessage .= '		<p class="dbwp_center">';
		$formatedMessage .= '			<a target="_blank" id="dbwp_reportButton" class="button button-primary" href="' . $json_response['report']['publicReportUrl'] . '">' . __('Access to the full report',self::DBWP_TEXT_DOMAIN) . '</a>';
		$formatedMessage .= '		</p>';
		$formatedMessage .= '	</div>';
		
		// priority tips
		$formatedMessage .= $this->dbwp_get_priority_tips($json_response['report']['tips']);
		
		// performance timings
		if($performanceTimingsMessage != ""){
			$formatedMessage .= $performanceTimingsMessage;
		}

		$formatedMessage .= '</div>';

		$result['message'] =  $formatedMessage ;
		$result['isEnded'] = true;
		return $result;
	}
	
	/**
	 * Construct html part of gauge indicators
	 * It get the image from curl and display them with label
	 * @param unknown $json_response
	 * @return string
	 */
	private function dbwp_get_gauge($json_response){
		$computeWeight;
		if($json_response['report']['summary']['weight']  >= 1048576 ){
			$computeWeight = floor($json_response['report']['summary']['weight'] / 1048576.0) . __(' MB',self::DBWP_TEXT_DOMAIN);
		}elseif($json_response['report']['summary']['weight']  >= 1000 && $json_response['report']['summary']['weight']  < 1048576){
			$computeWeight = floor($json_response['report']['summary']['weight'] / 1024.0) . __(' kB',self::DBWP_TEXT_DOMAIN);
		}elseif($json_response['report']['summary']['weight']  < 1000){
			$computeWeight = $json_response['report']['summary']['weight'] . __(' B',self::DBWP_TEXT_DOMAIN);
		}
	
		// gauge
		$gaugeFormated = '<div class="dbwp_containerPart">';
		$gaugeFormated .= '	<h2>' . __('Overall Metrics', self::DBWP_TEXT_DOMAIN) . '</h2>';
		$gaugeFormated .= '	<div class="dbwp_gaugeContainer dbwp_first">';
		$gaugeFormated .= '		<img src="'. $this->dbwp_get_image_gauge_link('mark', $json_response['report']['summary']['score']) . '" alt="'. $json_response['report']['summary']['score'].'"/>';
		$gaugeFormated .= '		<span class="dbwp_gaugeText">'.$json_response['report']['summary']['score'].' / 100</span>';
		$gaugeFormated .= '	</div>';
		$gaugeFormated .= '	<div class="dbwp_gaugeContainer">';
		$gaugeFormated .= '		<img src="'. $this->dbwp_get_image_gauge_link('loadtime', $json_response['report']['summary']['loadTime']) . '" alt="'. $json_response['report']['summary']['loadTime'].'"/>';
		$gaugeFormated .= '		<span class="dbwp_gaugeText">'.$this->dbwp_floor_with_two_decimals($json_response['report']['summary']['loadTime'] / 1000.0). __(' sec', self::DBWP_TEXT_DOMAIN) . '</span>';
		$gaugeFormated .= '	</div>';
		$gaugeFormated .= '	<div class="dbwp_gaugeContainer dbwp_last">';
		$gaugeFormated .= '		<img src="'. $this->dbwp_get_image_gauge_link('weight', $json_response['report']['summary']['weight']) . '" alt="'. $json_response['report']['summary']['weight'].'"/>';
		$gaugeFormated .= '		<span class="dbwp_gaugeText">'.$computeWeight.' </span>';
		$gaugeFormated .= '	</div>';
		// 		Not use for the moment
		// 		$gaugeFormated .= '	<div class="dbwp_textContainer">';
		// 		$gaugeFormated .= '		<p>';
		// 		$gaugeFormated .= '			<img src="' . plugin_dir_url(__FILE__) . 'image/stair_drawer.png'.'"/><br/>';
		// 		$gaugeFormated .= 			$json_response['report']['summary']['requestsCount'] . __(' requetes',self::DBWP_TEXT_DOMAIN);
		// 		$gaugeFormated .= '		</p>';
		// 		$gaugeFormated .= '	</div>';
		$gaugeFormated .= '</div>';
	
		return $gaugeFormated;
	}
	
	private function dbwp_floor_with_two_decimals($number){
		return (floor($number*100)/100);
	}
	
	/**
	 * Construct html of priority tips part
	 */
	private function dbwp_get_priority_tips($tips){
		$tipsFormated = '<div  class="dbwp_containerPart">';
		$tipsFormated .= '<h2>' . __('Your 3 improvement priorities', self::DBWP_TEXT_DOMAIN) . '</h2>';
		$tipsFormated .= '<p>';
		for($i = 0; $i < 3 ; $i++ ) {
			if($tips[$i]['score'] == 100){
				break;
			}
			$tipsFormated .= '<span class="dbwp_bold">' . __('Priority',self::DBWP_TEXT_DOMAIN) . ' ' . ($i+1) .  __(': ',self::DBWP_TEXT_DOMAIN) . '</span>' . $tips[$i]['name'] . '<br/>';
		}
		$tipsFormated .= '</p>';
		$tipsFormated .= '</div>';
		return $tipsFormated;
	}
	
	/**
	 * construct HTML of performance timings from json response
	 * @param unknown $json_response
	 * @return string
	 */
	private function dbpw_get_performance_timings($json_response){
		$performanceTimingsMessage = "";
		if(isset($json_response['report']['performanceTimings'])){
			// get timings
			$loadTime = $json_response['report']['summary']['loadTime'];
			$navigationStartTimestamp = $json_response['report']['performanceTimings']['navigationStart'];
			$firstByteTimestamp = $json_response['report']['performanceTimings']['firstByte'];
			$domInteractifTimestamp = $json_response['report']['performanceTimings']['domInteractive'];
	
			// compute size of the element and the value to display
			$ttfbSize = ((( $firstByteTimestamp - $navigationStartTimestamp) * 100 ) / $loadTime);
			$ttfb = ($firstByteTimestamp - $navigationStartTimestamp);
			$domInteractifSize = ((($domInteractifTimestamp - $firstByteTimestamp) * 100 ) / $loadTime);
			$domInteractif = ($domInteractifTimestamp - $navigationStartTimestamp);
			$fullyLoadedSize = (100-$ttfbSize)-$domInteractifSize;
			$fullyLoaded = $loadTime;
	
			$ttfbSize=$ttfbSize.'%';
			$domInteractifSize=$domInteractifSize.'%';
			$fullyLoadedSize=$fullyLoadedSize.'%';
			
			// compute HTMl
			$performanceTimingsMessage .= '<div class="dbwp_containerPart">';
			$performanceTimingsMessage .= '	<h2>' . __('Performance timings',self::DBWP_TEXT_DOMAIN) . '</h2>';
			$performanceTimingsMessage .= '	<p>' . __('Performance timings are a set of data which provides information on the loadtime of a web page. It allows to identify the different parts of the loadtime and how long it takes for each one to be completed.',self::DBWP_TEXT_DOMAIN) . '</p>';
			$performanceTimingsMessage .= '	<div>';
			$performanceTimingsMessage .= '		<span class="dbwp_captionPT"><span class="dbwp_captionColorPT dbwp_ttfb"></span>' . __('Server response',self::DBWP_TEXT_DOMAIN) . '* ' . round($ttfb) . __(' ms',self::DBWP_TEXT_DOMAIN) .'</span>';
			$performanceTimingsMessage .= '		<span class="dbwp_captionPT"><span class="dbwp_captionColorPT dbwp_domInteractive"></span>' . __('Page interactive',self::DBWP_TEXT_DOMAIN) . '* ' . round($domInteractif) . __(' ms',self::DBWP_TEXT_DOMAIN) .'</span>';
			$performanceTimingsMessage .= '		<span class="dbwp_captionPT"><span class="dbwp_captionColorPT dbwp_fullyLoad"></span>' . __('Page is fully loaded',self::DBWP_TEXT_DOMAIN) . '* ' . round($fullyLoaded) . __(' ms',self::DBWP_TEXT_DOMAIN) .'</span><br>';
			$performanceTimingsMessage .= '	</div>';
			$performanceTimingsMessage .= '	<br/>';
			$performanceTimingsMessage .= '	<div class="dbwp_performanceTimingsGauge">';
			$performanceTimingsMessage .= '		<span class="dbwp_ttfb dbwp_performanceTimingsParts" style="width:'.$ttfbSize.'"></span>';
			$performanceTimingsMessage .= '		<span class="dbwp_domInteractive dbwp_performanceTimingsParts" style="width:'.$domInteractifSize.'"></span>';
			$performanceTimingsMessage .= '		<span class="dbwp_fullyLoad dbwp_performanceTimingsParts" style="width:'.$fullyLoadedSize.'"></span>';
			$performanceTimingsMessage .= '	</div>';
			$performanceTimingsMessage .= '	<p id="dbwp_perfTimingsExplain">';
			$performanceTimingsMessage .= __('* Server response: Time to first byte. Delay between the request emission and the reception of the first byte of the response.',self::DBWP_TEXT_DOMAIN);
			$performanceTimingsMessage .= '<br/>'. __('* Page interactive: It\'s when the user can interact with the web page.',self::DBWP_TEXT_DOMAIN);
			$performanceTimingsMessage .= '<br/>'. __('* Page is fully loaded: It\'s the time when the web page is fully loaded (the loading indicator of your browser disappear.)',self::DBWP_TEXT_DOMAIN);
			$performanceTimingsMessage .= '	</p>';
			$performanceTimingsMessage .= '</div>';
		}
	
		return $performanceTimingsMessage;
	}
	
	/**
	 * Get HTML for the FAQ part
	 * @return string
	 */
	private function dbwp_get_intro(){
		$introFormated =  '<div id="dbwp_intro">';
		$introFormated .= '	<hr/> ';
		$introFormated .= '	<h2>' . __('Who develops this plugin?',self::DBWP_TEXT_DOMAIN) . '</h2>';
		$introFormated .= '	<p>';
		$introFormated .= '		<a href="https://www.dareboost.com"><img src="' . plugin_dir_url(__FILE__) . 'image/logo.png'.'" id="dbwp_logo" alt="DareBoost logo"/></a>';
		$introFormated .= 		__('This plugin is developped by ',self::DBWP_TEXT_DOMAIN) . '<a target="_blank" href="https://www.dareboost.com">DareBoost</a>' . ', ' . __('a french start-up that aims to speed-up the web.',self::DBWP_TEXT_DOMAIN) . '<br/>' . __('We provide an online tool to analyze your web pages and to detect quality and loading times issues. With this plugin we aim to help you to easily improve your web site.',self::DBWP_TEXT_DOMAIN);
		$introFormated .= '	</p>';
		$introFormated .= '	<h2>' . __('What is coming next?',self::DBWP_TEXT_DOMAIN) . '</h2>';
		$introFormated .= '	<p>';
		$introFormated .= 		__('For the moment this plugin allows you to analyze your home page only. However we plan to improve this plugin and to offer monitoring feature.',self::DBWP_TEXT_DOMAIN);
		$introFormated .= 		'<br/>';
		$introFormated .= 		__('If you are interested in, don\'t hesitate to ',self::DBWP_TEXT_DOMAIN) . '<a href="mailto:contact@dareboost.com">' . __('contact us.',self::DBWP_TEXT_DOMAIN) . '</a>' ;
		$introFormated .= '	</p>';
		$introFormated .= '	<h2>' . __('Like this plugin? ',self::DBWP_TEXT_DOMAIN) . '</h2>';
		$introFormated .= '	<p>';
		$introFormated .= 		__(' We would love to hear your feedback. Please be blunt, positive and negative comments are welcome.',self::DBWP_TEXT_DOMAIN);
		$introFormated .= 		'<br/><a href="mailto:contact@dareboost.com"> ' . __('Contact us',self::DBWP_TEXT_DOMAIN) . '</a>';
		$introFormated .= 		'<br/>';
		$introFormated .=		__('Help us to improve it, find source on ',self::DBWP_TEXT_DOMAIN) . '<a href="https://github.com/DareBoost/performance-tester">GitHub</a>';
		$introFormated .= 		'<br/>';
		$introFormated .= 		__('You can give it a good rating on WordPress.org.',self::DBWP_TEXT_DOMAIN);
		$introFormated .= '	</p>';
		$introFormated .= '</div>';
		return $introFormated;
	}
}

new Performance_Tester();
