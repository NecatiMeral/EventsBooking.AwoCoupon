<?php
/**
 * @package            Joomla
 * @subpackage         Event Booking
 * @author             Necati Meral
 * @copyright          Copyright (C) 2010 - 2019 Ossolution Team
 * @license            GNU/GPL, see LICENSE.php
 */
// no direct access
defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Router\Route;
use Joomla\CMS\User\UserHelper;
use Joomla\Registry\Registry;

class EventBookingModelOverrideRegister extends EventbookingModelRegister
{
	/**
	 * Process individual registration
	 *
	 * @param $data
	 *
	 * @return int
	 * @throws Exception
	 */
	public function processIndividualRegistration(&$data)
	{
		$app     = Factory::getApplication();
		$db      = Factory::getDbo();
		$query   = $db->getQuery(true);
		$user    = Factory::getUser();
		$config  = EventbookingHelper::getConfig();
		$eventId = (int) $data['event_id'];
		$event   = EventbookingHelperDatabase::getEvent($eventId);

		EventbookingHelper::overrideGlobalConfig($config, $event);

		/* @var EventbookingTableRegistrant $row */
		$row                       = $this->getTable('Registrant');
		$data['transaction_id']    = strtoupper(UserHelper::genRandomPassword());
		$data['registration_code'] = EventbookingHelperRegistration::getRegistrationCode();
		$data['created_by']        = $user->id;

		if (!$user->id && $config->user_registration)
		{
			$userId          = EventbookingHelper::callOverridableHelperMethod('Registration', 'saveRegistration', [$data]);
			$data['user_id'] = $userId;
		}

		$row->ticket_qrcode = EventbookingHelperRegistration::getTicketCode();

		if (($event->event_capacity > 0) && ($event->event_capacity <= $event->total_registrants))
		{
			$waitingList        = true;
			$typeOfRegistration = 2;
		}
		else
		{
			$waitingList        = false;
			$typeOfRegistration = 1;
		}

		$paymentMethod = $data['payment_method'] ?? '';
		$rowFields     = EventbookingHelperRegistration::getFormFields($eventId, 0, null, null, $typeOfRegistration);

		// Filter data
		$data = $this->filterFormData($rowFields, $data);

		$form = new RADForm($rowFields);
		$form->bind($data);
		$form->handleFieldsDependOnPaymentMethod($paymentMethod);

		if ($waitingList)
		{
			$fees = EventbookingHelper::callOverridableHelperMethod(
				'Registration',
				'calculateIndividualRegistrationFees',
				[$event, $form, $data, $config, ''],
				'Helper'
			);
		}
		else
		{
			$fees = EventbookingHelper::callOverridableHelperMethod(
				'Registration',
				'calculateIndividualRegistrationFees',
				[$event, $form, $data, $config, $paymentMethod],
				'Helper'
			);
		}

		$paymentType = isset($data['payment_type']) ? (int) $data['payment_type'] : 0;

		if ($paymentType == 0)
		{
			$fees['deposit_amount'] = 0;
		}

		$data['total_amount']           = round($fees['total_amount'], 2);
		$data['discount_amount']        = round($fees['discount_amount'], 2);
		$data['late_fee']               = round($fees['late_fee'], 2);
		$data['tax_amount']             = round($fees['tax_amount'], 2);
		$data['amount']                 = round($fees['amount'], 2);
		$data['deposit_amount']         = $fees['deposit_amount'];
		$data['payment_processing_fee'] = $fees['payment_processing_fee'];
		$data['coupon_discount_amount'] = round($fees['coupon_discount_amount'], 2);
		$data['tax_rate']               = $fees['tax_rate'];
		$row->bind($data);
		$row->id = 0;

		if ($config->show_subscribe_newsletter_checkbox)
		{
			$row->subscribe_newsletter = empty($data['subscribe_to_newsletter']) ? 0 : 1;
		}
		else
		{
			$row->subscribe_newsletter = 1;
		}

		$row->agree_privacy_policy = 1;
		$row->group_id             = 0;
		$row->published            = 0;
		$row->register_date        = gmdate('Y-m-d H:i:s');
		$row->number_registrants   = 1;

		if (isset($data['user_id']))
		{
			$row->user_id = $data['user_id'];
		}
		else
		{
			$row->user_id = $user->id;
		}

		if ($row->deposit_amount > 0)
		{
			$row->payment_status = 0;
		}
		else
		{
			$row->payment_status = 1;
		}

		$row->user_ip = EventbookingHelper::getUserIp();

		//Save the active language
		if (Factory::getApplication()->getLanguageFilter())
		{
			$row->language = Factory::getLanguage()->getTag();
		}
		else
		{
			$row->language = '*';
		}

		$couponCode = $data['coupon_code'] ?? null;

		if ($couponCode && $fees['coupon_valid'])
		{
			$coupon = $fees['coupon'];

			// NM@02.12.2019: In case the awo-flag is set, try to migrate the coupon record for internal bookkeeping.
            if ($coupon->awo){
                $coupon = $this->migrateAndRecordAwoCouponUsage($coupon, $data, $row, $fees);
            }

			$row->coupon_id = $coupon->id;
        }

		if (!empty($fees['bundle_discount_ids']))
		{
			$query->clear()
				->update('#__eb_discounts')
				->set('used = used + 1')
				->where('id IN (' . implode(',', $fees['bundle_discount_ids']) . ')');
			$db->setQuery($query);
			$db->execute();
		}

		if ($waitingList)
		{
			$row->published      = 3;
			$row->payment_method = 'os_offline';
		}

		$params = new Registry($row->params);
		$params->set('fields_fee_amount', $fees['fields_fee_amount'] ?? []);
		$row->params = $params->toString();

		$row->store();

		// Store custom fields data
		$form->storeData($row->id, $data);

		// Store registrant tickets
		if ($event->has_multiple_ticket_types)
		{
			$this->storeRegistrantTickets($row, $event, $data);
		}

		/* Accept privacy consent to avoid Joomla requires users to accept it again */
		if (PluginHelper::isEnabled(
				'system',
				'privacyconsent'
			) && $row->user_id > 0 && $config->show_privacy_policy_checkbox)
		{
			EventbookingHelperRegistration::acceptPrivacyConsent($row);
		}

		$data['event_title'] = $event->title;

		PluginHelper::importPlugin('eventbooking');
		$app->triggerEvent('onAfterStoreRegistrant', [$row]);

		if ($row->deposit_amount > 0)
		{
			$data['amount'] = $row->deposit_amount;
		}

		// Store registration_code into session, use for registration complete code
		Factory::getSession()->set('eb_registration_code', $row->registration_code);

		if ($row->amount > 0 && !$waitingList)
		{
			$this->processRegistrationPayment($row, $event, $data);
		}
		else
		{
			if (!$waitingList)
			{
				$this->completeNonePaymentRegistration($row, $event);

				return 1;
			}
			EventbookingHelper::callOverridableHelperMethod('Mail', 'sendWaitinglistEmail', [$row, $config]);

			return 2;
		}
    }

