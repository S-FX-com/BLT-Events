<?php
class Obie_Events_Helper
{
    public static function format_price($price, $includeTotal = false)
    {
        // Get currency settings
        $currency = get_option('obie_events_currency');
        $show_currency = get_option('obie_events_display_currency');
        $show_symbol = get_option('obie_events_display_currency_sign');

        // Currency symbol mapping
        $currency_symbols = array(
            'USD' => '$',
        );

        // Format the number with 2 decimal places
        $formatted_price = number_format($price, 2);

        // Build the price string
        $price_string = '';

        // Add "Total:" if requested
        if ($includeTotal) {
            $price_string .= 'Total: ';
        }

        // Add currency symbol before price if enabled
        if ($show_symbol === '1' && isset($currency_symbols[$currency])) {
            $price_string .= $currency_symbols[$currency];
        }

        // Add the price
        $price_string .= $formatted_price;

        // Add currency code after price if enabled
        if ($show_currency === '1') {
            $price_string .= ' ' . $currency;
        }

        return $price_string;
    }
}
