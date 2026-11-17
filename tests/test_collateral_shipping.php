<?php
/**
 * test_collateral_shipping.php — the parts of inc/collateral_shipping.php that need no database:
 * the tracking link from a PICKED carrier, which carrier the form shows for a stored link, and
 * which orders belong to one shipment.
 *
 *   php tests/test_collateral_shipping.php
 */
require __DIR__ . '/../inc/collateral_shipping.php';
$pass = 0; $fail = 0;
function ok(string $what, bool $c, string $d = ''): void { global $pass, $fail; if ($c) { $pass++; echo "  ok   {$what}\n"; } else { $fail++; echo "  FAIL {$what}" . ($d !== '' ? "\n       {$d}" : '') . "\n"; } }

echo "\nTHE TRACKING LINK\n";
ok('FedEx', mk_tracking_link('fedex', '480317121172') === 'https://www.fedex.com/fedextrack/?trknbr=480317121172');
ok('UPS',   mk_tracking_link('ups', '1Z999AA10123456784') === 'https://www.ups.com/track?tracknum=1Z999AA10123456784');
ok('USPS',  mk_tracking_link('usps', '9400111899223197428490') === 'https://tools.usps.com/go/TrackConfirmAction?tLabels=9400111899223197428490');
ok('spaces in the number are dropped', mk_tracking_link('fedex', ' 4803 1712 1172 ') === 'https://www.fedex.com/fedextrack/?trknbr=480317121172');
ok('no carrier picked: no link is made', mk_tracking_link('', '480317121172') === '');
ok('a carrier we do not know: no link', mk_tracking_link('dhl', '480317121172') === '');
ok('no number: no link', mk_tracking_link('fedex', '   ') === '');
ok('the carrier is never worked out from the number', mk_tracking_link('', '1Z999AA10123456784') === '');

echo "\nWHICH CARRIER THE FORM SHOWS\n";
ok('a link this code made shows its carrier', mk_tracking_carrier_of(mk_tracking_link('fedex', '480317121172'), '480317121172') === 'fedex');
ok('a pasted FedEx link is "as entered", so saving leaves it alone',
   mk_tracking_carrier_of('https://www.fedex.com/fedextrack/?trknbr=480317121172&trkqual=12031~480317121172~FDEG', '480317121172') === '');
ok('a made link whose number has since changed is "as entered"', mk_tracking_carrier_of(mk_tracking_link('ups', 'AAA'), 'BBB') === '');
ok('no link: nothing picked', mk_tracking_carrier_of('', '480317121172') === '' && mk_tracking_carrier_of(null, null) === '');

echo "\nONE SHIPMENT\n";
$all = [
    36 => ['type' => 'yard_signs', 'billed_with_order_id' => null],
    37 => ['type' => 'oh_signs',   'billed_with_order_id' => 36],
    38 => ['type' => 'brochures',  'billed_with_order_id' => 36],
    35 => ['type' => 'business_cards', 'billed_with_order_id' => null],
];
ok('the order holding the cost: the ones billed with it', array_keys(mk_coll_group_others($all, 36)) === [37, 38]);
ok('a linked order: the holder and its other linked orders', array_keys(mk_coll_group_others($all, 37)) === [36, 38]);
ok('an order on its own: nobody', mk_coll_group_others($all, 35) === []);
ok('an id that is not there: nobody', mk_coll_group_others($all, 99) === []);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