    /**
	 * 1. Creates a history record for the given coupon to decrement it's value and / or disable the record.
	 * 2. Tries to migrate the given awocoupon to the event booking coupons to ensure transactions are valid.
	 * 	  Without this step, data won't be accurate (registrant payed less than event costs w/o any coupon code being displayed) in the backend.
	 * 3. If succeed, this will return the migrated event booking coupon record.
	 *
	 * @param object    $awoCoupon
	 * @param array     $data
	 * @param object    $registrant
	 * @param array     $fees
	 *
	 * @return array
	 */
    private function migrateAndRecordAwoCouponUsage($awoCoupon, $data, $registrant, $fees) 
    {
        if ( ! self::init_awocoupon() )
        {
            return null;
        }

        $awo = AC()->storediscount->is_coupon_valid( $awoCoupon->code );
        if( ! $awo )
        {
            return null;
		}

		if( $this->save_coupon_history($awoCoupon, $data, $registrant, $fees) ) {

			$db     = JFactory::getDbo();
			$query  = $db->getQuery(true);
			$query->clear()
				->select('*')
				->from('#__eb_coupons')
				->where($db->quoteName('code') . '=' . $db->quote($awoCoupon->code));
			$db->setQuery($query);
			$coupon = $db->loadObject();

			if (!$coupon)
			{
				$coupon = JTable::getInstance('Coupon', 'EventbookingTable');
				$coupon->code        = $awoCoupon->code;
				$coupon->discount    = $awoCoupon->discount;
				$coupon->coupon_type = $awoCoupon->coupon_type;
				$coupon->apply_to    = $awoCoupon->apply_to;
				$coupon->enable_for  = $awoCoupon->enable_for;
				$coupon->access      = 1;
				$coupon->published   = 0;
				$coupon->note 		 = 'Importiert bei Einlösung (siehe AwoCoupon)';
				$coupon->store();
			}

			return $coupon;
		}
    }
    
