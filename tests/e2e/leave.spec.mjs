/**
 * Usage and Leave safely on a test network: Leave safely makes sites render identically after deactivation.
 *
 * Runs on a throwaway site of its own ("/anchors-e2e/"), so nothing else is converted: one hub image shared, a
 * network item of it on that site, used in a published post (image block and featured image). Windrose Anchors is
 * deactivated network-wide for a moment (to look at that post) and always activated again. Everything the test
 * created is deleted afterwards (the site with its uploads, the hub image), and the allowed sites are put back.
 */
import { existsSync, readdirSync, rmdirSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { test, expect } from '@playwright/test';
import { expectAccessible, HOST, USER, defaultHubs } from './helpers.mjs';

const BASE = `https://${ HOST }`;
const SLUG = 'anchors-e2e';
const PATH = `/${ SLUG }/`;
const IMAGE = '/var/www/html/tools/wp-tests/tests/phpunit/data/images/canola.jpg';
const TITLE = 'Anchors e2e leave canola';

function wpAt( path, ...args ) {
	return execFileSync( 'wp', [ ...args, '--path=/var/www/html/web', `--url=${ BASE }${ path }` ], {
		encoding: 'utf8',
		stdio: [ 'ignore', 'pipe', 'pipe' ],
	} ).trim();
}

/** Whether Windrose Anchors is network-active (WP-CLI answers with its exit code). */
function anchorsActive() {
	try {
		wpAt( '/', 'plugin', 'is-active', 'windrose-anchors', '--network' );
		return true;
	} catch {
		return false;
	}
}

function activateAnchors() {
	if ( ! anchorsActive() ) {
		wpAt( '/', 'plugin', 'activate', 'windrose-anchors', '--network' );
	}
}

const state = { site: 0, hub: 0, hubFiles: [], proxy: 0, post: 0, sitesBefore: null, unusedHub: 0, unused: 0 };

test.describe.configure( { mode: 'serial' } );

// These tests expect the default hub setup: put it in place, and the test network's own setup back afterwards.
let restoreHubs = () => {};
test.beforeAll( () => {
	restoreHubs = defaultHubs();
} );

test.beforeAll( () => {
	try {
		state.sitesBefore = wpAt( '/', 'site', 'option', 'get', 'windrose_anchors_sites', '--format=json' );
	} catch {
		state.sitesBefore = null;
	}
	state.site = Number( wpAt( '/', 'site', 'create', `--slug=${ SLUG }`, '--title=Anchors e2e', '--porcelain' ) );
	const allowed = state.sitesBefore ? JSON.parse( state.sitesBefore ) : [];
	wpAt( '/', 'site', 'option', 'update', 'windrose_anchors_sites', JSON.stringify( [ ...allowed, state.site ] ), '--format=json' );

	state.hub = Number( wpAt( '/', 'media', 'import', IMAGE, `--title=${ TITLE }`, '--alt=Canola on the hub', '--porcelain' ) );
	wpAt( '/', 'post', 'meta', 'update', String( state.hub ), '_windrose_anchors_shared', '1' );
	state.hubFiles = JSON.parse(
		wpAt( '/', 'eval', `$f = get_attached_file( ${ state.hub } ); $o = array( $f ); foreach ( wp_get_attachment_metadata( ${ state.hub } )['sizes'] as $s ) { $o[] = dirname( $f ) . '/' . $s['file']; } echo wp_json_encode( $o );` )
	);
	state.proxy = Number( wpAt( PATH, 'eval', `echo Windrose\\Anchors\\Proxy::create( ${ state.hub } )['id'];`, `--user=${ USER }` ) );
	state.post = Number(
		wpAt(
			PATH,
			'eval',
			`$p = ${ state.proxy }; $u = wp_get_attachment_image_url( $p, 'large' );
			$id = wp_insert_post( array( 'post_status' => 'publish', 'post_title' => 'Anchors e2e leave', 'post_content' => '<!-- wp:image {"id":' . $p . ',"sizeSlug":"large"} --><figure class="wp-block-image size-large"><img src="' . $u . '" alt="Colza" class="wp-image-' . $p . '"/></figure><!-- /wp:image -->' ) );
			set_post_thumbnail( $id, $p ); echo $id;`,
			`--user=${ USER }`
		)
	);
	// A second shared image, added to the site but used nowhere (Usage offers to delete it).
	state.unusedHub = Number( wpAt( '/', 'media', 'import', IMAGE, '--title=Anchors e2e unused canola', '--porcelain' ) );
	wpAt( '/', 'post', 'meta', 'update', String( state.unusedHub ), '_windrose_anchors_shared', '1' );
	state.unused = Number( wpAt( PATH, 'eval', `echo Windrose\\Anchors\\Proxy::create( ${ state.unusedHub } )['id'];`, `--user=${ USER }` ) );
	expect( state.unused ).toBeGreaterThan( 0 );
	expect( state.proxy ).toBeGreaterThan( 0 );
	expect( state.post ).toBeGreaterThan( 0 );
} );

test.afterAll( () => {
	activateAnchors();
	if ( state.site ) {
		wpAt( '/', 'site', 'delete', String( state.site ), '--yes' );
		// WordPress empties a deleted site's uploads folder but keeps the folder itself.
		const folder = `/var/www/html/web/wp-content/uploads/sites/${ state.site }`;
		if ( existsSync( folder ) && readdirSync( folder ).length === 0 ) {
			rmdirSync( folder );
		}
	}
	if ( state.unusedHub ) {
		try {
			wpAt( '/', 'post', 'meta', 'delete', String( state.unusedHub ), '_windrose_anchors_usage' );
		} catch {}
		wpAt( '/', 'post', 'delete', String( state.unusedHub ), '--force' );
	}
	if ( state.hub ) {
		try {
			wpAt( '/', 'post', 'meta', 'delete', String( state.hub ), '_windrose_anchors_usage' );
		} catch {}
		wpAt( '/', 'post', 'delete', String( state.hub ), '--force' );
		for ( const file of state.hubFiles ) {
			expect( existsSync( file ), `${ file } removed with the test image` ).toBe( false );
		}
	}
	if ( state.sitesBefore === null ) {
		try {
			wpAt( '/', 'site', 'option', 'delete', 'windrose_anchors_sites' );
		} catch {}
	} else {
		wpAt( '/', 'site', 'option', 'update', 'windrose_anchors_sites', state.sitesBefore, '--format=json' );
	}
} );

function watchErrors( page ) {
	const errors = [];
	page.on( 'pageerror', ( error ) => errors.push( error.message ) );
	page.on( 'console', ( message ) => message.type() === 'error' && errors.push( message.text() ) );
	return errors;
}

/** The test post as visitors see it: screenshots of its images and the URLs they loaded. */
async function lookAtPost( page ) {
	await page.goto( `${ PATH }?p=${ state.post }` );
	// The image block and the featured image (not the author avatar).
	const images = page.locator( `main img.wp-image-${ state.proxy }, main img.wp-post-image` );
	await expect( images.first() ).toBeVisible();
	const shots = [];
	const sources = [];
	for ( let i = 0; i < ( await images.count() ); i++ ) {
		const image = images.nth( i );
		await image.scrollIntoViewIfNeeded();
		await expect.poll( () => image.evaluate( ( img ) => img.complete && img.naturalWidth > 0 ) ).toBe( true );
		shots.push( await image.screenshot() );
		sources.push( await image.evaluate( ( img ) => ( { src: img.currentSrc, width: img.naturalWidth, height: img.naturalHeight, alt: img.alt } ) ) );
	}
	return { shots, sources };
}

test( 'Usage lists the shared item, the site using it and where (WCAG 2.2 AA)', async ( { page } ) => {
	const errors = watchErrors( page );
	await page.goto( '/wp-admin/network/admin.php?page=windrose-anchors-usage' );
	await expect( page.getByRole( 'heading', { level: 1, name: 'Network media usage' } ) ).toBeVisible();
	await page.getByRole( 'searchbox' ).fill( TITLE );
	const row = page.getByRole( 'row' ).filter( { hasText: TITLE } );
	await expect( row ).toHaveCount( 1 );
	await expect( row.getByRole( 'link', { name: 'Anchors e2e', exact: true } ) ).toHaveAttribute( 'href', `${ BASE }${ PATH }wp-admin/upload.php` );
	await page.waitForLoadState( 'networkidle' );
	await expectAccessible( page, '#windrose-anchors-app' );

	await page.getByRole( 'tab', { name: 'Network items on sites' } ).click();
	await page.getByRole( 'searchbox' ).fill( TITLE );
	const proxyRow = page.getByRole( 'row' ).filter( { hasText: 'Anchors e2e' } ).filter( { hasText: TITLE } );
	await expect( proxyRow ).toContainText( '2 places' );
	await expectAccessible( page, '#windrose-anchors-app' );

	// The unused one can be deleted from its site, after a confirmation; the used one cannot.
	await page.getByRole( 'searchbox' ).fill( 'Anchors e2e unused canola' );
	const unusedRow = page.getByRole( 'row' ).filter( { hasText: 'Anchors e2e unused canola' } );
	await expect( unusedRow ).toContainText( 'Not found in content' );
	await unusedRow.getByRole( 'button', { name: 'Actions' } ).click();
	await page.getByRole( 'menuitem', { name: 'Delete from its site' } ).click();
	const dialog = page.getByRole( 'dialog', { name: 'Delete network items' } );
	await expect( dialog ).toContainText( 'The file stays on its media hub' );
	// Check once the dialog has finished fading in (axe would measure half-transparent text).
	await page.waitForFunction( () => document.getAnimations().every( ( animation ) => animation.playState !== 'running' ) );
	await expectAccessible( page, '[role="dialog"]' );
	await dialog.getByRole( 'button', { name: 'Delete' } ).click();
	await expect( page.locator( '.components-notice' ) ).toContainText( '1 network item deleted from its site.' );
	expect( () => wpAt( PATH, 'post', 'get', String( state.unused ), '--field=ID' ) ).toThrow();
	expect( () => wpAt( '/', 'post', 'meta', 'get', String( state.unusedHub ), '_windrose_anchors_usage' ) ).toThrow();
	// A used item has "Edit on its site" only (so no Actions menu at all).
	await page.getByRole( 'searchbox' ).fill( TITLE );
	await expect( proxyRow.getByRole( 'button', { name: 'Edit on its site' } ) ).toBeVisible();
	await expect( proxyRow.getByRole( 'button', { name: 'Actions' } ) ).toHaveCount( 0 );
	await expect( proxyRow.getByRole( 'button', { name: 'Delete from its site' } ) ).toHaveCount( 0 );

	// The network Plugins screen warns before deactivation.
	await page.goto( '/wp-admin/network/plugins.php' );
	await expect( page.locator( '.windrose-anchors-plugin-notice' ) ).toContainText( 'Before deactivating Windrose Anchors, run Leave safely' );
	expect( errors ).toEqual( [] );
} );

test( 'Leave safely: the site renders identically after deactivation', async ( { page, request } ) => {
	const errors = watchErrors( page );
	const before = await lookAtPost( page );
	expect( before.sources.length ).toBeGreaterThanOrEqual( 1 );
	for ( const source of before.sources ) {
		expect( source.src.startsWith( `${ BASE }/wp-content/uploads/` ), source.src ).toBe( true );
	}

	// Dry run, one site only, then convert.
	await page.goto( '/wp-admin/network/admin.php?page=windrose-anchors-leave' );
	await expect( page.getByRole( 'heading', { level: 1, name: 'Leave safely' } ) ).toBeVisible();
	const scope = page.getByLabel( 'Network items of' );
	await expect( scope ).toBeVisible();
	await scope.selectOption( String( state.site ) );
	const row = page.getByRole( 'row' ).filter( { hasText: TITLE } );
	await expect( row ).toContainText( 'Ready' );
	await expect( row ).toContainText( '1 post' );
	await expect( page.getByText( /^Ready: 1 network item\./ ) ).toBeVisible();
	await page.waitForLoadState( 'networkidle' );
	await expectAccessible( page, '#windrose-anchors-app' );
	const convert = page.getByRole( 'button', { name: 'Convert 1 network item' } );
	await expect( convert ).toBeDisabled();
	await page.getByLabel( 'I have a backup of the database and of the uploads folder' ).check();
	await convert.click();
	await expect( page.locator( '.components-notice.is-success' ) ).toContainText( '1 network item is now a normal media item of its site. Content updated in 1 post.' );

	// What changed on the server.
	expect( () => wpAt( PATH, 'post', 'meta', 'get', String( state.proxy ), '_windrose_anchors_hub' ) ).toThrow();
	const file = wpAt( PATH, 'eval', `echo get_attached_file( ${ state.proxy } );` );
	expect( file ).toContain( `/uploads/sites/${ state.site }/` );
	expect( existsSync( file ) ).toBe( true );
	expect( JSON.parse( wpAt( '/', 'site', 'option', 'get', 'windrose_anchors_sites', '--format=json' ) ) ).not.toContain( state.site );
	let usage = [];
	try {
		usage = JSON.parse( wpAt( '/', 'post', 'meta', 'get', String( state.hub ), '_windrose_anchors_usage', '--format=json' ) );
	} catch {}
	expect( usage ).not.toContain( state.site );

	// Deactivate Windrose Anchors for a moment: the post looks the same, from the site's own files.
	wpAt( '/', 'plugin', 'deactivate', 'windrose-anchors', '--network' );
	let after;
	try {
		expect( anchorsActive() ).toBe( false );
		after = await lookAtPost( page );
	} finally {
		activateAnchors();
	}
	expect( after.sources.length ).toBe( before.sources.length );
	for ( let i = 0; i < before.sources.length; i++ ) {
		const { src, ...rest } = after.sources[ i ];
		const { src: beforeSrc, ...beforeRest } = before.sources[ i ];
		expect( rest ).toEqual( beforeRest );
		expect( src, 'From the site’s own uploads' ).toMatch( new RegExp( `/uploads/sites/${ state.site }/` ) );
		expect( src.split( '/' ).pop() ).toBe( beforeSrc.split( '/' ).pop() );
		expect( ( await request.get( src ) ).status() ).toBe( 200 );
		expect( Buffer.compare( after.shots[ i ], before.shots[ i ] ), `Image ${ i } renders identically` ).toBe( 0 );
	}
	for ( const hubFile of state.hubFiles ) {
		expect( existsSync( hubFile ), `${ hubFile } is still on the main site` ).toBe( true );
	}

	// The test site holds no network item any more.
	await page.goto( '/wp-admin/network/admin.php?page=windrose-anchors-leave' );
	await expect( page.getByLabel( 'Network items of' ).locator( 'option', { hasText: 'Anchors e2e' } ) ).toHaveCount( 0 );
	expect( errors ).toEqual( [] );
} );

test.afterAll( () => restoreHubs() );
