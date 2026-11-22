/**
 * Every media type the same, every way in: on the French site, the inserter's Network videos and Network audio
 * sources, the media dialog's Network tab in the Video, Audio and File blocks, a media dialog opened the way other
 * plugins open it (wp.media), and an image dragged from the inserter into the post. Each gives the block the site's
 * own network item, never a copy.
 *
 * Everything the tests create (hub video, audio, PDF and image, their network items, posts) is deleted afterwards,
 * and the allowed sites are put back as they were.
 */
import { execFileSync } from 'node:child_process';
import { test, expect } from '@playwright/test';
import { HOST, defaultHubs } from './helpers.mjs';

const BASE = `https://${ HOST }`;
const HUB_UPLOADS = `${ BASE }/wp-content/uploads/`;
const DATA = '/var/www/html/tools/wp-tests/tests/phpunit/data';
const LIBRARY = /^(Media Library|Médiathèque)$/;
const FILES = {
	video: { path: `${ DATA }/uploads/small-video.mp4`, title: 'Anchors e2e clip' },
	audio: { path: `${ DATA }/uploads/small-audio.mp3`, title: 'Anchors e2e sound' },
	pdf: { path: `${ DATA }/images/test-alpha.pdf`, title: 'Anchors e2e flyer' },
	image: { path: `${ DATA }/images/canola.jpg`, title: 'Anchors e2e dragged canola' },
};

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

/** This site's network item of a hub item, or 0. */
function proxyOf( hub ) {
	const ids = wpAt( '/fr/', 'post', 'list', '--post_type=attachment', '--post_status=any', '--meta_key=_windrose_anchors_hub', `--meta_value=${ hub }`, '--format=ids' );
	return ids ? Number( ids.split( ' ' )[ 0 ] ) : 0;
}

const state = { hubs: {}, sitesBefore: null, posts: [], frBefore: [] };

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
	for ( const [ key, file ] of Object.entries( FILES ) ) {
		state.hubs[ key ] = Number( wpAt( '/', 'media', 'import', file.path, `--title=${ file.title }`, '--porcelain' ) );
		wpAt( '/', 'post', 'meta', 'update', String( state.hubs[ key ] ), '_windrose_anchors_shared', '1' );
	}
	state.frBefore = attachmentsOn( '/fr/' );
} );

test.afterAll( () => {
	for ( const id of state.posts ) {
		wpAt( '/fr/', 'post', 'delete', String( id ), '--force' );
	}
	for ( const id of attachmentsOn( '/fr/' ).filter( ( id ) => ! state.frBefore.includes( id ) ) ) {
		wpAt( '/fr/', 'post', 'delete', String( id ), '--force' );
	}
	for ( const hub of Object.values( state.hubs ) ) {
		try {
			wpAt( '/', 'post', 'meta', 'delete', String( hub ), '_windrose_anchors_usage' );
		} catch {}
		wpAt( '/', 'post', 'delete', String( hub ), '--force' );
	}
	if ( state.sitesBefore === null ) {
		try {
			wpAt( '/', 'site', 'option', 'delete', 'windrose_anchors_sites' );
		} catch {}
	} else {
		wpAt( '/', 'site', 'option', 'update', 'windrose_anchors_sites', state.sitesBefore, '--format=json' );
	}
} );

/**
 * WordPress's bundled MediaElement throws this when a video in the dialog's details sidebar loads its metadata
 * after the sidebar was redrawn and its player removed (setResponsiveMode() reads `parentNode.length` of a container
 * no longer in the page). It happens on the plain "This site" tab too; picking a network video makes it likelier,
 * since the sidebar is redrawn when the site's own item replaces the network item. Nothing visible breaks.
 * Remove once fixed: https://core.trac.wordpress.org/ticket/62634
 *
 * @param {Error} error Page error.
 * @return {boolean} True for that MediaElement error.
 */
const isMediaElementResize = ( error ) =>
	error.message === "Cannot read properties of null (reading 'length')" &&
	/\/mediaelement\/mediaelement-and-player(\.min)?\.js/.test( error.stack || '' );

function watchErrors( page ) {
	const errors = [];
	page.on( 'pageerror', ( error ) => isMediaElementResize( error ) || errors.push( error.message ) );
	page.on( 'console', ( message ) => message.type() === 'error' && errors.push( message.text() ) );
	page.on( 'response', ( response ) => response.status() >= 400 && errors.push( `${ response.status() } ${ response.url() }` ) );
	return errors;
}

async function newFrenchPost( page ) {
	await page.goto( '/fr/wp-admin/post-new.php' );
	await page.waitForFunction( () => window.wp?.data?.select( 'core/editor' )?.getCurrentPostId() );
	state.posts.push( await page.evaluate( () => window.wp.data.select( 'core/editor' ).getCurrentPostId() ) );
}

const firstBlock = ( page ) => page.evaluate( () => window.wp.data.select( 'core/block-editor' ).getBlocks()[ 0 ] );

