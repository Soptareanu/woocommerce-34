<?php

/**
 * Plugin Name: SamedayCourier Shipping
 * Plugin URI: http://sameday.ro
 * Description: SamedayCourier Shipping Method for WooCommerce
 * Version: 1.0.1
 * Author: SamedayCourier
 * Author URI: http://sameday.ro
 * License: GPL-3.0+
 * License URI: http://sameday.ro
 * Domain Path: /ro
 * Text Domain: sameday
 */

if (! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Check if WooCommerce plugin is enabled
 */
if (! in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' )), '')) {
	exit;
}

require_once (ABSPATH . 'wp-admin/includes/upgrade.php');
require_once (plugin_basename('lib/sameday-courier/src/Sameday/autoload.php'));
require_once (plugin_basename('classes/samedaycourier-api.php'));
require_once (plugin_basename('classes/samedaycourier-helper-class.php'));
require_once (plugin_basename('classes/samedaycourier-sameday-class.php'));
require_once (plugin_basename('sql/sameday_create_db.php'));
require_once (plugin_basename('sql/sameday_drop_db.php'));
require_once (plugin_basename('sql/sameday_query_db.php'));
require_once (plugin_basename('classes/samedaycourier-services.php'));
require_once (plugin_basename('classes/samedaycourier-service-instance.php'));
require_once (plugin_basename('classes/samedaycourier-pickuppoints.php'));
require_once (plugin_basename('classes/samedaycourier-pickuppoint-instance.php'));
require_once (plugin_basename('views/add-awb-form.php'));
require_once (plugin_basename('views/awb-history-table.php'));
require_once (plugin_basename('views/add-new-parcel-form.php'));

function samedaycourier_shipping_method() {
	if (! class_exists('SamedayCourier_Shipping_Method')) {
		class SamedayCourier_Shipping_Method extends WC_Shipping_Method
		{
			/**
			 * @var bool
			 */
			private $configValidation;

			public function __construct( $instance_id = 0 )
			{
				parent::__construct( $instance_id );

				$this->id = 'samedaycourier';
				$this->method_title = __('SamedayCourier', 'samedaycourier');
				$this->method_description = __('Custom Shipping Method for SamedayCourier', 'samedaycourier');

				$this->configValidation = false;

				$this->init();
			}

			/**
			 * @param array $package
			 */
			public function calculate_shipping( $package = array() )
			{
				if ($this->settings['enabled'] === 'no') {
					return;
				}

				$useEstimatedCost = $this->settings['estimated_cost'] === 'yes' ? 1 : 0;

				$availableServices = $this->getAvailableServices();
				if (!empty($availableServices)) {
					foreach ( $availableServices as $service ) {
						$price = $service->price;

						if ($service->price_free != null && WC()->cart->subtotal > $service->price_free) {
							$price = 0;
						}

						if ($useEstimatedCost) {
							$estimatedCost = $this->getEstimatedCost($package['destination'], $service->sameday_id);

							if (isset($estimatedCost)) {
								$price = $estimatedCost;
							}
						}

						$rate = array(
							'id' => $this->id . $service->sameday_id,
							'label' => $service->name,
							'cost' => $price,
							'meta_data' => array(
								'service_id' => $service->sameday_id
							)
						);

						$this->add_rate( $rate );
					}
				}
			}

			/**
			 * @param $address
			 * @param $serviceId
			 *
			 * @return float|null
			 */
			private function getEstimatedCost($address, $serviceId)
			{
				$is_testing = $this->settings['is_testing'] === 'yes' ? 1 : 0;
				$pickupPointId = getDefaultPickupPointId($is_testing);
				$weight = WC()->cart->get_cart_contents_weight() ?: 1;
				$state = \HelperClass::convertStateCodeToName($address['country'], $address['state']);

				$estimateCostRequest = new Sameday\Requests\SamedayPostAwbEstimationRequest(
					$pickupPointId,
					null,
					new Sameday\Objects\Types\PackageType(
						Sameday\Objects\Types\PackageType::PARCEL
					),
					[new \Sameday\Objects\ParcelDimensionsObject($weight)],
					$serviceId,
					new Sameday\Objects\Types\AwbPaymentType(
						Sameday\Objects\Types\AwbPaymentType::CLIENT
					),
					new Sameday\Objects\PostAwb\Request\AwbRecipientEntityObject(
						ucwords(strtolower($address['city'])) !== 'Bucuresti' ? $address['city'] : 'Sector 1',
						$state,
						ltrim($address['address']) . " " . $address['address_2'],
						null,
						null,
						null,
						null
					),
					0,
					WC()->cart->subtotal,
					null,
					array()
				);

				$sameday =  new Sameday\Sameday(
					Api::initClient(
						$this->settings['user'],
						$this->settings['password'],
						$is_testing
					)
				);

				try {
					$estimation = $sameday->postAwbEstimation($estimateCostRequest);
					$cost = $estimation->getCost();

					return $cost;
				} catch (\Sameday\Exceptions\SamedayBadRequestException $exception) {
					return null;
				}
			}

			private function getAvailableServices()
			{
				$is_testing = $this->settings['is_testing'] === 'yes' ? 1 : 0;
				$services = getAvailableServices($is_testing);

				$availableServices = array();
				foreach ($services as $service) {
					switch ($service->status) {
						case 1:
							$availableServices[] = $service;
							break;

						case 2:
							$working_days = unserialize($service->working_days);

							$today = \HelperClass::getDays()[date('w')]['text'];
							$date_from = mktime($working_days["order_date_{$today}_h_from"], $working_days["order_date_{$today}_m_from"], $working_days["order_date_{$today}_s_from"], date('m'), date('d'), date('Y'));
							$date_to = mktime($working_days["order_date_{$today}_h_until"], $working_days["order_date_{$today}_m_until"], $working_days["order_date_{$today}_s_until"], date('m'), date('d'), date('Y'));
							$time = time();

							if (!isset($working_days["order_date_{$today}_enabled"]) || $time < $date_from || $time > $date_to) {
								// Not working on this day, or out of available time period.
								break;
							}

							$availableServices[] = $service;
							break;
					}
				}

				return $availableServices;
			}

			private function init()
			{
				$this->form_fields = array(
					'enabled' => array(
						'title' => __( 'Enable', 'samedaycourier' ),
						'type' => 'checkbox',
						'description' => __( 'Enable this shipping.', 'samedaycourier' ),
						'default' => 'yes'
					),

					'title' => array(
						'title' => __( 'Title', 'samedaycourier' ),
						'type' => 'text',
						'description' => __( 'Title to be display on site', 'samedaycourier' ),
						'default' => __( 'SamedayCourier Shipping', 'samedaycourier' )
					),

					'user' => array(
						'title' => __( 'Username', 'samedaycourier' ),
						'type' => 'text',
						'description' => __( 'Username', 'samedaycourier' ),
						'default' => __( '', 'samedaycourier' )
					),

					'password' => array(
						'title' => __( 'Password', 'samedaycourier' ),
						'type' => 'password',
						'description' => __( 'Password', 'samedaycourier' ),
						'default' => __( '', 'samedaycourier' )
					),

					'is_testing' => array(
						'title' => __( 'Is testing', 'samedaycourier' ),
						'type' => 'checkbox',
						'description' => __( 'Disable this for production mode', 'samedaycourier' ),
						'default' => 'yes'
					),

					'estimated_cost' => array(
						'title' => __( 'Use estimated cost', 'samedaycourier' ),
						'type' => 'checkbox',
						'description' => __( 'This will show shipping cost calculated by Sameday Api for each service and show it on checkout page', 'samedaycourier' ),
						'default' => 'no'
					),
				);

				// Show on checkout:
				$this->enabled = isset( $this->settings['enabled'] ) ? $this->settings['enabled'] : 'yes';
				$this->title = isset( $this->settings['title'] ) ? $this->settings['title'] : __( 'SamedayCourier', 'samedaycourier' );

				$this->init_settings();

				add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ));
			}

			public function process_admin_options()
			{
				$post_data = $this->get_post_data();

				$sameday = Api::initClient(
					$post_data['woocommerce_samedaycourier_user'],
					$post_data['woocommerce_samedaycourier_password'],
					$post_data['woocommerce_samedaycourier_is_testing']
				);


				if (! $this->configValidation ) {
					$this->configValidation = true;

					if ( $sameday->login() ) {
						return parent::process_admin_options();
					} else {
						WC_Admin_Settings::add_error( __( 'Invalid username/password combination provided! Settings have not been changed!'));
					}
				}
			}

			public function admin_options()
			{
				$serviceUrl = admin_url() . 'edit.php?post_type=page&page=sameday_services';
				$pickupPointUrl = admin_url() . 'edit.php?post_type=page&page=sameday_pickup_points';
				$buttons = '<a href="'.$serviceUrl.'" class="button-primary"> Services </a> <a href="'.$pickupPointUrl.'" class="button-primary"> Pickup-point </a>';

				$adminOptins = parent::admin_options();

				echo $adminOptins . $buttons;
			}
		}
	}
}

