/**
 * Windrose Anchors in the media modal (Backbone, still WordPress's default modal in 7.2):
 *
 * - a "Network" tab next to "Upload files" and "This site" (core's "Media Library" tab), on sites allowed to use
 *   network media. It lists the main site's shared items through windrose-anchors/v1/network on the current site;
 * - on other sites, network items carry negative IDs (minus the hub ID). Picking one asks the current site for its
 *   network item (created once, then reused) and puts that local item in the selection instead, so blocks and
 *   fields always get an attachment of their own site. Nothing is selected until it exists;
 * - on the main site, the items are the main site's own, and files uploaded while the Network tab is open are
 *   shared at once (classic uploader and the REST uploads of 7.2's block editor);
 * - "Reset to network values" in the attachment details of a network item;
 * - no "Edit Image" button for network items, nor for main site items that other sites use;
 * - network filters in the type menu of the Media Library grid, and a badge on network and shared items;
 * - "Delete from this site" instead of "Delete permanently" on network items.
 */
import apiFetch from '@wordpress/api-fetch';
import { speak } from '@wordpress/a11y';
import { __, sprintf } from '@wordpress/i18n';

import './media.scss';

const settings = window.windroseAnchors || {};
const media = window.wp && window.wp.media;
const $ = window.jQuery;

// Core keeps the open tab in the user setting `libraryContent`, and wp_user_settings() drops every character that
// is not a letter, a digit or an underscore: no hyphen in this ID.
const TAB = 'windrose_anchors_network';
// The tab's ID up to 0.5.2, as the browser's cookie and as the user meta kept it.
const OLD_TABS = [ 'windrose-network', 'windrosenetwork' ];
const PER_PAGE = 40;

/**
 * Whether a media model is a network item (not yet on this site).
 *
 * @param {Object} model Attachment model.
 * @return {boolean} True for a network item.
 */
function isNetworkItem( model ) {
	const data = model && model.get( 'windroseAnchors' );
	return !! ( data && data.network );
}

/**
 * The media types a state's library is limited to, as the REST route expects them.
 *
 * @param {Object} library Library collection of the state.
 * @return {string} Comma-separated types, or ''.
 */
function libraryTypes( library ) {
	const type = library && library.props ? library.props.get( 'type' ) : '';
	if ( Array.isArray( type ) ) {
		return type.filter( Boolean ).join( ',' );
	}
	return typeof type === 'string' ? type : '';
}

/**
 * A collection of network items, loaded page by page (search runs on the server).
 *
 * @param {string} types Media types.
 * @return {Object} Collection.
 */
function createCollection( types ) {
	const Attachments = media.model.Attachments;
	const Collection = Attachments.extend( {
		initialize( models, options ) {
			Attachments.prototype.initialize.call( this, models, options );
			this.windrose = {
				page: 0,
				more: true,
				total: 0,
				run: 0,
				pending: null,
			};
			// The block editor's MediaUpload does this to the open tab's collection each time the dialog opens:
			// "destroy" on every model, `collection.mirroring._hasMore = true`, `collection.more()`. This collection
			// mirrors no query, so it gets a stand-in whose `_hasMore` starts again from page 1.
			const collection = this;
			this.mirroring = {
				// Core's browser reads the search term here to announce the number of results.
				get args() {
					return { s: collection.props.get( 'search' ) || '' };
				},
				set _hasMore( value ) {
					if ( value ) {
						collection.restart();
					}
				},
			};
			let timer;
			this.props.on( 'change:search', () => {
				window.clearTimeout( timer );
				timer = window.setTimeout( () => this.reload(), 300 );
			} );
		},

		// Search is done by the server, not by filtering what is loaded.
		_changeFilteredProps() {},

		hasMore() {
			return this.windrose.more;
		},

		getTotalAttachments() {
			return this.windrose.total;
		},

		more() {
			const state = this.windrose;
			if ( state.pending && state.pending.state() === 'pending' ) {
				return state.pending;
			}
			const deferred = $.Deferred();
			if ( ! state.more ) {
				return deferred.resolveWith( this, [ [] ] ).promise();
			}
			const run = state.run;
			const page = state.page + 1;
			const query = new URLSearchParams( {
				page: String( page ),
				per_page: String( PER_PAGE ),
			} );
			const search = this.props.get( 'search' );
			if ( search ) {
				query.set( 'search', search );
			}
			if ( types ) {
				query.set( 'type', types );
			}
			apiFetch( {
				path: `${ settings.restNetwork }?${ query.toString() }`,
				parse: false,
			} )
				.then( ( response ) =>
					response.json().then( ( items ) => {
						if ( run !== state.run ) {
							deferred.resolveWith( this, [ [] ] );
							return;
						}
						state.page = page;
						state.total = Number(
							response.headers.get( 'X-WP-Total' ) || 0
						);
						state.more =
							page <
							Number(
								response.headers.get( 'X-WP-TotalPages' ) || 0
							);
						this.add( this.parse( items ) );
						this.trigger( 'attachments:received', this );
						deferred.resolveWith( this, [ items ] );
					} )
				)
				.catch( ( error ) => {
					state.more = false;
					this.trigger( 'windrose-anchors:error', error );
					deferred.resolveWith( this, [ [] ] );
				} );
			state.pending = deferred.promise();
			return state.pending;
		},

		// Starts again from page 1 with the next more(). A first page that is already on its way is kept.
		restart() {
			const state = this.windrose;
			if (
				state.page === 0 &&
				state.more &&
				state.pending &&
				state.pending.state() === 'pending'
			) {
				return;
			}
			state.run += 1;
			state.page = 0;
			state.more = true;
			state.pending = null;
		},

		reload() {
			this.windrose.pending = null;
			this.restart();
			this.reset();
		},
	} );
	return new Collection( [], { props: { query: false } } );
}

