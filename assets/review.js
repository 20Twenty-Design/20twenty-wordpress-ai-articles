/* global wp, taiReview */
/**
 * Block editor panel "Client approval": email a private preview link, see the client's decision.
 */
( function ( wp, cfg ) {
	'use strict';

	const { createElement: h, Fragment, useState, useEffect } = wp.element;
	const { Button, TextControl, TextareaControl, Notice, Spinner, ExternalLink } = wp.components;
	const { useSelect, useDispatch } = wp.data;
	const { __, sprintf } = wp.i18n;
	const apiFetch = wp.apiFetch;
	const TD = 'twenty-ai-articles';
	const Panel = ( wp.editor && wp.editor.PluginDocumentSettingPanel ) || ( wp.editPost && wp.editPost.PluginDocumentSettingPanel );

	const STATUS = {
		pending: { label: __( 'Waiting for the client', TD ), status: 'info' },
		approved: { label: __( 'Approved by the client', TD ), status: 'success' },
		changes: { label: __( 'Client requested changes', TD ), status: 'warning' },
	};

	const fmtDate = ( ts ) => new Date( ts * 1000 ).toLocaleString( undefined, { dateStyle: 'medium', timeStyle: 'short' } );

	function ReviewPanel() {
		const { postId, isNew, isDirty } = useSelect( ( select ) => {
			const editor = select( 'core/editor' );
			return {
				postId: editor.getCurrentPostId(),
				isNew: editor.isEditedPostNew(),
				isDirty: editor.isEditedPostDirty(),
			};
		}, [] );
		const { savePost } = useDispatch( 'core/editor' );

		const [ data, setData ] = useState( null );
		const [ emails, setEmails ] = useState( '' );
		const [ message, setMessage ] = useState( '' );
		const [ busy, setBusy ] = useState( false );
		const [ notice, setNotice ] = useState( null );
		const [ copied, setCopied ] = useState( false );

		const path = '/tai/v1/posts/' + postId + '/share';

		useEffect( () => {
			if ( ! postId || isNew ) {
				return;
			}
			apiFetch( { path } )
				.then( ( res ) => {
					setData( res );
					setEmails( ( res.recipients && res.recipients.length ? res.recipients.join( ', ' ) : cfg.defaultEmail ) || '' );
				} )
				.catch( () => setData( false ) );
		}, [ postId, isNew ] );

		const send = async () => {
			setBusy( true );
			setNotice( null );
			try {
				// The client sees the saved version, so save pending edits first.
				if ( isDirty ) {
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
						{ status: state.status, isDismissible: false },
						h( 'strong', null, state.label ),
						last && data.status !== 'pending' ? h( 'div', { style: { fontSize: 12, opacity: 0.8 } }, [ last.name, fmtDate( last.time ) ].filter( Boolean ).join( ' · ' ) ) : null,
						last && last.comment && data.status !== 'pending' ? h( 'p', { style: { margin: '6px 0 0', whiteSpace: 'pre-wrap' } }, last.comment ) : null,
						data.editedSince && data.status !== 'pending' ? h( 'p', { style: { margin: '6px 0 0', fontSize: 12 } }, __( 'You have edited the article since then.', TD ) ) : null
					)
				)
				: null,
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
							h( 'li', { key: i, style: { marginBottom: 8 } }, h( 'strong', null, e.decision === 'approved' ? __( 'Approved', TD ) : __( 'Changes requested', TD ) ), ' · ', fmtDate( e.time ), e.comment ? h( 'div', { style: { whiteSpace: 'pre-wrap' } }, e.comment ) : null )
						)
					)
				)
				: null
		);
	}

	if ( Panel ) {
		wp.plugins.registerPlugin( 'tai-review', {
			render: () => h( Panel, { name: 'tai-review', title: __( 'Client approval', TD ), className: 'tai-review-panel' }, h( ReviewPanel ) ),
		} );
	}
} )( window.wp, window.taiReview || {} );
