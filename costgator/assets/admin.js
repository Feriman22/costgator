( function () {
	'use strict';

	// The review form opens in a new tab and the request is already dismissed, so hide it here too.
	var review = document.getElementById( 'costgator-review' );
	if ( review ) {
		review.querySelector( 'a[target]' ).addEventListener( 'click', function () {
			review.hidden = true;
		} );
	}

	var config = window.costGator;
	var button = document.getElementById( 'costgator-scan' );
	if ( ! config || ! button ) {
		return;
	}

	var progress = document.getElementById( 'costgator-progress' );
	var fill = document.getElementById( 'costgator-progress-fill' );
	var text = document.getElementById( 'costgator-progress-text' );

	function post( action, data ) {
		var body = new URLSearchParams( data || {} );
		body.append( 'action', action );
		body.append( 'nonce', config.nonce );
		return fetch( config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body,
		} ).then( function ( response ) {
			return response.json().then( function ( json ) {
				if ( ! json || ! json.success ) {
					throw new Error( ( json && json.data && json.data.message ) || config.i18n.failed );
				}
				return json.data;
			} );
		} );
	}

	function show( status ) {
		var pct = status.total ? Math.round( ( status.processed / status.total ) * 100 ) : 100;
		fill.style.width = pct + '%';
		text.textContent = config.i18n.progress
			.replace( '%1$s', status.processed )
			.replace( '%2$s', status.total );
	}

	function run( status ) {
		show( status );
		if ( status.done ) {
			text.textContent = config.i18n.done;
			window.location.reload();
			return;
		}
		return post( 'costgator_step', { scan_id: status.scan_id } ).then( run );
	}

	button.addEventListener( 'click', function () {
		button.disabled = true;
		progress.hidden = false;
		fill.style.width = '0';
		text.textContent = config.i18n.starting;

		post( 'costgator_start' )
			.then( run )
			.catch( function ( error ) {
				text.textContent = error.message || config.i18n.failed;
				button.disabled = false;
			} );
	} );
} )();
