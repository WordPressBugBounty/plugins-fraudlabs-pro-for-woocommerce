<?php
/**
 * Plugin Name: FraudLabs Pro for WooCommerce
 * Plugin URI: https://www.fraudlabspro.com
 * Description: This plugin is an add-on for WooCommerce plugin that help you to screen your order transaction, such as credit card transaction, for online fraud.
 * Author: FraudLabs Pro
 * Author URI: https://www.fraudlabspro.com/
 * Version: 2.23.2
 * Requires Plugins: woocommerce
 * Text Domain: fraudlabs-pro-for-woocommerce
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WC_FLP_DIR' ) ) {
	define( 'WC_FLP_DIR', __FILE__ );
}

if ( ! function_exists( 'wc_fraudlabspro' ) ) :

add_action( 'plugins_loaded', 'wc_fraudlabspro' );

function wc_fraudlabspro() {
	if ( ! function_exists( 'WC' ) ) {
		class FraudLabsProWc {
			function __construct() {
				add_action( 'admin_init', array( $this, 'check_wc_install' ) );
				if ( ! function_exists( 'WC' ) ) {
					return;
				}
			}

			static function activation_check() {
				if ( ! function_exists( 'WC' ) ) {
					deactivate_plugins( plugin_basename( __FILE__ ) );
				}
			}

			function check_wc_install() {
				if ( ! function_exists( 'WC' ) ) {
					if ( is_plugin_active( plugin_basename( __FILE__ ) ) ) {
						deactivate_plugins( plugin_basename( __FILE__ ) );
						add_action( 'admin_notices', array( $this, 'admin_notice_flp' ) );
						if ( isset( $_GET['activate'] ) ) {
							unset( $_GET['activate'] );
						}
					}
				}
			}

			function admin_notice_flp() {
				$current_screen = get_current_screen();
				if ( 'plugins' == $current_screen->parent_base ) {
					echo '
						<div id="fraudlabspro-notice" class="error notice">
							<p>
								' . __( 'Please install and activate WooCommerce plugin before activating FraudLabs Pro for WooCommerce plugin.', 'woocommerce-fraudlabs-pro' ) . '
							</p>
						</div>';
				}
			}

			function wc_fraudlabspro() {
				require_once plugin_dir_path( __FILE__ ) . 'includes' . DIRECTORY_SEPARATOR . 'class.wc-fraudlabspro.php';
				WC_FraudLabs_Pro::get_instance();
			}
		}

		global $flpwc;
		$flpwc = new FraudLabsProWc();

		register_activation_hook( __FILE__, array( 'FraudLabsProWc', 'activation_check' ) );
	} else {
		require_once plugin_dir_path( __FILE__ ) . 'includes' . DIRECTORY_SEPARATOR . 'class.wc-fraudlabspro.php';
		WC_FraudLabs_Pro::get_instance();
	}

}

endif;

