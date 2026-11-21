/**
 * Windrose Anchors in the block inserter: "Network images", "Network videos" and "Network audio" media sources (the
 * main site's shared items of each type, only for types it shares) in the Media tab.
 *
 * On other sites, items carry a placeholder ID (minus the hub ID): the editor inserts an item with an ID as it is,
 * while one without an ID would be downloaded and uploaded as a copy. Right after insertion (click or drag), the
 * placeholder is swapped for the site's own network item (windrose-anchors/v1/proxies: created once, then reused).
 * Until then, the editor's request for the placeholder's media details waits for the swap instead of failing.
 * If the swap fails, the block is removed and the editor says why. Content saved with a placeholder left in it is
 * fixed on the server.
 */
import apiFetch from '@wordpress/api-fetch';
import { dispatch, select, subscribe } from '@wordpress/data';
import { __, sprintf } from '@wordpress/i18n';

const settings = window.windroseAnchorsInserter || {};
const BLOCKS = [ 'core/image', 'core/video', 'core/audio', 'core/file' ];

/** The inserter's media types and their source labels. */
const SOURCES = {
	image: {
		name: __( 'Network images', 'windrose-anchors' ),
		search: __( 'Search network images', 'windrose-anchors' ),
		empty: __(
			'No shared images yet. This site’s media hub chooses which media items it shares with the network.',
			'windrose-anchors'
		),
	},
	video: {
		name: __( 'Network videos', 'windrose-anchors' ),
		search: __( 'Search network videos', 'windrose-anchors' ),
		empty: __(
			'No shared videos yet. This site’s media hub chooses which media items it shares with the network.',
			'windrose-anchors'
		),
	},
	audio: {
		name: __( 'Network audio', 'windrose-anchors' ),
		search: __( 'Search network audio', 'windrose-anchors' ),
		empty: __(
			'No shared audio yet. This site’s media hub chooses which media items it shares with the network.',
			'windrose-anchors'
		),
	},
};
const PLACEHOLDER_MEDIA = /^\/wp\/v2\/media\/-(\d+)(?=[/?]|$)/;

/** Proxy requests in progress, by hub ID (two blocks of the same item share one request). */
const requests = new Map();

/** Blocks being swapped, by client ID. */
const swapping = new Set();

/**
 * The post being edited, when it is a real post (0 in the site editor's templates).
 *
 * @return {number} Post ID.
 */
function currentPost() {
	const editor = select( 'core/editor' );
	const id =
		editor && editor.getCurrentPostId ? editor.getCurrentPostId() : 0;
	return Number.isInteger( id ) && id > 0 ? id : 0;
}

/**
 * This site's network item of a hub item.
 *
 * @param {number} hub Hub attachment ID.
 * @return {Promise<Object>} The network item (as the media dialog gets it).
 */
function proxyFor( hub ) {
	if ( ! requests.has( hub ) ) {
		const request = apiFetch( {
			path: settings.restProxies,
			method: 'POST',
			data: { hub, post: currentPost() },
		} ).finally( () => requests.delete( hub ) );
		requests.set( hub, request );
	}
	return requests.get( hub );
}

/**
 * Swaps a block's placeholder ID for this site's network item.
 *
 * @param {string} clientId Block client ID.
 * @param {number} hub      Hub attachment ID.
 */
function swap( clientId, hub ) {
	swapping.add( clientId );
	const blockEditor = dispatch( 'core/block-editor' );
	proxyFor( hub )
		.then( ( item ) => {
			const attributes =
				select( 'core/block-editor' ).getBlockAttributes( clientId );
			if ( attributes && attributes.id === -hub ) {
				// Part of the insertion, not a change of its own to undo.
				blockEditor.__unstableMarkNextChangeAsNotPersistent();
				blockEditor.updateBlockAttributes( clientId, { id: item.id } );
			}
		} )
		.catch( ( error ) => {
			const attributes =
				select( 'core/block-editor' ).getBlockAttributes( clientId );
			if ( attributes && attributes.id === -hub ) {
				blockEditor.removeBlock( clientId, false );
			}
			dispatch( 'core/notices' ).createErrorNotice(
				sprintf(
					/* translators: %s: error message. */
					__(
						'The network media item could not be added to this site, so it was removed: %s',
						'windrose-anchors'
					),
					( error && error.message ) || ''
				),
				{ id: 'windrose-anchors-inserter' }
			);
		} )
		.finally( () => swapping.delete( clientId ) );
}

