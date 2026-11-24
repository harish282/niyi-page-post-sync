/* global niyiPpsAdmin */
( function ( $ ) {
	'use strict';

	var cfg = window.niyiPpsAdmin || {};
	var i18n = cfg.i18n || {};

	function escapeHtml( value ) {
		return $( '<span>' ).text( value == null ? '' : String( value ) ).html();
	}

	$( document ).on( 'click', '.niyi-pps-test', function ( event ) {
		event.preventDefault();

		var $button = $( this );
		var $form = $button.closest( 'form' );
		var receiverId = $button.data( 'receiver-id' ) || '';
		var $slot = $button.siblings( '.niyi-pps-test-slot' );
		if ( ! $slot.length ) {
			$slot = $( '<span class="niyi-pps-test-slot"></span>' );
			$button.after( $slot );
		}

		var data = {
			action: 'niyi_pps_test_receiver',
			nonce: cfg.nonce,
			receiver_id: receiverId
		};

		// If testing an unsaved receiver, send the form values.
		if ( ! receiverId && $form.length ) {
			data.url = $form.find( '[name="url"]' ).val();
			data.username = $form.find( '[name="username"]' ).val();
			data.password = $form.find( '[name="password"]' ).val();
		}

		var original = $button.text();
		$button.prop( 'disabled', true ).text( i18n.testing || 'Testing...' );
		$slot.text( '' );

		$.ajax( {
			url: cfg.ajax_url,
			type: 'POST',
			data: data
		} ).done( function ( response ) {
			var ok = ! ! ( response && response.success );
			var message = ( response && response.data && response.data.message ) ||
				( ok ? ( i18n.success || 'Connection successful.' ) : ( i18n.failed || 'Connection failed.' ) );
			$slot.html( '<span class="niyi-pps-test-result ' + ( ok ? 'ok' : 'err' ) + '">' + escapeHtml( message ) + '</span>' );
		} ).fail( function () {
			$slot.html( '<span class="niyi-pps-test-result err">' + escapeHtml( i18n.failed || 'Connection failed.' ) + '</span>' );
		} ).always( function () {
			$button.prop( 'disabled', false ).text( original || ( i18n.test || 'Test Connection' ) );
		} );
	} );

} )( jQuery );
