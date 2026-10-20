/**
 * Easy Site Backup: запуск резервного копіювання або відновлення і покрокове виконання через AJAX.
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

	// Поточне завдання: AJAX-дія кроку та її параметри.
	var task = null;
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

		request( task.action, task.params )
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

	function runBackup( job ) {
		task = { action: 'esb_step', params: { id: job.id } };
		render( job );
		step();
	}

	function runRestore( job ) {
		task = { action: 'esb_restore_step', params: { token: job.token } };
		// Відновлення не скасовується: частину файлів уже могло бути перезаписано.
		cancelButton.hidden = true;
		render( job );
		step();
	}

	form.addEventListener( 'submit', function ( event ) {
		event.preventDefault();

		var type = form.querySelector( 'input[name="type"]:checked' );
		var submit = form.querySelector( '[type="submit"]' );
		submit.disabled = true;

		request( 'esb_start', { type: type ? type.value : 'full' } )
			.then( runBackup )
			.catch( function ( error ) {
				submit.disabled = false;
				window.alert( error.message );
			} );
	} );

	Array.prototype.forEach.call( document.querySelectorAll( '.esb-restore' ), function ( button ) {
		button.addEventListener( 'click', function () {
			var time = button.closest( 'tr' ).querySelector( '.esb-time' );

			if ( ! window.confirm( config.i18n.restoreConfirm.replace( '%s', time ? time.textContent : '' ) ) ) {
				return;
			}

			button.disabled = true;

			request( 'esb_restore_start', { id: button.dataset.id } )
				.then( function ( job ) {
					window.scrollTo( 0, 0 );
					runRestore( job );
				} )
				.catch( function ( error ) {
					button.disabled = false;
					window.alert( error.message );
				} );
		} );
	} );

	cancelButton.addEventListener( 'click', function () {
		if ( ! task || 'esb_step' !== task.action || ! window.confirm( config.i18n.cancelConfirm ) ) {
			return;
		}

		cancelling = true;
		cancelButton.disabled = true;
		message.textContent = config.i18n.cancelling;

		// Якщо крок саме виконується, його завершить сервер; після перезавантаження
		// сторінка підхопить завдання і доведе скасування до кінця.
		request( 'esb_cancel', { id: task.params.id } )
			.catch( function () {} )
			.then( function () {
				window.location.reload();
			} );
	} );

	if ( config.activeJob ) {
		runBackup( config.activeJob );
	} else if ( config.activeRestore ) {
		runRestore( config.activeRestore );
	}
}() );

/**
 * Дати за часовим поясом і локаллю браузера, а не сайту.
 */
( function () {
	'use strict';

	if ( ! window.Intl || ! Intl.DateTimeFormat ) {
		return;
	}

	var short = new Intl.DateTimeFormat( undefined, { dateStyle: 'long', timeStyle: 'short' } );
	var full = new Intl.DateTimeFormat( undefined, { dateStyle: 'full', timeStyle: 'long' } );

	Array.prototype.forEach.call( document.querySelectorAll( '.esb-time' ), function ( el ) {
		var date = new Date( el.getAttribute( 'datetime' ) );

		if ( ! isNaN( date.getTime() ) ) {
			el.textContent = short.format( date );
			el.title = full.format( date );
		}
	} );

	// Сервер виводить підказку, лише якщо пояс сайту — UTC.
	var hint = document.getElementById( 'esb-tz-notice' );
	if ( hint && 0 !== new Date().getTimezoneOffset() ) {
		hint.hidden = false;
	}
}() );

/**
 * Завантаження архіву копії з комп'ютера частинами: розмір файлу не впирається в ліміти
 * сервера на один запит, а обірвана частина надсилається повторно з тієї самої позиції.
 */
( function () {
	'use strict';

	var config = window.esbAdmin;
	var input = document.getElementById( 'esb-upload-file' );
	var button = document.getElementById( 'esb-upload-start' );
	var box = document.getElementById( 'esb-upload-progress' );

	if ( ! config || ! input || ! button || ! box ) {
		return;
	}

	var bar = box.querySelector( '.esb-progress-bar' );
	var message = box.querySelector( '.esb-progress-message' );

	var MAX_RETRIES = 5;
	var RETRY_DELAY = 5000;

	function post( action, data ) {
		var body = new FormData();
		body.append( 'action', action );
		body.append( 'nonce', config.nonce );
		Object.keys( data ).forEach( function ( key ) {
			if ( data[ key ] instanceof Blob ) {
				body.append( key, data[ key ], 'chunk.bin' );
			} else {
				body.append( key, data[ key ] );
			}
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

	function wait( ms ) {
		return new Promise( function ( resolve ) {
			setTimeout( resolve, ms );
		} );
	}

	function mb( bytes ) {
		return ( bytes / 1048576 ).toFixed( 1 );
	}

	function show( done, total ) {
		bar.style.width = ( total ? Math.floor( done / total * 100 ) : 0 ) + '%';
		message.textContent = config.i18n.uploadProgress.replace( '%1$s', mb( done ) ).replace( '%2$s', mb( total ) );
	}

	function upload( file ) {
		var id = null;
		var chunk = 0;
		var offset = 0;
		var retries = 0;

		function next() {
			if ( offset >= file.size ) {
				message.textContent = config.i18n.uploadCheck;
				return post( 'esb_upload_finish', { id: id } );
			}

			return post( 'esb_upload_chunk', { id: id, offset: offset, chunk: file.slice( offset, offset + chunk ) } )
				.then(
					function ( result ) {
						retries = 0;
						offset = result.received;
						show( offset, file.size );
						return next();
					},
					function ( error ) {
						if ( retries++ >= MAX_RETRIES ) {
							throw error;
						}
						message.textContent = config.i18n.retry;
						// Та сама позиція: сервер перезапише частину, якщо вона встигла записатися.
						return wait( RETRY_DELAY ).then( next );
					}
				);
		}

		button.disabled = true;
		input.disabled = true;
		box.hidden = false;
		box.classList.remove( 'esb-failed' );
		bar.style.width = '0';
		message.textContent = config.i18n.uploadStart;

		post( 'esb_upload_start', { name: file.name, size: file.size } )
			.then( function ( result ) {
				id = result.id;
				chunk = result.chunk_size;
				show( 0, file.size );
				return next();
			} )
			.then( function () {
				bar.style.width = '100%';
				message.textContent = config.i18n.uploadDone;
				window.location.reload();
			} )
			.catch( function ( error ) {
				box.classList.add( 'esb-failed' );
				message.textContent = error.message;
				button.disabled = false;
				input.disabled = false;
			} );
	}

	button.addEventListener( 'click', function () {
		if ( ! input.files.length ) {
			window.alert( config.i18n.uploadSelect );
			return;
		}
		upload( input.files[0] );
	} );
}() );

/**
 * Розклад: день тижня потрібен лише для щотижневих копій.
 */
( function () {
	'use strict';

	var frequency = document.getElementById( 'esb-frequency' );
	var weekday = document.getElementById( 'esb-weekday' );

	if ( ! frequency || ! weekday ) {
		return;
	}

	frequency.addEventListener( 'change', function () {
		weekday.hidden = 'weekly' !== frequency.value;
	} );
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
