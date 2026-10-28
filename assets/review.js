/* global wp, taiReview */
/**
 * "Client approval": email a private preview link, see the client's decision.
 * A sidebar panel in the block editor, a meta box in the classic editor and page builders.
 */
( function ( wp, cfg ) {
	'use strict';

	const { createElement: h, Fragment, useState, useEffect } = wp.element;
	const { Button, TextControl, TextareaControl, Notice, Spinner, ExternalLink } = wp.components;
	const { __, sprintf } = wp.i18n;
	const apiFetch = wp.apiFetch;
	const TD = 'twenty-ai-articles';
	const Panel = ( wp.editor && wp.editor.PluginDocumentSettingPanel ) || ( wp.editPost && wp.editPost.PluginDocumentSettingPanel );

	const STATUS = {
		pending: { label: __( 'Waiting for the client', TD ), status: 'info' },
		approved: { label: __( 'Approved by the client', TD ), status: 'success' },
		changes: { label: __( 'Client requested changes', TD ), status: 'warning' },
	};

	const loadedAt = Date.now() / 1000; // AI changes saved after this are not in the editor yet.
	const fmtDate = ( ts ) => new Date( ts * 1000 ).toLocaleString( undefined, { dateStyle: 'medium', timeStyle: 'short' } );

	function ReviewPanel( { postId, isNew, isDirty, savePost, classic } ) {
		const [ data, setData ] = useState( null );
		const [ emails, setEmails ] = useState( '' );
		const [ message, setMessage ] = useState( '' );
		const [ busy, setBusy ] = useState( false );
		const [ notice, setNotice ] = useState( null );
		const [ copied, setCopied ] = useState( false );
		const [ revision, setRevision ] = useState( null );
		const [ manualOpen, setManualOpen ] = useState( false );
		const [ manual, setManual ] = useState( '' );
		const [ revBusy, setRevBusy ] = useState( false );
		const [ revError, setRevError ] = useState( '' );
		const [ dismissed, setDismissed ] = useState( 0 );

		const path = '/tai/v1/posts/' + postId + '/share';
		const revisePath = '/tai/v1/posts/' + postId + '/revise';
		const revising = !! revision && ( revision.state === 'queued' || revision.state === 'running' );

		useEffect( () => {
			if ( ! postId || isNew ) {
				return;
			}
			apiFetch( { path } )
				.then( ( res ) => {
					setData( res );
					setRevision( res.revision );
					setEmails( ( res.recipients && res.recipients.length ? res.recipients.join( ', ' ) : cfg.defaultEmail ) || '' );
				} )
				.catch( () => setData( false ) );
		}, [ postId, isNew ] );

		const send = async () => {
			setBusy( true );
			setNotice( null );
			try {
				// The client sees the saved version, so save pending edits first.
				if ( isDirty && savePost ) {
					await savePost();
				}
				const res = await apiFetch( { path, method: 'POST', data: { emails, message } } );
				setData( res );
				setNotice( {
					status: 'success',
					text: emails.trim()
						? sprintf( __( 'Preview link sent to %s.', TD ), emails.trim() )
						: __( 'Preview link created. Copy it below.', TD ),
				} );
				setMessage( '' );
			} catch ( err ) {
				setNotice( { status: 'error', text: err.message || __( 'Something went wrong.', TD ) } );
			}
			setBusy( false );
		};

		// Poll while the AI works, then refresh the history (applied markers).
		useEffect( () => {
			if ( ! revising ) {
				return;
			}
			const timer = setInterval( () => {
				apiFetch( { path: revisePath } )
					.then( ( res ) => {
						setRevision( res );
						if ( res && res.state !== 'queued' && res.state !== 'running' ) {
							apiFetch( { path } ).then( setData ).catch( () => {} );
						}
					} )
					.catch( () => {} );
			}, 4000 );
			return () => clearInterval( timer );
		}, [ revising, postId ] );

		const revise = async ( body ) => {
			setRevBusy( true );
			setRevError( '' );
			try {
				// The AI works on the saved version, so save pending edits first.
				if ( isDirty && savePost ) {
					await savePost();
				}
				setRevision( await apiFetch( { path: revisePath, method: 'POST', data: body } ) );
				setManual( '' );
				setManualOpen( false );
			} catch ( err ) {
				setRevError( err.message || __( 'Something went wrong.', TD ) );
			}
			setRevBusy( false );
		};

		const revoke = async () => {
			setBusy( true );
			try {
				setData( await apiFetch( { path, method: 'DELETE' } ) );
				setNotice( { status: 'info', text: __( 'The link no longer works.', TD ) } );
			} catch ( err ) {
				setNotice( { status: 'error', text: err.message } );
			}
			setBusy( false );
		};

		const copy = () => {
			const done = () => {
				setCopied( true );
				setTimeout( () => setCopied( false ), 2000 );
			};
			if ( window.navigator.clipboard ) {
				window.navigator.clipboard.writeText( data.link ).then( done, () => window.prompt( '', data.link ) ); // eslint-disable-line no-alert
			} else {
				window.prompt( '', data.link ); // eslint-disable-line no-alert
			}
		};

		if ( isNew ) {
			return h( 'p', null, __( 'Save the draft first to share it for approval.', TD ) );
		}
		if ( data === null ) {
			return h( Spinner );
		}
		if ( data === false ) {
			return h( 'p', null, __( 'Client approval is not available for this post.', TD ) );
		}
		if ( data.published ) {
			return h( 'p', null, __( 'This article is published, so the preview link is no longer needed.', TD ) );
		}

		function aiBox() {
			if ( ! data.ai ) {
				return null;
			}
			const out = [];
			if ( revError ) {
				out.push( h( Notice, { key: 'err', status: 'error', onRemove: () => setRevError( '' ) }, revError ) );
			}
			if ( revising ) {
				out.push(
					h(
						'div',
						{ key: 'run', className: 'tai-revise-run', style: { display: 'flex', gap: 8, alignItems: 'flex-start', padding: 10, marginBottom: 12, borderRadius: 4, background: '#f6f7f7' } },
						h( Spinner, { style: { margin: 0 } } ),
						h( 'div', { style: { fontSize: 13 } }, h( 'strong', null, __( 'AI is applying the changes…', TD ) ), h( 'div', { style: { fontSize: 12, color: '#646970', marginTop: 2 } }, __( 'Usually under a minute. Please do not edit the article until it is done.', TD ) ) )
					)
				);
			} else if ( revision && revision.state === 'failed' && dismissed !== revision.updated ) {
				out.push( h( Notice, { key: 'fail', status: 'error', onRemove: () => setDismissed( revision.updated ) }, sprintf( __( 'Applying the changes failed: %s', TD ), revision.error ) ) );
			} else if ( revision && revision.state === 'done' && dismissed !== revision.updated && Date.now() / 1000 - revision.updated < 86400 ) {
				out.push(
					h(
						Notice,
						{ key: 'done', status: revision.changed ? 'success' : 'warning', spokenMessage: revision.summary.join( '. ' ), onRemove: () => setDismissed( revision.updated ) },
						h( 'strong', null, revision.changed ? sprintf( __( 'AI made %d changes.', TD ), revision.changed ) : __( 'AI did not change the text.', TD ) ),
						revision.summary.length ? h( 'ul', { style: { margin: '6px 0 0 16px', listStyle: 'disc' } }, revision.summary.map( ( line, i ) => h( 'li', { key: i }, line ) ) ) : null,
						revision.skipped.length ? h( 'div', { style: { marginTop: 6 } }, h( 'em', null, __( 'Not changed:', TD ) ), h( 'ul', { style: { margin: '2px 0 0 16px', listStyle: 'disc' } }, revision.skipped.map( ( line, i ) => h( 'li', { key: i }, line ) ) ) ) : null,
						revision.changed
							? h(
								'div',
								{ style: { display: 'flex', gap: 8, marginTop: 8, flexWrap: 'wrap' } },
								revision.updated >= loadedAt ? h( Button, { variant: 'primary', size: 'small', onClick: () => window.location.reload() }, __( 'Reload to see changes', TD ) ) : null,
								revision.undo ? h( Button, { variant: 'tertiary', size: 'small', href: revision.undo }, __( 'Compare / undo', TD ) ) : null
							)
							: null
					)
				);
			}
			if ( ! revising ) {
				out.push(
					manualOpen
						? h(
							'div',
							{ key: 'manual', style: { marginBottom: 12 } },
							h( TextareaControl, {
								label: __( 'Client feedback', TD ),
								help: __( 'Paste what the client asked for, e.g. from their email. AI changes the text only, not images or layout.', TD ),
								value: manual,
								onChange: setManual,
								rows: 4,
								__nextHasNoMarginBottom: true,
							} ),
							h(
								'div',
								{ style: { display: 'flex', gap: 8, marginTop: 8 } },
								h( Button, { variant: 'primary', size: 'small', onClick: () => revise( { feedback: manual } ), isBusy: revBusy, disabled: revBusy || ! manual.trim() }, __( 'Apply with AI', TD ) ),
								h( Button, { variant: 'tertiary', size: 'small', onClick: () => setManualOpen( false ) }, __( 'Cancel', TD ) )
							),
							classic ? h( 'p', { className: 'description', style: { margin: '6px 0 0' } }, __( 'Save your changes first: the AI works on the last saved version.', TD ) ) : null
						)
						: h( 'p', { key: 'open', style: { margin: '12px 0' } }, h( Button, { variant: 'link', onClick: () => setManualOpen( true ) }, __( 'Got feedback by email? Apply it with AI', TD ) ) )
				);
			}
			return h( 'div', { className: 'tai-revise', style: { borderBottom: '1px solid #e0e0e0', marginBottom: 16 } }, out );
		}

		const state = STATUS[ data.status ];
		const last = data.last;

		return h(
			Fragment,
			null,
			state
				? h(
					'div',
					{ className: 'tai-review-status', style: { marginBottom: 12 } },
					h(
						Notice,
						// A plain spoken message: Notice renders its children to a string otherwise, which breaks on buttons.
						{ status: state.status, isDismissible: false, spokenMessage: state.label },
						h( 'strong', null, state.label ),
						last && data.status !== 'pending' ? h( 'div', { style: { fontSize: 12, opacity: 0.8 } }, [ last.name, fmtDate( last.time ) ].filter( Boolean ).join( ' · ' ) ) : null,
						last && last.comment && data.status !== 'pending' ? h( 'p', { style: { margin: '6px 0 0', whiteSpace: 'pre-wrap' } }, last.comment ) : null,
						data.editedSince && data.status !== 'pending' && ! ( last && last.applied ) ? h( 'p', { style: { margin: '6px 0 0', fontSize: 12 } }, __( 'You have edited the article since then.', TD ) ) : null,
						last && last.applied && data.status === 'changes' ? h( 'p', { style: { margin: '6px 0 0', fontSize: 12 } }, '✓ ' + __( 'Applied by AI', TD ) ) : null,
						data.status === 'changes' && last && last.comment && ! last.applied && data.ai && ! revising
							? h( Button, { variant: 'secondary', size: 'small', style: { marginTop: 8 }, onClick: () => revise( { use_last: true } ), isBusy: revBusy, disabled: revBusy }, __( 'Apply these changes with AI', TD ) )
							: null
					)
				)
				: null,
			aiBox(),
			notice ? h( Notice, { status: notice.status, onRemove: () => setNotice( null ) }, notice.text ) : null,
			h( TextControl, {
				label: __( 'Client email', TD ),
				help: __( 'Separate several addresses with commas. Leave empty to only create a link.', TD ),
				type: 'text',
				value: emails,
				onChange: setEmails,
				placeholder: 'client@example.com',
				__nextHasNoMarginBottom: true,
			} ),
			h( 'div', { style: { height: 12 } } ),
			h( TextareaControl, {
				label: __( 'Message (optional)', TD ),
				value: message,
				onChange: setMessage,
				rows: 3,
				placeholder: __( 'A short personal note. A friendly default is used when empty.', TD ),
				__nextHasNoMarginBottom: true,
			} ),
			classic ? h( 'p', { className: 'description', style: { margin: '8px 0 0' } }, __( 'The client sees the last saved version, so save your changes first.', TD ) ) : null,
			h(
				'div',
				{ style: { display: 'flex', gap: 8, margin: '12px 0' } },
				h( Button, { variant: 'primary', onClick: send, isBusy: busy, disabled: busy }, emails.trim() ? ( data.sent ? __( 'Send again', TD ) : __( 'Send for approval', TD ) ) : __( 'Create link', TD ) )
			),
			data.link
				? h(
					'div',
					{ style: { padding: 10, borderRadius: 4, background: '#f6f7f7' } },
					h( 'div', { style: { wordBreak: 'break-all', fontSize: 12, marginBottom: 6 } }, h( ExternalLink, { href: data.link }, __( 'Open preview', TD ) ) ),
					h( 'div', { style: { fontSize: 12, color: '#646970', marginBottom: 8 } }, sprintf( __( 'Works without login until %s.', TD ), fmtDate( data.expires ) ) ),
					h(
						'div',
						{ style: { display: 'flex', gap: 8 } },
						h( Button, { variant: 'secondary', size: 'small', onClick: copy }, copied ? __( 'Copied!', TD ) : __( 'Copy link', TD ) ),
						h( Button, { variant: 'tertiary', size: 'small', isDestructive: true, onClick: revoke, disabled: busy }, __( 'Withdraw link', TD ) )
					)
				)
				: null,
			data.history && data.history.length > 1
				? h(
					'details',
					{ style: { marginTop: 12 } },
					h( 'summary', null, __( 'Earlier feedback', TD ) ),
					h(
						'ul',
						{ style: { margin: '8px 0 0' } },
						data.history.slice( 1 ).map( ( e, i ) =>
							h( 'li', { key: i, style: { marginBottom: 8 } }, h( 'strong', null, e.decision === 'approved' ? __( 'Approved', TD ) : __( 'Changes requested', TD ) ), ' · ', fmtDate( e.time ), e.source === 'manual' ? ' · ' + __( 'added manually', TD ) : '', e.applied ? ' · ✓ ' + __( 'applied by AI', TD ) : '', e.comment ? h( 'div', { style: { whiteSpace: 'pre-wrap' } }, e.comment ) : null )
						)
					)
				)
				: null
		);
	}

	function EditorReviewPanel() {
		const props = wp.data.useSelect( ( select ) => {
			const editor = select( 'core/editor' );
			return {
				postId: editor.getCurrentPostId(),
				isNew: editor.isEditedPostNew(),
				isDirty: editor.isEditedPostDirty(),
			};
		}, [] );
		const { savePost } = wp.data.useDispatch( 'core/editor' );
		return h( ReviewPanel, { ...props, savePost } );
	}

	if ( Panel && wp.plugins ) {
		wp.plugins.registerPlugin( 'tai-review', {
			render: () => h( Panel, { name: 'tai-review', title: __( 'Client approval', TD ), className: 'tai-review-panel' }, h( EditorReviewPanel ) ),
		} );
	}

	// Classic editor / page builders: meta box.
	const mount = () => {
		document.querySelectorAll( '.tai-review-classic' ).forEach( ( el ) => {
			const panel = h( ReviewPanel, { postId: Number( el.dataset.postId ), isNew: el.dataset.new === '1', isDirty: false, classic: true } );
			if ( wp.element.createRoot ) {
				wp.element.createRoot( el ).render( panel );
			} else {
				wp.element.render( panel, el );
			}
		} );
	};
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', mount );
	} else {
		mount();
	}
} )( window.wp, window.taiReview || {} );
