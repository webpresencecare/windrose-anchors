/**
 * Media, Network media (other sites): the main site's shared items, with "Add to this site" and "Show on this site".
 */
import {
	createRoot,
	useCallback,
	useEffect,
	useState,
} from '@wordpress/element';
import { Button, Notice } from '@wordpress/components';
import { DataViews } from '@wordpress/dataviews/wp';
import { __, _n, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { speak } from '@wordpress/a11y';

import './library.scss';

const data = window.windroseAnchorsLibrary || {};

const STATUSES = [
	{ value: 'yes', label: __( 'On this site', 'windrose-anchors' ) },
	{ value: 'no', label: __( 'Not on this site yet', 'windrose-anchors' ) },
];

const DEFAULT_VIEW = {
	type: 'grid',
	search: '',
	filters: [],
	page: 1,
	perPage: 24,
	titleField: 'title',
	mediaField: 'media',
	fields: [ 'status' ],
	layout: {},
};

/**
 * This site's network item of an item, or 0.
 *
 * @param {Object} item Network item.
 * @return {number} Attachment ID on this site.
 */
const localId = ( item ) => item.windroseAnchors?.local || 0;

/**
 * The fields, with the status line's button.
 *
 * @param {(items: Array) => void} add Adds items to this site.
 * @return {Array} Fields.
 */
const makeFields = ( add ) => [
	{
		id: 'media',
		label: __( 'Preview', 'windrose-anchors' ),
		enableSorting: false,
		enableHiding: false,
		render: ( { item } ) => {
			const src =
				item.sizes?.medium?.url ||
				item.sizes?.thumbnail?.url ||
				( item.type === 'image' ? item.url : item.icon );
			// The title is shown next to the preview: the image itself is decorative here.
			return <img src={ src } alt="" loading="lazy" />;
		},
	},
	{
		id: 'title',
		type: 'text',
		label: __( 'Title', 'windrose-anchors' ),
		enableSorting: false,
		enableHiding: false,
		getValue: ( { item } ) => item.title || item.filename,
		render: ( { item } ) => (
			<>
				{ item.title || item.filename }
				{ item.windroseAnchors?.unpublished && (
					<span className="windrose-anchors-library__note">
						{ item.windroseAnchors.unpublished }
					</span>
				) }
			</>
		),
	},
	{
		id: 'status',
		type: 'text',
		label: __( 'Status', 'windrose-anchors' ),
		elements: STATUSES,
		enableSorting: false,
		filterBy: { operators: [ 'is' ] },
		getValue: ( { item } ) => ( localId( item ) ? 'yes' : 'no' ),
		render: ( { item } ) =>
			localId( item ) ? (
				<span className="windrose-anchors-library__status">
					{ __( 'On this site', 'windrose-anchors' ) }{ ' ' }
					<a href={ `${ data.uploadUrl }?item=${ localId( item ) }` }>
						{ __( 'Show', 'windrose-anchors' ) }
						<span className="screen-reader-text">
							{ ' ' }
							{ item.title }
						</span>
					</a>
				</span>
			) : (
				<Button
					__next40pxDefaultSize
					variant="secondary"
					onClick={ () => add( [ item ] ) }
				>
					{ __( 'Add to this site', 'windrose-anchors' ) }
					<span className="screen-reader-text"> { item.title }</span>
				</Button>
			),
	},
];

function App() {
	const [ view, setView ] = useState( DEFAULT_VIEW );
	const [ items, setItems ] = useState( null );
	const [ total, setTotal ] = useState( { items: 0, pages: 1 } );
	const [ notice, setNotice ] = useState( null );

	const notify = ( status, message ) => {
		setNotice( { status, message } );
		speak( message, 'error' === status ? 'assertive' : 'polite' );
	};

	const local =
		view.filters.find( ( filter ) => filter.field === 'status' )?.value ||
		'';

	const load = useCallback( async () => {
		const query = new URLSearchParams( {
			page: String( view.page ),
			per_page: String( view.perPage ),
		} );
		if ( view.search ) {
			query.set( 'search', view.search );
		}
		if ( local ) {
			query.set( 'local', local );
		}
		try {
			const response = await apiFetch( {
				path: `${ data.restNetwork }?${ query.toString() }`,
				parse: false,
			} );
			setItems( await response.json() );
			setTotal( {
				items: Number( response.headers.get( 'X-WP-Total' ) || 0 ),
				pages: Number( response.headers.get( 'X-WP-TotalPages' ) || 1 ),
			} );
		} catch ( error ) {
			setItems( [] );
			notify( 'error', error.message );
		}
	}, [ view.page, view.perPage, view.search, local ] );

	useEffect( () => {
		load();
	}, [ load ] );

	const add = async ( chosen ) => {
		let added = 0;
		try {
			for ( const item of chosen ) {
				await apiFetch( {
					path: data.restProxies,
					method: 'POST',
					data: { hub: item.windroseAnchors.hub },
				} );
				added += 1;
			}
			notify(
				'success',
				sprintf(
					/* translators: %d: number of media items. */
					_n(
						'%d media item added to this site.',
						'%d media items added to this site.',
						added,
						'windrose-anchors'
					),
					added
				)
			);
		} catch ( error ) {
			notify( 'error', error.message );
		}
		load();
	};

	const actions = [
		{
			id: 'add',
			label: __( 'Add to this site', 'windrose-anchors' ),
			isPrimary: true,
			supportsBulk: true,
			isEligible: ( item ) => ! localId( item ),
			callback: add,
		},
		{
			id: 'show',
			label: __( 'Show on this site', 'windrose-anchors' ),
			isPrimary: true,
			isEligible: ( item ) => !! localId( item ),
			callback: ( [ item ] ) => {
				window.location.href = `${ data.uploadUrl }?item=${ localId(
					item
				) }`;
			},
		},
	];

	return (
		<>
			{ notice && (
				<Notice
					status={ notice.status }
					onRemove={ () => setNotice( null ) }
					className="windrose-anchors-library__notice"
				>
					{ notice.message }
				</Notice>
			) }
			<DataViews
				data={ items ?? [] }
				fields={ makeFields( add ) }
				view={ view }
				onChangeView={ setView }
				actions={ actions }
				paginationInfo={ {
					totalItems: total.items,
					totalPages: total.pages,
				} }
				defaultLayouts={ { grid: {}, table: {} } }
				getItemId={ ( item ) => String( item.windroseAnchors.hub ) }
				isLoading={ items === null }
				searchLabel={ __( 'Search network media', 'windrose-anchors' ) }
				empty={
					<p>
						{ __(
							'No shared media found. This site’s media hub chooses which media items it shares with the network.',
							'windrose-anchors'
						) }
					</p>
				}
			/>
		</>
	);
}

const container = document.getElementById( 'windrose-anchors-library-app' );
if ( container ) {
	createRoot( container ).render( <App /> );
}