// Shipping Method init.
add_action('woocommerce_shipping_init', 'samedaycourier_shipping_method');

function add_samedaycourier_shipping_method( $methods ) {
	$methods['samedaycourier'] = 'SamedayCourier_Shipping_Method';

	return $methods;
}

add_filter('woocommerce_shipping_methods', 'add_samedaycourier_shipping_method');

// Plugin settings.
add_action('plugins_loaded', function () {
	SamedayCourierServiceInstance::get_instance();
	SamedayCourierPickupPointInstance::get_instance();
});

add_action('admin_post_refresh_services', function () {
	$samedayClass = new Sameday();
	return $samedayClass->refreshServices();
});
add_action('admin_post_refresh_pickup_points', function () {
	$samedayClass = new Sameday();
	return $samedayClass->refreshPickupPoints();
});

add_action('admin_post_edit_service', function() {
	$samedayClass = new Sameday();
	return $samedayClass->editService();
});

add_action('admin_post_add_awb', function (){
	$postFields = HelperClass::sanitizeInputs($_POST);
	$orderDetails = wc_get_order($postFields['samedaycourier-order-id']);
	if (empty($orderDetails)) {
		return wp_redirect(admin_url() . '/index.php');
	}

	$data = array_merge($postFields, $orderDetails->get_data());
	$samedayClass = new Sameday();
	return $samedayClass->postAwb($data);
});

