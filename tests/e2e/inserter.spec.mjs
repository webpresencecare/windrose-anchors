/**
 * The block inserter's "Network" media source: on an allowed site the main site's shared images are in
 * the Media tab; inserting one gives the block the site's own network item (no copy uploaded, no failed request);
 * a site that is not allowed has no Network source.
 *
 * Everything the tests create (one hub image, its network item, posts) is deleted afterwards, and the allowed sites
 * are put back as they were.
 */
import { existsSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { test, expect } from '@playwright/test';
import { expectAccessible, recordExternal, HOST, defaultHubs } from './helpers.mjs';

const BASE = `https://${ HOST }`;
const HUB_UPLOADS = `${ BASE }/wp-content/uploads/`;
const IMAGE = '/var/www/html/tools/wp-tests/tests/phpunit/data/images/canola.jpg';
const TITLE = 'Anchors e2e inserter canola';

function wpAt( path, ...args ) {
	return execFileSync( 'wp', [ ...args, '--path=/var/www/html/web', `--url=${ BASE }${ path }` ], {
		encoding: 'utf8',
		stdio: [ 'ignore', 'pipe', 'pipe' ],
	} ).trim();
}

function attachmentsOn( path ) {
	const ids = wpAt( path, 'post', 'list', '--post_type=attachment', '--post_status=any', '--format=ids' );
	return ids ? ids.split( ' ' ).map( Number ) : [];
}

const state = { hub: 0, files: [], sitesBefore: null, posts: [], frBefore: [], arBefore: [] };

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
	wpAt( '/', 'site', 'option', 'update', 'windrose_anchors_sites', '[2,3]', '--format=json' );
	state.hub = Number( wpAt( '/', 'media', 'import', IMAGE, `--title=${ TITLE }`, '--alt=Canola from the inserter', '--porcelain' ) );
	wpAt( '/', 'post', 'meta', 'update', String( state.hub ), '_windrose_anchors_shared', '1' );
	state.files = JSON.parse(
		wpAt( '/', 'eval', `$f = get_attached_file( ${ state.hub } ); $o = array( $f ); foreach ( wp_get_attachment_metadata( ${ state.hub } )['sizes'] as $s ) { $o[] = dirname( $f ) . '/' . $s['file']; } echo wp_json_encode( $o );` )
	);
	state.frBefore = attachmentsOn( '/fr/' );
	state.arBefore = attachmentsOn( '/ar/' );
} );

