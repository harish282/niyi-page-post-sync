/* global niyiSync */
( function ( $ ) {
	'use strict';

	var cfg  = window.niyiSync || {};
	var i18n = cfg.i18n || {};

	/**
	 * Active receivers only.
	 */
	function activeReceivers() {
		return ( cfg.receivers || [] ).filter( function ( receiver ) {
			return receiver.active;
		} );
	}

	/**
	 * Show an admin notice at the top of the screen.
	 */
	function showNotice( message, type ) {
		var variant = type === 'error' ? 'notice-error' : ( type === 'warning' ? 'notice-warning' : 'notice-success' );
		var klass = 'notice ' + variant + ' is-dismissible';
		var $notice = $( '<div class="' + klass + '"><p></p></div>' );
		$notice.find( 'p' ).text( message );
		$( '.wrap' ).first().prepend( $notice );
		$( document ).trigger( 'wp-plugin-updated' );
	}

	/**
	 * Persist a notice for display after a reload.
	 */
	function stashNotice( message, type ) {
		try {
			window.sessionStorage.setItem( 'niyi_pps_notice', JSON.stringify( { msg: message, type: type } ) );
		} catch ( e ) {
			// sessionStorage may be unavailable; ignore.
		}
	}

	/**
	 * Render a small modal for choosing receivers.
	 *
	 * @param {Array}   postIds  Posts being synced.
	 * @param {Function} done    Called with selected receiver IDs.
	 */
	function chooseReceivers( postIds, done ) {
		var receivers = activeReceivers();

		if ( ! receivers.length ) {
			window.alert( i18n.noReceivers || 'No active receivers configured.' );
			return;
		}

		// A single receiver: no popup needed, sync straight away.
		if ( receivers.length === 1 ) {
			done( [ receivers[ 0 ].id ] );
			return;
		}

		$( '.niyi-pps-overlay, .niyi-pps-modal' ).remove();

		var $overlay = $( '<div class="niyi-pps-overlay"></div>' );
		var $modal = $( '<div class="niyi-pps-modal" role="dialog" aria-modal="true">' +
			'<h2>' + ( i18n.selectReceiver || 'Select receivers' ) + '</h2>' +
			'<div class="niyi-pps-receiver-list"></div>' +
			'<div class="niyi-pps-compat" aria-live="polite"></div>' +
			'<p class="niyi-pps-modal-actions">' +
				'<button type="button" class="button button-primary niyi-pps-run">' + ( i18n.sync || 'Sync' ) + '</button> ' +
				'<button type="button" class="button niyi-pps-cancel">' + ( i18n.cancel || 'Cancel' ) + '</button>' +
			'</p>' +
		'</div>' );
		var $compat = $modal.find( '.niyi-pps-compat' );
		var compatTimer = null;
		var compatRequest = 0;

		$.each( receivers, function ( index, receiver ) {
			$modal.find( '.niyi-pps-receiver-list' ).append(
				'<label class="niyi-pps-receiver-option">' +
					'<input type="checkbox" name="niyi_receiver" value="' + receiver.id + '" checked> ' +
					'<span>' + $( '<span>' ).text( receiver.name ).html() + '</span>' +
				'</label>'
			);
		} );

		/**
		 * Ask the server which shortcodes the selected receivers support.
		 */
		function checkCompatibility() {
			var requestId = ++compatRequest;
			var selected = $modal.find( 'input[name="niyi_receiver"]:checked' ).map( function () {
				return this.value;
			} ).get();

			if ( ! selected.length ) {
				$compat.empty();
				return;
			}

			$compat.empty().append(
				$( '<p class="niyi-pps-compat-checking"></p>' ).text( i18n.checking || 'Checking shortcode compatibility...' )
			);

			$.ajax( {
				url: cfg.ajax_url,
				type: 'POST',
				data: {
					action: 'niyi_pps_check',
					nonce: cfg.nonce,
					post_ids: postIds,
					receiver_ids: selected
				}
			} ).done( function ( response ) {
				if ( requestId !== compatRequest ) {
					return;
				}
				$compat.empty();

				if ( ! response || ! response.success ) {
					return;
				}
				var messages = ( response.data && response.data.messages ) || [];
				if ( ! messages.length ) {
					return;
				}

				$compat.append(
					$( '<p class="niyi-pps-compat-title"><strong></strong></p>' )
						.find( 'strong' )
						.text( i18n.shortcodes || 'Receiver compatibility notes:' )
						.end()
				);
				var $list = $( '<ul class="niyi-pps-compat-list"></ul>' );
				$.each( messages, function ( index, message ) {
					$list.append( $( '<li></li>' ).text( message ) );
				} );
				$compat.append( $list );
			} ).fail( function () {
				if ( requestId === compatRequest ) {
					$compat.empty();
				}
			} );
		}

		function scheduleCompatibilityCheck() {
			if ( compatTimer ) {
				window.clearTimeout( compatTimer );
			}
			compatTimer = window.setTimeout( checkCompatibility, 250 );
		}

		$( 'body' ).append( $overlay ).append( $modal );

		function close() {
			if ( compatTimer ) {
				window.clearTimeout( compatTimer );
			}
			compatRequest++;
			$overlay.remove();
			$modal.remove();
		}

		$overlay.on( 'click', close );
		$modal.on( 'click', '.niyi-pps-cancel', close );
		$modal.on( 'change', 'input[name="niyi_receiver"]', scheduleCompatibilityCheck );
		checkCompatibility();
		$modal.on( 'click', '.niyi-pps-run', function () {
			var selected = $modal.find( 'input[name="niyi_receiver"]:checked' ).map( function () {
				return this.value;
			} ).get();

			if ( ! selected.length ) {
				window.alert( i18n.noneSelected || 'Please select at least one receiver.' );
				return;
			}
			close();
			done( selected );
		} );
	}

	/**
	 * Send the sync request to WordPress.
	 */
	function runSync( postIds, receiverIds ) {
		$( '.niyi-sync-single, .niyi-pps-run' ).prop( 'disabled', true );

		$.ajax( {
			url: cfg.ajax_url,
			type: 'POST',
			data: {
				action: 'niyi_pps_sync',
				nonce: cfg.nonce,
				post_ids: postIds,
				receiver_ids: receiverIds
			}
		} ).done( function ( response ) {
			if ( ! response || ! response.success ) {
				var error = ( response && response.data && response.data.message ) || i18n.error || 'Sync failed.';
				showNotice( error, 'error' );
				return;
			}

			var data = response.data || {};
			var notices = data.notices || [];
			var message = ( i18n.success || '%d item(s) synced.' ).replace( '%d', data.synced || 0 );
			if ( data.failed ) {
				message += ' ' + ( i18n.failed || '%d item(s) failed.' ).replace( '%d', data.failed );
				if ( data.messages && data.messages.length ) {
					message += ' — ' + data.messages.join( ' | ' );
				}
			}
			if ( notices.length ) {
				message += ' — ' + ( i18n.notes || 'Sync notes:' ) + ' ' + notices.join( ' | ' );
			}
			var noticeType = data.failed ? 'error' : ( notices.length ? 'warning' : 'success' );
			stashNotice( message, noticeType );
			window.location.reload();
		} ).fail( function () {
			showNotice( i18n.error || 'Sync failed.', 'error' );
		} ).always( function () {
			$( '.niyi-sync-single, .niyi-pps-run' ).prop( 'disabled', false );
		} );
	}

	/* Restore a stashed notice after reload. */
	$( function () {
		try {
			var raw = window.sessionStorage.getItem( 'niyi_pps_notice' );
			if ( raw ) {
				var notice = JSON.parse( raw );
				showNotice( notice.msg, notice.type );
				window.sessionStorage.removeItem( 'niyi_pps_notice' );
			}
		} catch ( e ) {
			// ignore
		}
	} );

	/* Bulk action: intercept the standard list-table form submit. */
	$( document ).on( 'submit', '#posts-filter', function ( event ) {
		var top = $( 'select[name="action"]' ).val();
		var bottom = $( 'select[name="action2"]' ).val();

		if ( top !== 'niyi_sync' && bottom !== 'niyi_sync' ) {
			return;
		}

		event.preventDefault();

		var ids = $( this ).find( 'input[name="post[]"]:checked' ).map( function () {
			return parseInt( this.value, 10 );
		} ).get();

		if ( ! ids.length ) {
			window.alert( i18n.noItems || 'Please select at least one item.' );
			return;
		}

		chooseReceivers( ids, function ( receiverIds ) {
			runSync( ids, receiverIds );
		} );
	} );

	/* Single sync button in the Sync column. */
	$( document ).on( 'click', '.niyi-sync-single', function ( event ) {
		event.preventDefault();
		var postId = parseInt( $( this ).data( 'post-id' ), 10 );
		if ( ! postId ) {
			return;
		}
		chooseReceivers( [ postId ], function ( receiverIds ) {
			runSync( [ postId ], receiverIds );
		} );
	} );

} )( jQuery );
