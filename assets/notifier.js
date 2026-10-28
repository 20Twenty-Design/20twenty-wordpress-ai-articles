/* global jQuery, wp, taiNotifier */
/**
 * Background job notifier, loaded on every admin screen.
 *
 * - Admin bar item with live progress and a jobs panel.
 * - Toasts (and optional desktop notifications) when a draft is ready or a job failed.
 * - Polls every few seconds while a job runs; otherwise piggybacks on the WP Heartbeat.
 * - Exposes window.taiJobs for the generator screen.
 */
( function ( $, wp, cfg ) {
	'use strict';

	if ( ! cfg ) {
		return;
	}

	const { __, sprintf } = wp.i18n;
	const TD = 'twenty-ai-articles';
	const POLL_MS = 4000;
	const PING_KEY = 'tai-jobs-ping';

	const jobs = new Map();
	const listeners = new Set();
	const toasted = new Set();
	let pollTimer = null;
	let baseTitle = document.title;

	const isActive = ( job ) => job.state === 'queued' || job.state === 'running';
	const isFinished = ( job ) => job.state === 'done' || job.state === 'failed';

	const el = ( tag, attrs, children ) => {
		const node = document.createElement( tag );
		Object.entries( attrs || {} ).forEach( ( [ key, value ] ) => {
			if ( value === null || value === undefined || value === false ) {
				return;
			}
			if ( key === 'className' ) {
				node.className = value;
			} else if ( key.startsWith( 'on' ) ) {
				node.addEventListener( key.slice( 2 ).toLowerCase(), value );
			} else {
				node.setAttribute( key, value );
			}
		} );
		( Array.isArray( children ) ? children : [ children ] ).forEach( ( child ) => {
			if ( child === null || child === undefined || child === false ) {
				return;
			}
			node.appendChild( typeof child === 'string' ? document.createTextNode( child ) : child );
		} );
		return node;
	};

	/* ------------------------------------------------------------------ *
	 * State
	 * ------------------------------------------------------------------ */

	function update( list, { initial = false } = {} ) {
		const seenIds = new Set();
		list.forEach( ( job ) => {
			seenIds.add( job.id );
			const prev = jobs.get( job.id );
			jobs.set( job.id, job );
			const justFinished = prev && isActive( prev ) && isFinished( job );
			if ( justFinished ) {
				notify( job, true );
			} else if ( initial && isFinished( job ) && ! job.seen ) {
				notify( job, false );
			}
		} );
		// Jobs that dropped out of the server list (cleaned up) are removed.
		Array.from( jobs.keys() ).forEach( ( id ) => {
			if ( ! seenIds.has( id ) ) {
				jobs.delete( id );
			}
		} );
		render();
		schedulePoll();
		listeners.forEach( ( fn ) => fn( getAll() ) );
	}

	function upsert( job ) {
		const list = getAll().filter( ( j ) => j.id !== job.id );
		list.unshift( job );
		update( list );
		try {
			window.localStorage.setItem( PING_KEY, String( Date.now() ) );
		} catch ( e ) {}
	}

	function getAll() {
		return Array.from( jobs.values() ).sort( ( a, b ) => b.created - a.created || b.id - a.id );
	}

	function refresh() {
		return wp.apiFetch( { path: '/tai/v1/jobs' } ).then( ( list ) => update( list ) ).catch( () => {} );
	}

	function schedulePoll() {
		clearTimeout( pollTimer );
		if ( getAll().some( isActive ) ) {
			pollTimer = setTimeout( refresh, POLL_MS );
		}
	}

	function action( id, name ) {
		return wp.apiFetch( { path: '/tai/v1/jobs/' + id + '/' + name, method: 'POST' } ).then( ( res ) => {
			if ( res && res.id ) {
				upsert( res );
			}
			return res;
		} );
	}

	// Update the UI immediately, then sync with the server response (or roll back on error).
	function optimistic( id, patch, name ) {
		const before = jobs.get( id );
		if ( before ) {
			upsert( { ...before, ...patch } );
		}
		return action( id, name ).catch( ( err ) => {
			if ( before ) {
				upsert( before );
			}
			throw err;
		} );
	}

	// Remove finished jobs from the list right away; put them back if the server refuses.
	function removeJobs( ids, request ) {
		const before = ids.map( ( id ) => jobs.get( id ) ).filter( Boolean );
		ids.forEach( ( id ) => jobs.delete( id ) );
		update( getAll() );
		return request.catch( ( err ) => {
			before.forEach( ( job ) => jobs.set( job.id, job ) );
			update( getAll() );
			throw err;
		} );
	}

	function markSeen( id ) {
		const job = jobs.get( id );
		if ( job ) {
			job.seen = true;
			render();
			listeners.forEach( ( fn ) => fn( getAll() ) );
		}
		return wp.apiFetch( { path: '/tai/v1/jobs/' + id + '/seen', method: 'POST' } ).catch( () => {} );
	}

	/* ------------------------------------------------------------------ *
	 * Notifications
	 * ------------------------------------------------------------------ */

	function notify( job, live ) {
		if ( toasted.has( job.id ) ) {
			return;
		}
		toasted.add( job.id );
		toast( job );
		// Shown once: it will not pop up again on the next screen (the jobs panel still lists it).
		wp.apiFetch( { path: '/tai/v1/jobs/' + job.id + '/seen', method: 'POST' } ).catch( () => {} );
		if ( live && document.hidden && 'Notification' in window && window.Notification.permission === 'granted' ) {
			const n = new window.Notification(
				job.state === 'done' ? __( 'Your AI draft is ready', TD ) : __( 'AI article generation failed', TD ),
				{ body: job.title, tag: 'tai-job-' + job.id }
			);
			n.onclick = () => {
				window.focus();
				if ( job.state === 'done' && job.result ) {
					markSeen( job.id );
					window.location.href = job.result.edit_url;
				}
				n.close();
			};
		}
	}

	let toastRoot = null;
	/**
	 * In-WordPress notification at the top of the screen. The block editor gets a native editor notice
	 * (shown above the canvas); every other admin screen gets a banner pinned below the admin bar.
	 */
	function toast( job ) {
		const done = job.state === 'done';
		const title = done ? __( 'Your AI draft is ready', TD ) : __( 'AI article generation failed', TD );

		const notices = wp.data && wp.data.dispatch && document.body.classList.contains( 'block-editor-page' ) ? wp.data.dispatch( 'core/notices' ) : null;
		if ( notices && notices.createNotice ) {
			notices.createNotice( done ? 'success' : 'error', title + ': ' + job.title + ( ! done && job.error ? ' — ' + job.error : '' ), {
				id: 'tai-job-' + job.id,
				isDismissible: true,
				actions: done
					? [
						{ label: __( 'Edit draft', TD ), url: job.result.edit_url },
						{ label: __( 'Preview', TD ), url: job.result.preview_url },
					]
					: [ { label: __( 'Retry', TD ), onClick: () => window.taiJobs.retry( job.id ) } ],
			} );
			return;
		}

		if ( ! toastRoot ) {
			toastRoot = el( 'div', { className: 'tai-banners', 'aria-live': 'polite' } );
			document.body.appendChild( toastRoot );
		}
		const close = () => {
			node.classList.add( 'is-leaving' );
			setTimeout( () => node.remove(), 250 );
		};

		const actions = done
			? [
				el( 'a', { className: 'tai-banner__btn is-primary', href: job.result.edit_url }, __( 'Edit draft', TD ) ),
				el( 'a', { className: 'tai-banner__btn', href: job.result.preview_url, target: '_blank', rel: 'noopener' }, __( 'Preview', TD ) ),
			]
			: [
				el( 'button', { type: 'button', className: 'tai-banner__btn is-primary', onClick: () => { window.taiJobs.retry( job.id ); close(); } }, __( 'Retry', TD ) ),
				el( 'a', { className: 'tai-banner__btn', href: job.generator }, __( 'Details', TD ) ),
			];

		const node = el( 'div', { className: 'tai-banner ' + ( done ? 'is-success' : 'is-error' ), role: done ? 'status' : 'alert' }, [
			el( 'span', { className: 'tai-banner__badge', 'aria-hidden': 'true' }, '20' ),
			el( 'div', { className: 'tai-banner__body' }, [
				el( 'strong', { className: 'tai-banner__title' }, title ),
				el( 'div', { className: 'tai-banner__text' }, job.title ),
				! done && job.error ? el( 'div', { className: 'tai-banner__error' }, job.error ) : null,
				done && job.warnings && job.warnings.length
					? el( 'div', { className: 'tai-banner__meta' }, sprintf( /* translators: %d: number of notes. */ __( '%d note(s) to review in the editor', TD ), job.warnings.length ) )
					: null,
			] ),
			el( 'div', { className: 'tai-banner__actions' }, actions ),
			el( 'button', { type: 'button', className: 'tai-banner__close', 'aria-label': __( 'Dismiss', TD ), onClick: close }, '×' ),
		] );

		toastRoot.appendChild( node );
		requestAnimationFrame( () => node.classList.add( 'is-visible' ) );
	}

	function requestPermission() {
		if ( ! ( 'Notification' in window ) || window.Notification.permission !== 'default' ) {
			return Promise.resolve( window.Notification ? window.Notification.permission : 'denied' );
		}
		return window.Notification.requestPermission();
	}

	/* ------------------------------------------------------------------ *
	 * Admin bar + panel
	 * ------------------------------------------------------------------ */

	let panel = null;

	function render() {
		const all = getAll();
		const active = all.filter( isActive );
		const unread = all.filter( ( j ) => isFinished( j ) && ! j.seen );
		const node = document.getElementById( 'wp-admin-bar-tai-jobs' );

		if ( active.length ) {
			const avg = Math.round( active.reduce( ( sum, j ) => sum + j.progress, 0 ) / active.length );
			document.title = '(' + avg + '%) ' + baseTitle;
		} else if ( document.title !== baseTitle && /^\(\d+%\) /.test( document.title ) ) {
			document.title = baseTitle;
		}

		if ( node ) {
			const label = node.querySelector( '.ab-label' );
			node.classList.toggle( 'is-idle', ! active.length && ! unread.length );
			node.classList.toggle( 'is-running', !! active.length );
			node.classList.toggle( 'has-unread', ! active.length && !! unread.length );
			if ( active.length ) {
				const avg = Math.round( active.reduce( ( sum, j ) => sum + j.progress, 0 ) / active.length );
				label.textContent = sprintf( /* translators: %d: percent. */ __( 'AI writing… %d%%', TD ), avg );
			} else if ( unread.length ) {
				label.textContent = sprintf( /* translators: %d: number of drafts. */ __( 'AI drafts ready: %d', TD ), unread.filter( ( j ) => j.state === 'done' ).length );
				if ( unread.every( ( j ) => j.state === 'failed' ) ) {
					label.textContent = __( 'AI generation failed', TD );
				}
			} else {
				label.textContent = __( 'AI Articles', TD );
			}
		}
		if ( panel && panel.classList.contains( 'is-open' ) ) {
			renderPanel();
		}
	}

	function stateText( job ) {
		switch ( job.state ) {
			case 'queued':
			case 'running':
				return job.stalled ? __( 'Waiting for the server…', TD ) : job.label;
			case 'done':
				return __( 'Draft ready', TD );
			case 'failed':
				return job.error;
			case 'cancelled':
				return __( 'Cancelled', TD );
		}
		return '';
	}

	function renderPanel() {
		panel.innerHTML = '';
		const all = getAll().slice( 0, 6 );
		panel.appendChild( el( 'div', { className: 'tai-panel__head' }, [
			el( 'strong', null, __( 'AI Articles', TD ) ),
			el( 'div', { className: 'tai-panel__new' }, cfg.generators.map( ( g ) =>
				el( 'a', { href: g.url }, sprintf( /* translators: %s: post type name. */ __( '+ New %s', TD ), g.label ) )
			) ),
		] ) );
		if ( ! all.length ) {
			panel.appendChild( el( 'p', { className: 'tai-panel__empty' }, __( 'No recent generations.', TD ) ) );
			return;
		}
		panel.appendChild( el( 'ul', { className: 'tai-panel__list' }, all.map( ( job ) =>
			el( 'li', { className: 'tai-panel__item is-' + job.state }, [
				el( 'div', { className: 'tai-panel__title' }, job.state === 'done' && job.result
					? el( 'a', { href: job.result.edit_url, onClick: () => markSeen( job.id ) }, job.title )
					: job.title ),
				isActive( job ) ? el( 'div', { className: 'tai-mini-bar' }, el( 'span', { style: 'width:' + Math.max( 4, job.progress ) + '%' } ) ) : null,
				el( 'div', { className: 'tai-panel__state' }, stateText( job ) ),
			] )
		) ) );
	}

	function togglePanel( e ) {
		e.preventDefault();
		if ( ! panel ) {
			panel = el( 'div', { className: 'tai-panel', role: 'dialog', 'aria-label': __( 'AI Articles', TD ) } );
			document.body.appendChild( panel );
			document.addEventListener( 'click', ( ev ) => {
				if ( panel.classList.contains( 'is-open' ) && ! panel.contains( ev.target ) && ! ev.target.closest( '#wp-admin-bar-tai-jobs' ) ) {
					panel.classList.remove( 'is-open' );
				}
			} );
		}
		const open = ! panel.classList.contains( 'is-open' );
		panel.classList.toggle( 'is-open', open );
		if ( open ) {
			renderPanel();
		}
	}

	/* ------------------------------------------------------------------ *
	 * Boot
	 * ------------------------------------------------------------------ */

	window.taiJobs = {
		getAll,
		upsert,
		refresh,
		retry: ( id ) => optimistic( id, { state: 'queued', error: '', label: __( 'Restarting…', TD ) }, 'retry' ),
		cancel: ( id ) => optimistic( id, { state: 'cancelled', label: '' }, 'cancel' ),
		seen: markSeen,
		remove: ( id ) => removeJobs( [ id ], wp.apiFetch( { path: '/tai/v1/jobs/' + id + '/remove', method: 'POST' } ) ),
		clear: () =>
			removeJobs(
				getAll().filter( ( j ) => ! isActive( j ) ).map( ( j ) => j.id ),
				wp.apiFetch( { path: '/tai/v1/jobs/clear', method: 'POST' } )
			),
		requestPermission,
		subscribe( fn ) {
			listeners.add( fn );
			fn( getAll() );
			return () => listeners.delete( fn );
		},
	};

	$( function () {
		baseTitle = document.title;
		const bar = document.querySelector( '#wp-admin-bar-tai-jobs > .ab-item' );
		if ( bar ) {
			bar.addEventListener( 'click', togglePanel );
		}
		update( cfg.jobs || [], { initial: true } );
	} );

	// Heartbeat keeps idle screens informed (e.g. a job started in another tab).
	$( document ).on( 'heartbeat-send', ( e, data ) => {
		data.tai_jobs = 1;
	} );
	$( document ).on( 'heartbeat-tick', ( e, data ) => {
		if ( data && Array.isArray( data.tai_jobs ) ) {
			update( data.tai_jobs );
		}
	} );
	window.addEventListener( 'storage', ( e ) => {
		if ( e.key === PING_KEY ) {
			refresh();
		}
	} );
} )( jQuery, window.wp, window.taiNotifier );
