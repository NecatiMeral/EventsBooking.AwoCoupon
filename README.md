# EventsBooking.AwoCoupon
Required overrides to integrate AwoDev's [AwoCoupon](https://awodev.com/products/joomla/awocoupon) component into the [EventsBooking](https://www.joomdonation.com/joomla-extensions/events-booking-joomla-events-registration.html) component by JoomDonation.
With this customizations, EventsBooking accepts awocoupon-codes when registering for a event and migrates used coupons into the event booking-coupons (as deactivated coupons), so the registrant lists will include the used coupon. The original bookkeeping of coupons will be handled by AwoCoupon.

## How to install
Just move the files into the `com_eventbooking` directory.

## Caution: Registrant-notification
The registrant will get notified about the usage of his coupon in case there's any balance left on the coupon. 
This requires the 'Purchaser as Manager' notification to be enabled. Instead of notifiying the 'manager', this integration will notify the actual registrant about the left balance.

## Contribute
Any feedback or contribution is highly appreciated and you're encouraged to contribute.
