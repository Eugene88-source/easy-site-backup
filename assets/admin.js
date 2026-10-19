/**
 * Easy Site Backup: запуск резервного копіювання і покрокове виконання через AJAX.
 */
( function () {
	'use strict';

	var config = window.esbAdmin;
	var form = document.getElementById( 'esb-start-form' );
	var box = document.getElementById( 'esb-progress' );

	if ( ! config || ! form || ! box ) {
		return;
	}

	var bar = box.querySelector( '.esb-progress-bar' );
	var message = box.querySelector( '.esb-progress-message' );
	var cancelButton = document.getElementById( 'esb-cancel' );

	// Скільки разів поспіль повторювати крок після обриву зв'язку (наприклад, 524 від Cloudflare).
	var MAX_RETRIES = 5;
	var RETRY_DELAY = 5000;
	// Якщо крок уже виконує WP-Cron, чекаємо довше, щоб не засипати сервер запитами.
	var BUSY_DELAY = 3000;
	var STEP_DELAY = 300;

	var jobId = null;
	var retries = 0;
	var cancelling = false;

	function request( action, data ) {
		var body = new FormData();
		body.append( 'action', action );
		body.append( 'nonce', config.nonce );
		Object.keys( data ).forEach( function ( key ) {
			body.append( key, data[ key ] );
		} );

		return fetch( config.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' } )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( result ) {
				if ( ! result.success ) {
					throw new Error( ( result.data && result.data.message ) || config.i18n.error );
				}
				return result.data;
			} );
	}

	function render( job ) {
		form.hidden = true;
		box.hidden = false;
		bar.style.width = job.percent + '%';
		message.textContent = job.message;
	}

	function step() {
		if ( cancelling ) {
			return;
		}

		request( 'esb_step', { id: jobId } )
			.then( function ( job ) {
				retries = 0;
				render( job );

				if ( 'running' === job.status ) {
					setTimeout( step, job.busy ? BUSY_DELAY : STEP_DELAY );
				} else {
					window.location.reload();
				}
			} )
			.catch( function ( error ) {
				if ( retries++ < MAX_RETRIES ) {
					message.textContent = config.i18n.retry;
					setTimeout( step, RETRY_DELAY );
				} else {
					box.classList.add( 'esb-failed' );
					message.textContent = error.message;
				}
			} );
	}

	form.addEventListener( 'submit', function ( event ) {
		event.preventDefault();

		var type = form.querySelector( 'input[name="type"]:checked' );
		var submit = form.querySelector( '[type="submit"]' );
		submit.disabled = true;

		request( 'esb_start', { type: type ? type.value : 'full' } )
			.then( function ( job ) {
				jobId = job.id;
				render( job );
				step();
			} )
			.catch( function ( error ) {
				submit.disabled = false;
				window.alert( error.message );
			} );
	} );

	cancelButton.addEventListener( 'click', function () {
		if ( ! jobId || ! window.confirm( config.i18n.cancelConfirm ) ) {
			return;
		}

		cancelling = true;
		cancelButton.disabled = true;
		message.textContent = config.i18n.cancelling;

		// Якщо крок саме виконується, його завершить сервер; після перезавантаження
		// сторінка підхопить завдання і доведе скасування до кінця.
		request( 'esb_cancel', { id: jobId } )
			.catch( function () {} )
			.then( function () {
				window.location.reload();
			} );
	} );

	if ( config.activeJob ) {
		jobId = config.activeJob.id;
		render( config.activeJob );
		step();
	}
}() );

/**
 * Вибір кількох копій і їх видалення.
 */
( function () {
	'use strict';

	var config = window.esbAdmin;
	var form = document.getElementById( 'esb-bulk-form' );

	if ( ! config || ! form ) {
		return;
	}

	var selectAll = document.getElementById( 'esb-select-all' );
	var button = document.getElementById( 'esb-bulk-delete' );
	var boxes = Array.prototype.slice.call( form.querySelectorAll( '.esb-select' ) );

	function checkedCount() {
		return boxes.filter( function ( box ) {
			return box.checked;
		} ).length;
	}

	function update() {
		var count = checkedCount();
		button.disabled = 0 === count;
		selectAll.checked = count > 0 && count === boxes.length;
	}

	selectAll.addEventListener( 'click', function () {
		boxes.forEach( function ( box ) {
			box.checked = selectAll.checked;
		} );
		update();
	} );

	boxes.forEach( function ( box ) {
		box.addEventListener( 'change', update );
	} );

	form.addEventListener( 'submit', function ( event ) {
		if ( ! window.confirm( config.i18n.bulkConfirm.replace( '%d', checkedCount() ) ) ) {
			event.preventDefault();
		}
	} );

	update();
}() );
