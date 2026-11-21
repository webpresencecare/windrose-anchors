/**
 * Several media hubs on a test network: make a site a hub, choose the hub of another site, add an item of that
 * hub there, refuse to unmark a hub whose items are used, follow language groups (Windrose Sabir).
 *
 * Runs on two throwaway sites of its own ("/hubs-e2e-hub/", "/hubs-e2e-site/"), created and deleted by the test with
 * everything on them. The hub settings, the allowed sites and Windrose Sabir's groups are put back as they were.
 */
import { execFileSync } from 'node:child_process';
import { test, expect } from '@playwright/test';
import { expectAccessible, HOST } from './helpers.mjs';

const BASE = `https://${ HOST }`;
const IMAGE = '/var/www/html/tools/wp-tests/tests/phpunit/data/images/canola.jpg';
const TITLE = 'Hubs e2e canola';
const OPTIONS = [
	'windrose_anchors_sites',
	'windrose_anchors_hubs',
	'windrose_anchors_site_hubs',
	'windrose_anchors_follow_groups',
	'windrose_sabir_groups',
];

function wpAt( path, ...args ) {
	return execFileSync( 'wp', [ ...args, '--path=/var/www/html/web', `--url=${ BASE }${ path }` ], {
		encoding: 'utf8',
		stdio: [ 'ignore', 'pipe', 'pipe' ],
	} ).trim();
}

function tryWp( path, ...args ) {
	try {
		return wpAt( path, ...args );
	} catch {
		return null;
	}
}

const state = { before: {}, hub: 0, site: 0, image: 0, proxy: 0, sabirMeta: [] };

test.describe.configure( { mode: 'serial' } );

test.beforeAll( () => {
	for ( const option of OPTIONS ) {
		state.before[ option ] = tryWp( '/', 'site', 'option', 'get', option, '--format=json' );
	}
	state.sabirMeta = JSON.parse(
		wpAt( '/', 'eval', "global $wpdb; echo wp_json_encode( $wpdb->get_results( \"SELECT blog_id, meta_value FROM {$wpdb->blogmeta} WHERE meta_key = 'windrose_sabir_group'\", ARRAY_A ) );" )
	);
	state.hub = Number( wpAt( '/', 'site', 'create', '--slug=hubs-e2e-hub', '--title=Hubs e2e hub', '--porcelain' ) );
	state.site = Number( wpAt( '/', 'site', 'create', '--slug=hubs-e2e-site', '--title=Hubs e2e site', '--porcelain' ) );
	const allowed = state.before.windrose_anchors_sites ? JSON.parse( state.before.windrose_anchors_sites ) : [];
	wpAt( '/', 'site', 'option', 'update', 'windrose_anchors_sites', JSON.stringify( [ ...allowed, state.site ] ), '--format=json' );
	state.image = Number( wpAt( '/hubs-e2e-hub/', 'media', 'import', IMAGE, `--title=${ TITLE }`, '--alt=Alt on the second hub', '--porcelain' ) );
	wpAt( '/hubs-e2e-hub/', 'post', 'meta', 'update', String( state.image ), '_windrose_anchors_shared', '1' );
} );

test.afterAll( () => {
	if ( state.proxy > 0 ) {
		tryWp( '/hubs-e2e-site/', 'post', 'delete', String( state.proxy ), '--force' );
	}
	// Deleting the sites removes their uploads and their place in the hub settings.
	for ( const id of [ state.site, state.hub ] ) {
		if ( id ) {
			tryWp( '/', 'site', 'delete', String( id ), '--yes' );
			execFileSync( 'rm', [ '-rf', `/var/www/html/web/wp-content/uploads/sites/${ id }` ] );
		}
	}
	for ( const option of OPTIONS ) {
		tryWp( '/', 'site', 'option', 'delete', option );
		if ( null !== state.before[ option ] ) {
			wpAt( '/', 'site', 'option', 'update', option, state.before[ option ], '--format=json' );
		}
	}
	wpAt( '/', 'eval', "delete_metadata( 'blog', 0, 'windrose_sabir_group', '', true );" );
	for ( const row of state.sabirMeta ) {
		wpAt( '/', 'eval', `update_site_meta( ${ Number( row.blog_id ) }, 'windrose_sabir_group', ${ Number( row.meta_value ) } );` );
	}
} );

const row = ( page, slug ) => page.getByRole( 'row' ).filter( { hasText: `${ HOST }/${ slug }/` } );

async function rowAction( page, slug, name ) {
	await row( page, slug ).getByRole( 'button', { name: 'Actions' } ).click();
	await page.getByRole( 'menuitem', { name } ).click();
}

