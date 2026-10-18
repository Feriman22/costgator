<?php
$s = CostGator\Scanner::last_result();
var_export( $s['coupons'] );
var_export( $s['unsupported_coupons'] );
var_export( CostGator\Auditor::find_coupons() );
$c = new WC_Coupon( 'old40' );
var_export( array( $c->get_id(), $c->get_discount_type(), $c->get_amount(), get_post_status( $c->get_id() ) ) );
