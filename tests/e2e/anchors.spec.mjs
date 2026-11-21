/**
 * Windrose Anchors on a test network, end to end: one hub file used on /fr/
 * and /ar/ with different alt texts; deleting the French item keeps the file; deleting the hub item is refused.
 *
 * Everything the tests create (one hub image, the network items, two posts, one upload) is deleted afterwards, and
 * the allowed sites are put back as they were.
 */
import { existsSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { test, expect } from '@playwright/test';
import { expectAccessible, recordExternal, HOST, USER, defaultHubs } from './helpers.mjs';

const BASE = `https://${ HOST }`;
const HUB_UPLOADS = `${ BASE }/wp-content/uploads/`;
const IMAGE = '/var/www/html/tools/wp-tests/tests/phpunit/data/images/canola.jpg';
const LIBRARY = /^(Media Library|Médiathèque|مكتبة الوسائط)$/;
const SITES = {
	fr: { path: '/fr/', id: 2, alt: 'Champ de colza en fleurs' },
	ar: { path: '/ar/', id: 3, alt: 'حقل الكانولا' },
};

/** WP-CLI on one site of the test network. */
function wpAt( path, ...args ) {
	return execFileSync( 'wp', [ ...args, '--path=/var/www/html/web', `--url=${ BASE }${ path }` ], {
		encoding: 'utf8',
		stdio: [ 'ignore', 'pipe', 'pipe' ],
	} ).trim();
}

/** This site's network items of the test image. */
function proxiesOn( path, hub ) {
	const ids = wpAt( path, 'post', 'list', '--post_type=attachment', '--post_status=any', '--meta_key=_windrose_anchors_hub', `--meta_value=${ hub }`, '--format=ids' );
	return ids ? ids.split( ' ' ).map( Number ) : [];
}

const state = { hub: 0, files: [], sitesBefore: null, posts: {}, uploads: [], extraPosts: [] };

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
	state.hub = Number( wpAt( '/', 'media', 'import', IMAGE, '--title=Anchors e2e canola', '--alt=Canola on the hub', '--porcelain' ) );
	state.files = JSON.parse(
		wpAt(
			'/',
			'eval',
			`$f = get_attached_file( ${ state.hub } ); $m = wp_get_attachment_metadata( ${ state.hub } ); $o = array( $f ); foreach ( $m['sizes'] as $s ) { $o[] = dirname( $f ) . '/' . $s['file']; } echo wp_json_encode( $o );`
		)
	);
	expect( state.files.length ).toBeGreaterThanOrEqual( 3 );
} );

