<?php
/**
 * @package            Joomla
 * @subpackage         Event Booking
 * @author             Necati Meral
 * @copyright          Copyright (C) 2010 - 2019 Ossolution Team
 * @license            GNU/GPL, see LICENSE.php
 */

defined('_JEXEC') or die;

use Joomla\Registry\Registry;
use Joomla\Utilities\ArrayHelper;

/**
 * EventbookingHelperOverrideRegistration class
 *
 * This class can be used to override some common methods used in EventbookingHelperOverrideJquery class. It is needed when you need to
 * override these methods without having to worry about losing the customization while updating to future releases of Events Booking
 */
class EventbookingHelperOverrideRegistration extends EventbookingHelperRegistration
{
    /**
	 * Calculate fees use for individual registration
	 *
	 * @param object    $event
	 * @param RADForm   $form
	 * @param array     $data
	 * @param RADConfig $config
	 * @param string    $paymentMethod
	 *
	 * @return array
	 */
	public static function calculateIndividualRegistrationFees($event, $form, $data, $config, $paymentMethod = null)
	{
		$fees       = array();
		$user       = JFactory::getUser();
		$db         = JFactory::getDbo();
		$query      = $db->getQuery(true);
        $couponCode = isset($data['coupon_code']) ? $data['coupon_code'] : '';

		$feeCalculationTags = array(
			'NUMBER_REGISTRANTS' => 1,
			'INDIVIDUAL_PRICE'   => $event->individual_price
		);

		if ($config->event_custom_field && file_exists(JPATH_ROOT . '/components/com_eventbooking/fields.xml'))
		{
			EventbookingHelperData::prepareCustomFieldsData(array($event));

			$filterInput = JFilterInput::getInstance();

			foreach ($event->paramData as $customFieldName => $param)
			{
				$feeCalculationTags[strtoupper($customFieldName)] = $filterInput->clean($param['value'], 'float');
			}
        }

		$totalAmount         = $event->individual_price + $form->calculateFee($feeCalculationTags);
		$noneDiscountableFee = empty($feeCalculationTags['none_discountable_fee']) ? 0 : $feeCalculationTags['none_discountable_fee'];
		$totalAmount         -= $noneDiscountableFee;

		if ($event->has_multiple_ticket_types)
		{
			$ticketTypes               = EventbookingHelperData::getTicketTypes($event->id);
			$params                    = new Registry($event->params);
			$collectMembersInformation = $params->get('ticket_types_collect_members_information', 0);

			foreach ($ticketTypes as $ticketType)
			{
				if (empty($data['ticket_type_' . $ticketType->id]))
				{
					continue;
				}

				$ticketType->quantity = $data['ticket_type_' . $ticketType->id];
				$totalAmount          += (int) $ticketType->quantity * $ticketType->price;
			}

			if ($collectMembersInformation)
			{
				$ticketsMembersData                = [];
				$ticketsMembersData['eventId']     = $event->id;
				$ticketsMembersData['ticketTypes'] = $ticketTypes;
				$ticketsMembersData['formData']    = $data;

				$rowFields = EventbookingHelperRegistration::getFormFields($event->id, 2);

				if (isset($data['use_field_default_value']))
				{
					$useDefault = $data['use_field_default_value'];
				}
				else
				{
					$useDefault = true;
				}

				$count = 0;

				foreach ($ticketTypes as $item)
				{
					if (empty($item->quantity))
					{
						continue;
					}

					for ($i = 0; $i < $item->quantity; $i++)
					{
						$count++;
						$memberForm = new RADForm($rowFields);
						$memberForm->setFieldSuffix($count);
						$memberForm->bind($data, $useDefault);
						$totalAmount += $memberForm->calculateFee();
					}
				}

				$fees['tickets_members'] = EventbookingHelperHtml::loadCommonLayout('common/tmpl/tickets_members.php', $ticketsMembersData);
			}
		}


		if ($config->get('setup_price'))
		{
			$totalAmount         = $totalAmount / (1 + $event->tax_rate / 100);
			$noneDiscountableFee = $noneDiscountableFee / (1 + $event->tax_rate / 100);
		}

		$discountAmount        = 0;
		$fees['discount_rate'] = 0;
		$nullDate              = $db->getNullDate();

		if ($user->id)
		{
			$discountRate = self::calculateMemberDiscount($event->discount_amounts, $event->discount_groups);

			if ($discountRate > 0 && $config->get('setup_price') && $event->discount_type == 2)
			{
				$discountRate = $discountRate / (1 + $event->tax_rate / 100);
			}

			if ($discountRate > 0)
			{
				$fees['discount_rate'] = $discountRate;

				if ($event->discount_type == 1)
				{
					$discountAmount = $totalAmount * $discountRate / 100;
				}
				else
				{
					$discountAmount = $discountRate;
				}
			}
		}

		if ($event->early_bird_discount_date != $nullDate
			&& $event->date_diff >= 0
			&& $event->early_bird_discount_amount > 0)
		{
			if ($event->early_bird_discount_type == 1)
			{
				$discountAmount = $discountAmount + $totalAmount * $event->early_bird_discount_amount / 100;
			}
			else
			{
				if ($config->get('setup_price'))
				{
					$discountAmount = $discountAmount + $event->early_bird_discount_amount / (1 + $event->tax_rate / 100);
				}
				else
				{
					$discountAmount = $discountAmount + $event->early_bird_discount_amount;
				}
			}
		}

		if ($couponCode)
		{
			$negEventId          = -1 * $event->id;
			$nullDateQuoted      = $db->quote($db->getNullDate());
			$eventMainCategoryId = (int) $event->main_category_id;

			//Validate the coupon
			$query->clear()
				->select('*')
				->from('#__eb_coupons')
				->where('published = 1')
				->where('`access` IN (' . implode(',', $user->getAuthorisedViewLevels()) . ')')
				->where('code = ' . $db->quote($couponCode))
				->where('(valid_from = ' . $nullDateQuoted . ' OR valid_from <= NOW())')
				->where('(valid_to = ' . $nullDateQuoted . ' OR valid_to >= NOW())')
				->where('(times = 0 OR times > used)')
				->where('discount > used_amount')
				->where('enable_for IN (0, 1)')
				->where('user_id IN (0, ' . $user->id . ')')
				->where('(category_id = -1 OR id IN (SELECT coupon_id FROM #__eb_coupon_categories WHERE category_id = ' . $eventMainCategoryId . '))')
				->where('(event_id = -1 OR id IN (SELECT coupon_id FROM #__eb_coupon_events WHERE event_id = ' . $event->id . ' OR event_id < 0))')
				->where('id NOT IN (SELECT coupon_id FROM #__eb_coupon_events WHERE event_id = ' . $negEventId . ')')
				->order('id DESC');
			$db->setQuery($query);
            $coupon = $db->loadObject();
            
            if( ! $coupon ) 
            {
				// NM@02.12.2019: Try to validate awocoupon in case there isn't any internal coupon
                $coupon = self::validateAwoCoupon($couponCode);
			}

			if ($coupon && $coupon->max_usage_per_user > 0 && $user->id > 0)
			{
				$query->clear()
					->select('COUNT(*)')
					->from('#__eb_registrants')
					->where('user_id = ' . $user->id)
					->where('coupon_id = ' . $coupon->id)
					->where('group_id = 0')
					->where('(published = 1 OR (published = 0 AND payment_method LIKE "%os_offline"))');
				$db->setQuery($query);
				$total = $db->loadResult();

				if ($total >= $coupon->max_usage_per_user)
				{
					$coupon = null;
				}
			}

			if ($coupon)
			{
				$fees['coupon_valid'] = 1;
                $fees['coupon']       = $coupon;

				if ($coupon->coupon_type == 0)
				{
					$discountAmount = $discountAmount + $totalAmount * $coupon->discount / 100;
				}
				elseif ($coupon->coupon_type == 1)
				{
					if ($coupon->apply_to == 0 && $event->has_multiple_ticket_types)
					{
						foreach ($ticketTypes as $item)
						{
							if (empty($item->quantity))
							{
								continue;
							}

							$discountAmount = $discountAmount + $item->quantity * $coupon->discount;
						}
					}
					else
					{
						$discountAmount = $discountAmount + $coupon->discount;
					}
				}
			}
			else
			{
                $fees['coupon_valid'] = 0;
            }
        }
        else
		{
			$fees['coupon_valid'] = 1;
		}

		$fees['bundle_discount_amount'] = 0;
		$fees['bundle_discount_ids']    = array();

		// Calculate bundle discount if setup
		if ($user->id > 0)
		{
			$nullDate    = $db->quote($db->getNullDate());
			$currentDate = $db->quote(JHtml::_('date', 'Now', 'Y-m-d'));
			$query->clear()
				->select('id, event_ids, discount_amount')
				->from('#__eb_discounts')
				->where('(from_date = ' . $nullDate . ' OR DATE(from_date) <=' . $currentDate . ')')
				->where('(to_date = ' . $nullDate . ' OR DATE(to_date) >= ' . $currentDate . ')')
				->where('(times = 0 OR times > used)')
				->where('id IN (SELECT discount_id FROM #__eb_discount_events WHERE event_id = ' . $event->id . ')');
			$db->setQuery($query);

			$discountRules = $db->loadObjectList();

			if (!empty($discountRules))
			{
				$query->clear()
					->select('DISTINCT event_id')
					->from('#__eb_registrants')
					->where('user_id = ' . $user->id)
					->where('(published = 1 OR (payment_method LIKE "os_offline%" AND published IN (0, 1)))');
				$registeredEventIds = $db->loadColumn();

				if (count($registeredEventIds))
				{
					$registeredEventIds[] = $event->id;

					foreach ($discountRules as $rule)
					{
						$eventIds = explode(',', $rule->event_ids);

						if (!array_diff($eventIds, $registeredEventIds))
						{
							$fees['bundle_discount_amount'] += $rule->discount_amount;
							$discountAmount                 += $rule->discount_amount;
							$fees['bundle_discount_ids'][]  = $rule->id;
						}
					}
				}
			}
		}

		$totalAmount += $noneDiscountableFee;

		if ($discountAmount > $totalAmount)
		{
			$discountAmount = $totalAmount;
		}

		// Late Fee
		$lateFee = 0;

		if ($event->late_fee_date != $nullDate
			&& $event->late_fee_date_diff >= 0
			&& $event->late_fee_amount > 0)
		{
			if ($event->late_fee_type == 1)
			{
				$lateFee = $totalAmount * $event->late_fee_amount / 100;
			}
			else
			{

				$lateFee = $event->late_fee_amount;
			}
		}

		if ($event->tax_rate > 0 && ($totalAmount - $discountAmount + $lateFee > 0))
		{
			$taxAmount = round(($totalAmount - $discountAmount + $lateFee) * $event->tax_rate / 100, 2);
			$amount    = $totalAmount - $discountAmount + $taxAmount + $lateFee;
		}
		else
		{
			$taxAmount = 0;
			$amount    = $totalAmount - $discountAmount + $taxAmount + $lateFee;
		}

		// Init payment processing fee amount
		$fees['payment_processing_fee'] = 0;

		// Payment processing fee
		$hasPaymentProcessingFee = false;
		$paymentFeeAmount        = 0;
		$paymentFeePercent       = 0;

		if ($paymentMethod)
		{
			$method            = os_payments::loadPaymentMethod($paymentMethod);
			$params            = new Registry($method->params);
			$paymentFeeAmount  = (float) $params->get('payment_fee_amount');
			$paymentFeePercent = (float) $params->get('payment_fee_percent');

			if ($paymentFeeAmount != 0 || $paymentFeePercent != 0)
			{
				$hasPaymentProcessingFee = true;
			}
		}

		$paymentType = isset($data['payment_type']) ? (int) $data['payment_type'] : 0;

		if ($paymentType == 0 && $amount > 0 && $hasPaymentProcessingFee)
		{
			$fees['payment_processing_fee'] = round($paymentFeeAmount + $amount * $paymentFeePercent / 100, 2);
			$amount                         += $fees['payment_processing_fee'];
		}

		$couponDiscountAmount = 0;

		if (!empty($coupon) && $coupon->coupon_type == 2)
		{
			$couponAvailableAmount = $coupon->discount - $coupon->used_amount;

			if ($couponAvailableAmount >= $amount)
			{
				$couponDiscountAmount = $amount;
				$amount               = 0;
			}
			else
			{
				$amount               = $amount - $couponAvailableAmount;
				$couponDiscountAmount = $couponAvailableAmount;
			}
		}

		$discountAmount += $couponDiscountAmount;

		// Calculate the deposit amount as well
		if ($config->activate_deposit_feature && $event->deposit_amount > 0)
		{
			if ($event->deposit_type == 2)
			{
				$depositAmount = $event->deposit_amount;
			}
			else
			{
				$depositAmount = $event->deposit_amount * $amount / 100;
			}
		}
		else
		{
			$depositAmount = 0;
		}

		if ($paymentType == 1 && $depositAmount > 0 && $hasPaymentProcessingFee)
		{
			$fees['payment_processing_fee'] = round($paymentFeeAmount + $depositAmount * $paymentFeePercent / 100, 2);
			$amount                         += $fees['payment_processing_fee'];
			$depositAmount                  += $fees['payment_processing_fee'];
		}

		$fees['total_amount']           = round($totalAmount, 2);
		$fees['discount_amount']        = round($discountAmount, 2);
		$fees['tax_amount']             = round($taxAmount, 2);
		$fees['amount']                 = round($amount, 2);
		$fees['deposit_amount']         = round($depositAmount, 2);
		$fees['late_fee']               = round($lateFee, 2);
		$fees['coupon_discount_amount'] = round(100, 2);
		return $fees;
    }

