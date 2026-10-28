/* global wp, taiData */
( function ( wp, cfg ) {
	'use strict';

	const mount = document.getElementById( 'tai-app' );
	if ( ! cfg || ! mount ) {
		return;
	}

	const { createElement: h, useState, useEffect, useMemo, useRef, Fragment } = wp.element;
	const C = wp.components;
	const { Button, TextControl, TextareaControl, SelectControl, CheckboxControl, Notice, Spinner, ExternalLink } = C;
	const { __, sprintf, _n } = wp.i18n;
	const apiFetch = wp.apiFetch;
	const TD = 'twenty-ai-articles';
	const PREFS_KEY = 'tai-prefs-' + cfg.postType;
	const field = { __nextHasNoMarginBottom: true, __next40pxDefaultSize: true };

	/* ------------------------------------------------------------------ *
	 * Helpers
	 * ------------------------------------------------------------------ */

	const loadPrefs = () => {
		try {
			return JSON.parse( window.localStorage.getItem( PREFS_KEY ) ) || {};
		} catch ( e ) {
			return {};
		}
	};
	const savePrefs = ( form ) => {
		try {
			const { length, cta, links_mode, image_mode, copy_acf } = form;
			window.localStorage.setItem( PREFS_KEY, JSON.stringify( { length, cta, links_mode, image_mode, copy_acf } ) );
		} catch ( e ) {}
	};

	const timeAgo = ( ts ) => {
		const s = Math.max( 0, Math.floor( Date.now() / 1000 - ts ) );
		if ( s < 60 ) {
			return __( 'just now', TD );
		}
		if ( s < 3600 ) {
			/* translators: %d: minutes. */
			return sprintf( _n( '%d min ago', '%d min ago', Math.floor( s / 60 ), TD ), Math.floor( s / 60 ) );
		}
		if ( s < 86400 ) {
			/* translators: %d: hours. */
			return sprintf( _n( '%d hour ago', '%d hours ago', Math.floor( s / 3600 ), TD ), Math.floor( s / 3600 ) );
		}
		/* translators: %d: days. */
		return sprintf( _n( '%d day ago', '%d days ago', Math.floor( s / 86400 ), TD ), Math.floor( s / 86400 ) );
	};

	// Brand icons from assets/icons, tinted with currentColor via CSS mask.
	const Svg = ( { name, className } ) =>
		h( 'span', { className: 'tai-ico' + ( className ? ' ' + className : '' ), style: { '--tai-ico': 'url(' + cfg.iconsUrl + name + '.svg)' }, 'aria-hidden': true } );

	/* ------------------------------------------------------------------ *
	 * Building blocks
	 * ------------------------------------------------------------------ */

	function Step( { n, title, help, children, aside } ) {
		return h(
			'section',
			{ className: 'tai-card' },
			h(
				'header',
				{ className: 'tai-card__head' },
				h( 'span', { className: 'tai-card__num' }, String( n ).padStart( 2, '0' ) ),
				h( 'div', { className: 'tai-card__titles' }, h( 'h2', null, title ), help ? h( 'p', null, help ) : null ),
				aside || null
			),
			h( 'div', { className: 'tai-card__body' }, children )
		);
	}

	function Tiles( { value, onChange, options, label } ) {
		return h(
			'div',
			{ className: 'tai-tiles', role: 'radiogroup', 'aria-label': label },
			options.map( ( o ) =>
				h(
					'button',
					{
						key: o.value,
						type: 'button',
						role: 'radio',
						'aria-checked': value === o.value,
						disabled: !! o.disabled,
						className: 'tai-tile' + ( value === o.value ? ' is-selected' : '' ),
						onClick: () => onChange( o.value ),
						title: o.disabledReason || undefined,
					},
					h( Svg, { name: o.icon, className: 'tai-tile__icon' } ),
					h( 'span', { className: 'tai-tile__label' }, o.label ),
					o.hint ? h( 'span', { className: 'tai-tile__hint' }, o.hint ) : null
				)
			)
		);
	}

	function LengthControl( { value, onChange } ) {
		const words = ( cfg.lengths.find( ( l ) => l.value === value ) || {} ).words || 0;
		return h(
			'div',
			null,
			h( 'p', { className: 'tai-label' }, __( 'Length', TD ) ),
			h(
				'div',
				{ className: 'tai-tabs', role: 'radiogroup', 'aria-label': __( 'Length', TD ) },
				cfg.lengths.map( ( l ) =>
					h(
						'button',
						{ key: l.value, type: 'button', role: 'radio', 'aria-checked': value === l.value, className: 'tai-tab' + ( value === l.value ? ' is-selected' : '' ), onClick: () => onChange( l.value ) },
						l.label
					)
				)
			),
			h(
				'p',
				{ className: 'tai-help' },
				value === 'auto'
					/* translators: %d: word count. */
					? sprintf( __( 'About %d words — the average of your reference articles.', TD ), words )
					/* translators: %d: word count. */
					: sprintf( __( 'About %d words.', TD ), words )
			)
		);
	}

	function ImagePicker( { value, onChange } ) {
		const [ preview, setPreview ] = useState( null );
		const open = () => {
			const frame = wp.media( {
				title: __( 'Select featured image', TD ),
				library: { type: 'image' },
				multiple: false,
				button: { text: __( 'Use this image', TD ) },
			} );
			frame.on( 'select', () => {
				const a = frame.state().get( 'selection' ).first().toJSON();
				onChange( a.id );
				setPreview( a.sizes && a.sizes.medium ? a.sizes.medium.url : a.url );
			} );
			frame.open();
		};

		return h(
			'div',
			{ className: 'tai-dropzone' + ( preview ? ' has-image' : '' ) },
			preview
				? h( 'img', { src: preview, alt: '' } )
				: h( Svg, { name: 'upload', className: 'tai-dropzone__icon' } ),
			h(
				'div',
				{ className: 'tai-dropzone__text' },
				h( 'strong', null, preview ? __( 'Image selected', TD ) : __( 'Upload or choose an image', TD ) ),
				h( 'span', null, __( 'Saved as a WebP copy (80% quality) with AI-written alt text. Your original stays untouched.', TD ) ),
				h(
					'div',
					{ className: 'tai-row' },
					h( Button, { variant: 'secondary', onClick: open }, value ? __( 'Replace', TD ) : __( 'Open Media Library', TD ) ),
					value ? h( Button, { variant: 'tertiary', onClick: () => { onChange( 0 ); setPreview( null ); } }, __( 'Remove', TD ) ) : null
				)
			)
		);
	}

	function LinkPicker( { selected, onChange } ) {
		const [ query, setQuery ] = useState( '' );
		const byId = useMemo( () => new Map( cfg.linkTargets.map( ( t ) => [ t.id, t ] ) ), [] );
		const items = useMemo( () => {
			const q = query.trim().toLowerCase();
			return cfg.linkTargets.filter( ( t ) => ! q || t.title.toLowerCase().includes( q ) ).slice( 0, 80 );
		}, [ query ] );
		const toggle = ( id ) => onChange( selected.includes( id ) ? selected.filter( ( x ) => x !== id ) : [ ...selected, id ] );

		return h(
			'div',
			{ className: 'tai-links' },
			selected.length
				? h(
					'div',
					{ className: 'tai-chips' },
					selected.map( ( id ) =>
						h(
							'span',
							{ key: id, className: 'tai-chip' },
							( byId.get( id ) || {} ).title || '#' + id,
							h( 'button', { type: 'button', 'aria-label': __( 'Remove', TD ), onClick: () => toggle( id ) }, '×' )
						)
					)
				)
				: null,
			h( TextControl, { ...field, label: __( 'Find pages and articles', TD ), hideLabelFromVision: true, placeholder: __( 'Search pages and articles…', TD ), value: query, onChange: setQuery } ),
			h(
				'ul',
				{ className: 'tai-links__list' },
				items.length
					? items.map( ( t ) =>
						h(
							'li',
							{ key: t.id },
							h(
								'button',
								{ type: 'button', className: selected.includes( t.id ) ? 'is-selected' : '', onClick: () => toggle( t.id ) },
								h( 'span', { className: 'tai-links__box', 'aria-hidden': true } ),
								h( 'span', { className: 'tai-links__title' }, t.title ),
								h( 'span', { className: 'tai-links__type' }, t.type )
							)
						)
					)
					: h( 'li', { className: 'tai-links__empty' }, __( 'No matches.', TD ) )
			)
		);
	}

	/* ------------------------------------------------------------------ *
	 * Estimate (tokens, cost, time) - defaults first, then learned from real generations
	 * ------------------------------------------------------------------ */

	const CHARS_PER_TOKEN = 3.6;
	const STEP_OVERHEAD = 1.5; // Seconds to hand a step to the next background request.

	function estimate( form, words ) {
		const e = cfg.estimate;
		const st = e.stages || {};
		const tok = ( chars ) => chars / CHARS_PER_TOKEN;
		const get = ( stage, key, fallback ) => ( st[ stage ] && st[ stage ][ key ] ) || fallback;
		const system = 500 + e.brandChars;
		const extra = ( form.instructions || '' ).length;
		const style = get( 'analyze', 'out', 450 ) * CHARS_PER_TOKEN; // The style guide is pasted into later prompts.
		const steps = [];

		const cached = ! e.hasRefs || ( e.styleCached && ! form.refresh_style );
		steps.push(
			cached
				? { key: 'analyze', label: __( 'Tone of voice', TD ), in: 0, out: 0, sec: get( 'analyze', 'sec_cached', 1 ), note: e.hasRefs ? __( 'saved profile', TD ) : __( 'no reference articles', TD ) }
				: { key: 'analyze', label: __( 'Tone of voice', TD ), in: tok( system + 1400 + e.refChars ), out: get( 'analyze', 'out', 450 ), sec: get( 'analyze', 'sec', 12 ) }
		);
		steps.push( {
			key: 'plan',
			label: __( 'Plan', TD ),
			in: tok( system + 2600 + style + extra + ( form.category ? 0 : e.catChars ) ),
			out: get( 'plan', 'out', 800 ),
			sec: get( 'plan', 'sec', 10 ),
		} );

		const links = { ai: e.linkChars, selected: form.link_ids.length * 80, none: 0 }[ form.links_mode ] || 0;
		const writeOut = get( 'write', 'out_per_word', 0 ) ? st.write.out_per_word * words : words * ( e.english ? 1.35 : 1.9 ) * 1.15 + ( e.faq ? 350 : 0 );
		steps.push( {
			key: 'write',
			label: __( 'Writing', TD ),
			in: tok( system + 3800 + style + 1400 + links + extra ),
			out: writeOut,
			sec: get( 'write', 'sec_per_out', 0 ) ? writeOut * st.write.sec_per_out : 6 + writeOut / 55,
		} );

		if ( form.image_mode === 'generate' ) {
			steps.push( { key: 'image', label: __( 'AI image', TD ), in: 0, out: 0, images: 1, sec: get( 'image', 'sec', 30 ) } );
		} else if ( form.image_mode === 'upload' ) {
			steps.push( { key: 'image', label: __( 'Image to WebP', TD ), in: 0, out: 0, sec: get( 'image', 'sec_upload', 3 ) } );
		}
		steps.push( { key: 'create', label: __( 'Draft', TD ), in: 0, out: 0, sec: get( 'create', 'sec', 2 ) } );
		if ( form.copy_acf && cfg.acf.active ) {
			steps.push( { key: 'acf', label: __( 'ACF', TD ), in: 0, out: 0, sec: get( 'acf', 'sec', 1 ) } );
		}
		steps.push( { key: 'seo', label: __( 'SEO', TD ), in: 0, out: 0, sec: get( 'seo', 'sec', 2 ) } );

		const tp = e.textPrice;
		const ip = e.imagePrice;
		let cost = 0;
		let costKnown = true;
		steps.forEach( ( step ) => {
			step.sec += STEP_OVERHEAD;
			step.tokens = Math.round( step.in + step.out );
			if ( step.tokens ) {
				if ( tp ) {
					cost += ( step.in * tp.in + step.out * tp.out ) / 1e6;
				} else {
					costKnown = false;
				}
			}
			if ( step.images ) {
				if ( ip ) {
					cost += step.images * ip.per;
				} else {
					costKnown = false;
				}
			}
		} );

		return {
			steps,
			tokens: steps.reduce( ( sum, s ) => sum + s.tokens, 0 ),
			seconds: steps.reduce( ( sum, s ) => sum + s.sec, 0 ),
			cost: costKnown ? cost : null,
			partialCost: ! costKnown && cost > 0 ? cost : null,
		};
	}

	const fmtTokens = ( n ) => ( n >= 1000 ? ( Math.round( n / 100 ) / 10 ).toLocaleString() + 'k' : String( Math.round( n ) ) );
	const fmtTime = ( sec ) => {
		const s = Math.max( 1, Math.round( sec ) );
		if ( s < 60 ) {
			/* translators: %d: seconds. */
			return sprintf( __( '%d s', TD ), s );
		}
		const m = Math.floor( s / 60 );
		const r = s % 60;
		/* translators: 1: minutes, 2: seconds. */
		return r ? sprintf( __( '%1$d min %2$d s', TD ), m, r ) : sprintf( /* translators: %d: minutes. */ __( '%d min', TD ), m );
	};
	const fmtCost = ( usd ) => {
		if ( usd === null || usd === undefined ) {
			return '—';
		}
		if ( usd < 0.01 ) {
			return '< $0.01';
		}
		return '$' + usd.toFixed( usd < 1 ? 3 : 2 );
	};

	function Estimate( { form, words } ) {
		const [ open, setOpen ] = useState( false );
		const est = estimate( form, words );
		const e = cfg.estimate;

		let note;
		if ( e.jobs ) {
			/* translators: %d: number of generations. */
			note = sprintf( _n( 'Learned from your last generation', 'Learned from your last %d generations', e.jobs, TD ), e.jobs );
		} else {
			note = __( 'Rough estimate — gets more accurate after your first generation', TD );
		}
		let costNote = '';
		if ( est.cost === null ) {
			costNote = e.textModel
				/* translators: %s: model id. */
				? sprintf( __( 'No price known for %s — add one in Settings.', TD ), e.textModel )
				: __( 'Cost appears once the first article shows which model your connector uses (or set prices in Settings).', TD );
		}

		return h(
			'div',
			{ className: 'tai-estimate' },
			h(
				'div',
				{ className: 'tai-estimate__stats' },
				h( 'div', null, h( 'span', null, __( 'Tokens', TD ) ), h( 'strong', null, '≈ ' + fmtTokens( est.tokens ) ) ),
				h( 'div', null, h( 'span', null, __( 'Cost', TD ) ), h( 'strong', null, est.cost === null ? '—' : '≈ ' + fmtCost( est.cost ) ) ),
				h( 'div', null, h( 'span', null, __( 'Time', TD ) ), h( 'strong', null, '≈ ' + fmtTime( est.seconds ) ) )
			),
			h(
				'p',
				{ className: 'tai-estimate__note' },
				note,
				e.textModel ? ' · ' + e.textModel : '',
				' · ',
				h( 'button', { type: 'button', className: 'tai-linkbtn', onClick: () => setOpen( ! open ), 'aria-expanded': open }, open ? __( 'Hide breakdown', TD ) : __( 'Breakdown', TD ) )
			),
			costNote ? h( 'p', { className: 'tai-estimate__note' }, costNote ) : null,
			open
				? h(
					'table',
					{ className: 'tai-estimate__table' },
					h( 'tbody', null,
						est.steps.map( ( step ) =>
							h(
								'tr',
								{ key: step.key },
								h( 'th', null, step.label, step.note ? h( 'small', null, ' (' + step.note + ')' ) : null ),
								h( 'td', null, step.images ? __( '1 image', TD ) : step.tokens ? fmtTokens( step.tokens ) : '—' ),
								h( 'td', null, fmtTime( step.sec ) )
							)
						)
					)
				)
				: null
		);
	}

	/* ------------------------------------------------------------------ *
	 * Connection
	 * ------------------------------------------------------------------ */

	function useStatus() {
		const [ status, setStatus ] = useState( cfg.clientMissing ? { available: false, text: false, image: false, providers: [], message: '' } : cfg.status );
		const [ checking, setChecking ] = useState( ! status );
		const check = ( refresh ) => {
			setChecking( true );
			return apiFetch( { path: '/tai/v1/status' + ( refresh ? '?refresh=1' : '' ) } )
				.then( setStatus )
				.catch( () => setStatus( { available: true, text: false, image: false, providers: [], message: __( 'Could not check the AI connection.', TD ) } ) )
				.finally( () => setChecking( false ) );
		};
		useEffect( () => {
			if ( ! status ) {
				check( false );
			}
		}, [] );
		return { status, checking, check };
	}

	function ConnectionPill( { status, checking } ) {
		if ( checking && ! status ) {
			return h( 'span', { className: 'tai-pill is-checking' }, h( Spinner ), __( 'Checking AI connection…', TD ) );
		}
		if ( ! status || ! status.text ) {
			return h( 'span', { className: 'tai-pill is-off' }, h( 'i' ), __( 'AI not connected', TD ) );
		}
		const providers = status.providers.length ? status.providers.join( ', ' ) : __( 'AI', TD );
		return h(
			'span',
			{ className: 'tai-pill is-on', title: status.image ? __( 'Text and image generation available', TD ) : __( 'Text generation available; image generation is not', TD ) },
			h( 'i' ),
			/* translators: %s: provider names. */
			sprintf( __( '%s connected', TD ), providers )
		);
	}

	function ConnectState( { status, checking, onCheck } ) {
		const missing = cfg.clientMissing;
		return h(
			'div',
			{ className: 'tai-connect' },
			h( 'h2', null, missing ? __( 'WordPress 7.0 or newer is required', TD ) : __( 'Connect an AI provider to get started', TD ) ),
			h( 'p', null, ( status && status.message ) || __( 'AI Articles uses the AI connector configured in WordPress (OpenAI, Anthropic, Google…).', TD ) ),
			! missing
				? h(
					'ol',
					{ className: 'tai-connect__steps' },
					h( 'li', null, __( 'Open Settings → Connectors.', TD ) ),
					h( 'li', null, __( 'Install a provider (OpenAI or Google also generate images; Anthropic writes text only).', TD ) ),
					h( 'li', null, __( 'Paste your API key, save, and come back here.', TD ) )
				)
				: null,
			h(
				'div',
				{ className: 'tai-row tai-row--center' },
				cfg.canManage
					? h( Button, { variant: 'primary', href: cfg.connectorsUrl, __next40pxDefaultSize: true }, missing ? __( 'Update WordPress', TD ) : __( 'Open Connectors settings', TD ) )
					: h( 'p', null, __( 'Ask a site administrator to connect an AI provider.', TD ) ),
				! missing ? h( Button, { variant: 'secondary', onClick: () => onCheck( true ), isBusy: checking, disabled: checking, __next40pxDefaultSize: true }, __( 'Check again', TD ) ) : null
			)
		);
	}

	/* ------------------------------------------------------------------ *
	 * Jobs list
	 * ------------------------------------------------------------------ */

	function useJobs() {
		const [ jobs, setJobs ] = useState( window.taiJobs ? window.taiJobs.getAll() : [] );
		useEffect( () => ( window.taiJobs ? window.taiJobs.subscribe( setJobs ) : undefined ), [] );
		return jobs;
	}

	const STATE_LABEL = {
		queued: __( 'Queued', TD ),
		running: __( 'Working', TD ),
		done: __( 'Ready', TD ),
		failed: __( 'Failed', TD ),
		cancelled: __( 'Cancelled', TD ),
	};

	function JobCard( { job, highlight } ) {
		const [ open, setOpen ] = useState( false );
		const active = job.state === 'queued' || job.state === 'running';
		const done = job.state === 'done' && job.result;
		const seen = () => window.taiJobs && window.taiJobs.seen( job.id );
		const warnings = ! active && job.warnings ? job.warnings : [];
		const steps = job.log || [];

		const meta = [
			STATE_LABEL[ job.state ] || job.state,
			done && job.result.category,
			done && job.result.word_count ? sprintf( __( '%s words', TD ), Number( job.result.word_count ).toLocaleString() ) : null,
			timeAgo( job.created ),
		].filter( Boolean );

		return h(
			'li',
			{ className: 'tai-job is-' + job.state + ( highlight ? ' is-new' : '' ) },
			h(
				'div',
				{ className: 'tai-job__main' },
				h( 'span', { className: 'tai-job__dot', 'aria-hidden': true } ),
				h(
					'div',
					{ className: 'tai-job__body' },
					h( 'span', { className: 'tai-job__title' }, job.title ),
					h( 'span', { className: 'tai-job__meta' }, meta.join( ' · ' ) )
				),
				h(
					'div',
					{ className: 'tai-job__actions' },
					done ? h( Button, { variant: 'primary', size: 'small', href: job.result.edit_url, onClick: seen }, __( 'Edit draft', TD ) ) : null,
					job.state === 'failed' ? h( Button, { variant: 'secondary', size: 'small', onClick: () => window.taiJobs.retry( job.id ) }, __( 'Retry', TD ) ) : null,
					active
						? h( Button, { variant: 'tertiary', size: 'small', onClick: () => window.taiJobs.cancel( job.id ) }, __( 'Cancel', TD ) )
						: h( 'button', { type: 'button', className: 'tai-x tai-job__remove', 'aria-label': __( 'Remove from the list (the draft is kept)', TD ), title: __( 'Remove from the list (the draft is kept)', TD ), onClick: () => window.taiJobs.remove( job.id ) }, '×' )
				)
			),
			active
				? h(
					'div',
					{ className: 'tai-job__progress' },
					h( 'div', { className: 'tai-bar' }, h( 'span', { style: { width: Math.max( 3, job.progress ) + '%' } } ) ),
					h(
						'div',
						{ className: 'tai-job__label' },
						h( 'span', null, job.stalled ? __( 'Waiting for the server to pick this up…', TD ) : job.label ),
						h( 'span', { className: 'tai-job__pct' }, job.progress + '%' )
					),
					job.stalled
						? h( 'p', { className: 'tai-job__hint' }, __( 'Background requests seem slow on this server. The job resumes automatically; if it stays stuck, check Tools → Site Health for loopback or WP-Cron problems.', TD ) )
						: null
				)
				: null,
			job.state === 'failed' ? h( 'p', { className: 'tai-job__error' }, job.error ) : null,
			! active && ( warnings.length || steps.length )
				? h(
					'button',
					{ type: 'button', className: 'tai-job__more', onClick: () => setOpen( ! open ), 'aria-expanded': open },
					open ? __( 'Hide details', TD ) : __( 'Details', TD ),
					warnings.length ? h( 'span', { className: 'tai-job__count' }, sprintf( _n( '%d note', '%d notes', warnings.length, TD ), warnings.length ) ) : null
				)
				: null,
			open
				? h(
					'div',
					{ className: 'tai-job__details' },
					warnings.length ? h( 'ul', { className: 'tai-job__warnings' }, warnings.map( ( w, i ) => h( 'li', { key: i }, w ) ) ) : null,
					steps.length
						? h( 'ol', { className: 'tai-job__log' }, steps.map( ( l, i ) => h( 'li', { key: i }, h( 'strong', null, l.label ), l.detail ? h( 'span', null, l.detail ) : null ) ) )
						: null,
					done ? h( ExternalLink, { href: job.result.preview_url, onClick: seen }, __( 'Preview draft', TD ) ) : null
				)
				: null
		);
	}

	function JobsPanel( { highlightId } ) {
		const jobs = useJobs();
		const [ permission, setPermission ] = useState( 'Notification' in window ? window.Notification.permission : 'unsupported' );
		const finished = jobs.filter( ( j ) => j.state !== 'queued' && j.state !== 'running' );
		return h(
			'section',
			{ className: 'tai-card tai-jobs' },
			h(
				'header',
				{ className: 'tai-card__head' },
				h( 'div', { className: 'tai-card__titles' }, h( 'h2', null, __( 'Your generations', TD ) ), h( 'p', null, __( 'Runs in the background — feel free to keep working anywhere in WordPress.', TD ) ) ),
				finished.length
					? h( Button, { variant: 'tertiary', size: 'small', onClick: () => window.taiJobs.clear(), label: __( 'Clear finished generations from the list. Drafts are kept.', TD ), showTooltip: true }, __( 'Clear', TD ) )
					: null
			),
			permission === 'default'
				? h(
					'div',
					{ className: 'tai-notify-optin' },
					h( 'span', null, __( 'Get a desktop notification when a draft is ready, even in another tab.', TD ) ),
					h( Button, { variant: 'link', onClick: () => window.taiJobs.requestPermission().then( setPermission ) }, __( 'Enable', TD ) )
				)
				: null,
			jobs.length
				? h( 'ul', { className: 'tai-jobs__list' }, jobs.map( ( job ) => h( JobCard, { key: job.id, job, highlight: job.id === highlightId } ) ) )
				: h( 'p', { className: 'tai-empty' }, __( 'No generations yet. Your drafts will appear here.', TD ) )
		);
	}

	/* ------------------------------------------------------------------ *
	 * App
	 * ------------------------------------------------------------------ */

	const blankForm = () => {
		const prefs = loadPrefs();
		const form = {
			title: '',
			keyword: '',
			length: cfg.lengths.some( ( l ) => l.value === 'auto' ) ? 'auto' : 'medium',
			category: 0,
			cta: cfg.defaultCta || 0,
			links_mode: 'ai',
			link_ids: [],
			image_mode: cfg.canUpload ? 'generate' : 'none',
			attachment_id: 0,
			image_prompt: '',
			copy_acf: !! cfg.acf.lastArticle,
			instructions: '',
			refresh_style: false,
			...prefs,
		};
		if ( ! cfg.lengths.some( ( l ) => l.value === form.length ) ) {
			form.length = 'medium';
		}
		if ( ! cfg.pages.some( ( p ) => p.id === form.cta ) ) {
			form.cta = cfg.defaultCta || 0;
		}
		if ( ! cfg.acf.lastArticle ) {
			form.copy_acf = false;
		}
		if ( form.image_mode === 'upload' ) {
			form.image_mode = cfg.canUpload ? 'generate' : 'none'; // An attachment is never remembered.
		}
		return form;
	};

	function App() {
		const { status, checking, check } = useStatus();
		const [ form, setForm ] = useState( blankForm );
		const [ submitting, setSubmitting ] = useState( false );
		const [ error, setError ] = useState( null );
		const [ started, setStarted ] = useState( null );
		const [ showMore, setShowMore ] = useState( false );
		const titleRef = useRef();

		const set = ( key ) => ( value ) => setForm( ( f ) => ( { ...f, [ key ]: value } ) );
		const imageGen = !! ( status && status.image );

		// Fall back if the connected provider cannot generate images.
		useEffect( () => {
			if ( status && status.text && ! status.image && form.image_mode === 'generate' ) {
				set( 'image_mode' )( 'upload' );
			}
		}, [ status ] );

		if ( cfg.clientMissing || ( status && ! status.text ) ) {
			return h(
				'div',
				{ className: 'tai-app' },
				h( 'div', { className: 'tai-topbar' }, h( ConnectionPill, { status, checking } ) ),
				h( ConnectState, { status, checking, onCheck: check } )
			);
		}

		const missing = [];
		if ( ! form.title.trim() ) {
			missing.push( __( 'Add a main title.', TD ) );
		}
		if ( form.image_mode === 'upload' && ! form.attachment_id ) {
			missing.push( __( 'Choose an image, or pick another image option.', TD ) );
		}
		if ( form.links_mode === 'selected' && ! form.link_ids.length ) {
			missing.push( __( 'Select at least one page to link to.', TD ) );
		}
		const ready = status && status.text && ! missing.length && ! submitting;

		const submit = ( e ) => {
			if ( e ) {
				e.preventDefault();
			}
			if ( ! ready ) {
				return;
			}
			setSubmitting( true );
			setError( null );
			apiFetch( { path: '/tai/v1/jobs', method: 'POST', data: { ...form, post_type: cfg.postType } } )
				.then( ( job ) => {
					savePrefs( form );
					if ( window.taiJobs ) {
						window.taiJobs.upsert( job );
					}
					setStarted( job );
					setForm( ( f ) => ( { ...blankForm(), cta: f.cta, length: f.length, links_mode: f.links_mode, image_mode: f.image_mode, category: 0 } ) );
					if ( titleRef.current ) {
						titleRef.current.focus();
					}
				} )
				.catch( ( err ) => {
					setError( err.message || __( 'Could not start the generation.', TD ) );
				} )
				.finally( () => setSubmitting( false ) );
		};

		const cta = cfg.pages.find( ( p ) => p.id === form.cta );
		const category = cfg.categories.find( ( c ) => c.id === form.category );
		const length = cfg.lengths.find( ( l ) => l.value === form.length ) || {};

		const summary = [
			sprintf( /* translators: 1: length label, 2: words. */ __( '%1$s · ~%2$d words', TD ), length.label, length.words ),
			category ? category.name : __( 'AI picks the category', TD ),
			cta ? sprintf( /* translators: %s: page title. */ __( 'CTA → %s', TD ), cta.title ) : __( 'No CTA', TD ),
			{ none: __( 'No internal links', TD ), ai: __( 'AI adds internal links', TD ), selected: sprintf( /* translators: %d: pages. */ __( 'Links to %d chosen pages', TD ), form.link_ids.length ) }[ form.links_mode ],
			{ generate: __( 'AI image (WebP 80%)', TD ), upload: __( 'Your image (WebP 80%)', TD ), none: __( 'No featured image', TD ) }[ form.image_mode ],
			cfg.seo ? sprintf( /* translators: %s: plugin. */ __( 'SEO via %s', TD ), cfg.seo.name ) : __( 'No SEO plugin — built-in meta & schema', TD ),
		];
		if ( form.copy_acf && cfg.acf.active ) {
			summary.push( __( 'Copies ACF from last article', TD ) );
		}

		return h(
			'div',
			{ className: 'tai-app' },
			h(
				'div',
				{ className: 'tai-topbar' },
				h( ConnectionPill, { status, checking } ),
				cfg.settingsUrl ? h( Button, { className: 'tai-btn-flat', href: cfg.settingsUrl }, __( 'Settings', TD ) ) : null
			),
			h(
				'div',
				{ className: 'tai-layout' },
				h(
					'form',
					{ className: 'tai-main', onSubmit: submit },
					error ? h( Notice, { status: 'error', onRemove: () => setError( null ) }, error ) : null,

					h(
						Step,
						{ n: 1, title: __( 'Topic', TD ), help: sprintf( /* translators: %d: articles. */ __( 'Written in the tone of voice of your first %d articles.', TD ), cfg.scanCount ) },
						h(
							'div',
							{ className: 'tai-title-input' },
							h( TextControl, {
								...field,
								ref: titleRef,
								label: __( 'Main title', TD ),
								placeholder: __( 'e.g. How to choose the right running shoes', TD ),
								value: form.title,
								onChange: set( 'title' ),
								onKeyDown: ( e ) => {
									if ( ( e.metaKey || e.ctrlKey ) && e.key === 'Enter' ) {
										submit();
									}
								},
							} )
						),
						h(
							'div',
							{ className: 'tai-grid-2' },
							h( TextControl, {
								...field,
								label: __( 'Focus keyword', TD ),
								placeholder: __( 'Optional — AI picks one', TD ),
								value: form.keyword,
								onChange: set( 'keyword' ),
							} ),
							cfg.categories.length
								? h( SelectControl, {
									...field,
									label: __( 'Category', TD ),
									value: String( form.category ),
									onChange: ( v ) => set( 'category' )( parseInt( v, 10 ) || 0 ),
									options: [ { value: '0', label: __( 'Let AI choose', TD ) } ].concat( cfg.categories.map( ( c ) => ( { value: String( c.id ), label: c.name } ) ) ),
								} )
								: null
						),
						h( LengthControl, { value: form.length, onChange: set( 'length' ) } )
					),

					h(
						Step,
						{ n: 2, title: __( 'Call to action & internal links', TD ), help: __( 'The article ends with a CTA button; links are only added where they genuinely fit.', TD ) },
						h( SelectControl, {
							...field,
							label: __( 'Main CTA page', TD ),
							value: String( form.cta ),
							onChange: ( v ) => set( 'cta' )( parseInt( v, 10 ) || 0 ),
							options: [ { value: '0', label: __( '— No CTA —', TD ) } ].concat( cfg.pages.map( ( p ) => ( { value: String( p.id ), label: p.title } ) ) ),
						} ),
						h( 'p', { className: 'tai-label' }, __( 'Internal links', TD ) ),
						h( Tiles, {
							label: __( 'Internal links', TD ),
							value: form.links_mode,
							onChange: set( 'links_mode' ),
							options: [
								{ value: 'ai', icon: 'ai-decides', label: __( 'Let AI decide', TD ), hint: __( 'From your published content', TD ) },
								{ value: 'selected', icon: 'pages', label: __( 'Pages I choose', TD ), hint: __( 'Linked where relevant', TD ) },
								{ value: 'none', icon: 'none', label: __( 'None', TD ), hint: __( 'Only the CTA', TD ) },
							],
						} ),
						form.links_mode === 'selected' ? h( LinkPicker, { selected: form.link_ids, onChange: set( 'link_ids' ) } ) : null
					),

					h(
						Step,
						{ n: 3, title: __( 'Featured image', TD ), help: __( 'Converted to WebP at 80% quality, resized, with SEO alt text.', TD ) },
						cfg.canUpload
							? h( Tiles, {
								label: __( 'Featured image', TD ),
								value: form.image_mode,
								onChange: set( 'image_mode' ),
								options: [
									{
										value: 'generate',
										icon: 'ai-image',
										label: __( 'Generate with AI', TD ),
										hint: imageGen ? __( 'Matches the article', TD ) : __( 'Not supported by your provider', TD ),
										disabled: ! imageGen,
										disabledReason: imageGen ? '' : __( 'Your connected provider cannot generate images. Connect OpenAI or Google in Settings → Connectors.', TD ),
									},
									{ value: 'upload', icon: 'upload', label: __( 'Upload / Media Library', TD ), hint: __( 'Use your own photo', TD ) },
									{ value: 'none', icon: 'no-image', label: __( 'No image', TD ), hint: __( 'Add one later', TD ) },
								],
							} )
							: h( Notice, { status: 'info', isDismissible: false }, __( 'You do not have permission to upload images.', TD ) ),
						form.image_mode === 'upload' ? h( ImagePicker, { value: form.attachment_id, onChange: set( 'attachment_id' ) } ) : null,
						form.image_mode === 'generate'
							? h( TextareaControl, {
								__nextHasNoMarginBottom: true,
								label: __( 'Image idea (optional)', TD ),
								placeholder: __( 'Leave empty and AI describes an image that fits the article.', TD ),
								value: form.image_prompt,
								onChange: set( 'image_prompt' ),
								rows: 2,
							} )
							: null
					),

					h(
						Step,
						{
							n: 4,
							title: __( 'Extras', TD ),
							help: __( 'Optional fine-tuning.', TD ),
							aside: h( 'button', { type: 'button', className: 'tai-linkbtn', onClick: () => setShowMore( ! showMore ), 'aria-expanded': showMore }, showMore ? __( 'Less', TD ) : __( 'More', TD ) ),
						},
						cfg.acf.active
							? h( CheckboxControl, {
								__nextHasNoMarginBottom: true,
								label: __( 'Copy ACF from last article', TD ),
								help: cfg.acf.lastArticle
									? sprintf( /* translators: %s: title. */ __( 'Prefills custom fields from “%s”.', TD ), cfg.acf.lastArticle )
									: __( 'No published article to copy from yet.', TD ),
								disabled: ! cfg.acf.lastArticle,
								checked: form.copy_acf,
								onChange: set( 'copy_acf' ),
							} )
							: null,
						showMore
							? h(
								Fragment,
								null,
								h( TextareaControl, {
									__nextHasNoMarginBottom: true,
									label: __( 'Additional instructions', TD ),
									placeholder: __( 'Angle, audience, points to include, things to avoid…', TD ),
									value: form.instructions,
									onChange: set( 'instructions' ),
									rows: 3,
								} ),
								h( CheckboxControl, {
									__nextHasNoMarginBottom: true,
									label: __( 'Re-analyse tone of voice', TD ),
									help: __( 'The saved tone profile refreshes automatically when articles change; tick to force it.', TD ),
									checked: form.refresh_style,
									onChange: set( 'refresh_style' ),
								} )
							)
							: ! cfg.acf.active
								? h( 'p', { className: 'tai-help' }, __( 'Additional instructions and tone settings.', TD ) )
								: null
					)
				),

				h(
					'aside',
					{ className: 'tai-side' },
					h(
						'section',
						{ className: 'tai-card tai-summary' },
						h( 'h2', null, __( 'Summary', TD ) ),
						h( 'ul', null, summary.map( ( text, i ) => h( 'li', { key: i }, text ) ) ),
						started
							? h(
								Notice,
								{ status: 'success', isDismissible: true, onRemove: () => setStarted( null ) },
								/* translators: %s: title. */
								sprintf( __( '“%s” is being written. We’ll notify you when the draft is ready — you can leave this page.', TD ), started.title )
							)
							: null,
						h( Estimate, { form, words: length.words || 1300 } ),
						h(
							Button,
							{ variant: 'primary', className: 'tai-generate', onClick: submit, disabled: ! ready, isBusy: submitting, __next40pxDefaultSize: true },
							submitting ? __( 'Starting…', TD ) : __( 'Generate draft', TD )
						),
						missing.length && form.title ? h( 'ul', { className: 'tai-missing' }, missing.map( ( m, i ) => h( 'li', { key: i }, m ) ) ) : null,
						h( 'p', { className: 'tai-help tai-center' }, __( 'Saved as a draft — nothing is published.', TD ), h( 'br' ), h( 'kbd', null, '⌘/Ctrl + Enter' ) )
					),
					h( JobsPanel, { highlightId: started ? started.id : null } )
				)
			)
		);
	}

	if ( wp.element.createRoot ) {
		wp.element.createRoot( mount ).render( h( App ) );
	} else {
		wp.element.render( h( App ), mount );
	}
} )( window.wp, window.taiData );