test.afterAll( () => {
	for ( const site of Object.values( SITES ) ) {
		if ( state.posts[ site.path ] ) {
			wpAt( site.path, 'post', 'delete', String( state.posts[ site.path ] ), '--force' );
		}
		for ( const id of proxiesOn( site.path, state.hub ) ) {
			wpAt( site.path, 'post', 'delete', String( id ), '--force' );
		}
	}
	for ( const [ path, id ] of state.extraPosts ) {
		wpAt( path, 'post', 'delete', String( id ), '--force' );
	}
	for ( const id of state.uploads ) {
		wpAt( '/', 'post', 'delete', String( id ), '--force' );
	}
	if ( state.hub ) {
		try {
			wpAt( '/', 'post', 'meta', 'delete', String( state.hub ), '_windrose_anchors_usage' );
		} catch {} // Already gone with the last network item.
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
	return errors;
}

test( 'network admin allows the French and Arabic sites (keyboard, WCAG 2.2 AA)', async ( { page } ) => {
	const errors = watchErrors( page );
	const external = recordExternal( page );
	await page.goto( '/wp-admin/network/admin.php?page=windrose-anchors' );
	await expect( page.getByRole( 'heading', { level: 1, name: 'Windrose Anchors' } ) ).toBeVisible();
	const row = ( address ) => page.getByRole( 'row' ).filter( { hasText: `${ HOST }${ address }` } );
	await expect( row( '/fr/' ) ).toBeVisible();
	await expect( page.getByRole( 'row' ).filter( { hasText: 'Main site, always a media hub' } ) ).toHaveCount( 1 );
	await page.waitForLoadState( 'networkidle' );
	await expectAccessible( page, '#windrose-anchors-app' );

	wpAt( '/', 'site', 'option', 'update', 'windrose_anchors_sites', '[]', '--format=json' );
	await page.reload();
	await row( '/fr/' ).getByRole( 'button', { name: 'Allow' } ).click();
	await expect( page.locator( '.components-notice' ).getByText( '1 site can now use network media.' ) ).toBeVisible();
	// Keyboard only for the Arabic site.
	await row( '/ar/' ).getByRole( 'button', { name: 'Allow' } ).focus();
	await page.keyboard.press( 'Enter' );
	await expect( row( '/ar/' ) ).toContainText( 'Allowed' );
	expect( JSON.parse( wpAt( '/', 'site', 'option', 'get', 'windrose_anchors_sites', '--format=json' ) ) ).toEqual( [ 2, 3 ] );
	await expect( row( '/social/' ) ).toContainText( 'Not allowed' );

	expect( errors ).toEqual( [] );
	expect( external ).toEqual( [] );
} );

test( 'the main site shares the image from the media list', async ( { page } ) => {
	await page.goto( '/wp-admin/upload.php?mode=list' );
	const row = page.locator( `#post-${ state.hub }` );
	await expect( row.locator( '.column-windrose_anchors' ) ).toContainText( 'Not shared' );
	await row.hover();
	await row.getByRole( 'link', { name: 'Share “Anchors e2e canola” with the network' } ).click();
	await expect( page.locator( '.notice-success' ) ).toContainText( 'The media item is shared with the network.' );
	await expect( page.locator( `#post-${ state.hub } .column-windrose_anchors` ) ).toHaveText( 'Shared' );
	expect( wpAt( '/', 'post', 'meta', 'get', String( state.hub ), '_windrose_anchors_shared' ) ).toBe( '1' );
} );

test( 'the main site: bulk Stop sharing and Share, and the Network filter', async ( { page } ) => {
	const bulk = async ( action ) => {
		await page.locator( `#cb-select-${ state.hub }` ).check();
		await page.locator( '#bulk-action-selector-top' ).selectOption( action );
		await page.locator( '#doaction' ).click();
	};
	await page.goto( '/wp-admin/upload.php?mode=list' );
	await bulk( 'windrose_anchors_unshare' );
	await expect( page.locator( '.notice-success' ) ).toContainText( '1 media item is no longer shared with the network.' );
	await expect( page.locator( `#post-${ state.hub } .column-windrose_anchors` ) ).toContainText( 'Not shared' );
	expect( page.url() ).not.toContain( 'windrose_anchors_unshared' );
	await bulk( 'windrose_anchors_share' );
	await expect( page.locator( '.notice-success' ) ).toContainText( '1 media item is shared with the network.' );
	await expect( page.locator( `#post-${ state.hub } .column-windrose_anchors` ) ).toHaveText( 'Shared' );

	const filter = async ( label ) => {
		await page.getByLabel( 'Filter by network media' ).selectOption( { label } );
		await page.locator( '#post-query-submit' ).click();
	};
	await filter( 'Shared with the network' );
	await expect( page.locator( `#post-${ state.hub }` ) ).toBeVisible();
	await filter( 'Not shared' );
	await expect( page.locator( `#post-${ state.hub }` ) ).toHaveCount( 0 );
	await expectAccessible( page, '.wp-filter' );
} );

test( 'ar: Media, Network media adds the image to the site (WCAG 2.2 AA)', async ( { page } ) => {
	const errors = watchErrors( page );
	await page.goto( '/ar/wp-admin/upload.php?page=windrose-anchors-network' );
	await expect( page.getByRole( 'heading', { level: 1, name: 'Network media' } ) ).toBeVisible();
	const add = page.getByRole( 'button', { name: 'Add to this site Anchors e2e canola' } );
	await expect( add ).toBeVisible();
	await page.waitForLoadState( 'networkidle' );
	await expectAccessible( page, '#windrose-anchors-library-app' );
	await add.click();
	await expect( page.locator( '.components-notice' ).getByText( '1 media item added to this site.' ) ).toBeVisible();
	await expect( add ).toHaveCount( 0 );
	expect( proxiesOn( '/ar/', state.hub ) ).toHaveLength( 1 );
	expect( errors ).toEqual( [] );
} );

for ( const [ key, site ] of Object.entries( SITES ) ) {
	test( `${ key }: the editor picks the network image in the image block, with its own alt text`, async ( { page } ) => {
		const errors = watchErrors( page );
		const external = recordExternal( page );
		await page.goto( `${ site.path }wp-admin/post-new.php` );
		await page.waitForFunction( () => window.wp?.data?.select( 'core/editor' )?.getCurrentPostId() );
		state.posts[ site.path ] = await page.evaluate( () => window.wp.data.select( 'core/editor' ).getCurrentPostId() );
		await page.evaluate( () => {
			window.wp.data.dispatch( 'core/block-editor' ).insertBlocks( window.wp.blocks.createBlock( 'core/image' ) );
		} );
		await page.frameLocator( 'iframe[name="editor-canvas"]' ).getByRole( 'button', { name: LIBRARY } ).click();

		const modal = page.locator( '.media-modal:visible' );
		await expect( modal.getByRole( 'tab', { name: 'This site' } ) ).toBeVisible();
		await modal.getByRole( 'tab', { name: 'Network' } ).click();
		const item = modal.locator( `li.attachment[data-id="-${ state.hub }"]` );
		await expect( item ).toBeVisible();
		if ( key === 'ar' ) {
			await expect( page.locator( 'html' ) ).toHaveAttribute( 'dir', 'rtl' );
			await expectAccessible( page, '.media-modal .media-router' );
			await expectAccessible( page, '.media-modal .attachments-browser' );
		}

		const created = page.waitForResponse( ( r ) => r.url().includes( 'windrose-anchors/v1/proxies' ) && r.request().method() === 'POST' );
		await item.click();
		const proxy = ( await ( await created ).json() ).id;
		expect( proxy ).toBeGreaterThan( 0 );
		await expect( modal.locator( `li.attachment[data-id="${ proxy }"]` ) ).toHaveAttribute( 'aria-checked', 'true' );
		await expect( modal.locator( '.media-sidebar .compat-item' ) ).toContainText( 'From the network' );
		await expect( modal.locator( '.attachment-details .edit-attachment' ) ).toHaveCount( 0 );

		const saved = page.waitForResponse( ( r ) => r.url().includes( 'admin-ajax.php' ) && ( r.request().postData() || '' ).includes( 'action=save-attachment' ) );
		await modal.locator( '#attachment-details-alt-text' ).fill( site.alt );
		await modal.locator( '#attachment-details-alt-text' ).press( 'Tab' );
		await saved;
		await modal.locator( '.media-button-select' ).click();
		await expect( modal ).toBeHidden();

		const block = await page.evaluate( () => window.wp.data.select( 'core/block-editor' ).getBlocks()[ 0 ].attributes );
		expect( block.id ).toBe( proxy );
		expect( block.url.startsWith( HUB_UPLOADS ) ).toBe( true );
		expect( block.alt ).toBe( site.alt );

		await page.evaluate( async ( title ) => {
			window.wp.data.dispatch( 'core/editor' ).editPost( { title, status: 'publish' } );
			await window.wp.data.dispatch( 'core/editor' ).savePost();
		}, `Anchors e2e ${ key }` );
		await page.waitForFunction( () => window.wp.data.select( 'core/editor' ).getCurrentPostAttribute( 'status' ) === 'publish' );
		site.proxy = proxy;

		expect( errors ).toEqual( [] );
		expect( external ).toEqual( [] );
	} );
}

test( 'fr: the featured image dialog has the Network tab too', async ( { page } ) => {
	const errors = watchErrors( page );
	await page.goto( '/fr/wp-admin/post-new.php' );
	await page.waitForFunction( () => window.wp?.data?.select( 'core/editor' )?.getCurrentPostId() );
	const post = await page.evaluate( () => window.wp.data.select( 'core/editor' ).getCurrentPostId() );
	state.extraPosts.push( [ '/fr/', post ] );
	await page.evaluate( () => window.wp.data.dispatch( 'core/edit-post' ).openGeneralSidebar( 'edit-post/document' ) );
	await page.getByRole( 'button', { name: /^(Set featured image|Définir l’image mise en avant)$/ } ).click();
	const modal = page.locator( '.media-modal:visible' );
	await modal.getByRole( 'tab', { name: 'Network' } ).click();
	// The French item exists already: the network item is swapped for it, no second one is created.
	const reused = page.waitForResponse( ( r ) => r.url().includes( 'windrose-anchors/v1/proxies' ) );
	await modal.locator( `li.attachment[data-id="-${ state.hub }"]` ).click();
	expect( ( await reused ).status() ).toBe( 200 );
	await modal.locator( '.media-button-select' ).click();
	await expect.poll( () => page.evaluate( () => window.wp.data.select( 'core/editor' ).getEditedPostAttribute( 'featured_media' ) ) ).toBe( SITES.fr.proxy );
	expect( proxiesOn( '/fr/', state.hub ) ).toEqual( [ SITES.fr.proxy ] );
	expect( errors ).toEqual( [] );
} );

// The block editor empties and reloads the open tab's list each time the dialog opens, and core remembers the tab.
for ( const path of [ '/', '/fr/' ] ) {
	test( `${ path }: the dialog opens again on the Network tab, listed from page 1`, async ( { page } ) => {
		const errors = watchErrors( page );
		const firstPages = [];
		page.on( 'request', ( r ) => {
			const url = new URL( r.url() );
			if ( decodeURIComponent( url.href ).includes( 'windrose-anchors/v1/network' ) && url.searchParams.get( 'page' ) === '1' ) {
				firstPages.push( url.href );
			}
		} );
		const modal = page.locator( '.media-modal:visible' );
		const network = modal.getByRole( 'tab', { name: 'Network' } );
		const items = modal.locator( 'li.attachment' );
		const hubItem = modal.locator( `li.attachment[data-id="${ state.hub }"], li.attachment[data-id="-${ state.hub }"]` );
		const featured = page.locator( '.media-edit__picker-button' );
		const listedOnce = async () => {
			await expect( network ).toHaveAttribute( 'aria-selected', 'true' );
			await expect( items.first() ).toBeVisible();
			const ids = await items.evaluateAll( ( list ) => list.map( ( li ) => li.dataset.id ) );
			expect( new Set( ids ).size ).toBe( ids.length );
		};

		await page.goto( `${ path }wp-admin/post-new.php` );
		await page.waitForFunction( () => window.wp?.data?.select( 'core/editor' )?.getCurrentPostId() );
		state.extraPosts.push( [ path, await page.evaluate( () => window.wp.data.select( 'core/editor' ).getCurrentPostId() ) ] );
		await page.evaluate( () => window.wp.data.dispatch( 'core/edit-post' ).openGeneralSidebar( 'edit-post/document' ) );

		await featured.click();
		await network.click();
		await hubItem.click();
		await expect( modal.locator( '.media-button-select' ) ).toBeEnabled();
		await modal.locator( '.media-button-select' ).click();
		await expect( modal ).toBeHidden();
		await expect.poll( () => page.evaluate( () => window.wp.data.select( 'core/editor' ).getEditedPostAttribute( 'featured_media' ) ) ).toBeGreaterThan( 0 );

		// Again in the same page load: core restores the Network tab, then empties and reloads its list.
		firstPages.length = 0;
		await featured.click();
		await listedOnce();
		expect( firstPages ).toHaveLength( 1 );
		await page.keyboard.press( 'Escape' );
		await expect( modal ).toBeHidden();

		// After a reload, the tab comes from the saved user setting.
		await page.evaluate( () => window.wp.data.dispatch( 'core/editor' ).savePost() );
		await page.reload();
		await page.waitForFunction( () => window.wp?.data?.select( 'core/editor' )?.getCurrentPostId() );
		expect( await page.evaluate( () => window.getUserSetting( 'libraryContent' ) ) ).toBe( 'windrose_anchors_network' );
		await page.evaluate( () => window.wp.data.dispatch( 'core/edit-post' ).openGeneralSidebar( 'edit-post/document' ) );
		await featured.click();
		await listedOnce();
		await page.keyboard.press( 'Escape' );
		await expect( modal ).toBeHidden();

		// The tab's IDs up to 0.5.2 (browser cookie, user meta) open "This site" instead of an empty dialog.
		for ( const old of [ 'windrose-network', 'windrosenetwork' ] ) {
			await page.evaluate( ( value ) => window.setUserSetting( 'libraryContent', value ), old );
			await featured.click();
			await expect( modal.getByRole( 'tab', { name: 'This site' } ) ).toHaveAttribute( 'aria-selected', 'true' );
			await expect( items.first() ).toBeVisible();
			await page.keyboard.press( 'Escape' );
			await expect( modal ).toBeHidden();
		}
		// The next page load saves the last tab ("This site") for the test user, as before the test.
		await page.reload();
		expect( errors ).toEqual( [] );
	} );
}

// Opens the main site's editor with one block, the Network tab remembered, and returns the post's ID.
async function hubEditorWith( page, name, attributes = {} ) {
	await page.goto( '/wp-admin/post-new.php' );
	await page.waitForFunction( () => window.wp?.data?.select( 'core/editor' )?.getCurrentPostId() );
	state.extraPosts.push( [ '/', await page.evaluate( () => window.wp.data.select( 'core/editor' ).getCurrentPostId() ) ] );
	await page.evaluate(
		( [ block, attrs ] ) => {
			window.setUserSetting( 'libraryContent', 'windrose_anchors_network' );
			window.wp.data.dispatch( 'core/block-editor' ).insertBlocks( window.wp.blocks.createBlock( block, attrs ) );
		},
		[ name, attributes ]
	);
}

test( 'main site: Replace in the image block opens on the remembered Network tab', async ( { page } ) => {
	const errors = watchErrors( page );
	const url = wpAt( '/', 'eval', `echo wp_get_attachment_url( ${ state.hub } );` );
	await hubEditorWith( page, 'core/image', { id: state.hub, url } );
	await page.frameLocator( 'iframe[name="editor-canvas"]' ).locator( '.wp-block-image img' ).click();
	await page.getByRole( 'toolbar', { name: 'Block tools' } ).getByRole( 'button', { name: 'Replace' } ).click();
	await page.getByRole( 'menuitem', { name: /Media Library/ } ).click();

	const modal = page.locator( '.media-modal:visible' );
	await expect( modal.getByRole( 'tab', { name: 'Network' } ) ).toHaveAttribute( 'aria-selected', 'true' );
	const item = modal.locator( `li.attachment[data-id="${ state.hub }"]` );
	await expect( item ).toHaveCount( 1 );
	if ( ( await item.getAttribute( 'aria-checked' ) ) !== 'true' ) {
		await item.click();
	}
	await modal.locator( '.media-button-select' ).click();
	await expect( modal ).toBeHidden();
	expect( await page.evaluate( () => window.wp.data.select( 'core/block-editor' ).getBlocks()[ 0 ].attributes.id ) ).toBe( state.hub );
	expect( errors ).toEqual( [] );
} );

test( 'main site: a gallery made from the Network tab', async ( { page } ) => {
	const errors = watchErrors( page );
	await hubEditorWith( page, 'core/gallery' );
	await page.frameLocator( 'iframe[name="editor-canvas"]' ).getByRole( 'button', { name: LIBRARY } ).click();

	const modal = page.locator( '.media-modal:visible' );
	const network = modal.getByRole( 'tab', { name: 'Network' } );
	await expect( network ).toBeVisible();
	if ( ( await network.getAttribute( 'aria-selected' ) ) !== 'true' ) {
		await network.click();
	}
	await modal.locator( `li.attachment[data-id="${ state.hub }"]` ).click();
	// "Create a new gallery", then "Insert gallery".
	await modal.locator( '.media-toolbar-primary .media-button:visible' ).click();
	await modal.locator( '.media-toolbar-primary .media-button:visible' ).click();
	await expect( modal ).toBeHidden();
	await expect
		.poll( () => page.evaluate( () => window.wp.data.select( 'core/block-editor' ).getBlocks()[ 0 ].innerBlocks.map( ( image ) => image.attributes.id ) ) )
		.toEqual( [ state.hub ] );
	expect( errors ).toEqual( [] );
} );

test( 'main site: the Network tab loads more items and searches on the server', async ( { page } ) => {
	const errors = watchErrors( page );
	const stamp = Date.now();
	// 45 shared items without files, a minute apart: more than one page (40) whatever the test network already shares.
	const bulk = wpAt(
		'/',
		'eval',
		`$ids = array(); for ( $i = 1; $i <= 45; $i++ ) { $id = wp_insert_attachment( array( 'post_title' => sprintf( 'anchorsbulk${ stamp }x%03d', $i ), 'post_mime_type' => 'image/png', 'post_status' => 'inherit', 'post_date' => gmdate( 'Y-m-d H:i:s', time() - $i * 60 ) ) ); update_post_meta( $id, '_windrose_anchors_shared', '1' ); $ids[] = $id; } echo implode( ' ', $ids );`
	).split( ' ' );
	try {
		const requests = [];
		page.on( 'request', ( r ) => {
			const address = decodeURIComponent( r.url() );
			if ( address.includes( 'windrose-anchors/v1/network' ) ) {
				requests.push( new URL( address.replace( '?rest_route=', '?x=' ).replace( /\/network&/, '/network?' ) ).searchParams );
			}
		} );
		await hubEditorWith( page, 'core/image' );
		await page.frameLocator( 'iframe[name="editor-canvas"]' ).getByRole( 'button', { name: LIBRARY } ).click();
		const modal = page.locator( '.media-modal:visible' );
		// The block's placeholder always opens the dialog on "This site".
		await modal.getByRole( 'tab', { name: 'Network' } ).click();
		const items = modal.locator( 'li.attachment' );
		await expect.poll( () => items.count() ).toBeGreaterThanOrEqual( 40 );

		// The second page: core's "Load more" button, or on scroll where infinite scrolling is on.
		const loadMore = modal.getByRole( 'button', { name: 'Load more' } );
		await expect
			.poll( async () => {
				if ( await loadMore.isVisible() ) {
					await loadMore.click();
				} else {
					await modal.locator( 'ul.attachments' ).evaluate( ( list ) => list.scrollTo( 0, list.scrollHeight ) );
				}
				const listed = await items.evaluateAll( ( list ) => list.map( ( li ) => li.dataset.id ) );
				return bulk.filter( ( id ) => ! listed.includes( id ) ).length;
			} )
			.toBe( 0 );
		const ids = await items.evaluateAll( ( list ) => list.map( ( li ) => li.dataset.id ) );
		expect( new Set( ids ).size ).toBe( ids.length );
		expect( requests.some( ( query ) => query.get( 'page' ) === '2' ) ).toBe( true );

		// One item matches; the search is the server's, so the item need not be loaded already.
		await modal.getByRole( 'searchbox' ).fill( `anchorsbulk${ stamp }x001` );
		await expect( items ).toHaveCount( 1 );
		await expect( items ).toHaveAttribute( 'data-id', bulk[ 0 ] );
		expect( requests.some( ( query ) => query.get( 'search' ) === `anchorsbulk${ stamp }x001` && query.get( 'page' ) === '1' ) ).toBe( true );

		await modal.getByRole( 'searchbox' ).fill( '' );
		await expect.poll( () => items.count() ).toBeGreaterThanOrEqual( 40 );
		expect( errors ).toEqual( [] );
	} finally {
		wpAt( '/', 'post', 'delete', ...bulk, '--force' );
	}
} );

test( 'fr: the image of a draft of the main site is offered to its readers, marked, until the post is published', async ( { page } ) => {
	const errors = watchErrors( page );
	const draft = Number( wpAt( '/', 'post', 'create', '--post_title=Anchors e2e draft', '--post_status=draft', `--post_author=${ USER }`, '--porcelain' ) );
	const image = Number( wpAt( '/', 'media', 'import', IMAGE, `--post_id=${ draft }`, '--title=Anchors e2e draft image', '--porcelain' ) );
	wpAt( '/', 'post', 'meta', 'update', String( image ), '_windrose_anchors_shared', '1' );
	try {
		const modal = page.locator( '.media-modal:visible' );
		const item = modal.locator( `li.attachment[data-id="-${ image }"]` );
		const openNetworkTab = async () => {
			await page.goto( '/fr/wp-admin/post-new.php' );
			await page.waitForFunction( () => window.wp?.data?.select( 'core/editor' )?.getCurrentPostId() );
			state.extraPosts.push( [ '/fr/', await page.evaluate( () => window.wp.data.select( 'core/editor' ).getCurrentPostId() ) ] );
			await page.evaluate( () => window.wp.data.dispatch( 'core/edit-post' ).openGeneralSidebar( 'edit-post/document' ) );
			await page.locator( '.media-edit__picker-button' ).click();
			const network = modal.getByRole( 'tab', { name: 'Network' } );
			if ( ( await network.getAttribute( 'aria-selected' ) ) !== 'true' ) {
				await network.click();
			}
			await expect( item ).toBeVisible();
		};

		await openNetworkTab();
		await expect( item.locator( '.windrose-anchors-badge' ) ).toHaveText( 'Not published yet' );
		await expect( item ).toHaveAttribute( 'title', /^Attached to a draft on / );
		await expect( item ).toHaveAttribute( 'aria-label', /Attached to a draft on / );

		const created = page.waitForResponse( ( r ) => r.url().includes( 'windrose-anchors/v1/proxies' ) && r.request().method() === 'POST' );
		await item.click();
		const response = await created;
		expect( response.status() ).toBe( 201 );
		const proxy = ( await response.json() ).id;
		await expect( modal.locator( `li.attachment[data-id="${ proxy }"]` ) ).toHaveAttribute( 'aria-checked', 'true' );
		await modal.locator( '.media-button-select' ).click();
		await expect( modal ).toBeHidden();
		await page.evaluate( async () => {
			window.wp.data.dispatch( 'core/editor' ).editPost( { title: 'Anchors e2e brouillon' } );
			await window.wp.data.dispatch( 'core/editor' ).savePost();
		} );
		expect( await page.evaluate( () => window.wp.data.select( 'core/editor' ).getCurrentPostAttribute( 'featured_media' ) ) ).toBe( proxy );

		// Published on the main site: listed to everyone, so the mark goes.
		wpAt( '/', 'post', 'update', String( draft ), '--post_status=publish' );
		await openNetworkTab();
		await expect( item.locator( '.windrose-anchors-badge' ) ).toHaveCount( 0 );
		expect( await item.getAttribute( 'title' ) ).toBeNull();
		expect( errors ).toEqual( [] );
	} finally {
		for ( const id of proxiesOn( '/fr/', image ) ) {
			wpAt( '/fr/', 'post', 'delete', String( id ), '--force' );
		}
		try {
			wpAt( '/', 'post', 'meta', 'delete', String( image ), '_windrose_anchors_usage' );
		} catch {} // Already gone with the network item.
		wpAt( '/', 'post', 'delete', String( image ), '--force' );
		wpAt( '/', 'post', 'delete', String( draft ), '--force' );
	}
} );

test( 'social: a site that is not allowed has no Network tab', async ( { page } ) => {
	await page.goto( '/social/wp-admin/post-new.php' );
	await page.waitForFunction( () => window.wp?.data?.select( 'core/editor' )?.getCurrentPostId() );
	state.extraPosts.push( [ '/social/', await page.evaluate( () => window.wp.data.select( 'core/editor' ).getCurrentPostId() ) ] );
	await page.evaluate( () => window.wp.data.dispatch( 'core/block-editor' ).insertBlocks( window.wp.blocks.createBlock( 'core/image' ) ) );
	// A Network tab remembered from an allowed site (the sites share the cookie) must not leave this dialog empty.
	await page.evaluate( () => window.setUserSetting( 'libraryContent', 'windrose_anchors_network' ) );
	await page.frameLocator( 'iframe[name="editor-canvas"]' ).getByRole( 'button', { name: LIBRARY } ).click();
	const modal = page.locator( '.media-modal:visible' );
	await expect( modal.getByRole( 'tab', { name: LIBRARY } ) ).toHaveAttribute( 'aria-selected', 'true' );
	await expect( modal.getByRole( 'tab', { name: 'Network' } ) ).toHaveCount( 0 );
} );

test( 'front end: both sites show the hub file with their own alt text', async ( { page, request } ) => {
	expect( JSON.parse( wpAt( '/', 'post', 'meta', 'get', String( state.hub ), '_windrose_anchors_usage', '--format=json' ) ) ).toEqual( [ 2, 3 ] );
	for ( const site of Object.values( SITES ) ) {
		await page.goto( `${ site.path }?p=${ state.posts[ site.path ] }` );
		const image = page.locator( `img.wp-image-${ site.proxy }` );
		await expect( image ).toHaveAttribute( 'alt', site.alt );
		expect( ( await image.getAttribute( 'src' ) ).startsWith( HUB_UPLOADS ) ).toBe( true );
		const srcset = ( await image.getAttribute( 'srcset' ) ) || '';
		const urls = srcset.split( ', ' ).map( ( candidate ) => candidate.split( ' ' )[ 0 ] );
		expect( urls.length ).toBeGreaterThanOrEqual( 2 );
		for ( const url of urls ) {
			expect( url.startsWith( HUB_UPLOADS ), url ).toBe( true );
			expect( ( await request.get( url ) ).status(), url ).toBe( 200 );
		}
	}
} );

test( 'fr: Media, Network media shows the image as on this site', async ( { page } ) => {
	await page.goto( '/fr/wp-admin/upload.php?page=windrose-anchors-network' );
	const show = page.getByRole( 'link', { name: 'Show Anchors e2e canola' } );
	await expect( show ).toHaveAttribute( 'href', `${ BASE }/fr/wp-admin/upload.php?item=${ SITES.fr.proxy }` );
	await expect( page.getByRole( 'button', { name: 'Add to this site Anchors e2e canola' } ) ).toHaveCount( 0 );
} );

test( 'fr: the Media Library marks and filters network items', async ( { page } ) => {
	await page.goto( '/fr/wp-admin/upload.php?mode=list' );
	await expect( page.locator( `#post-${ SITES.fr.proxy } .column-windrose_anchors` ) ).toContainText( 'From the network' );
	await page.getByLabel( 'Filter by network media' ).selectOption( { label: 'Not from the network' } );
	await page.locator( '#post-query-submit' ).click();
	await expect( page.locator( `#post-${ SITES.fr.proxy }` ) ).toHaveCount( 0 );

	await page.goto( '/fr/wp-admin/upload.php?mode=grid' );
	const tile = page.locator( `li.attachment[data-id="${ SITES.fr.proxy }"]` );
	await expect( tile.locator( '.windrose-anchors-badge' ) ).toHaveText( 'From the network' );
	await expect( tile ).toHaveAttribute( 'aria-label', 'Anchors e2e canola, From the network' );
	await page.locator( '#media-attachment-filters' ).selectOption( 'windrose-anchors-local' );
	await expect( tile ).toHaveCount( 0 );
	await page.locator( '#media-attachment-filters' ).selectOption( 'windrose-anchors-network' );
	await expect( tile ).toBeVisible();
	await expectAccessible( page, '.attachments-browser' );
} );

test( 'deleting the French item keeps every hub file; the hub item cannot be deleted while Arabic uses it', async ( { page } ) => {
	await page.goto( '/fr/wp-admin/upload.php?mode=list' );
	const row = page.locator( `#post-${ SITES.fr.proxy }` );
	await row.hover();
	let warning = '';
	page.once( 'dialog', ( dialog ) => {
		warning = dialog.message();
		dialog.accept();
	} );
	await expect( row.locator( '.row-actions .delete a' ) ).toHaveText( 'Delete from this site' );
	await row.locator( '.row-actions .delete a' ).click();
	await expect( page.locator( `#post-${ SITES.fr.proxy }` ) ).toHaveCount( 0 );
	expect( warning ).toContain( 'The file stays on its media hub' );
	for ( const file of state.files ) {
		expect( existsSync( file ), file ).toBe( true );
	}
	expect( JSON.parse( wpAt( '/', 'post', 'meta', 'get', String( state.hub ), '_windrose_anchors_usage', '--format=json' ) ) ).toEqual( [ 3 ] );

	await page.goto( '/wp-admin/upload.php?mode=list' );
	const hubRow = page.locator( `#post-${ state.hub }` );
	await expect( hubRow.locator( '.column-windrose_anchors' ) ).toHaveText( `Shared, used on ${ wpAt( '/ar/', 'option', 'get', 'blogname' ) }` );
	await expect( hubRow.locator( '.column-windrose_anchors a' ) ).toHaveAttribute( 'href', `${ BASE }/ar/wp-admin/upload.php` );
	await expect( hubRow.locator( '.row-actions .delete' ) ).toHaveCount( 0 );
	await expect( hubRow.locator( '.row-actions .windrose_anchors_unshare' ) ).toHaveCount( 0 );

	await page.goto( '/wp-admin/upload.php?mode=grid' );
	await page.locator( `li.attachment[data-id="${ state.hub }"]` ).click();
	const details = page.locator( '.media-modal:visible' );
	await expect( details.getByRole( 'checkbox', { name: 'Share with the network' } ) ).toBeDisabled();
	await expect( details ).toContainText( 'العربية' );
	await expect( details.locator( '.delete-attachment' ) ).toHaveCount( 0 );
	await expect( details.locator( '.edit-attachment' ) ).toHaveCount( 0 );

	const refused = await page.evaluate(
		( id ) => window.wp.apiFetch( { path: `/wp/v2/media/${ id }?force=true`, method: 'DELETE' } ).catch( ( e ) => e ),
		state.hub
	);
	expect( refused.code ).toBe( 'windrose_anchors_in_use' );
	expect( existsSync( state.files[ 0 ] ) ).toBe( true );
} );

test( 'Arabic: Reset to network values', async ( { page } ) => {
	await page.goto( '/ar/wp-admin/upload.php?mode=grid' );
	await page.locator( `li.attachment[data-id="${ SITES.ar.proxy }"]` ).click();
	const details = page.locator( '.media-modal:visible' );
	await expect( details.locator( '#attachment-details-two-column-alt-text' ) ).toHaveValue( SITES.ar.alt );
	await expect( details.locator( '.compat-field-windrose_anchors_network' ) ).toContainText( 'Changed on this site' );
	await expect( details.locator( '.delete-attachment' ) ).toHaveText( 'Delete from this site' );
	await details.locator( '.windrose-anchors-reset' ).click();
	await expect( details.locator( '#attachment-details-two-column-alt-text' ) ).toHaveValue( 'Canola on the hub' );
	expect( wpAt( '/ar/', 'post', 'meta', 'get', String( SITES.ar.proxy ), '_wp_attachment_image_alt' ) ).toBe( 'Canola on the hub' );

	// The edit screen says the same, and cancelling keeps the item.
	await page.goto( `/ar/wp-admin/post.php?post=${ SITES.ar.proxy }&action=edit` );
	const link = page.locator( '#delete-action .submitdelete' );
	await expect( link ).toHaveText( 'Delete from this site' );
	let warning = '';
	page.once( 'dialog', ( dialog ) => {
		warning = dialog.message();
		dialog.dismiss();
	} );
	await link.click();
	expect( warning ).toContain( 'The file stays on its media hub' );
	await expect( page ).toHaveURL( /action=edit/ );
	expect( proxiesOn( '/ar/', state.hub ) ).toEqual( [ SITES.ar.proxy ] );
} );

/** Waits for the next upload (REST in 7.2's block editor, classic uploader elsewhere) and returns the new ID. */
async function nextUpload( page, file ) {
	const response = page.waitForResponse(
		( r ) =>
			r.request().method() === 'POST' &&
			( /\/wp-json\/wp\/v2\/media(\?|$)/.test( r.url() ) || r.url().includes( 'async-upload.php' ) )
	);
	await page.locator( '.media-modal input[type="file"]' ).first().setInputFiles( file );
	const body = await ( await response ).json();
	return Number( body.id || body.data?.id );
}

test( 'main site: a file uploaded from the Network tab is shared at once', async ( { page } ) => {
	const errors = watchErrors( page );
	await page.goto( '/wp-admin/post-new.php' );
	await page.waitForFunction( () => window.wp?.data?.select( 'core/editor' )?.getCurrentPostId() );
	state.uploads.push( await page.evaluate( () => window.wp.data.select( 'core/editor' ).getCurrentPostId() ) );
	await page.evaluate( () => window.wp.data.dispatch( 'core/block-editor' ).insertBlocks( window.wp.blocks.createBlock( 'core/image' ) ) );
	await page.frameLocator( 'iframe[name="editor-canvas"]' ).getByRole( 'button', { name: LIBRARY } ).click();
	const modal = page.locator( '.media-modal:visible' );
	await modal.getByRole( 'tab', { name: 'Network' } ).click();
	await expect( modal.locator( `li.attachment[data-id="${ state.hub }"]` ) ).toBeVisible();

	const id = await nextUpload( page, IMAGE );
	state.uploads.push( id );
	await expect.poll( () => wpAt( '/', 'post', 'meta', 'get', String( id ), '_windrose_anchors_shared' ) ).toBe( '1' );
	await expect( modal.locator( `li.attachment[data-id="${ id }"]` ) ).toBeVisible();

	// From "This site", uploads are not shared.
	await modal.getByRole( 'tab', { name: 'This site' } ).click();
	const local = await nextUpload( page, IMAGE );
	state.uploads.push( local );
	await expect( page.locator( `.media-modal li.attachment[data-id="${ local }"]` ) ).toBeVisible();
	expect( () => wpAt( '/', 'post', 'meta', 'get', String( local ), '_windrose_anchors_shared' ) ).toThrow();
	expect( errors ).toEqual( [] );
} );

test.afterAll( () => restoreHubs() );
