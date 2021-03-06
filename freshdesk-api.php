<?php
/**
* Plugin Name: WP Freshdesk
* Plugin URI: 
* Description: With this plugin, your users will be able to see their tickets on your Freshdesk support portal. Other features include - SSO, ticket filtering, sorting & search options. Admins have an options to display only certain status tickets with shortcodes.
* Version: 1.0
* Author: Brainstorm Force
* Author URI: https://www.brainstormforce.com/
* License:
*/


//Block direct access to plugin files
defined( 'ABSPATH' ) or die();

if(!class_exists("FreshDeskAPI")){
	class FreshDeskAPI{
	
		//Class Variables
		private $freshdeskUrl;
		private $opt;
		private $options;
		private $display_option;
	
		/*
		 * Function Name: __construct
		 * Function Description: Constructor
		 */
		
		function __construct() {
		
			add_action( 'init', array( $this, 'init' ) );
			add_action( 'plugins_loaded', array( $this, 'fd_load_textdomain' ) );
			add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
			add_shortcode( "fd_fetch_tickets", array($this, "fetch_tickets"));
			
			include_once( 'admin-settings.php' );
			
			$this->options = get_option( 'fd_url' );
			$this->opt = get_option( 'fd_apikey' );
			$this->display_option = get_option( 'fd_display' );
			
			if( isset( $this->opt['freshdesk_url'] ) ) {
				$this->freshdeskUrl = rtrim( $this->opt['freshdesk_url'], '/' ) . '/';
			} else {
				$this->freshdeskUrl = '';
			}
			if ( !preg_match( "/\b(?:(?:https?|ftp):\/\/|www\.)[-a-z0-9+&@#\/%?=~_|!:,.;]*[-a-z0-9+&@#\/%=~_|]/i", $this->freshdeskUrl ) ) {
				$this->freshdeskUrl = '';
			} else {
				$this->freshdeskUrl;
			}
		}
		
		
		/**
		 * Load plugin textdomain.
		 *
		 * @since 1.0.0
		 */
		function fd_load_textdomain() {
			load_plugin_textdomain( 'freshdesk-api', false, plugin_basename( dirname( __FILE__ ) ) . '/lang' ); 
		}
		
		
		/*
		 * Function Name: enqueue_scripts
		 * Function Description: Adds scripts to wp pages
		 */
		
		function enqueue_scripts() {
		
			if( isset( $this->display_option['fd_display_use_css'] ) && $this->display_option['fd_display_use_css'] != '' ){
				if( $this->display_option['fd_display_use_css'] == 'on' ){
					wp_register_style( 'fd-table', plugins_url('css/fd-table.css', __FILE__) );
					wp_enqueue_style( 'fd-table' );
				}
			}
			wp_register_style( 'fd-style', plugins_url('css/fd-style.css', __FILE__) );
			wp_enqueue_style( 'fd-style' );
			wp_register_script( 'fd-script-frontend', plugins_url('js/fd-script-frontend.js', __FILE__), array('jquery'), '1.1', true );
			wp_enqueue_script( 'fd-script-frontend' );
		}
		
		
		/*
		 * Function Name: process_filter_tickets
		 * Function Description: AJAX call function for ticket search/filter
		 */
		
		function process_filter_tickets(){
			global $current_user;
			$postArray = $_POST;
			$returnArray = array();
			
			$tickets = $this->get_tickets( $current_user->data->user_email, $current_user->roles );
			$tickets = json_decode( json_encode( $tickets ), true );
			$filteredTickets = $tickets;
			if( !isset( $tickets->require_login ) && $tickets != '' && !isset( $tickets->errors ) && !empty( $tickets ) ) {
				if( isset( $postArray['fd-filter_dropdown'] ) ) {
					$filteredTickets = ( $postArray['fd-filter_dropdown'] != 'all_tickets' ) ? $this->filter_tickets( $tickets, $postArray['fd-filter_dropdown'] ) : $tickets ;
				}
				if( isset( $postArray['search_txt'] ) && trim( $postArray['search_txt'] ) != '' ) {
					$filteredTickets = ( trim( $postArray['search_txt'] ) != '' ) ? $this->search_tickets( $filteredTickets, $postArray['search_txt'] ) : $tickets ;
				}
				if( empty( $filteredTickets ) ) {
					$returnArray = '<div id="fd-tickets_html"><p> ' . __( 'No tickets for "' . strtoupper( str_replace( '_', ' ', $postArray['fd-filter_dropdown'] ) ) . '" category.', 'freshdesk-api' ) . '</p><div class="fd-more-ticket">Could not find what you are searching for? Click <a href="' . $this->freshdeskUrl . 'support/tickets" target="_blank">here</a> to check all your old tickets.</div></div>';
				} else {
					$returnArray = $this->get_html( $filteredTickets );
				}
			} else {
				if( isset( $tickets->require_login ) ) {
					$msg = __( 'Invalid Credentials', 'freshdesk-api' );
				} else if( isset( $tickets->errors ) ) {
					if( isset( $tickets->errors->no_email ) ){
						$msg = ( isset( $this->display_option['invalid_user_msg'] ) && $this->display_option['invalid_user_msg'] != '' ) ? $this->display_option['invalid_user_msg'] : __( 'Invalid User', 'freshdesk-api' );
					} else {
						$msg = __( 'Invalid Freshdesk URL', 'freshdesk-api' );
					}
				} else if( empty( $tickets ) ){
					$msg = ( isset( $this->display_option['no_tickets_msg'] ) && $this->display_option['no_tickets_msg'] != '' ) ? $this->display_option['no_tickets_msg'] : __( 'No tickets', 'freshdesk-api' );
				}else {
					$msg = __( 'Error!', 'freshdesk-api' );
				}
				$returnArray = '<div id="fd-tickets_html"><p>' . $msg . '</p></div>';
			}
			
			echo $returnArray; die;
		}
		
		
		
		/*
		 * Function Name: init
		 * Function Description: Initialization
		 */
		public function init(){
			add_action( 'wp_ajax_filter_tickets', array( &$this, 'process_filter_tickets' ) );
			add_action( 'wp_ajax_nopriv_filter_tickets', array( &$this, 'process_filter_tickets' ) );
			
			if ( is_user_logged_in() ) {
				
				// This is a login request.
				if ( isset( $_REQUEST['action'] ) && $_REQUEST['action'] == 'fd-remote-login' ) {
					// Don't waste time if remote auth is turned off.
					if ( !isset( $this->options['freshdesk_enable'] ) && $this->options['freshdesk_enable'] != 'on' && !isset( $this->options['freshdesk_sharedkey'] ) && $this->options['freshdesk_sharedkey'] != '' ) {
						__( 'Remote authentication is not configured yet.', 'freshdesk-api' );
						die();
					}
					// Filter freshdesk_return_to
					$return_to = apply_filters( 'freshdesk_return_to', $_REQUEST['host_url'] ) ;
	
					global $current_user;
					wp_get_current_user();
	
					// If the current user is logged in
					if ( 0 != $current_user->ID ) {
	
						// Pick the most appropriate name for the current user.
						if ( $current_user->user_firstname != '' && $current_user->user_lastname != '' )
							$name = $current_user->user_firstname . ' ' . $current_user->user_lastname;
						else
							$name = $current_user->display_name;
	
						// Gather more info from the user, incl. external ID
						$email = $current_user->user_email;
	
						// The token is the remote "Shared Secret" under Admin - Security - Enable Single Sign On
						$token = $this->options['freshdesk_sharedkey'];
	
						// Generate the hash as per http://www.freshdesk.com/api/remote-authentication
						$hash = md5( $name . $email . $token );
	
						// Create the SSO redirect URL and fire the redirect.
						$sso_url = trailingslashit( $this->freshdeskUrl ) . 'login/sso/?action=fd-remote-login&return_to=' . urlencode( 'https://' . $return_to . '/' ) . '&name=' . urlencode( $name ) . '&email=' . urlencode( $email ) . '&hash=' . urlencode( $hash );
	
						//Hook before redirecting logged in user.
						do_action( 'freshdesk_logged_in_redirect_before' );
	
						wp_redirect( $sso_url );
	
						// No further output.
						die();
					} else {
	
						//Hook before redirecting user to login form
						do_action( 'freshdesk_logged_in_redirect_before' );
	
						// If the current user is not logged in we ask him to visit the login form
						// first, authenticate and specify the current URL again as the return
						// to address. Hopefully WordPress will understand this.
						wp_redirect( wp_login_url( wp_login_url() . '?action=fd-remote-login&&return_to=' . urlencode( $return_to ) ) );
						die();
					}
				}
	
				// Is this a logout request? Errors from Freshdesk are handled here too.
				if ( isset( $_REQUEST['action'] ) && $_REQUEST['action'] == 'fd-remote-logout' ) {
	
	
					// Error processing and info messages are done here.
					$kind = isset( $_REQUEST['kind'] ) ? $_REQUEST['kind'] : 'info';
					$message = isset( $_REQUEST['message'] ) ? $_REQUEST['message'] : 'nothing';
	
					// Depending on the message kind
					if ( $kind == 'info' ) {
	
						// When the kind is an info, it probably means that the logout
						// was successful, thus, logout of WordPress too.
						wp_redirect( htmlspecialchars_decode( wp_logout_url() ) );
						die();
	
					} elseif ( $kind == 'error' ) {
						// If there was an error...
					?>
						<p><?php __( 'Remote authentication failed: ', 'freshdesk-api' ); ?><?php echo $message; ?>.</p>
						<ul>
							<li><a href="<?php echo $this->freshdeskUrl; ?>"><?php __( 'Try again', 'freshdesk-api' ); ?></a></li>
							<li><a href="<?php echo wp_logout_url(); ?>"><?php printf( __( 'Log out of %s', 'freshdesk-api' ), get_bloginfo( 'name' ) ); ?></a></li>
							<li><a href="<?php echo admin_url(); ?>"><?php printf( __( 'Return to %s dashboard', 'freshdesk-api' ), get_bloginfo( 'name' ) ); ?></a></li>
						</ul>
					<?php
					}
	
					// No further output.
					die();
				}
			}
		}
		
		
		
		/*
		 * Function Name: fetch_tickets
		 * Function Description: Fetched all tickets from Freshdesk for current logged in user.
		 */
		
		public function fetch_tickets( $atts ){
			$result = '';
			if ( is_user_logged_in() ) {
					global $current_user;
				if( ( isset( $this->opt['freshdesk_apikey'] ) && $this->opt['freshdesk_apikey'] != '' ) || !isset( $this->opt['use_apikey'] ) ) {
					if( isset( $atts['filter'] ) && trim( $atts['filter'] ) != '' ) {
				
						switch( trim( ucwords( strtolower( $atts['filter'] ) ) ) ) {
							case 'Open':
								$_POST["fd-filter_dropdown"] = 'Open';
								break;
							case 'Closed':
								$_POST["fd-filter_dropdown"] = 'Closed';
								break;
							case 'Resolved':
								$_POST["fd-filter_dropdown"] = 'Resolved';
								break;
							case 'Waiting On Third Party':
								$_POST["fd-filter_dropdown"] = 'Waiting on Third Party';
								break;
							case 'Waiting On Customer':
								$_POST["fd-filter_dropdown"] = 'Waiting on Customer';
								break;
							case 'Pending':
								$_POST["fd-filter_dropdown"] = 'Pending';
								break;
							default:
								break;
						}
					}
									
					$tickets = $this->get_tickets( $current_user->data->user_email, $current_user->roles, $_POST );
					$ajaxTickets = $this->get_tickets( $current_user->data->user_email, $current_user->roles );
					$result .= '
					<div class="">
						<form method="post" action="" id="fd-filter_form" name="fd-filter_form">
							<div class="fd-filter-dropdown">
								<select id="fd-filter_dropdown" name="fd-filter_dropdown">
									<option value="all_tickets" ';
						if( isset( $_POST["fd-filter_dropdown"] ) ) {
							$result .= ( $_POST["fd-filter_dropdown"] == "all_tickets" ) ? 'selected="selected"' : '';
						}
						$result .= '>' . __( 'All Tickets', 'freshdesk-api' ) . '</option>
									<option value="Open" ';
						if( isset( $_POST["fd-filter_dropdown"] ) ) {
							$result .= ( $_POST["fd-filter_dropdown"] == "Open" ) ? 'selected="selected"' : '';
						}
						$result .= '>' . __( 'Open', 'freshdesk-api' ) . '</option>
									<option value="Pending" ';
						if( isset( $_POST["fd-filter_dropdown"] ) ) {
							$result .= ( $_POST["fd-filter_dropdown"] == "Pending" ) ? 'selected="selected"' : '';
						}
						$result .= '>' . __( 'Pending', 'freshdesk-api' ) . '</option>
									<option value="Resolved" ';
						if( isset( $_POST["fd-filter_dropdown"] ) ) {
							$result .= ( $_POST["fd-filter_dropdown"] == "Resolved" ) ? 'selected="selected"' : '';
						}
						$result .= '>' . __( 'Resolved', 'freshdesk-api' ) . '</option>
									<option value="Closed" ';
						if( isset( $_POST["fd-filter_dropdown"] ) ) {
							$result .= ( $_POST["fd-filter_dropdown"] == "Closed" ) ? 'selected="selected"' : '';
						}
						$result .= '>' . __( 'Closed', 'freshdesk-api' ) . '</option>
									<option value="Waiting on Customer" ';
						if( isset( $_POST["fd-filter_dropdown"] ) ) {
							$result .= ( $_POST["fd-filter_dropdown"] == "Waiting on Customer" ) ? 'selected="selected"' : '';
						}
						$result .= '>' . __( 'Waiting on Customer', 'freshdesk-api' ) . '</option>
									<option value="Waiting on Third Party" ';
						if( isset( $_POST["fd-filter_dropdown"] ) ) {
							$result .= ( $_POST["fd-filter_dropdown"] == "Waiting on Third Party" ) ? 'selected="selected"' : '';
						}
						$txt = ( isset( $_POST['search_txt'] ) ) ? $_POST['search_txt'] : '';
						$result .= '>' . __( 'Waiting on Third Party', 'freshdesk-api' ) . '</option>
								</select>
							</div>
							<div class="fd-search-box">
								<input type="text" value="' . $txt . '" id="search_txt" name="search_txt" placeholder="' . __( 'Search...', 'freshdesk-api' ) . '"/>
							</div>
							<div class="clear"></div>';
					$is_call_ajax_flag = ( !isset( $tickets->require_login ) && $tickets != '' && !isset( $tickets->errors ) ) ? '1' : '0';
					$result .= '
							<input type="hidden" id="action" name="action" value="filter_tickets"/>
							<input type="hidden" id="call_ajax_flag" name="call_ajax_flag" value="' . $is_call_ajax_flag . '"/>
						</form>
					</div>';
					
					$result .= '<div class="fd-cntlst-loader" style="visibility: visible;overflow: hidden; display:none;" id="fd-dark-bg">
									<div class="fd-smile-loader">
										<div class="fd-smile-loading-bar"></div>
										<div class="fd-smile-loading-bar"></div>
										<div class="fd-smile-loading-bar"></div>
										<div class="fd-smile-loading-bar"></div>
									</div>
								</div>';
							
					$result .= 
							'<script type="text/javascript">
								ajaxurl = "' . admin_url('admin-ajax.php') . '";
							</script>
							';
							

					if( !isset( $tickets->require_login ) && $tickets != '' && !isset( $tickets->errors ) && !empty( $tickets ) ) {
						$result .= $this->get_html( $tickets );
					} else {
						if( isset( $tickets->require_login ) ) {
							$msg = __( 'Invalid Credentials', 'freshdesk-api' );
						} else if( isset( $tickets->errors ) ) {
							if( isset( $tickets->errors->no_email ) ){
								$msg = ( isset( $this->display_option['invalid_user_msg'] ) && $this->display_option['invalid_user_msg'] != '' ) ? $this->display_option['invalid_user_msg'] : __( 'Invalid User', 'freshdesk-api' );
							} else {
								$msg = __( 'Invalid Freshdesk URL' , 'freshdesk-api');
							}
						} else if( empty( $tickets ) ) {
							$msg = ( isset( $this->display_option['no_tickets_msg'] ) && $this->display_option['no_tickets_msg'] != '' ) ? $this->display_option['no_tickets_msg'] : __( 'No tickets', 'freshdesk-api' );
						}else {
							$msg = __( 'Error!', 'freshdesk-api' );
						}
						$result = '<div id="fd-tickets_html"><p>' . $msg . '</p></div>';
					}
					return $result;
				} else {
					return '<p>Please configure settings for <strong>Freshdesk API</strong> from <a href="' . admin_url( '/options-general.php?page=wp-freshdesk' ) . '" target="_blank">admin panel</a></p>';
				}
			} else{
				return '<p><a href="' . wp_login_url() . '" title="Login">Login</a> to view your tickets!</p>';
			}
		}
		
		
		/*
		 * Function Name: get_tickets
		 * Function Description: API call to Freshdesk to get all tickets of the user(email)
		 */
		
		public function get_tickets( $uemail = '', $roles = array(), $post_array = array() ){
			if( !empty( $uemail ) ){
			
				$filterName = 'all_tickets';
				if( isset( $this->opt['use_apikey'] ) ){
					$apikey = ( $this->opt['freshdesk_apikey'] != '' ) ? $this->opt['freshdesk_apikey'] : '';
					$password = "";
				} else {
					$apikey = ( $this->opt['api_username'] != '' ) ? $this->opt['api_username'] : '';
					$password = ( $this->opt['api_pwd'] != '' ) ? $this->opt['api_pwd'] : '';
				}
				
				
				$filter = ( !in_array( 'administrator', $roles ) ) ? '&email=' . $uemail : '';
				$url = $this->freshdeskUrl . 'helpdesk/tickets.json?filter_name=' . $filterName . $filter;
				
				$ch = curl_init ($url);
				curl_setopt($ch, CURLOPT_USERPWD, "$apikey:$password");
				curl_setopt($ch, CURLOPT_HEADER, false);
				curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
				curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
				curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
				curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
				$server_output = curl_exec ($ch);
				curl_close ($ch);
				
				$tickets = json_decode( $server_output );
				
				if( isset( $tickets ) ) {
					if( isset( $post_array['fd-filter_dropdown'] ) ) {
						$tickets = json_decode( json_encode( $tickets ), true );
						$tickets = ( $post_array['fd-filter_dropdown'] != 'all_tickets' ) ? $this->filter_tickets( $tickets, $post_array['fd-filter_dropdown'] ) : $tickets ;
					}
					if( isset( $post_array['search_txt'] ) ) {
						$tickets = ( trim( $post_array['search_txt'] ) != '' ) ? $this->search_tickets( $tickets, $post_array['search_txt'] ) : $tickets ;
					}
				} else {
					$tickets = false;
				}
				
				return $tickets;
			} else{
				return false;
			}			
		}
		
		
		/*
		 * Function Name: get_html
		 * Function Description: Returns HTML string of the tickets
		 */
		
		public function get_html( $tickets = '' ){
			$html = '';
			$tickets = json_decode( json_encode( $tickets ), FALSE );
			
			$html .= '<div id="fd-tickets_html" class="fd-lic-table fd-table-responsive">
						<div class="fd-total-tickets"><p>Total Tickets: ' . count( $tickets ) . '</p></div>
						<table class="fd-lic-table-list">
							<tr>
								<th>' . strtoupper(__( 'ID', 'freshdesk-api' ) ). '</th>
								<th>' .strtoupper( __( 'Subject', 'freshdesk-api' ) ) . '</th>
								<th>' . strtoupper( __( 'Status', 'freshdesk-api' ) ) . '</th>';
			if( isset( $this->display_option ) ) {
				if( $this->display_option != '' ) {
					foreach( $this->display_option as $key=>$value ){
						switch( $key ){
							case 'fd_display_description':
							case 'fd_display_priority_name':
							case 'fd_display_updated_at':
								$html .= '<th>' . strtoupper( __( str_replace( "_", " ", str_replace( "fd_display_", "", $key ) ), 'freshdesk-api' ) ) . '</th>';
								break;
							case 'no_tickets_msg':
							default:
								break;
						}
					}
				}
			}
			
			$html .= '</tr>';
			
			foreach( $tickets as $d ) {
				$html .= '
							<tr>
								<td><a href="' . $this->freshdeskUrl . 'helpdesk/tickets/' . $d->display_id . '" target="_blank">#' . $d->display_id . '</a></td>
								<td><a href="' . $this->freshdeskUrl . 'helpdesk/tickets/' . $d->display_id . '" target="_blank">' . $d->subject . '</a></td>
								<td>' . $d->status_name . '</td>';
				if( isset( $this->display_option ) ) {
					if( $this->display_option != '' ) {
						foreach( $this->display_option as $key=>$value ){
							$data = '';
							switch( $key ){
								case 'fd_display_description':
									$data = ( strlen( $d->description ) > 50 ) ? substr( $d->description, 0, 50 ) . '...' : $d->description ;
									break;
								case 'fd_display_updated_at':
									$diff = ( strtotime( date_i18n('Y-m-d H:i:s') ) - strtotime( date_i18n( 'Y-m-d H:i:s', false, 'gmt' ) ) );
									$data = date_i18n( 'j M, Y, g:i A', strtotime( $d->updated_at ) + $diff );
									break;
								case 'no_tickets_msg':
									break;
								case 'fd_display_priority_name':
									$index = str_replace( "fd_display_", "", $key );
									$data = '<a href="' . $this->freshdeskUrl . 'helpdesk/tickets/' . $d->display_id . '" target="_blank">' . $d->$index . '</a>';
									break;
								default:
									break;
							}
							if( $data != '' ) {
								$html .= '<td>' . $data . '</td>';
							}
						}
					}
				}
				$html .= 
							'</tr>';
			}
			
			$html .= '</table></div>';
			return $html;
		}
		
		
		/*
		 * Function Name: filter_tickets
		 * Function Description: Filters the tickets according to ticket_status
		 */
		
		public function filter_tickets( $tickets = '', $status = '' ){
			$filtered_tickets = array();
			if( $status != 'all_tickets' ) {
				foreach( $tickets as $t ){
					if( $t['status_name'] == $status ) {
						$filtered_tickets[] = $t;
					}
				}
				return $filtered_tickets;
			} else {
				return $tickets;
			}
		}
		
		
		/*
		 * Function Name: search_tickets
		 * Function Description: Searches the tickets according to input text
		 */
		
		public function search_tickets( $tickets, $txt = '' ){
			$filtered_tickets = array();
			foreach( $tickets as $t ){
				if(  stristr( $t['subject'], trim( $txt ) ) || stristr( $t['description'], trim( $txt ) ) || stristr( $t['id'], trim( $txt ) ) ) {
					$filtered_tickets[] = $t;
				}
			}
			return $filtered_tickets;
		}
		
		
	}
} //end of class


/* Register the activation function and redirect to Setting page. */
register_activation_hook(__FILE__, 'fd_plugin_activate');
add_action('admin_init', 'fd_plugin_redirect' );

/*
 * Function Name: fd_plugin_redirect
 * Function Description:
 */
 
function fd_plugin_redirect() {
	if ( get_option( 'fd_do_activation_redirect', false ) ) {
		delete_option( 'fd_do_activation_redirect' );
		if( !isset( $_GET['activate-multi'] ) ) {
			wp_redirect( 'options-general.php?page=wp-freshdesk' );
		}
	}
}

/*
 * Function Name: fd_plugin_activate
 * Function Description:
 */

function fd_plugin_activate() {
	add_option('fd_do_activation_redirect', true);
	if( !isset( $_GET['activate-multi'] ) ) {
		wp_redirect( 'options-general.php?page=wp-freshdesk' );
	}
}

new FreshDeskAPI();
?>