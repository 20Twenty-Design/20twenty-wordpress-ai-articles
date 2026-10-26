/* global wp */
/**
 * Animated line-art emoji for the generator screen.
 *
 * <Emoji mood="smile" /> morphs between expressions, blinks, follows the cursor with its eyes,
 * and pulls a random face every once in a while. Exposed as window.taiEmoji.
 */
( function ( wp ) {
	'use strict';

	const { createElement: h, useState, useEffect, useRef } = wp.element;

	// Eye and mouth centres in a 120×120 box.
	const L = 43;
	const R = 77;
	const EY = 52;

	const EYES = {
		dot: ( x ) => h( 'circle', { cx: x, cy: EY, r: 5, className: 'is-fill' } ),
		big: ( x ) => h( 'ellipse', { cx: x, cy: EY, rx: 4.5, ry: 7.5, className: 'is-fill' } ),
		happy: ( x ) => h( 'path', { d: `M${ x - 8 } ${ EY + 3 } Q${ x } ${ EY - 9 } ${ x + 8 } ${ EY + 3 }` } ),
		closed: ( x ) => h( 'path', { d: `M${ x - 8 } ${ EY - 1 } Q${ x } ${ EY + 7 } ${ x + 8 } ${ EY - 1 }` } ),
		x: ( x ) => h( 'path', { d: `M${ x - 6 } ${ EY - 6 } L${ x + 6 } ${ EY + 6 } M${ x + 6 } ${ EY - 6 } L${ x - 6 } ${ EY + 6 }` } ),
		heart: ( x ) =>
			h( 'path', {
				className: 'is-fill',
				d: `M${ x } ${ EY + 8 } C${ x - 13 } ${ EY - 1 } ${ x - 9 } ${ EY - 11 } ${ x } ${ EY - 4 } C${ x + 9 } ${ EY - 11 } ${ x + 13 } ${ EY - 1 } ${ x } ${ EY + 8 } Z`,
			} ),
		wink: ( x ) => h( 'path', { d: `M${ x + 6 } ${ EY - 6 } L${ x - 5 } ${ EY } L${ x + 6 } ${ EY + 6 }` } ),
		squintL: ( x ) => h( 'path', { d: `M${ x - 6 } ${ EY - 6 } L${ x + 5 } ${ EY } L${ x - 6 } ${ EY + 6 }` } ),
		squintR: ( x ) => h( 'path', { d: `M${ x + 6 } ${ EY - 6 } L${ x - 5 } ${ EY } L${ x + 6 } ${ EY + 6 }` } ),
		flat: ( x ) => h( 'g', null, h( 'path', { d: `M${ x - 8 } ${ EY - 9 } L${ x + 8 } ${ EY - 9 }` } ), h( 'circle', { cx: x, cy: EY + 2, r: 4.5, className: 'is-fill' } ) ),
		angryL: ( x ) => h( 'g', null, h( 'path', { d: `M${ x - 9 } ${ EY - 11 } L${ x + 7 } ${ EY - 5 }` } ), h( 'circle', { cx: x, cy: EY + 2, r: 4.5, className: 'is-fill' } ) ),
		angryR: ( x ) => h( 'g', null, h( 'path', { d: `M${ x + 9 } ${ EY - 11 } L${ x - 7 } ${ EY - 5 }` } ), h( 'circle', { cx: x, cy: EY + 2, r: 4.5, className: 'is-fill' } ) ),
		sorrow: ( x ) => h( 'path', { d: `M${ x - 8 } ${ EY - 6 } L${ x + 8 } ${ EY - 6 } M${ x - 5 } ${ EY - 6 } L${ x - 5 } ${ EY + 6 } M${ x + 5 } ${ EY - 6 } L${ x + 5 } ${ EY + 6 }` } ),
	};

	const MOUTHS = {
		smile: () => h( 'path', { d: 'M43 74 Q60 92 77 74' } ),
		grin: () => h( 'path', { className: 'is-soft', d: 'M40 73 L80 73 Q80 97 60 97 Q40 97 40 73 Z' } ),
		laughO: () => h( 'circle', { className: 'is-soft', cx: 60, cy: 85, r: 10 } ),
		o: () => h( 'circle', { cx: 60, cy: 82, r: 5 } ),
		flat: () => h( 'path', { d: 'M52 81 L68 81' } ),
		wavy: () => h( 'path', { d: 'M45 82 L50 77 L55 82 L60 77 L65 82 L70 77 L75 82' } ),
		tongue: () => h( 'g', null, h( 'path', { d: 'M47 75 Q60 85 73 75' } ), h( 'path', { className: 'is-soft', d: 'M54 80 L54 88 Q60 95 66 88 L66 80' } ) ),
		nose: () => h( 'path', { d: 'M54 80 L60 74 L66 80' } ),
		shock: () => h( 'path', { className: 'is-soft', d: 'M47 90 Q60 68 73 90 Z' } ),
		cat: () => h( 'g', null, h( 'path', { d: 'M48 76 Q54 83 60 76 Q66 83 72 76' } ), h( 'path', { className: 'is-soft', d: 'M55 80 Q60 88 65 80' } ) ),
		oval: () => h( 'ellipse', { cx: 60, cy: 82, rx: 8, ry: 4.5 } ),
	};

	const FACES = {
		smile: [ 'happy', 'happy', 'smile' ],
		smize: [ 'happy', 'happy', 'flat' ],
		dazzling: [ 'happy', 'happy', 'wavy' ],
		tongue: [ 'happy', 'happy', 'tongue' ],
		silence: [ 'happy', 'happy', 'nose' ],
		laughing: [ 'happy', 'happy', 'laughO' ],
		firm: [ 'closed', 'closed', 'flat' ],
		crush: [ 'heart', 'heart', 'grin' ],
		frightened: [ 'x', 'x', 'laughO' ],
		die: [ 'x', 'x', 'flat' ],
		taking: [ 'squintL', 'squintR', 'flat' ],
		sour: [ 'squintL', 'squintR', 'wavy' ],
		dumbfounded: [ 'big', 'big', 'shock' ],
		speechless: [ 'big', 'big', 'oval' ],
		wink: [ 'dot', 'wink', 'grin' ],
		blankly: [ 'flat', 'flat', 'nose' ],
		graveyard: [ 'flat', 'flat', 'flat' ],
		angry: [ 'angryL', 'angryR', 'oval' ],
		sorrow: [ 'sorrow', 'sorrow', 'flat' ],
		nitpicking: [ 'angryL', 'angryR', 'cat' ],
	};

	const IDLE = [ 'wink', 'tongue', 'dazzling', 'laughing', 'smize', 'silence', 'nitpicking', 'dumbfounded', 'firm', 'speechless' ];
	const BLINKABLE = [ 'dot', 'big', 'flat', 'angryL', 'angryR' ];
	const reducedMotion = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
	const random = ( list ) => list[ Math.floor( Math.random() * list.length ) ];

	function Emoji( { mood, size = 128, label } ) {
		const [ idle, setIdle ] = useState( null );
		const [ blink, setBlink ] = useState( false );
		const [ look, setLook ] = useState( { x: 0, y: 0 } );
		const ref = useRef();

		// A random face every once in a while.
		useEffect( () => {
			if ( reducedMotion ) {
				return undefined;
			}
			let timer;
			const schedule = () => {
				timer = setTimeout( () => {
					setIdle( random( IDLE.filter( ( f ) => f !== mood ) ) );
					timer = setTimeout( () => {
						setIdle( null );
						schedule();
					}, 1800 );
				}, 7000 + Math.random() * 6000 );
			};
			schedule();
			return () => clearTimeout( timer );
		}, [ mood ] );

		// A new mood always wins over an idle face.
		useEffect( () => setIdle( null ), [ mood ] );

		// Blink.
		useEffect( () => {
			if ( reducedMotion ) {
				return undefined;
			}
			let timer;
			const schedule = () => {
				timer = setTimeout( () => {
					setBlink( true );
					timer = setTimeout( () => {
						setBlink( false );
						schedule();
					}, 140 );
				}, 2500 + Math.random() * 3500 );
			};
			schedule();
			return () => clearTimeout( timer );
		}, [] );

		// Eyes follow the cursor.
		useEffect( () => {
			if ( reducedMotion ) {
				return undefined;
			}
			let frame;
			const onMove = ( e ) => {
				cancelAnimationFrame( frame );
				frame = requestAnimationFrame( () => {
					if ( ! ref.current ) {
						return;
					}
					const box = ref.current.getBoundingClientRect();
					const dx = e.clientX - ( box.left + box.width / 2 );
					const dy = e.clientY - ( box.top + box.height / 2 );
					const dist = Math.max( 1, Math.hypot( dx, dy ) );
					const pull = Math.min( 1, dist / 400 );
					setLook( { x: ( dx / dist ) * 4 * pull, y: ( dy / dist ) * 3 * pull } );
				} );
			};
			window.addEventListener( 'mousemove', onMove );
			return () => {
				window.removeEventListener( 'mousemove', onMove );
				cancelAnimationFrame( frame );
			};
		}, [] );

		const name = FACES[ idle || mood ] ? idle || mood : 'smile';
		const [ left, right, mouth ] = FACES[ name ];
		const canBlink = BLINKABLE.includes( left ) && BLINKABLE.includes( right );

		return h(
			'div',
			{
				ref,
				className: 'tai-emoji',
				style: { width: size, height: size },
				role: 'img',
				'aria-label': label || name,
				'data-face': name,
				onClick: () => setIdle( random( IDLE ) ),
			},
			h(
				'svg',
				{ viewBox: '0 0 120 120', key: 'face-' + name, className: 'tai-emoji__svg' },
				h( 'circle', { className: 'tai-emoji__face', cx: 60, cy: 60, r: 52 } ),
				h(
					'g',
					{ className: 'tai-emoji__features', style: { transform: `translate(${ look.x }px, ${ look.y }px)` } },
					h( 'g', { className: 'tai-emoji__eyes' + ( blink && canBlink ? ' is-blinking' : '' ) }, EYES[ left ]( L ), EYES[ right ]( R ) ),
					h( 'g', { className: 'tai-emoji__mouth' }, MOUTHS[ mouth ]() )
				)
			)
		);
	}

	Emoji.faces = Object.keys( FACES );
	window.taiEmoji = Emoji;
} )( window.wp );