/**
 * The browser of the Network tab: core's attachments browser, with an error line, and on other sites an empty
 * state that explains where network items come from (instead of the uploader).
 */
function networkBrowserClass() {
	const Browser = media.view.AttachmentsBrowser;
	return Browser.extend( {
		initialize() {
			Browser.prototype.initialize.apply( this, arguments );
			this.windroseNotice = $(
				'<div class="windrose-anchors-notice notice notice-error inline hidden" role="alert"><p></p></div>'
			);
			this.attachmentsWrapper.$el.prepend( this.windroseNotice );
			this.listenTo( this.collection, 'windrose-anchors:error', ( e ) =>
				this.windroseError( e && e.message )
			);
		},

		createUploader() {
			if ( settings.isHub ) {
				Browser.prototype.createUploader.apply( this, arguments );
				return;
			}
			this.uploader = new media.View( {
				className: 'windrose-anchors-empty hidden',
			} );
			this.uploader.$el.append(
				$( '<p>' ).text(
					__(
						'No shared media yet. This site’s media hub chooses which media items it shares with the network.',
						'windrose-anchors'
					)
				)
			);
			this.views.add( this.uploader );
		},

		windroseError( message ) {
			const text =
				message ||
				__(
					'The network media could not be loaded.',
					'windrose-anchors'
				);
			this.windroseNotice
				.removeClass( 'hidden' )
				.find( 'p' )
				.text( text );
			speak( text, 'assertive' );
		},
	} );
}

/**
 * On other sites: when a network item is picked, put this site's network item in the selection instead.
 *
 * @param {Object} frame     Media frame.
 * @param {Object} selection Selection of the state.
 */
function watchSelection( frame, selection ) {
	if ( settings.isHub || selection.windroseAnchorsWatched ) {
		return;
	}
	selection.windroseAnchorsWatched = true;
	selection.on( 'add', ( model ) => {
		if ( ! isNetworkItem( model ) ) {
			return;
		}
		selection.remove( model );
		if ( model.get( 'windroseAnchorsBusy' ) ) {
			return;
		}
		model.set( 'windroseAnchorsBusy', true );
		const post =
			( media.view.settings.post && media.view.settings.post.id ) || 0;
		speak(
			__( 'Adding the network item to this site…', 'windrose-anchors' )
		);
		apiFetch( {
			path: settings.restProxies,
			method: 'POST',
			data: { hub: model.get( 'windroseAnchors' ).hub, post },
		} )
			.then( ( data ) => {
				const local = media.model.Attachment.get( data.id );
				local.set( local.parse( data ) );
				// Show this site's item in place of the network item.
				Object.values( frame.windroseAnchors || {} ).forEach(
					( collection ) => {
						const at = collection.indexOf( model );
						if ( at > -1 ) {
							collection.remove( model );
							collection.add( local, { at } );
						}
					}
				);
				selection.add( local );
				if ( selection.single ) {
					selection.single( local );
				}
				speak( __( 'Added to this site.', 'windrose-anchors' ) );
			} )
			.catch( ( error ) => {
				const view = frame.content.get();
				if ( view && view.windroseError ) {
					view.windroseError( error && error.message );
				}
			} )
			.finally( () => model.unset( 'windroseAnchorsBusy' ) );
	} );
}

