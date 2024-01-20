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
use Joomla\CMS\Factory;

/**
 * EventbookingHelperOverrideRegistration class
 *
 * This class can be used to override some common methods used in EventbookingHelperOverrideJquery class. It is needed when you need to
 * override these methods without having to worry about losing the customization while updating to future releases of Events Booking
 */
class EventbookingHelperOverrideRegistration extends EventbookingHelperRegistration
{
	/**
	 * Method to get coupon for individual registration
	 *
	 * @param   string    $couponCode
	 * @param   stdClass  $event
	 * @param   Joomla\CMS\User\User
	 *
	 * @return mixed|null
	 * @throws Exception
	 */
	public static function getCouponForIndividualRegistration($couponCode, $event, $user)
	{
		$db          = Factory::getDbo();
		$couponQuery = EventbookingHelper::callOverridableHelperMethod('Registration', 'getCouponQuery', [$couponCode, $event, $user]);
		$couponQuery->where('enable_for IN (0, 1)');
		$db->setQuery($couponQuery);
		$coupon = $db->loadObject();

		if ($coupon && $coupon->max_usage_per_user > 0 && $user->id > 0)
		{
			$query = $db->getQuery(true)
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

		if(!$coupon)
		{
			// NM@02.12.2019: Try to validate awocoupon in case there isn't any internal coupon
			$coupon = self::validateAwoCoupon($couponCode);
		}

		return $coupon;
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
        if ( !self::init_awocoupon() )
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
		if ( ! function_exists( 'AC' ) ) {
			AwoCoupon::instance();
		}
		AC()->init();
		return true;
	}
}