/**
 * Shared helpers for Windrose Anchors' Playwright tests, run on a WordPress multisite test network with WP-CLI.
 * Environment: WINDROSE_E2E_HOST (the network's host), WINDROSE_E2E_USER and WINDROSE_PASS (a super admin).
 */
import { execFileSync } from 'node:child_process';
import AxeBuilder from '@axe-core/playwright';
import { expect } from '@playwright/test';

export const HOST = process.env.WINDROSE_E2E_HOST ?? 'localhost';
export const USER = process.env.WINDROSE_E2E_USER ?? 'admin';

/** Runs WP-CLI on the test network. */
export function wp( ...args ) {
	return execFileSync( 'wp', [ ...args, '--path=/var/www/html/web', `--url=https://${ HOST }` ], {
		encoding: 'utf8',
	} ).trim();
}

/** Records every browser request to another host. */
export function recordExternal( page ) {
	const external = [];
	page.on( 'request', ( request ) => {
		const url = new URL( request.url() );
		if ( url.protocol.startsWith( 'http' ) && url.hostname !== HOST ) {
			external.push( `${ url.hostname }${ url.pathname }` );
		}
	} );
	return external;
}

/**
 * DataViews 22 draws its selection checkboxes (`@wordpress/ui` Checkbox) 16px wide with a 24px `::after` click area
 * that axe cannot see, so axe reports them under target-size although people get 24px (checked with real clicks on
 * 2026-11-10). Only those checkboxes are left out of target-size; everything else is still checked. Remove once fixed
 * upstream.
 *
 * @param {import('@playwright/test').Page} page       The page.
 * @param {Array}                           violations axe violations.
 * @return {Promise<Array>} The violations to report.
 */
async function withoutDataViewsCheckboxes( page, violations ) {
	const kept = [];
	for ( const violation of violations ) {
		if ( 'target-size' !== violation.id ) {
			kept.push( violation );
			continue;
		}
		const nodes = [];
		for ( const node of violation.nodes ) {
			const skipped = await page.evaluate( ( selector ) => {
				const element = document.querySelector( selector );
				return !! element && 'checkbox' === element.getAttribute( 'role' ) && !! element.closest( '.dataviews-wrapper' );
			}, String( node.target[ 0 ] ) );
			if ( ! skipped ) {
				nodes.push( node );
			}
		}
		if ( nodes.length ) {
			kept.push( { ...violation, nodes } );
		}
	}
	return kept;
}

/** WCAG 2.2 AA checks (axe) on part of the page. */
export async function expectAccessible( page, selector ) {
	const results = await new AxeBuilder( { page } )
		.include( selector )
		.withTags( [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa' ] )
		.analyze();
	const violations = await withoutDataViewsCheckboxes( page, results.violations );
	expect( violations.map( ( v ) => `${ v.id }: ${ v.nodes.map( ( n ) => n.target ).join( ', ' ) }` ) ).toEqual( [] );
}

const HUB_OPTIONS = [ 'windrose_anchors_hubs', 'windrose_anchors_site_hubs', 'windrose_anchors_follow_groups' ];

/** WP-CLI on the main site, quietly; null when it fails (for example an option that does not exist). */
function quietWp( ...args ) {
	try {
		return execFileSync( 'wp', [ ...args, '--path=/var/www/html/web', `--url=https://${ HOST }` ], {
			encoding: 'utf8',
			stdio: [ 'ignore', 'pipe', 'pipe' ],
		} ).trim();
	} catch {
		return null;
	}
}

/**
 * Puts the default media hub setup in place for a test file (the main site is the only hub and the hub of every site)
 * and returns a function that puts the test network's own setup back.
 */
export function defaultHubs() {
	const saved = {};
	for ( const option of HUB_OPTIONS ) {
		saved[ option ] = quietWp( 'site', 'option', 'get', option, '--format=json' );
		quietWp( 'site', 'option', 'delete', option );
	}
	return () => {
		for ( const option of HUB_OPTIONS ) {
			quietWp( 'site', 'option', 'delete', option );
			if ( null !== saved[ option ] ) {
				quietWp( 'site', 'option', 'update', option, saved[ option ], '--format=json' );
			}
		}
	};
}