test.afterAll( () => {
	for ( const [ path, id ] of state.posts ) {
		wpAt( path, 'post', 'delete', String( id ), '--force' );
	}
	for ( const id of attachmentsOn( '/ar/' ).filter( ( id ) => ! state.arBefore.includes( id ) ) ) {
		wpAt( '/ar/', 'post', 'delete', String( id ), '--force' );
	}
	for ( const id of attachmentsOn( '/fr/' ).filter( ( id ) => ! state.frBefore.includes( id ) ) ) {
		wpAt( '/fr/', 'post', 'delete', String( id ), '--force' );
	}
	if ( state.hub ) {
		try {
			wpAt( '/', 'post', 'meta', 'delete', String( state.hub ), '_windrose_anchors_usage' );
		} catch {}
		wpAt( '/', 'post', 'delete', String( state.hub ), '--force' );
		for ( const file of state.files ) {
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
	page.on( 'response', ( response ) => response.status() >= 400 && errors.push( `${ response.status() } ${ response.url() }` ) );
	return errors;
}

/** Opens a new post and the inserter's Media tab. */
async function openMediaTab( page, path ) {
	await page.goto( `${ path }wp-admin/post-new.php` );
	await page.waitForFunction( () => window.wp?.data?.select( 'core/editor' )?.getCurrentPostId() );
	const post = await page.evaluate( () => window.wp.data.select( 'core/editor' ).getCurrentPostId() );
	state.posts.push( [ path, post ] );
	await page.locator( '.editor-document-tools__inserter-toggle' ).click();
	// The French admin is in French ("Médias"); "Media source" is new in 7.2 and not translated yet.
	await page.getByRole( 'tab', { name: /^(Media|Médias)$/ } ).click();
	return post;
}

const SOURCE_MENU = /^(Media source|Source des médias)$/;

/** Opens a new post, the inserter's Media tab, and the Media source menu. */
async function openMediaSources( page, path ) {
	const post = await openMediaTab( page, path );
	await page.getByRole( 'button', { name: SOURCE_MENU } ).click();
	return post;
}

test( 'fr: the Network source inserts the site’s own network item, not a copy', async ( { page } ) => {
	const errors = watchErrors( page );
	const external = recordExternal( page );
	await openMediaSources( page, '/fr/' );
	await page.getByRole( 'menuitemradio', { name: 'Network images' } ).click();
	const item = page.getByRole( 'gridcell', { name: TITLE } ).first();
	await expect( item ).toBeVisible();
	await expectAccessible( page, '.block-editor-inserter__media-panel' );

	const created = page.waitForResponse( ( r ) => r.url().includes( 'windrose-anchors/v1/proxies' ) && r.request().method() === 'POST' );
	await item.getByRole( 'button' ).first().click();
	const proxy = await ( await created ).json();
	expect( proxy.id ).toBeGreaterThan( 0 );
	await expect.poll( () => page.evaluate( () => window.wp.data.select( 'core/block-editor' ).getBlocks()[ 0 ]?.attributes.id ) ).toBe( proxy.id );
	const block = await page.evaluate( () => window.wp.data.select( 'core/block-editor' ).getBlocks()[ 0 ] );
	expect( block.name ).toBe( 'core/image' );
	expect( block.attributes.url.startsWith( HUB_UPLOADS ) ).toBe( true );
	expect( block.attributes.alt ).toBe( 'Canola from the inserter' );

	// The only new attachment on the site is the network item: nothing was uploaded.
	const added = attachmentsOn( '/fr/' ).filter( ( id ) => ! state.frBefore.includes( id ) );
	expect( added ).toEqual( [ proxy.id ] );
	expect( wpAt( '/fr/', 'post', 'meta', 'get', String( proxy.id ), '_windrose_anchors_hub' ) ).toBe( String( state.hub ) );

	// The editor loaded the item's details (image block selected) without a failed request for the placeholder.
	await page.evaluate( async () => {
		window.wp.data.dispatch( 'core/editor' ).editPost( { title: 'Anchors e2e inserter', status: 'publish' } );
		await window.wp.data.dispatch( 'core/editor' ).savePost();
	} );
	await page.waitForFunction( () => window.wp.data.select( 'core/editor' ).getCurrentPostAttribute( 'status' ) === 'publish' );
	const content = await page.evaluate( () => window.wp.data.select( 'core/editor' ).getEditedPostContent() );
	expect( content ).toContain( `"id":${ proxy.id }` );
	expect( content ).toContain( `wp-image-${ proxy.id }` );
	expect( content ).not.toMatch( /"id":-\d|wp-image--/ );
	expect( JSON.parse( wpAt( '/', 'post', 'meta', 'get', String( state.hub ), '_windrose_anchors_usage', '--format=json' ) ) ).toContain( 2 );

	expect( errors ).toEqual( [] );
	expect( external ).toEqual( [] );
} );

test( 'fr: inserting the same item again reuses the network item', async ( { page } ) => {
	const errors = watchErrors( page );
	await openMediaSources( page, '/fr/' );
	await page.getByRole( 'menuitemradio', { name: 'Network images' } ).click();
	const reused = page.waitForResponse( ( r ) => r.url().includes( 'windrose-anchors/v1/proxies' ) );
	await page.getByRole( 'gridcell', { name: TITLE } ).first().getByRole( 'button' ).first().click();
	expect( ( await reused ).status() ).toBe( 200 );
	const added = attachmentsOn( '/fr/' ).filter( ( id ) => ! state.frBefore.includes( id ) );
	expect( added ).toHaveLength( 1 );
	await expect.poll( () => page.evaluate( () => window.wp.data.select( 'core/block-editor' ).getBlocks()[ 0 ]?.attributes.id ) ).toBe( added[ 0 ] );
	expect( errors ).toEqual( [] );
} );

test( 'ar: the Network source in a right-to-left editor (WCAG 2.2 AA)', async ( { page } ) => {
	const errors = watchErrors( page );
	await page.goto( '/ar/wp-admin/post-new.php' );
	await page.waitForFunction( () => window.wp?.data?.select( 'core/editor' )?.getCurrentPostId() );
	state.posts.push( [ '/ar/', await page.evaluate( () => window.wp.data.select( 'core/editor' ).getCurrentPostId() ) ] );
	await expect( page.locator( 'html' ) ).toHaveAttribute( 'dir', 'rtl' );
	await page.locator( '.editor-document-tools__inserter-toggle' ).click();
	// Labels are in Arabic here: the Media tab by its ID, the source menu by its place.
	await page.locator( '[role="tab"][id$="-media"]' ).click();
	await page.locator( '.block-editor-inserter__media-panel button[aria-haspopup="menu"]' ).first().click();
	await page.getByRole( 'menuitemradio', { name: 'Network images' } ).click();
	await expect( page.getByRole( 'gridcell', { name: TITLE } ).first() ).toBeVisible();
	await expectAccessible( page, '.block-editor-inserter__media-panel' );
	expect( errors ).toEqual( [] );
} );

test( 'social: a site that is not allowed has no Network source', async ( { page } ) => {
	await openMediaTab( page, '/social/' );
	await expect( page.locator( '.block-editor-inserter__media-panel' ) ).toBeVisible();
	// With one source only ("Attached images"), the editor shows no Media source menu at all.
	const menu = page.getByRole( 'button', { name: SOURCE_MENU } );
	if ( await menu.count() ) {
		await menu.click();
		await expect( page.getByRole( 'menuitemradio' ).first() ).toBeVisible();
		await expect( page.getByRole( 'menuitemradio', { name: 'Network images' } ) ).toHaveCount( 0 );
	}
	expect( await page.evaluate( () => window.windroseAnchorsInserter ) ).toBeUndefined();
} );

test.afterAll( () => restoreHubs() );