/**
 * Looks for media blocks with a placeholder ID (called on every block editor change; cheap: only media blocks).
 */
function findPlaceholders() {
	const blockEditor = select( 'core/block-editor' );
	if ( ! blockEditor || ! blockEditor.getBlocksByName ) {
		return;
	}
	blockEditor.getBlocksByName( BLOCKS ).forEach( ( clientId ) => {
		if ( swapping.has( clientId ) ) {
			return;
		}
		const id = blockEditor.getBlockAttributes( clientId )?.id;
		if ( Number.isInteger( id ) && id < 0 ) {
			swap( clientId, -id );
		}
	} );
}

/**
 * The editor asks for a placeholder's media details (the image block does when selected): wait for the swap and
 * answer with this site's network item instead of a 404.
 */
function placeholderMiddleware() {
	apiFetch.use( ( options, next ) => {
		const method = ( options.method || 'GET' ).toUpperCase();
		const match =
			method === 'GET' && typeof options.path === 'string'
				? options.path.match( PLACEHOLDER_MEDIA )
				: null;
		if ( ! match ) {
			return next( options );
		}
		const hub = Number( match[ 1 ] );
		return proxyFor( hub ).then( ( item ) =>
			next( {
				...options,
				path: options.path.replace(
					`/media/-${ hub }`,
					`/media/${ item.id }`
				),
			} )
		);
	} );
}

/**
 * A network item as the inserter shows it.
 *
 * @param {Object} item Item from windrose-anchors/v1/network.
 * @return {Object} Inserter media item.
 */
function toMediaItem( item ) {
	const title = item.title || item.filename || '';
	// An item attached to a draft of the media hub says so (only people who can read that draft see it).
	const note = item.windroseAnchors?.unpublished;
	return {
		id: item.id,
		url: item.url,
		previewUrl:
			item.sizes?.medium?.url || item.sizes?.thumbnail?.url || item.url,
		alt: item.alt || '',
		caption: item.caption || '',
		title: note
			? sprintf(
					/* translators: 1: media title, 2: why only some people see the item. */
					__( '%1$s, %2$s', 'windrose-anchors' ),
					title,
					note
				)
			: title,
	};
}

/**
 * Registers a network media source for one media type.
 *
 * @param {string} type image, video or audio.
 */
function registerCategory( type ) {
	const blockEditor = dispatch( 'core/block-editor' );
	const labels = SOURCES[ type ];
	if (
		! labels ||
		! blockEditor ||
		! blockEditor.registerInserterMediaCategory
	) {
		return;
	}
	blockEditor.registerInserterMediaCategory( {
		name: `windrose-anchors-network-${ type }`,
		labels: { name: labels.name, search_items: labels.search },
		mediaType: type,
		emptyMessage: labels.empty,
		async fetch( query = {} ) {
			const params = new URLSearchParams( {
				page: String( query.page || 1 ),
				per_page: String( Math.min( query.per_page || 20, 100 ) ),
				type,
			} );
			if ( query.search ) {
				params.set( 'search', query.search );
			}
			const response = await apiFetch( {
				path: `${ settings.restNetwork }?${ params.toString() }`,
				parse: false,
			} );
			const items = await response.json();
			return {
				mediaItems: items.map( toMediaItem ),
				totalItems: Number( response.headers.get( 'X-WP-Total' ) || 0 ),
				totalPages: Number(
					response.headers.get( 'X-WP-TotalPages' ) || 0
				),
			};
		},
	} );
}

( settings.types || [] ).forEach( registerCategory );
if ( ! settings.isHub ) {
	placeholderMiddleware();
	subscribe( findPlaceholders, 'core/block-editor' );
}