/**
 * On the main site, whether the Network tab is open (and in which frame), for uploads.
 */
const sharing = { active: false, frame: null };

/**
 * Shows the latest items in the open Network tab (after an upload from it).
 */
function reloadOpenTab() {
	const frame = sharing.frame;
	const view = frame && frame.content.get();
	if ( frame && frame.content.mode() === TAB && view && view.collection ) {
		view.collection.reload();
	}
}

/**
 * On the main site: WordPress 7.2's block editor uploads through the REST API (POST /wp/v2/media, then sideload
 * and finalize for client-side image processing). While the Network tab is open, the new item's request carries a
 * nonce in the X-Windrose-Anchors-Share header and the server shares it at once.
 */
function shareRestUploads() {
	apiFetch.use( ( options, next ) => {
		const path = options.path || options.url || '';
		const post = ( options.method || 'GET' ).toUpperCase() === 'POST';
		const create = post && /\/wp\/v2\/media(\?|$)/.test( path );
		const finalize = post && /\/wp\/v2\/media\/\d+\/finalize/.test( path );
		if ( ! sharing.active || ! ( create || finalize ) ) {
			return next( options );
		}
		const result = next(
			create
				? {
						...options,
						headers: {
							...( options.headers || {} ),
							'X-Windrose-Anchors-Share': settings.shareNonce,
						},
					}
				: options
		);
		result.then( reloadOpenTab, () => {} );
		return result;
	} );
}

/**
 * On the main site: uploads made while the Network tab is open are shared at once (classic uploader).
 *
 * @param {Object}  frame Media frame.
 * @param {boolean} on    Whether the Network tab is open.
 */
function shareUploads( frame, on ) {
	const uploader =
		frame.uploader && frame.uploader.uploader
			? frame.uploader.uploader
			: null;
	if ( uploader && typeof uploader.param === 'function' ) {
		uploader.param(
			'windrose_anchors_share',
			on ? settings.shareNonce : ''
		);
	}
}

/**
 * Content of the Network tab (called with the media frame as `this`).
 *
 * @param {Object} contentRegion Content region.
 */
function networkContent( contentRegion ) {
	const frame = this;
	const state = frame.state();
	const types = libraryTypes( state.get( 'library' ) );
	frame.windroseAnchors = frame.windroseAnchors || {};
	if ( ! frame.windroseAnchors[ types ] ) {
		frame.windroseAnchors[ types ] = createCollection( types );
	}
	const collection = frame.windroseAnchors[ types ];
	const NetworkBrowser = networkBrowserClass();

	frame.$el.removeClass( 'hide-toolbar' );
	watchSelection( frame, state.get( 'selection' ) );

	contentRegion.view = new NetworkBrowser( {
		controller: frame,
		collection,
		selection: state.get( 'selection' ),
		model: state,
		sortable: false,
		search: true,
		filters: false,
		date: false,
		display: state.has( 'display' )
			? state.get( 'display' )
			: state.get( 'displaySettings' ),
		dragInfo: false,
		idealColumnWidth: state.get( 'idealColumnWidth' ),
		suggestedWidth: state.get( 'suggestedWidth' ),
		suggestedHeight: state.get( 'suggestedHeight' ),
		AttachmentView: state.get( 'AttachmentView' ),
	} );
}

/**
 * Adds the Network tab to every media frame that browses a library (image, gallery, featured image...).
 */
function addNetworkTab() {
	const Select = media.view.MediaFrame.Select;
	const browseRouter = Select.prototype.browseRouter;
	const bindHandlers = Select.prototype.bindHandlers;

	Select.prototype.browseRouter = function ( routerView ) {
		browseRouter.apply( this, arguments );
		if ( ! this.state().get( 'library' ) ) {
			return;
		}
		routerView.set( {
			browse: {
				text: __( 'This site', 'windrose-anchors' ),
				priority: 40,
			},
			[ TAB ]: {
				text: __( 'Network', 'windrose-anchors' ),
				priority: 60,
			},
		} );
	};

	Select.prototype.bindHandlers = function () {
		bindHandlers.apply( this, arguments );
		this.on( `content:create:${ TAB }`, networkContent, this );
		if ( settings.isHub ) {
			this.on( 'content:activate', () => {
				const open = this.content.mode() === TAB;
				sharing.active = open;
				sharing.frame = open ? this : null;
				shareUploads( this, open );
			} );
			this.on( 'close', () => {
				sharing.active = false;
				sharing.frame = null;
				shareUploads( this, false );
			} );
			if ( window.wp.Uploader && window.wp.Uploader.queue ) {
				// When uploads made from the Network tab are done, show them there.
				this.listenTo( window.wp.Uploader.queue, 'reset', () => {
					const view = this.content.get();
					if (
						this.content.mode() === TAB &&
						view &&
						view.collection
					) {
						view.collection.reload();
					}
				} );
			}
		}
	};
}