test( 'network admin makes a site a media hub and chooses it for another site (WCAG 2.2 AA)', async ( { page } ) => {
	await page.goto( '/wp-admin/network/admin.php?page=windrose-anchors' );
	await expect( page.getByRole( 'heading', { name: 'Media hubs' } ) ).toBeVisible();
	await expect( row( page, 'hubs-e2e-hub' ) ).toContainText( 'Not allowed' );

	await rowAction( page, 'hubs-e2e-hub', 'Make media hub' );
	await expect( page.locator( '.components-notice' ).getByText( 'Hubs e2e hub is now a media hub' ) ).toBeVisible();
	await expect( row( page, 'hubs-e2e-hub' ) ).toContainText( 'Media hub' );
	await expect( page.locator( '.windrose-anchors__hubs' ) ).toContainText( 'Hubs e2e hub' );

	await rowAction( page, 'hubs-e2e-site', 'Choose media hub' );
	const dialog = page.getByRole( 'dialog', { name: 'Choose media hub' } );
	await expectAccessible( page, '[role="dialog"]' );
	await dialog.getByLabel( 'Media hub' ).selectOption( { label: `Hubs e2e hub (${ HOST }/hubs-e2e-hub)` } );
	await dialog.getByRole( 'button', { name: 'Save' } ).click();
	await expect( page.locator( '.components-notice' ).getByText( '1 site now uses Hubs e2e hub' ) ).toBeVisible();
	await expect( row( page, 'hubs-e2e-site' ) ).toContainText( 'Hubs e2e hub' );
	await expectAccessible( page, '#windrose-anchors-app' );

	// Other hubs and choices made on the test network stay as they are.
	expect( JSON.parse( wpAt( '/', 'site', 'option', 'get', 'windrose_anchors_hubs', '--format=json' ) ) ).toContain( state.hub );
	expect( JSON.parse( wpAt( '/', 'site', 'option', 'get', 'windrose_anchors_site_hubs', '--format=json' ) ) ).toMatchObject( { [ state.site ]: state.hub } );
} );

test( 'the site adds an item of its own hub; the file stays on that hub', async ( { page, request } ) => {
	await page.goto( '/hubs-e2e-site/wp-admin/upload.php?page=windrose-anchors-network' );
	await expect( page.locator( '.windrose-anchors-library > p' ) ).toContainText( 'Hubs e2e hub' );
	const add = page.getByRole( 'button', { name: `Add to this site ${ TITLE }` } );
	await expect( add ).toBeVisible();
	await add.click();
	await expect( page.locator( '.components-notice' ).getByText( '1 media item added to this site.' ) ).toBeVisible();

	const found = JSON.parse(
		wpAt(
			'/hubs-e2e-site/',
			'eval',
			`$ids = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'any', 'fields' => 'ids', 'meta_key' => '_windrose_anchors_hub', 'meta_value' => '${ state.image }' ) ); $id = (int) ( $ids[0] ?? 0 ); echo wp_json_encode( array( 'id' => $id, 'link' => get_post_meta( $id, '_msls_linked', true ), 'url' => wp_get_attachment_url( $id ), 'alt' => get_post_meta( $id, '_wp_attachment_image_alt', true ) ) );`
		)
	);
	state.proxy = found.id;
	expect( found.id ).toBeGreaterThan( 0 );
	expect( found.link.blog ).toBe( state.hub );
	expect( found.url ).toContain( `/uploads/sites/${ state.hub }/` );
	expect( found.alt ).toBe( 'Alt on the second hub' );
	expect( ( await request.get( found.url ) ).status() ).toBe( 200 );
} );

test( 'a hub whose items are used cannot stop being one', async ( { page } ) => {
	await page.goto( '/wp-admin/network/admin.php?page=windrose-anchors' );
	await rowAction( page, 'hubs-e2e-hub', 'Stop being a media hub' );
	await expect( page.locator( '.components-notice' ).getByText( 'Other sites use media items of Hubs e2e hub' ) ).toBeVisible();
	await expect( row( page, 'hubs-e2e-hub' ) ).toContainText( 'Media hub' );
} );

test( 'follow language groups: the group’s main site becomes the hub of its sites', async ( { page } ) => {
	// A language group of Windrose Sabir with the two sites, the hub site as main site (written directly: both test
	// sites are in English, which Sabir's screen would refuse).
	wpAt( '/', 'site', 'option', 'update', 'windrose_sabir_groups', JSON.stringify( [ { id: 900, label: 'Hubs e2e group', main: state.hub } ] ), '--format=json' );
	wpAt( '/', 'eval', `update_site_meta( ${ state.hub }, 'windrose_sabir_group', 900 ); update_site_meta( ${ state.site }, 'windrose_sabir_group', 900 );` );
	wpAt( '/', 'site', 'option', 'update', 'windrose_anchors_site_hubs', '{}', '--format=json' );
	tryWp( '/', 'site', 'option', 'delete', 'windrose_anchors_follow_groups' );

	await page.goto( '/wp-admin/network/admin.php?page=windrose-anchors' );
	await expect( row( page, 'hubs-e2e-site' ) ).not.toContainText( 'Hubs e2e hub' );
	const toggle = page.getByRole( 'checkbox', { name: 'Follow language groups' } );
	await expect( toggle ).not.toBeChecked();
	await expect( page.locator( '.windrose-anchors__notice' ).filter( { hasText: 'as its main site' } ) ).toContainText( 'Hubs e2e hub' );
	await toggle.click();
	await expect( page.locator( '.components-notice' ).getByText( 'now uses its group’s main site as media hub' ) ).toBeVisible();
	await expect( row( page, 'hubs-e2e-site' ) ).toContainText( 'Hubs e2e hub' );
	expect( wpAt( '/', 'site', 'option', 'get', 'windrose_anchors_follow_groups' ) ).toBe( '1' );

	await expect( toggle ).toBeChecked();
	await expect( page.locator( '.windrose-anchors__notice' ).filter( { hasText: 'as its main site' } ) ).toHaveCount( 0 );
	await toggle.click();
	await expect( page.locator( '.components-notice' ).getByText( 'no longer followed' ) ).toBeVisible();
	await expect( row( page, 'hubs-e2e-site' ) ).not.toContainText( 'Hubs e2e hub' );
	await expect( toggle ).not.toBeChecked();
} );
