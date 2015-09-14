<?php
/*
Plugin Name: Instagram Feed Plugin
Description: Instagram Feed Plugin that pulls the user information and the recent posts from the users Instagram Feed via the API and displays it on a WordPress based website.
Plugin URI: http://www.niklasdahlqvist.com
Author: Niklas Dahlqvist
Author URI: http://www.niklasdahlqvist.com
Version: 1.0.0
Requires at least: 4.2
License: GPL
*/

/*
   Copyright 2015  Niklas Dahlqvist  (email : dalkmania@gmail.com)

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License, version 2, as
    published by the Free Software Foundation.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

// Support local development (symlinks)
// Via Alex King @ http://alexking.org/blog/2011/12/15/wordpress-plugins-and-symlinks
$my_plugin_file = __FILE__;

if (isset($plugin)) {
    $my_plugin_file = $plugin;
}
else if (isset($mu_plugin)) {
    $my_plugin_file = $mu_plugin;
}
else if (isset($network_plugin)) {
    $my_plugin_file = $network_plugin;
}

//=============================================
// Define constants
//=============================================

if ( ! defined( 'INSTAGRAM_CLASS_PATH' ) ){
   define('INSTAGRAM_CLASS_PATH', WP_PLUGIN_DIR.'/'.basename(dirname($my_plugin_file)).'/classes/');
}

if ( ! defined( 'INSTAGRAM_CLASS_URL' ) ){
    define( 'INSTAGRAM_CLASS_URL' ,  plugin_dir_url($my_plugin_file) . 'classes/');
}

//=============================================
// Include needed Instsgram Class file
//=============================================
require_once(INSTAGRAM_CLASS_PATH."Instagram.php");
use MetzWeb\Instagram\Instagram;
/**
* Ensure class doesn't already exist
*/
if(! class_exists ("Instagram_Feed_Plugin") ) {

  class Instagram_Feed_Plugin {
    private $options;

    /**
     * Start up
     */
    public function __construct()
    {
        $this->options = get_option( 'instagram_settings' );
        $this->api_client_id = $this->options['instagram_api_client_id'];
        $this->api_client_secret = $this->options['instagram_api_client_secret'];
        $this->instagram_username = $this->options['instagram_username'];
        $this->instagram_user_id = $this->options['instagram_user_id'];
        $this->instagram_redirect_uri = $this->options['instagram_redirect_uri'];
        $this->instagram_access_token = $this->options['instagram_access_token'];

        add_action( 'admin_menu', array( $this, 'add_plugin_page' ) );
        add_action( 'admin_init', array( $this, 'page_init' ) );

        add_action('admin_print_styles', array($this,'plugin_admin_styles'));
        add_action( 'admin_enqueue_scripts', array( $this, 'plugin_admin_js' ) );

        add_action( 'wp_ajax_instagram_redirect_uri', array( $this, 'instagram_redirect_uri' ));
        add_action( 'wp_ajax_nopriv_instagram_redirect_uri', array( $this,'instagram_redirect_uri') );

        add_shortcode('instagram_feed', array( $this,'FeedShortCode') );


    }

    public function setupInstagramClient() {

      $instagram = new Instagram(array(
        'apiKey'      => $this->api_client_id,
        'apiSecret'   => $this->api_client_secret,
        'apiCallback' => $this->instagram_redirect_uri
      ));

      return $instagram;

    }

    public function getInstagramLoginLink() {
      $instagram = $this->setupInstagramClient();

      // create login URL
      $loginUrl = $instagram->getLoginUrl();

      if(!isset($this->instagram_access_token) OR $this->instagram_access_token == '') {
        return '<a id="oauth" href="' . $loginUrl .'">Authorize with Instagram</a>';
      }

    }

    public function checkInstagramOauth() {

      if(!isset($this->instagram_access_token) OR $this->instagram_access_token == '') {
        return false;
      } else {
        return true;
      }
    }

    public function plugin_admin_styles() {
        wp_enqueue_style('admin-style', $this->getBaseUrl() . '/assets/css/plugin-admin-styles.css');

    }

    public function plugin_admin_js() {
        wp_register_script( 'admin-js', $this->getBaseUrl() . '/assets/js/plugin-admin-scripts.js' );
        wp_enqueue_script( 'admin-js' );
    }

    public function instagram_redirect_uri() {

      $instagram = $this->setupInstagramClient();

      $token = $instagram->getOAuthToken($_GET['code']);

      $instagram->setAccessToken($token);

      $user_information = $instagram->getUser();

      $user = $user_information->data->username;
      $id = $user_information->data->id;

      $this->options['instagram_username'] = $user;
      $this->options['instagram_user_id'] = $id;
      $this->options['instagram_access_token'] = $instagram->getAccessToken();

      update_option( 'instagram_settings', $this->options );

      wp_redirect( admin_url('tools.php?page=instagram-settings-admin') ); 
      exit;

    }

    /**
     * Add options page
     */
    public function add_plugin_page()
    {
        // This page will be under "Settings"
        add_management_page(
            'Instagram Settings Admin', 
            'Instagram Settings', 
            'manage_options', 
            'instagram-settings-admin', 
            array( $this, 'create_admin_page' )
        );
    }

    /**
     * Options page callback
     */
    public function create_admin_page()
    {
        // Set class property
        $this->options = get_option( 'instagram_settings' );
        ?>
        <div class="wrap instagram-settings">
            <h2>Instagram Settings</h2>           
            <form method="post" action="options.php">
            <?php
                // This prints out all hidden setting fields
                settings_fields( 'instagram_settings_group' );   
                do_settings_sections( 'instagram-settings-admin' );
                if($this->checkInstagramOauth() == false) {
                  $this->getInstagramLoginLink();
                }
                submit_button();
            ?>
            </form>
        </div>
        <?php
    }

    /**
     * Register and add settings
     */
    public function page_init()
    {        
        register_setting(
            'instagram_settings_group', // Option group
            'instagram_settings', // Option name
            array( $this, 'sanitize' ) // Sanitize
        );

        add_settings_section(
            'instagram_section', // ID
            'instagram Settings', // Title
            array( $this, 'print_section_info' ), // Callback
            'instagram-settings-admin' // Page
        );  

        add_settings_field(
            'instagram_api_client_id', // ID
            'Instagram API Client ID', // Title 
            array( $this, 'instagram_api_client_id_callback' ), // Callback
            'instagram-settings-admin', // Page
            'instagram_section' // Section           
        );      

        add_settings_field(
            'instagram_api_client_secret', 
            'Instagram API Client Secret', 
            array( $this, 'instagram_api_client_secret_callback' ), 
            'instagram-settings-admin', 
            'instagram_section'
        );

        add_settings_field(
            'instagram_redirect_uri', 
            'Instagram Redirect URI', 
            array( $this, 'instagram_redirect_uri_callback' ), 
            'instagram-settings-admin', 
            'instagram_section'
        );

        add_settings_field(
            'instagram_username', 
            'Instagram Username', 
            array( $this, 'instagram_username_callback' ), 
            'instagram-settings-admin', 
            'instagram_section'
        );

        add_settings_field(
            'instagram_user_id', 
            'Instagram User ID', 
            array( $this, 'instagram_user_id_callback' ), 
            'instagram-settings-admin', 
            'instagram_section'
        );

        add_settings_field(
            'instagram_access_token', 
            'Instagram Access Token', 
            array( $this, 'instagram_access_token_callback' ), 
            'instagram-settings-admin', 
            'instagram_section'
        );      
    }

    /**
     * Sanitize each setting field as needed
     *
     * @param array $input Contains all settings fields as array keys
     */
    public function sanitize( $input )
    {
        $new_input = array();
        if( isset( $input['instagram_api_client_id'] ) )
            $new_input['instagram_api_client_id'] = sanitize_text_field( $input['instagram_api_client_id'] );

        if( isset( $input['instagram_api_client_secret'] ) )
            $new_input['instagram_api_client_secret'] = sanitize_text_field( $input['instagram_api_client_secret'] );

        if( isset( $input['instagram_redirect_uri'] ) )
            $new_input['instagram_redirect_uri'] = sanitize_text_field( $input['instagram_redirect_uri'] );

        if( isset( $input['instagram_username'] ) )
            $new_input['instagram_username'] = sanitize_text_field( $input['instagram_username'] );

        if( isset( $input['instagram_user_id'] ) )
            $new_input['instagram_user_id'] = sanitize_text_field( $input['instagram_user_id'] );

        if( isset( $input['instagram_access_token'] ) )
            $new_input['instagram_access_token'] = sanitize_text_field( $input['instagram_access_token'] );

        return $new_input;
    }

    /** 
     * Print the Section text
     */
    public function print_section_info() {
      print 'Enter your settings below:';
    }

    /** 
     * Get the settings option array and print one of its values
     */
    public function instagram_api_client_id_callback()
    {
        printf(
            '<input type="text" id="instagram_api_client_id" class="regular-text" name="instagram_settings[instagram_api_client_id]" value="%s" />',
            isset( $this->options['instagram_api_client_id'] ) ? esc_attr( $this->options['instagram_api_client_id']) : ''
        );
    }

    /** 
     * Get the settings option array and print one of its values
     */
    public function instagram_api_client_secret_callback()
    {
        printf(
            '<input type="text" id="instagram_api_client_secret" class="regular-text" name="instagram_settings[instagram_api_client_secret]" value="%s" />',
            isset( $this->options['instagram_api_client_secret'] ) ? esc_attr( $this->options['instagram_api_client_secret']) : ''
        );
    }

    public function instagram_redirect_uri_callback()
    {
        printf(
            '<input type="text" id="instagram_redirect_uri" class="regular-text" name="instagram_settings[instagram_redirect_uri]" value="%s" />',
            isset( $this->options['instagram_redirect_uri'] ) ? esc_attr( $this->options['instagram_redirect_uri']) : ''
        );
    }

    

    public function instagram_username_callback() {
      if($this->checkInstagramOauth() == true) {
        printf(
            '<input type="text" id="instagram_username" name="instagram_settings[instagram_username]" value="%s" />',
            isset( $this->options['instagram_username'] ) ? esc_attr( $this->options['instagram_username']) : ''
        );
      }
        
    }

    public function instagram_user_id_callback() {
      if($this->checkInstagramOauth() == true) {
        printf(
            '<input type="text" id="instagram_user_id" name="instagram_settings[instagram_user_id]" value="%s" />',
            isset( $this->options['instagram_user_id'] ) ? esc_attr( $this->options['instagram_user_id']) : ''
        );
      }  
    }

    public function instagram_access_token_callback() {
      if($this->checkInstagramOauth() == true) {
        printf(
            '<input type="text" id="instagram_access_token" class="regular-text" name="instagram_settings[instagram_access_token]" value="%s" />',
            isset( $this->options['instagram_access_token'] ) ? esc_attr( $this->options['instagram_access_token']) : ''
        );
      }
    }

    

    public function storeInstagramUserInformation($user) {

      // Get any existing copy of our transient data
      if ( false === ( $userdata = get_transient( 'instagram_user_information' ) ) ) {
        // It wasn't there, so regenerate the data and save the transient for 12 hours
        $userdata = serialize($user);
        set_transient( 'instagram_user_information', $userdata, 12 * HOUR_IN_SECONDS );
      }

    }

    public function storeInstagramFeed($instagram_data) {

      // Get any existing copy of our transient data
      if ( false === ( $instagram_feed = get_transient( 'instagram_feed' ) ) ) {
        // It wasn't there, so regenerate the data and save the transient for 2 hours
        $instagram_feed = serialize($instagram_data);
        set_transient( 'instagram_feed', $instagram_feed, 2 * HOUR_IN_SECONDS );
      }
      
    }

    public function flushStoredInformation() {
      //Delete transients to force a new pull from the API
      delete_transient( 'instagram_feed' );
      delete_transient( 'instagram_user_information' );
    }

    public function FeedShortCode($atts, $content = null) {
      $args = shortcode_atts(array(
        'count' => 5,
        'display_information' => 'yes'
        ), $atts);

      $user_information = $this->getInstagramUserInformation();
      $instagramFeed = $this->getInstagramUserFeed();

      $output = '';

      if($args['display_information'] == 'yes') {
        $output .= '<div class="instagram-headings">';

        if(!empty($user_information->data->profile_picture)) {
          $output .= '<img src="'. $user_information->data->profile_picture .'" alt="">';
        }
        if(!empty($user_information->data->username)) {
          $output .= '<h2>@'. $user_information->data->username .' <a href="https://instagram.com/'. $user_information->data->username .'/"><span><i class="fa fa-instagram"></i>Follow on Instagram</span></a></h2>';
        }
        if(!empty($user_information->data->bio)) {
         $output .= '<p>'. $user_information->data->bio .'</p>';
        }
        $output .= '</div></div>';
      }

      if(!empty($instagramFeed)) {
        $i = 1;
        $output .= '<ul class="instagram-feed">';      
          foreach ($instagramFeed as $insta_item) {
            if($i <= $args['count']) {
              $output .= '<li data-likes="'. $insta_item['likes'] .'">';
              $output .= '<img src="'. $insta_item['image']['url'] .'" width="'. $insta_item['image']['width'] .'" height="'. $insta_item['image']['height'] .'" alt="'. $insta_item['caption'] .'">';
              $output .= '</li>';
            }
            $i++;
          }
  
        $output .= '</ul>';
      }

      

      return $output;
    }

    public function getInstagramUserInformation() {

      // Get any existing copy of our transient data
      if ( false === ( $instagram_data = get_transient( 'instagram_user_information' ) ) ) {
        // It wasn't there, so make a new API Request and regenerate the data
        
        $instagram = $this->setupInstagramClient();
        $instagram->setAccessToken($this->instagram_access_token);
        $user = $instagram->getUser();


        // It wasn't there, so save the transient for 2 hours
        $this->storeInstagramUserInformation($user);

      } else {
        // Get any existing copy of our transient data
        $user = unserialize(get_transient( 'instagram_user_information' ));
      }

      // Finally return the data
      return $user;
    }

    public function getInstagramUserFeed() {

      // Get any existing copy of our transient data
      if ( false === ( $instagram_data = get_transient( 'instagram_feed' ) ) ) {
        
        // It wasn't there, so make a new API Request and regenerate the data
        $instagram_data = array();
        $instagram = $this->setupInstagramClient();
        $instagram->setAccessToken($this->instagram_access_token);
        $user = $instagram->getUser();
        $instafeed = $instagram->getUserMedia($id = 'self', $limit = 0);

        if(!empty($instafeed)) {

          foreach ($instafeed->data as $instagram) {

            if($instagram->type == 'image') {

              $insta_item = array(
                'tags' => $instagram->tags,
                'likes' => $instagram->likes->count,
                'image' => array(
                  'url' => $instagram->images->standard_resolution->url,
                  'width' => $instagram->images->standard_resolution->width,
                  'height' => $instagram->images->standard_resolution->height
                ),
                'caption' => $instagram->caption->text
              );

              array_push($instagram_data, $insta_item);

            }
            
          }
        }

        // It wasn't there, so save the transient for 2 hours
        $this->storeInstagramFeed($instagram_data);

      } else {
        // Get any existing copy of our transient data
        $instagram_data = unserialize(get_transient( 'instagram_feed' ));
      }

      // Finally return the data
      return $instagram_data;
      
    }

   //Returns the url of the plugin's root folder
    protected function getBaseUrl(){
      return plugins_url(null, __FILE__);
    }

    //Returns the physical path of the plugin's root folder
    protected function getBasePath(){
      $folder = basename(dirname(__FILE__));
      return WP_PLUGIN_DIR . "/" . $folder;
    }


  } //End Class

  /**
   * Instantiate this class to ensure the action and shortcode hooks are hooked.
   * This instantiation can only be done once (see it's __construct() to understand why.)
   */
  new Instagram_Feed_Plugin();

} // End if class exists statement