/**
 * A remembered tab that this dialog does not have would open it empty, with no tab selected: the Network tab's old
 * IDs, or the Network tab on a site that may no longer use network media. Those open on "This site".
 */
function forgetMissingTab() {
	const Library = media.controller.Library;
	const activate = Library.prototype.activate;
	Library.prototype.activate = function () {
		activate.apply( this, arguments );
		const content = this.get( 'content' );
		if (
			OLD_TABS.includes( content ) ||
			( content === TAB && ! settings.tab )
		) {
			this.set( 'content', 'browse' );
		}
	};
}

/**
 * No "Edit Image" for network items, nor for main site items that other sites use (the server refuses anyway).
 */
function hideImageEditor() {
	const views = [ media.view.Attachment.Details ];
	if ( media.view.Attachment.Details.TwoColumn ) {
		views.push( media.view.Attachment.Details.TwoColumn );
	}
	views.forEach( ( View ) => {
		if (
			! Object.prototype.hasOwnProperty.call(
				View.prototype,
				'render'
			) &&
			View !== views[ 0 ]
		) {
			return;
		}
		const render = View.prototype.render;
		View.prototype.render = function () {
			const result = render.apply( this, arguments );
			const data = this.model && this.model.get( 'windroseAnchors' );
			const nonces = ( this.model && this.model.get( 'nonces' ) ) || {};
			if ( data && ( data.proxy || ( data.usage && ! nonces.edit ) ) ) {
				this.$( '.edit-attachment' ).remove();
			}
			return result;
		};
	} );
}

/**
 * The type menu of the Media Library grid (and of the dialog, where it shows) gets the network filters: on the main
 * site "Shared with the network", "Used by other sites", "Not shared"; elsewhere "From the network", "Not from the
 * network". The server reads the `windroseAnchors` query variable.
 */
function addLibraryFilters() {
	const All =
		media.view.AttachmentFilters && media.view.AttachmentFilters.All;
	if ( ! All ) {
		return;
	}
	const createFilters = All.prototype.createFilters;
	All.prototype.createFilters = function () {
		createFilters.apply( this, arguments );
		// Core's own entries clear the network filter.
		Object.values( this.filters ).forEach( ( filter ) => {
			filter.props = { ...filter.props, windroseAnchors: null };
		} );
		Object.entries( settings.filters || {} ).forEach(
			( [ value, text ], index ) => {
				this.filters[ `windrose-anchors-${ value }` ] = {
					text,
					props: {
						status: null,
						type: null,
						uploadedTo: null,
						orderby: 'date',
						order: 'DESC',
						author: null,
						windroseAnchors: value,
					},
					priority: 70 + index,
				};
			}
		);
	};
}

/**
 * A badge on media tiles: "From the network" on other sites, "Shared" on the main site (not in the Network tab,
 * where every item is). In the Network tab, "Not published yet" on an item attached to a draft of the media hub,
 * which only the people who can read that draft see. Screen readers get it in the tile's name.
 */
function addBadges() {
	const Attachment = media.view.Attachment;
	const render = Attachment.prototype.render;
	Attachment.prototype.render = function () {
		const result = render.apply( this, arguments );
		const data = this.model && this.model.get( 'windroseAnchors' );
		if ( ! data || ! this.$el.is( 'li' ) ) {
			return result;
		}
		const networkTab = !! ( this.collection && this.collection.windrose );
		let text = '';
		// The whole reason, for the tooltip and for screen readers.
		let reason = '';
		if ( networkTab ) {
			if ( data.unpublished ) {
				text = __( 'Not published yet', 'windrose-anchors' );
				reason = data.unpublished;
			}
		} else if ( data.proxy ) {
			text = __( 'From the network', 'windrose-anchors' );
		} else if ( data.shared ) {
			text = __( 'Shared', 'windrose-anchors' );
		}
		if ( text ) {
			this.$( '.attachment-preview' ).append(
				$(
					'<span class="windrose-anchors-badge" aria-hidden="true"></span>'
				).text( text )
			);
			if ( reason ) {
				this.$el.attr( 'title', reason );
			}
			this.$el.attr(
				'aria-label',
				sprintf(
					/* translators: 1: media title, 2: "From the network", "Shared", or why only some people see the item. */
					__( '%1$s, %2$s', 'windrose-anchors' ),
					this.model.get( 'title' ),
					reason || text
				)
			);
		}
		return result;
	};
}

