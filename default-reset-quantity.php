<?php
/*
	Plugin Name: Default Reset Quantity
	Description: Sets products with 'default_reset_quantity' attribute to a specified quantity, sets all other products to quantity 0, and ignores products with 'do_not_reset_quantity' attribute.
	Version: 1.0.0
	Author: <a href="https://github.com/lkarinja">Leejae Karinja</a>
	License: GPL3
	License URI: https://www.gnu.org/licenses/gpl-3.0.html
*/

/*
	Default Reset Quantity
	Copyright (C) 2017 Leejae Karinja

	This program is free software: you can redistribute it and/or modify
	it under the terms of the GNU General Public License as published by
	the Free Software Foundation, either version 3 of the License, or
	(at your option) any later version.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.

	You should have received a copy of the GNU General Public License
	along with this program.  If not, see <http://www.gnu.org/licenses/>.
*/

// Prevents execution outside of core WordPress
if(!defined('ABSPATH'))
{
	header('Status: 403 Forbidden');
	header('HTTP/1.1 403 Forbidden');
	exit;
}

if(!class_exists('Default_Reset_Quantity'))
{
	class Default_Reset_Quantity
	{
		// This is for the options found in the Admin Menu
		protected $options;
		protected $saved_options;

		/**
		 * Plugin constructor
		 */
		public function __construct()
		{
			// Used for debugging, allows us to 'echo' for JS 'alert()' and such
			ob_start();

			$this->textdomain = 'default-reset-quantity';

			// On every page load
			add_action('init', array($this, 'init_check'), 20);

			// Plugin Admin Menu Options
			$this->options = array(
				'auto_reset_quantities' => 'yes',
			);
			$this->saved_options = array();
		}

		/**
		 * Checks if the plugin should run and reset quantities
		 */
		public function init_check()
		{
			// Needed to check if plugin is active
			include_once(ABSPATH . 'wp-admin/includes/plugin.php');

			// Gets the option for the store override found in the admin menu
			$auto_reset_quantities = get_option('auto_reset_quantities') ? get_option('auto_reset_quantities') : 'yes';

			// If quantities should be reset automatically each store close
			if($auto_reset_quantities == 'yes')
			{
				// If 'store-closing-override' plugin is active, use that to determine when quantities should be reset
				if(is_plugin_active('store-closing-override/store-closing-override.php'))
				{
					// Try to load the value of the option 'drq_should_run'
					$drq_should_run = get_option('drq_should_run');
					// If option 'drq_should_run' did not exist yet
					if($drq_should_run === false)
					{
						// Create the option 'drq_should_run' and set it to 'no'
						update_option('drq_should_run', 'no');
						$drq_should_run = 'no';
					}

					// Try to load the value of the option 'drq_completed'
					$drq_completed = get_option('drq_completed');
					// If option 'drq_completed' did not exist yet
					if($drq_completed === false)
					{
						// Create the option 'drq_completed' and set it to 'no'
						update_option('drq_completed', 'no');
						$drq_completed = 'no';
					}

					// If 'drq_should_run' is set to 'no'
					if($drq_should_run === 'no')
					{
						// Try to load the value of the option 'store_status'
						$store_status = get_option('store_status');
						// If option 'store_status' did not exist (Meaning there is an error with the plugin 'store-closing-override')
						if($store_status === false)
						{
							// Set 'drq_completed' to 'yes' to prevent accidental resets
							update_option('drq_completed', 'yes');
							$drq_completed = 'yes';
						}

						// If 'store_status' is set to 'closed' (Store is closed) and 'drq_completed' is set to 'no' (We haven't ran the reset process yet for this week)
						if($store_status === 'closed' && $drq_completed === 'no')
						{
							// Set the option 'drq_should_run' to 'yes' (We want to reset quantities after store is first closed)
							update_option('drq_should_run', 'yes');
						}
						// If 'store_status' is set to 'open' (Store is open)
						elseif($store_status === 'open')
						{
							// Set the option 'drq_completed' to 'no' (We have not ran the reset process for this week)
							update_option('drq_completed', 'no');
						}
					}

					// If 'drq_should_run' is set to 'yes' and 'drq_completed' is set to 'no'
					if($drq_should_run === 'yes' && $drq_completed === 'no')
					{
						// Reset quantities of items to their default values
						$this->reset_quantities();

						// Set the option 'drq_should_run' to 'no' (We finished running the reset process for this week)
						update_option('drq_should_run', 'no');
						// Set the option 'drq_completed' to 'yes' (We finished running the reset process for this week)
						update_option('drq_completed', 'yes');
					}
				}
			}
			// Add options in the admin menu under WooCommerce
			add_action('admin_menu', array($this, 'add_menu_options'));
		}

		/**
		 * Resets quantities of products
		 *
		 * Default quantity is 0, so products without the 'default_reset_quantity' will be reset to 0 quantity
		 * If 'default_reset_quantity' custom attribute is found on the product, reset the quantity to the attribute value
		 * If 'do_not_reset_quantity' custom attribute is found, don't reset the quantity
		 */
		protected function reset_quantities()
		{
			// Query for the database, gets all products that should have their quantity reset (Does not get products with the 'do_not_reset_quantity' attribute)
			$product_query = array(
				'post_type' => 'product', // Type of post is Product
				'posts_per_page' => -1, // -1 Gets all matching posts rather than just a few
				'tax_query' => array( // 'tax_query' must be used instead of 'meta_query' now
					array(
						'taxonomy' => 'pa_do_not_reset_quantity', // Product attribute slug prefixed with 'pa_'
						'field' => 'name', // Gets it by the 'pa_' naming scheme of attributes
						'operator' => 'NOT EXISTS', // As long as the product does not have the 'do_not_reset_quantity' attribute
					),
				),
			);
			// Get all products returned from the query
			$product_query_result = get_posts($product_query);
			// For all products
			foreach($product_query_result as $product)
			{
				// Get the product ID
				$product_id = $product->ID;
				// Get the value of 'default_reset_quantity'
				$drq_value = array_shift(wc_get_product_terms($product_id, 'pa_default_reset_quantity', array('fields' => 'names')));
				// If the product had a set 'default_reset_quantity' attribute
				if($drq_value)
				{
					// Set the quantity of the product to the 'default_reset_quantity' value
					update_post_meta($product_id, '_stock', $drq_value);
				}
				else
				{
					// Set the quantity of the product to 0
					update_post_meta($product_id, '_stock', 0);
				}
			}
		}

		/**
		 * Set all products to quantity of 100
		 *
		 * Debug/Test function, should only be used for debugging and testing
		 */
		private function set_quantities()
		{
			$product_query = array(
				'post_type' => 'product',
				'posts_per_page' => -1,
			);
			$product_query_result = get_posts($product_query);
			foreach($product_query_result as $product)
			{
				update_post_meta($product->ID, '_stock', 100);
			}
		}

		/**
		 * Adds an options page under WooCommerce -> Default Reset Quantity
		 *
		 * Parts of this function are referenced from Terry Tsang (http://shop.terrytsang.com) Extra Fee Option Plugin (http://terrytsang.com/shop/shop/woocommerce-extra-fee-option/)
		 * Licensed under GPL2
		 */
		public function add_menu_options()
		{
			$woocommerce_page = 'woocommerce';
			$settings_page = add_submenu_page(
				$woocommerce_page,
				__('Default Reset Quantity', $this->textdomain),
				__('Default Reset Quantity', $this->textdomain),
				'manage_options',
				'default-reset-quantity',
				array(
					$this,
					'drq_options'
				)
			);
		}

		/**
		 * Page builder for the Default Reset Quantity options page
		 *
		 * Parts of this function are referenced from Terry Tsang (http://shop.terrytsang.com) Extra Fee Option Plugin (http://terrytsang.com/shop/shop/woocommerce-extra-fee-option/)
		 * Licensed under GPL2
		 */
		public function drq_options()
		{
			// If a request for manual reset of quantities was made
			if(isset($_POST['reset']))
			{
				// Reset quantities of items to their default values
				$this->reset_quantities();
				// Display a message
				echo '<div><p>' . __('Reset quantities of all products', $this->textdomain) . '</p></div>';
			}

			// Debug/Test Only
			if(isset($_POST['set']))
			{
				$this->set_quantities();
				echo '<div><p>' . __('Set quantities to 100 (Debug/Test Only)', $this->textdomain) . '</p></div>';
			}

			// If options should be saved
			if(isset($_POST['save']))
			{
				check_admin_referer($this->textdomain);

				// Try to load saved Store Closing Override options
				$this->saved_options['auto_reset_quantities'] = isset($_POST['auto_reset_quantities']) ? $_POST['auto_reset_quantities'] : 'yes';

				// For each options in the plugin
				foreach($this->options as $field => $value)
				{
					// If there was an update to an option
					if(get_option($field) != $this->saved_options[$field])
					{
						// Save the new value of that option
						update_option($field, $this->saved_options[$field]);
					}
				}

				// Display a save message
				echo '<div><p>' . __('Options saved.', $this->textdomain) . '</p></div>';
			}

			// Store Closing Override options
			$auto_reset_quantities = get_option('auto_reset_quantities') ? get_option('auto_reset_quantities') : 'yes';

			$actionurl = $_SERVER['REQUEST_URI'];
			$nonce = wp_create_nonce($this->textdomain);

			// HTML/inline PHP for the options page
			?>
			<h3><?php _e( 'Default Reset Quantity', $this->textdomain); ?></h3>
			<form action="<?php echo $actionurl; ?>" method="post">
				<?php _e('Reset Quantities Automatically on All Store Closes:', $this->textdomain); ?>
				<select name="auto_reset_quantities">
					<option value="yes" <?php if($auto_reset_quantities == 'yes') { echo 'selected="selected"'; } ?>><?php _e('Yes (Reset automatically every close)', $this->textdomain); ?></option>
					<option value="no" <?php if($auto_reset_quantities == 'no') { echo 'selected="selected"'; } ?>><?php _e('No (Resets will need to be performed manually)', $this->textdomain); ?></option>
				</select>

				<div style="height: 20px;"></div>

				<input class="button-primary" type="submit" name="SAVE" value="<?php _e('Save Options', $this->textdomain); ?>" id="submitbutton" />
				<input type="hidden" name="save" value="1" /> 
				<input type="hidden" id="_wpnonce" name="_wpnonce" value="<?php echo $nonce; ?>" />

				<div style="height: 20px;"></div>

				<input class="button-primary" type="submit" name="reset" value="<?php _e('Maunally Reset Quantities', $this->textdomain); ?>" id="submitbutton" />
				<input class="button-primary" type="submit" name="set" value="<?php _e('Set Product Quantities (Debug/Test Only)', $this->textdomain); ?>" id="setbutton" style="display:none" />
			</form>
			<?php
		}

	}
	// Create new instance of 'Default_Reset_Quantity' class
	$default_reset_quantity = new Default_Reset_Quantity();
}