    /**
	 * Validate validity and balance of an (awo-)coupon-code.
	 * In case of a valid record with any balance, this method will return the awocoupon data in the same structure as event-bookins coupons.
	 *
	 * @param string    $couponCode
	 *
	 * @return array
	 */
    private static function validateAwoCoupon($couponCode) 
    {
        if ( ! self::init_awocoupon() )
        {
            return null;
        }

        $awo = AC()->storediscount->is_coupon_valid( $couponCode );
        if($awo) 
        {
			// get balance of gift certificate.
			$balance = AC()->coupon->get_giftcert_balance( $awo->coupon_row->id );

			if ( empty( $balance ) || $balance <= 0 ) {
				return null;
			}

            $migratedCoupon = (object) [
                'id' => $awo->coupon_row->id,
                'code' => $awo->coupon_row->coupon_code,
                'coupon_type' => $awo->coupon_row->coupon_value_type == 'percent' ? 0 : 2,
                'discount' => $awo->coupon_row->coupon_value,
                'times' => min($awo->coupon_row->num_of_uses_total, $awo->coupon_row->num_of_uses_customer),
                'used' => 0, // NM@26.11.2019: Maybe calculate this value (maybe this matches 'balance')
                'published' => $awo->coupon_row->state == 'published',
                'max_usage_per_user' => $awo->coupon_row->num_of_uses_customer,
                'apply_to' => 1, // NM@26.11.2019: Apply to each registration
                'max_number_registrants' => 0,
                'min_number_registrants' => 0,
                'note' => $awo->coupon_row->note,
                'enable_for' => 0,
                'access' => 0,
                'used_amount' => $awo->coupon_row->coupon_value_type == 'percent' ? 0 : ( $awo->coupon_row->coupon_value - $balance ),
                'awo' => true
            ];
            return $migratedCoupon;
        }
        return null;
    }
    
	/**
	 * Ensure awocoupon components are initialized.
	 */
    private static function init_awocoupon() {
		if ( ! class_exists( 'awocoupon' ) ) {
			if ( ! file_exists( JPATH_ADMINISTRATOR . '/components/com_awocoupon/helper/awocoupon.php' ) ) {
				return false;
			}
			require JPATH_ADMINISTRATOR . '/components/com_awocoupon/helper/awocoupon.php';
		}
		if ( ! class_exists( 'awocoupon' ) ) {
			return false;
		}
		AwoCoupon::instance();
		AC()->init();
		return true;
	}
}