add_action('admin_post_remove-awb', function (){
	$awb = getAwbForOrderId(sanitize_key($_POST['order-id']));
	if (empty($awb)) {
		return wp_redirect(admin_url() . '/index.php');
	}

	$samedayClass = new Sameday();
	return $samedayClass->removeAwb($awb);
});

add_action('admin_post_show-awb-pdf', function (){
	$orderId = sanitize_key($_POST['order-id']);
	if (!isset($orderId)) {
		return wp_redirect(admin_url() . '/index.php');
	}

	$samedayClass = new Sameday();
	return $samedayClass->showAwbAsPdf($orderId);
});

add_action('admin_post_add-new-parcel', function() {
	$postFields = HelperClass::sanitizeInputs($_POST);
	if (empty($postFields)) {
		return wp_redirect(admin_url() . '/index.php');
	}

	$samedayClass = new Sameday();
	return $samedayClass->addNewParcel($postFields);
});

add_action('admin_head', function () {
	if (isset($_GET["add-awb"])){
		if ($_GET["add-awb"] === "error") {
			echo '
				<div class="notice notice-error is-dismissible">
					<p> <strong>' . __("Something did not work properly and the awb could not be generated successfully !") . '</strong> </p>
				<button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss this notice.</span></button></div>
			';
		}

		if ($_GET["add-awb"] === "success") {
			echo '
				<div class="notice notice-success is-dismissible">
					<p> <strong>' . __("Awb was successfully generated !") . '</strong> </p>
				<button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss this notice.</span></button></div>
			';
		}
	}

	if (isset($_GET["remove-awb"])) {
		if ($_GET["remove-awb"] === "error") {
			echo '
				<div class="notice notice-error is-dismissible">
					<p> <strong>' . __("Something did not work properly and the awb could not be removed successfully !") . '</strong> </p>
				<button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss this notice.</span></button></div>
			';
		}

		if ($_GET["remove-awb"] === "success") {
			echo '
				<div class="notice notice-success is-dismissible">
					<p> <strong>' . __("Awb was successfully removed !") . '</strong> </p>
				<button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss this notice.</span></button></div>
			';
		}
	}

	if (isset($_GET["show-awb"])) {
		if ($_GET["show-awb"] === "error") {
			echo '
				<div class="notice notice-error is-dismissible">
					<p> <strong>' . __("Awb invalid !") . '</strong> </p>
				<button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss this notice.</span></button></div>
			';
		}
	}

	if (isset($_GET["add-new-parcel"])) {
		if ($_GET["add-new-parcel"] === "error") {
			echo '
				<div class="notice notice-error is-dismissible">
					<p> <strong>' . __("Something did not work properly and the system could not be able to generate new parcel for this awb!") . '</strong> </p>
				<button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss this notice.</span></button></div>
			';
		}

		if ($_GET["add-new-parcel"] === "success") {
			echo '
				<div class="notice notice-success is-dismissible">
					<p> <strong>' . __("New parcel has been added to this awb!") . '</strong> </p>
				<button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss this notice.</span></button></div>
			';
		}
	}

	echo '<form id="addAwbForm" method="POST" action="'.admin_url('admin-post.php').'"><input type="hidden" name="action" value="add_awb"></form>
		  <form id="showAsPdf"  method="POST" action="'.admin_url('admin-post.php').'"><input type="hidden" name="action" value="show-awb-pdf"></form>
		  <form id="addNewParcelForm"  method="POST" action="'.admin_url('admin-post.php').'"><input type="hidden" name="action" value="add-new-parcel"></form>
          <form id="removeAwb"  method="POST" action="'.admin_url('admin-post.php').'"><input type="hidden" name="action" value="remove-awb"></form>';
});

