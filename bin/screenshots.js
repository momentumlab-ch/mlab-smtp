#!/usr/bin/env node
/**
 * Erzeugt die Screenshots in docs/screenshots/ aus der echten Test-WordPress-Umgebung (tests/env, SQLite).
 *
 * Voraussetzung: bash tests/env/setup.sh && bash tests/env/serve.sh (URL steht in tests/env/.run/url).
 * Aufruf: node bin/screenshots.js
 * Die Demo-Daten kommen aus bin/seed-demo.php und werden pro Szenario neu gesetzt.
 * Die Sprachen en/fr/it laden über ein temporäres mu-plugin, das dieses Skript anlegt und am Ende wieder löscht
 * (tests/env/wordpress/wp-content/mu-plugins/mlab-demo-locale.php, wertet ?mlab_lang=… aus).
 */
const { chromium } = require( 'playwright' );
const { execFileSync } = require( 'child_process' );
const fs = require( 'fs' );
const path = require( 'path' );

const root = path.resolve( __dirname, '..' );
const out = path.join( root, 'docs', 'screenshots' );
const base = fs.readFileSync( path.join( root, 'tests/env/.run/url' ), 'utf8' ).trim();
const password = fs.readFileSync( path.join( root, 'tests/env/admin-password.txt' ), 'utf8' ).trim();
const seed = ( scenario ) => execFileSync( 'php', [ path.join( root, 'bin/seed-demo.php' ), scenario ], { stdio: 'inherit' } );
const url = ( args = {}, lang = '' ) => {
	const q = new URLSearchParams( { page: 'mlab-smtp', ...args } );
	if ( lang ) q.set( 'mlab_lang', lang );
	return `${ base }/wp-admin/admin.php?${ q }`;
};
const muDir = path.join( root, 'tests/env/wordpress/wp-content/mu-plugins' );
const muFile = path.join( muDir, 'mlab-demo-locale.php' );
const MU = "<?php\n// Tempor\u00e4r f\u00fcr Screenshots (bin/screenshots.js): Sprache per ?mlab_lang=en_US|fr_FR|it_IT, sonst Deutsch; WP-Cron aus, solange tests/env/.run/nocron existiert.\nadd_filter( 'locale', static function () {\n\t$l = isset( $_GET['mlab_lang'] ) ? preg_replace( '/[^a-zA-Z_]/', '', (string) $_GET['mlab_lang'] ) : 'de_DE';\n\treturn $l ?: 'de_DE';\n}, 99 );\nif ( is_file( dirname( __DIR__, 3 ) . '/.run/nocron' ) && ! defined( 'DISABLE_WP_CRON' ) ) {\n\tdefine( 'DISABLE_WP_CRON', true );\n}\n";
const entry = '00000000-0000-4000-8000-000000000012';

( async () => {
	fs.mkdirSync( out, { recursive: true } );
	fs.mkdirSync( muDir, { recursive: true } );
	fs.writeFileSync( muFile, MU ); // wird am Ende wieder entfernt
	const browser = await chromium.launch();
	const ctx = await browser.newContext( { viewport: { width: 1280, height: 900 }, deviceScaleFactor: 1 } );
	const page = await ctx.newPage();
	const errors = [];
	page.on( 'pageerror', ( e ) => errors.push( e.message ) );

	// Echter Formular-Login.
	await page.goto( `${ base }/wp-login.php` );
	await page.fill( '#user_login', 'admin' );
	await page.fill( '#user_pass', password );
	await Promise.all( [ page.waitForURL( /wp-admin/ ), page.click( '#wp-submit' ) ] );

	const shot = async ( name, target, opts = {} ) => {
		await page.goto( target, { waitUntil: 'networkidle' } );
		if ( opts.scroll ) await page.evaluate( ( s ) => document.querySelector( s ).scrollIntoView(), opts.scroll );
		await page.waitForTimeout( 400 );
		const body = await page.evaluate( () => document.body.innerText );
		if ( /Warning:|Notice:|Deprecated:|Fatal error/.test( body ) ) errors.push( `${ name }: PHP-Meldung auf der Seite` );
		const sw = await page.evaluate( () => document.documentElement.scrollWidth > window.innerWidth );
		if ( sw ) errors.push( `${ name }: horizontaler Scroll` );
		await page.screenshot( { path: path.join( out, `${ name }.png` ), ...( opts.clip ? { clip: opts.clip } : {} ) } );
		console.log( 'ok', name );
	};

	seed( 'normal' );
	await shot( '01-uebersicht', url() );
	await shot( '03-warteschlange', url( { view: 'queue' } ) );
	await shot( '04-protokoll', url( { view: 'log' } ) );
	await shot( '05-protokoll-detail', url( { view: 'log', entry } ) );
	await shot( '06-einstellungen', url( { view: 'settings' } ) );
	await shot( '06-einstellungen-sicherheit', url( { view: 'settings' } ), { scroll: '#mlab-s-staging' } );
	await shot( '07-assistent-anbieter', url( { view: 'wizard', step: 1 } ) );
	await shot( '08-assistent-resend-key', url( { view: 'wizard', step: 2, provider: 'resend' } ) );
	await shot( '09-ersatzweg-schritt-1', url( { view: 'fallback', step: 1 } ) );
	await shot( '09-ersatzweg-schritt-6', url( { view: 'fallback', step: 6 } ) );
	for ( const [ sc, n ] of [ [ 'fb-leer', '1-nicht-eingetragen' ], [ 'normal', '2-nicht-geprueft' ], [ 'fb-getestet', '3-getestet' ], [ 'fb-veraltet', '4-veraltet' ] ] ) {
		seed( sc );
		await shot( `10-ersatzweg-zusammenfassung-${ n }`, url( { view: 'fallback', step: 7 } ), { clip: { x: 160, y: 32, width: 1120, height: 640 } } );
	}
	seed( 'normal' );
	for ( const [ n, l ] of [ [ '14-uebersicht-en', 'en_US' ], [ '15-uebersicht-fr', 'fr_FR' ], [ '16-uebersicht-it', 'it_IT' ] ] ) {
		await shot( n, url( {}, l ) );
	}

	seed( 'stoerung' );
	await shot( '02-uebersicht-stoerung', url() );

	seed( 'staging' );
	await shot( '11-staging-blockiert', url() );

	seed( 'normal' );
	await page.setViewportSize( { width: 390, height: 844 } );
	await shot( '12-uebersicht-mobil', url() );
	await shot( '13-warteschlange-mobil', url( { view: 'queue' } ) );

	await browser.close();
	fs.rmSync( muFile, { force: true } );
	fs.rmSync( path.join( root, 'tests/env/.run/nocron' ), { force: true } );
	if ( errors.length ) {
		console.log( 'HINWEISE:\n' + errors.join( '\n' ) );
	}
} )().catch( ( e ) => {
	fs.rmSync( muFile, { force: true } );
	console.error( e );
	process.exit( 1 );
} );