/**
 * On other sites, a network item is deleted from this site only: "Delete from this site" instead of "Delete
 * permanently" in the dialog and grid view details, and a confirmation saying the file stays on the main site.
 */
function deleteFromThisSite() {
	const Details = media.view.Attachment.Details;
	const render = Details.prototype.render;
	const deleteAttachment = Details.prototype.deleteAttachment;
	const isProxy = ( view ) =>
		!! ( view.model && view.model.get( 'windroseAnchors' )?.proxy );

	Details.prototype.render = function () {
		const result = render.apply( this, arguments );
		if ( isProxy( this ) ) {
			this.$( '.delete-attachment' ).text( settings.deleteText );
		}
		return result;
	};
	Details.prototype.deleteAttachment = function () {
		if ( ! isProxy( this ) ) {
			return deleteAttachment.apply( this, arguments );
		}
		// Core reads its confirmation text from wp.media.view.l10n when asked.
		const l10n = media.view.l10n;
		const warning = l10n.warnDelete;
		l10n.warnDelete = settings.deleteWarn;
		try {
			return deleteAttachment.apply( this, arguments );
		} finally {
			l10n.warnDelete = warning;
		}
	};
}

/**
 * The same on the edit screen of a network item.
 */
function deleteLinkOnEditScreen() {
	const link = document.querySelector( '#delete-action .submitdelete' );
	if ( ! settings.editProxy || ! link ) {
		return;
	}
	link.textContent = settings.deleteText;
	link.removeAttribute( 'onclick' );
	link.addEventListener( 'click', ( event ) => {
		// eslint-disable-next-line no-alert
		if ( ! window.confirm( settings.deleteWarn ) ) {
			event.preventDefault();
		}
	} );
}

/**
 * "Reset to network values" (media modal, Media Library grid, attachment edit screen).
 */
function resetButton() {
	document.addEventListener( 'click', ( event ) => {
		const button =
			event.target instanceof window.Element
				? event.target.closest( '.windrose-anchors-reset' )
				: null;
		if ( ! button ) {
			return;
		}
		event.preventDefault();
		const id = Number( button.dataset.id );
		button.disabled = true;
		apiFetch( {
			path: `${ settings.restProxies }/${ id }/reset`,
			method: 'POST',
		} )
			.then( ( data ) => {
				if ( document.body.classList.contains( 'post-php' ) ) {
					window.location.reload();
					return;
				}
				const scope = button.closest(
					'.attachment-details, .attachment-info, .media-frame-content'
				);
				if ( scope ) {
					[ 'title', 'alt', 'caption', 'description' ].forEach(
						( field ) => {
							const input = scope.querySelector(
								`[data-setting="${ field }"] input, [data-setting="${ field }"] textarea`
							);
							if ( input ) {
								input.value = data[ field ] || '';
							}
						}
					);
				}
				if ( media && media.model ) {
					const model = media.model.Attachment.get( id );
					model.set( model.parse( data ) );
				}
				speak(
					__(
						'The network values are back on this site.',
						'windrose-anchors'
					)
				);
			} )
			.catch( ( error ) => {
				speak( error.message, 'assertive' );
				// eslint-disable-next-line no-alert
				window.alert( error.message );
			} )
			.finally( () => {
				button.disabled = false;
			} );
	} );
}

if ( media && media.view && media.view.MediaFrame ) {
	if ( settings.tab ) {
		addNetworkTab();
		if ( settings.isHub ) {
			shareRestUploads();
		}
	}
	forgetMissingTab();
	hideImageEditor();
	addLibraryFilters();
	addBadges();
	if ( ! settings.isHub ) {
		deleteFromThisSite();
	}
}
if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', deleteLinkOnEditScreen );
} else {
	deleteLinkOnEditScreen();
}
resetButton();