	/**
	 * Ensure awocoupon components are initialized.
	 */
    private function init_awocoupon() {
		if ( ! class_exists( 'awocoupon' ) ) {
			if ( ! file_exists( JPATH_ADMINISTRATOR . '/components/com_awocoupon/helper/awocoupon.php' ) ) {
				return false;
			}
			require JPATH_ADMINISTRATOR . '/components/com_awocoupon/helper/awocoupon.php';
		}
		if ( ! class_exists( 'awocoupon' ) ) {
			return false;
		}
		if ( ! function_exists( 'AC' ) ) {
			AwoCoupon::instance();
		}
		AC()->init();
		return true;
	}

	/**
	 * Add coupon to history after creating order
	 * `Copied from class-awocoupon-library-discount::save_coupon_history`
	 * CAUTION: Extended this implementation to notify registrant (not manager) about the usage and possible left balance on the coupon.
	 *
	 * @param object    $coupon the coupon.
	 * @param object 	$data the event booking payload.
	 * @param object 	$registrant the current registrant record.
	 * @param object 	$fees the already calculated fees.
	 **/
	protected function save_coupon_history( $coupon, $data, $registrant, $fees ) {
		$db = AC()->db;

		$user_id = $registrant->user_id;
		$user_email = $data['email'];
		$order_id = 'NULL';
		$user_email = empty( $user_email ) ? 'NULL' : '"' . $db->escape( $user_email ) . '"';
		$estore = AC()->store_id;

		$coupon_ids = implode( ',', [$coupon->id] );
		$sql = 'SELECT * FROM #__awocoupon WHERE estore="' . $estore . '" AND state IN ("published", "balance") AND id IN (' . $coupon_ids . ')';
		$rows = $db->get_objectlist( $sql );

		$coupon_details = $db->escape( AC()->helper->json_encode( $data ) );
		$created_at = gmdate( 'Y-m-d H:i:s' );

		foreach ( $rows as $coupon_row ) {

			// mark coupon used.
			$is_customer_balance = 1;
			$coupon_id_entered = $coupon_row->id;

			$total_curr_product = (float) $data['discount_amount'];
			$total_curr_shipping = (float) 0;
			$total_product = AC()->storecurrency->convert_to_default( $total_curr_product );
			$total_shipping = AC()->storecurrency->convert_to_default( $total_curr_shipping );

			$sql = 'INSERT INTO #__awocoupon_history SET
						estore="' . $estore . '",
						coupon_id=' . $coupon_row->id . ',
						coupon_code="' . AC()->db->escape( $coupon_row->coupon_code ) . '",
						coupon_id_entered=' . $coupon_row->id . ',
						coupon_code_entered="' . AC()->db->escape( $coupon_row->coupon_code ) . '",
						is_customer_balance=' . $is_customer_balance . ',
						user_id=' . $user_id . ',
						user_email=' . $user_email . ',
						total_product=' . $total_product . ',
						total_shipping=' . $total_shipping . ',
						currency_code="' . AC()->db->escape( AC()->storecurrency->get_current_currencycode() ) . '",
						total_curr_product=' . $total_curr_product . ',
						total_curr_shipping=' . $total_curr_shipping . ',
						order_id=' . $order_id . ',
						productids="NULL",
						details="' . $coupon_details . '",
						created_at="' . $created_at . '"
			';
			$db->query( $sql );

			$is_part_of_balance = false;
			if ( 'giftcert' === $coupon_row->function_type ) {
				// gift certificate.
				if ( ! empty( $user_id ) && 1 === (int) AC()->param->get( 'enable_frontend_balance', 0 ) && 1 === (int) AC()->param->get( 'enable_frontend_balance_isauto', 0 ) ) {
					// add valid gift certificate to customer balance.
					AC()->coupon->add_customer_balance( $user_id, $coupon_row->id );
					$is_part_of_balance = AC()->coupon->is_giftcert_valid_for_balance( $coupon_row->id, false ) ? true : false;
				}

				if ( ! $is_part_of_balance ) {
					$balance = AC()->coupon->get_giftcert_balance( $coupon_row->id );
					if ( empty( $balance ) ) {
						// credits maxed out.
						$db->query( 'UPDATE #__awocoupon SET state="unpublished" WHERE id=' . $coupon_row->id );
					}
				}
			} else {
				$is_unpublished = false;
				if ( ! empty( $coupon_row->num_of_uses_total ) ) {
					// limited amount of uses so can be removed.
					$sql = 'SELECT COUNT(id) FROM #__awocoupon_history WHERE estore="' . $this->estore . '" AND coupon_id=' . $coupon_row->id . ' GROUP BY coupon_id';
					$num = $db->get_value( $sql );
					if ( ! empty( $num ) && $num >= $coupon_row->num_of_uses_total ) {
						// already used max number of times.
						$is_unpublished = true;
						$db->query( 'UPDATE #__awocoupon SET state="unpublished" WHERE id=' . $coupon_row->id );
					}
				}
			}

			if ( ! $is_part_of_balance && ! empty( $user_id ) ) {
				$giftcard_id = (int) $db->get_value( 'SELECT id FROM #__awocoupon_voucher_customer_code WHERE coupon_id=' . $coupon_row->id . ' AND (recipient_user_id IS NULL OR recipient_user_id=0)' );
				if ( ! empty( $giftcard_id ) ) {
					// if purchased voucher transfer to recipient user account.
					$db->query( 'UPDATE #__awocoupon_voucher_customer_code SET recipient_user_id=' . (int) $user_id . ' WHERE id=' . $giftcard_id );
				}
			}

			if ( $balance > 0 && AC()->param->get( 'giftcert_purchasermanager_enable', 0 ) == 1 && (int) AC()->param->get( 'giftcert_purchasermanager_notification', 0 ) > 0 ) {
				$profile = AC()->db->get_arraylist( 'SELECT * FROM #__awocoupon_profile WHERE id=' . (int) AC()->param->get( 'giftcert_purchasermanager_notification', 0 ) );
				if ( ! empty( $profile ) ) {
					$profile = AC()->profile->decrypt_profile( current( $profile ) );
					$coupon_row->coupon_price = '';
					if(!empty($coupon_row->coupon_value)) {
						$coupon_row->coupon_price = $coupon_row->coupon_value_type == 'amount' 
							? AC()->storecurrency->format( $coupon_row->coupon_value )
							: round( $coupon_row->coupon_value ) . '%'
						;
					}
					$voucher_row = $db->get_object( '
						SELECT g.user_id, g.order_id,gc.recipient_user_id,gc.product_id,gc.voucher_customer_id,gc.id
						  FROM #__awocoupon_voucher_customer_code gc
						  JOIN #__awocoupon_voucher_customer g ON g.id=gc.voucher_customer_id
						 WHERE gc.coupon_id=' . $coupon_row->id . ' AND gc.recipient_user_id IS NOT NULL AND gc.recipient_user_id!=0
					' );

					// CAUTION: Using the registrant's user instead of using the actual manager here, because the regular 'manager' user is the
					// person who bought the gift certificate.
					$manager_user = ! empty( $user_id ) ? AC()->helper->get_user( $user_id ) : 0;
					if ( ! empty( $manager_user ) && $manager_user->id != $coupon_session->user_id ) {
						// send notification email to registrant (NOT the purchaser) (manager)
						$giftcert_voucher_row = null;
						$tmp_rows = AC()->storegift->get_resend_orderitem_rows( $voucher_row->voucher_customer_id );
						foreach ( $tmp_rows as $tmp_row ) {
							if ( $tmp_row->coupon_id = $coupon_row->id ) {
								$giftcert_voucher_row = $tmp_row;
								break;
							}
						}
						if ( ! empty( $giftcert_voucher_row ) ) {
							$store_name = AC()->store->get_name();
							$store_email = AC()->store->get_email();
							$order = AC()->store->get_order( $order_id );
							$original_order = AC()->store->get_order( $voucher_row->order_id );
							$recipient_user = ! empty( $voucher_row->recipient_user_id ) ? AC()->helper->get_user( $voucher_row->recipient_user_id ) : 0;
							$giftcards = AC()->db->get_objectlist( AC()->store->sql_history_giftcert( array( 'c.id IN (' . $coupon_row->id . ')' ) ), 'id' );
							
							$tag_replace = array( 
								'{store_name}' => $store_name,
								'{siteurl}' => AC()->store->get_home_link(),
								'{voucher}' => $coupon_row->coupon_code,
								'{voucher_value}' => AC()->storecurrency->format( $coupon_row->coupon_value ),
								'{voucher_value_used}' => isset( $giftcards[ $coupon_row->id ] ) ? AC()->storecurrency->format( $giftcards[ $coupon_row->id ]->coupon_value_used ) : '',
								'{voucher_balance}' => isset( $giftcards[ $coupon_row->id ] ) ? AC()->storecurrency->format( $giftcards[ $coupon_row->id ]->balance ) : '',
								'{voucher_expiration}' => ! empty( $giftcards[ $coupon_row->id ]->expiration ) ? AC()->helper->get_date( $giftcards[ $coupon_row->id ]->expiration ) : '',
								'{today_date}' => AC()->helper->get_date(),
								'{order_id}' => $order->order_id,
								'{order_number}' => $order->order_number,
								'{recipient_name}' => $recipient_user->name,
								'{recipient_email}' => $recipient_user->email,
								'{purchased_product_name}' => $giftcert_voucher_row->order_item_name,
								'{original_order_number}' => isset( $original_order->order_number ) ? $original_order->order_number : '',
								'{original_order_date}' => ! empty( $original_order->_created_on ) ? AC()->helper->get_date( $original_order->_created_on ) : '',
								'{original_order_id}' => isset( $original_order->order_id ) ? $original_order->order_id : '',
								'{manager_name}' => $manager_user->name,
								'{manager_username}' => $manager_user->username,

							);
							$dynamic_tags = array(
								'find' => array_keys( $tag_replace ),
								'replace' => array_values( $tag_replace ),
							);
							AC()->profile->send_email( $manager_user, array( $coupon_row ), $profile, $dynamic_tags, true );
						}
					}
				}
			}
		}

		$this->initialize_coupon();

		// reset customer balance session.
		$this->session_set( 'customer_balance', null );

		return true;
	}

	/**
	 * Set data to session
	 *
	 * @param string $name key to save to.
	 * @param mixed  $value maixed value to save.
	 **/
	protected function session_set( $name, $value ) {
		if ( is_object( $value ) ) {
			$value = AC()->helper->json_encode( $value );
		}
		AC()->helper->set_session( 'site', $name, $value );
	}
}