for ( const [ key, source ] of [
	[ 'video', 'Network videos' ],
	[ 'audio', 'Network audio' ],
] ) {
	test( `inserter: ${ source } inserts the site’s network item`, async ( { page } ) => {
		const errors = watchErrors( page );
		await newFrenchPost( page );
		await page.locator( '.editor-document-tools__inserter-toggle' ).click();
		await page.locator( '[role="tab"][id$="-media"]' ).click();
		await page.locator( '.block-editor-inserter__media-panel button[aria-haspopup="menu"]' ).first().click();
		await page.getByRole( 'menuitemradio', { name: source } ).click();
		const created = page.waitForResponse( ( r ) => r.url().includes( 'windrose-anchors/v1/proxies' ) && r.request().method() === 'POST' );
		await page.getByRole( 'gridcell', { name: FILES[ key ].title } ).first().getByRole( 'button' ).first().click();
		const proxy = ( await ( await created ).json() ).id;
		await expect.poll( async () => ( await firstBlock( page ) )?.attributes.id ).toBe( proxy );
		const block = await firstBlock( page );
		expect( block.name ).toBe( `core/${ key }` );
		expect( block.attributes.src.startsWith( HUB_UPLOADS ) ).toBe( true );
		expect( proxyOf( state.hubs[ key ] ) ).toBe( proxy );
		expect( errors ).toEqual( [] );
	} );
}

for ( const [ key, blockName ] of [
	[ 'video', 'core/video' ],
	[ 'audio', 'core/audio' ],
	[ 'pdf', 'core/file' ],
] ) {
	test( `media dialog: the ${ blockName } block picks from the Network tab`, async ( { page } ) => {
		const errors = watchErrors( page );
		await newFrenchPost( page );
		await page.evaluate( ( name ) => window.wp.data.dispatch( 'core/block-editor' ).insertBlocks( window.wp.blocks.createBlock( name ) ), blockName );
		await page.frameLocator( 'iframe[name="editor-canvas"]' ).getByRole( 'button', { name: LIBRARY } ).click();
		const modal = page.locator( '.media-modal:visible' );
		await modal.getByRole( 'tab', { name: 'Network' } ).click();
		const item = modal.locator( `li.attachment[data-id="-${ state.hubs[ key ] }"]` );
		await expect( item ).toBeVisible();
		if ( key !== 'pdf' ) {
			// Only the block's media type is listed.
			await expect( modal.locator( `li.attachment[data-id="-${ state.hubs.image }"]` ) ).toHaveCount( 0 );
		}
		const created = page.waitForResponse( ( r ) => r.url().includes( 'windrose-anchors/v1/proxies' ) && r.request().method() === 'POST' );
		await item.click();
		const proxy = ( await ( await created ).json() ).id;
		await expect( modal.locator( `li.attachment[data-id="${ proxy }"]` ) ).toHaveAttribute( 'aria-checked', 'true' );
		await modal.locator( '.media-button-select' ).click();
		await expect.poll( async () => ( await firstBlock( page ) )?.attributes.id ).toBe( proxy );
		const attributes = ( await firstBlock( page ) ).attributes;
		expect( ( attributes.src || attributes.href ).startsWith( HUB_UPLOADS ) ).toBe( true );
		expect( errors ).toEqual( [] );
	} );
}

test( 'a media dialog opened the way other plugins open it has the Network tab', async ( { page } ) => {
	const errors = watchErrors( page );
	await newFrenchPost( page );
	await page.evaluate( () => {
		window.windroseTestFrame = window.wp.media( { title: 'Plugin picker', library: { type: 'video' }, multiple: false } );
		window.windroseTestFrame.on( 'select', () => {
			window.windroseTestPicked = window.windroseTestFrame.state().get( 'selection' ).first().id;
		} );
		window.windroseTestFrame.open();
	} );
	const modal = page.locator( '.media-modal:visible' );
	await modal.getByRole( 'tab', { name: 'Network' } ).click();
	await modal.locator( `li.attachment[data-id="-${ state.hubs.video }"]` ).click();
	await expect( modal.locator( 'li.attachment[aria-checked="true"]' ) ).toHaveCount( 1 );
	await modal.locator( '.media-button-select' ).click();
	await expect.poll( () => page.evaluate( () => window.windroseTestPicked ) ).toBe( proxyOf( state.hubs.video ) );
	expect( errors ).toEqual( [] );
} );

test( 'inserter: an image dragged into the post becomes the site’s network item', async ( { page } ) => {
	const errors = watchErrors( page );
	await newFrenchPost( page );
	await page.locator( '.editor-document-tools__inserter-toggle' ).click();
	await page.locator( '[role="tab"][id$="-media"]' ).click();
	await page.locator( '.block-editor-inserter__media-panel button[aria-haspopup="menu"]' ).first().click();
	await page.getByRole( 'menuitemradio', { name: 'Network images' } ).click();
	const preview = page.getByRole( 'gridcell', { name: FILES.image.title } ).first().locator( '.block-editor-inserter__media-grid__preview' );
	await expect( preview ).toBeVisible();
	const target = page.frameLocator( 'iframe[name="editor-canvas"]' ).locator( '.block-editor-default-block-appender, .block-editor-block-list__layout' ).first();
	const created = page.waitForResponse( ( r ) => r.url().includes( 'windrose-anchors/v1/proxies' ) && r.request().method() === 'POST' );
	await preview.dragTo( target );
	const proxy = ( await ( await created ).json() ).id;
	await expect.poll( async () => {
		const blocks = await page.evaluate( () => window.wp.data.select( 'core/block-editor' ).getBlocks() );
		return blocks.find( ( block ) => block.name === 'core/image' )?.attributes.id;
	} ).toBe( proxy );
	expect( proxyOf( state.hubs.image ) ).toBe( proxy );
	const added = attachmentsOn( '/fr/' ).filter( ( id ) => ! state.frBefore.includes( id ) );
	expect( added ).toContain( proxy );
	expect( added.every( ( id ) => wpAt( '/fr/', 'post', 'meta', 'get', String( id ), '_windrose_anchors_hub' ) !== '' ) ).toBe( true );
	expect( errors ).toEqual( [] );
} );

test.afterAll( () => restoreHubs() );