add_action( 'woocommerce_admin_order_data_after_shipping_address', function ( $order ){
	add_thickbox();
	if ($_GET['action'] === 'edit') {

		$_generateAwb = '
			<p class="form-field form-field-wide wc-customer-user">
				<a href="#TB_inline?&width=670&height=470&inlineId=sameday-shipping-content-add-awb" class="button-primary button-samll thickbox"> ' . __('Generate awb') . ' </a>
			</p>';

		$_showAwb = '
			<p class="form-field form-field-wide wc-customer-user">
				<a href="#TB_inline?&width=670&height=470&inlineId=sameday-shipping-content-add-new-parcel" class="button-primary button-samll thickbox"> ' . __('Add new parcel') . ' </a>
				<a href="#TB_inline?&width=1024&height=400&inlineId=sameday-shipping-content-awb-history" class="button-primary button-samll thickbox"> ' . __('Awb history') . ' </a>
				<input type="hidden" form="showAsPdf" name="order-id" value="' . $order->id . '">
			    <button type="submit" form="showAsPdf" class="button-primary button-samll">'.  __('Show as pdf') . ' </button>
			</p>';

		$_removeAwb = '
			<p class="form-field form-field-wide wc-customer-user">
				<input type="hidden" form="removeAwb" name="order-id" value="' . $order->id . '">
			  	<button type="submit" form="removeAwb" class="button button-samll">'.  __('Remove Awb') . ' </button>
			</p>';

		$shipping_method_sameday = HelperClass::getShippingMethodSameday($order->id);

		if (empty($shipping_method_sameday)) {
			$buttons = '
				<div class="address">
					' . $_generateAwb  .'
				</div>';
		} else {
			$buttons = '
				<div class="address">
					' . $_showAwb . $_removeAwb  .'
				</div>';
		}

		if ($shipping_method_sameday === null) {
			$buttons = "";
		}

		$awbModal = addAwbForm($order);

		$sameday = new Sameday();
		$awbHistoryTable = $sameday->showAwbHistory($order->id);

		$addNewParcelForm = addNewParcelForm($order->id);

		$newParcelModal = '<div id="sameday-shipping-content-add-new-parcel" style="display: none;">
 							' . $addNewParcelForm . ' 
                           </div>';

		$historyModal = '<div id="sameday-shipping-content-awb-history" style="display: none;">
 							' . $awbHistoryTable . ' 
                         </div>';

		echo $buttons . $awbModal . $newParcelModal . $historyModal;
	}
});

register_activation_hook( __FILE__, 'samedaycourier_create_db' );
register_uninstall_hook( __FILE__, 'samedaycourier_drop_db');
