/**
 * Windrose Anchors network admin, Usage: the media hubs' shared items, the network items sites hold of them, and
 * what to look at (local changes, items not found in content, duplicates, usage records out of date).
 */
import { useCallback, useEffect, useMemo, useState } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	Notice,
	TabPanel,
} from '@wordpress/components';
import { DataViews, filterSortAndPaginate } from '@wordpress/dataviews/wp';
import { __, _n, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

import { data, useNotice } from './common';

const PATH = '/windrose-anchors/v1/usage';

const ITEM_NOTES = [
	{ value: 'changed', label: __( 'Changed on sites', 'windrose-anchors' ) },
	{
		value: 'unused',
		label: __( 'Not found in content', 'windrose-anchors' ),
	},
	{ value: 'duplicates', label: __( 'Duplicates', 'windrose-anchors' ) },
	{
		value: 'out-of-sync',
		label: __( 'Usage record out of date', 'windrose-anchors' ),
	},
	{
		value: 'missing',
		label: __( 'Deleted on its media hub', 'windrose-anchors' ),
	},
	{
		value: 'not-shared',
		label: __( 'No longer shared', 'windrose-anchors' ),
	},
	{
		value: 'hidden',
		label: __(
			'Hidden from other sites (private, or shown only to readers of its draft)',
			'windrose-anchors'
		),
	},
];

const PROXY_NOTES = [
	{ value: 'changed', label: __( 'Changed here', 'windrose-anchors' ) },
	{
		value: 'unused',
		label: __( 'Not found in content', 'windrose-anchors' ),
	},
	{ value: 'duplicate', label: __( 'Duplicate', 'windrose-anchors' ) },
	{
		value: 'hub-missing',
		label: __( 'Deleted on its media hub', 'windrose-anchors' ),
	},
	{ value: 'trash', label: __( 'In the trash', 'windrose-anchors' ) },
];

const FIELD_NAMES = [
	{ value: 'title', label: __( 'Title', 'windrose-anchors' ) },
	{ value: 'alt', label: __( 'Alternative Text', 'windrose-anchors' ) },
	{ value: 'caption', label: __( 'Caption', 'windrose-anchors' ) },
	{ value: 'description', label: __( 'Description', 'windrose-anchors' ) },
];

const STATUSES = [
	{ value: 'used', label: __( 'Used by other sites', 'windrose-anchors' ) },
	{
		value: 'unused',
		label: __( 'Not used by other sites', 'windrose-anchors' ),
	},
];

/**
 * Labels of note values.
 *
 * @param {Array} values   Note values.
 * @param {Array} elements Known notes.
 * @return {string} Labels, comma separated.
 */
const labels = ( values, elements ) =>
	values
		.map(
			( value ) =>
				elements.find( ( element ) => element.value === value )
					?.label || value
		)
		.join( ', ' );

/**
 * A long explanation of an item's notes, with counts.
 *
 * @param {Object} item Shared item.
 * @return {Array} Lines.
 */
function itemNoteLines( item ) {
	const lines = [];
	if ( item.changed ) {
		lines.push(
			sprintf(
				/* translators: %d: number of network items. */
				_n(
					'%d network item with local changes',
					'%d network items with local changes',
					item.changed,
					'windrose-anchors'
				),
				item.changed
			)
		);
	}
	if ( item.unused ) {
		lines.push(
			sprintf(
				/* translators: %d: number of network items. */
				_n(
					'%d network item not found in content',
					'%d network items not found in content',
					item.unused,
					'windrose-anchors'
				),
				item.unused
			)
		);
	}
	if ( item.duplicates ) {
		lines.push(
			sprintf(
				/* translators: %d: number of network items. */
				_n(
					'%d duplicate',
					'%d duplicates',
					item.duplicates,
					'windrose-anchors'
				),
				item.duplicates
			)
		);
	}
	[ 'out-of-sync', 'missing', 'not-shared', 'hidden' ].forEach( ( note ) => {
		if ( item.notes.includes( note ) ) {
			lines.push( labels( [ note ], ITEM_NOTES ) );
		}
	} );
	return lines;
}

/**
 * The shared items table.
 *
 * @param {Object}  props          Props.
 * @param {Array}   props.items    Shared items.
 * @param {boolean} props.manyHubs Whether the network has several media hubs.
 */
function ItemsTable( { items, manyHubs } ) {
	const [ view, setView ] = useState( {
		type: 'table',
		search: '',
		filters: [],
		page: 1,
		perPage: 20,
		sort: { field: 'proxies', direction: 'desc' },
		titleField: 'title',
		mediaField: 'thumb',
		fields: manyHubs
			? [ 'hub', 'sites', 'proxies', 'notes' ]
			: [ 'sites', 'proxies', 'notes' ],
		layout: {},
	} );
	const hubs = useMemo( () => {
		const all = new Map();
		items.forEach( ( item ) =>
			all.set( String( item.site ), item.siteName )
		);
		return [ ...all ].map( ( [ value, label ] ) => ( { value, label } ) );
	}, [ items ] );
	const sites = useMemo( () => {
		const all = new Map();
		items.forEach( ( item ) =>
			item.sites.forEach( ( site ) =>
				all.set( String( site.id ), site.name )
			)
		);
		return [ ...all ].map( ( [ value, label ] ) => ( { value, label } ) );
	}, [ items ] );
	const fields = useMemo(
		() => [
			{
				id: 'thumb',
				type: 'media',
				label: __( 'Preview', 'windrose-anchors' ),
				enableSorting: false,
				enableHiding: false,
				render: ( { item } ) =>
					item.thumb ? (
						<img src={ item.thumb } alt="" loading="lazy" />
					) : null,
			},
			{
				id: 'title',
				type: 'text',
				label: __( 'Media item', 'windrose-anchors' ),
				enableHiding: false,
				enableGlobalSearch: true,
				getValue: ( { item } ) => item.title || item.file,
				render: ( { item } ) =>
					item.editUrl ? (
						<a href={ item.editUrl }>
							{ item.title ||
								item.file ||
								__( '(no title)', 'windrose-anchors' ) }
						</a>
					) : (
						item.title
					),
			},
			{
				id: 'file',
				type: 'text',
				label: __( 'File', 'windrose-anchors' ),
				enableGlobalSearch: true,
			},
			{
				id: 'hub',
				type: 'text',
				label: __( 'Media hub', 'windrose-anchors' ),
				elements: hubs,
				filterBy: { operators: [ 'is' ] },
				getValue: ( { item } ) => String( item.site ),
			},
			{
				id: 'sites',
				type: 'array',
				label: __( 'Sites using it', 'windrose-anchors' ),
				elements: sites,
				enableSorting: false,
				filterBy: { operators: [ 'isAny', 'isNone' ] },
				getValue: ( { item } ) =>
					item.sites.map( ( site ) => String( site.id ) ),
				render: ( { item } ) =>
					item.sites.length ? (
						<span className="windrose-anchors__list">
							{ item.sites.map( ( site ) => (
								<a key={ site.id } href={ site.url }>
									{ site.name }
								</a>
							) ) }
						</span>
					) : (
						<span className="windrose-anchors__muted">
							{ __( 'None', 'windrose-anchors' ) }
						</span>
					),
			},
			{
				id: 'proxies',
				type: 'integer',
				label: __( 'Network items', 'windrose-anchors' ),
				filterBy: false,
			},
			{
				id: 'status',
				type: 'text',
				label: __( 'Status', 'windrose-anchors' ),
				elements: STATUSES,
				filterBy: { operators: [ 'is' ] },
			},
			{
				id: 'notes',
				type: 'array',
				label: __( 'To look at', 'windrose-anchors' ),
				elements: ITEM_NOTES,
				enableSorting: false,
				filterBy: { operators: [ 'isAny', 'isNone' ] },
				render: ( { item } ) => {
					const lines = itemNoteLines( item );
					return lines.length ? (
						<ul className="windrose-anchors__notes">
							{ lines.map( ( line ) => (
								<li key={ line }>{ line }</li>
							) ) }
						</ul>
					) : (
						<span className="windrose-anchors__muted">
							{ __( 'Nothing', 'windrose-anchors' ) }
						</span>
					);
				},
			},
		],
		[ sites, hubs ]
	);
	const { data: shown, paginationInfo } = useMemo(
		() => filterSortAndPaginate( items, view, fields ),
		[ items, view, fields ]
	);
	return (
		<DataViews
			data={ shown }
			fields={ fields }
			view={ view }
			onChangeView={ setView }
			paginationInfo={ paginationInfo }
			defaultLayouts={ { table: {}, grid: {} } }
			getItemId={ ( item ) => item.key }
			searchLabel={ __( 'Search shared items', 'windrose-anchors' ) }
			empty={
				<p>
					{ __(
						'No media hub shares media items yet.',
						'windrose-anchors'
					) }
				</p>
			}
		/>
	);
}

/**
 * Confirmation before deleting network items found in no content.
 *
 * @param {Object}                                 props            Props.
 * @param {Array}                                  props.items      Network items.
 * @param {() => void}                             props.closeModal Closes the dialog.
 * @param {(results: Array, items: Array) => void} props.onDeleted  Called with the results.
 */
function DeleteModal( { items, closeModal, onDeleted } ) {
	const [ busy, setBusy ] = useState( false );
	const confirm = async () => {
		setBusy( true );
		let results;
		try {
			results = (
				await apiFetch( {
					path: `${ PATH }/delete`,
					method: 'POST',
					data: {
						items: items.map( ( item ) => ( {
							site: item.site,
							id: item.id,
						} ) ),
					},
				} )
			).results;
		} catch ( error ) {
			results = items.map( ( item ) => ( {
				site: item.site,
				id: item.id,
				ok: false,
				message: error.message,
			} ) );
		}
		closeModal();
		onDeleted( results, items );
	};
	return (
		<>
			<p>
				{ sprintf(
					/* translators: %d: number of network items. */
					_n(
						'Delete %d network item from its site? No content there uses it. The file stays on its media hub, and the item can be added again from Media, Network media.',
						'Delete %d network items from their sites? No content there uses them. The files stay on their media hubs, and the items can be added again from Media, Network media.',
						items.length,
						'windrose-anchors'
					),
					items.length
				) }
			</p>
			<ul className="windrose-anchors__notes">
				{ items.map( ( item ) => (
					<li key={ item.key }>
						{ sprintf(
							/* translators: 1: media title, 2: site name. */
							__( '%1$s, on %2$s', 'windrose-anchors' ),
							item.title ||
								__( '(no title)', 'windrose-anchors' ),
							item.siteName
						) }
					</li>
				) ) }
			</ul>
			<div className="windrose-anchors__buttons">
				<Button
					__next40pxDefaultSize
					variant="tertiary"
					onClick={ closeModal }
					disabled={ busy }
				>
					{ __( 'Cancel', 'windrose-anchors' ) }
				</Button>
				<Button
					__next40pxDefaultSize
					variant="primary"
					isDestructive
					onClick={ confirm }
					isBusy={ busy }
					disabled={ busy }
				>
					{ __( 'Delete', 'windrose-anchors' ) }
				</Button>
			</div>
		</>
	);
}

/**
 * The network items table.
 *
 * @param {Object}                                 props           Props.
 * @param {Array}                                  props.proxies   Network items.
 * @param {(results: Array, items: Array) => void} props.onDeleted Called after deleting items.
 * @param {boolean}                                props.manyHubs  Whether the network has several media hubs.
 */
function ProxiesTable( { proxies, onDeleted, manyHubs } ) {
	const [ view, setView ] = useState( {
		type: 'table',
		search: '',
		filters: [],
		page: 1,
		perPage: 20,
		titleField: 'title',
		fields: [ 'site', 'hubTitle', 'overridden', 'uses', 'notes' ],
		layout: {},
	} );
	const sites = useMemo( () => {
		const all = new Map();
		proxies.forEach( ( row ) =>
			all.set( String( row.site ), row.siteName )
		);
		return [ ...all ].map( ( [ value, label ] ) => ( { value, label } ) );
	}, [ proxies ] );
	const fields = useMemo(
		() => [
			{
				id: 'title',
				type: 'text',
				label: __( 'Network item', 'windrose-anchors' ),
				enableHiding: false,
				enableGlobalSearch: true,
				render: ( { item } ) => (
					<a href={ item.libraryUrl }>
						{ item.title || __( '(no title)', 'windrose-anchors' ) }
					</a>
				),
			},
			{
				id: 'site',
				type: 'text',
				label: __( 'Site', 'windrose-anchors' ),
				elements: sites,
				filterBy: { operators: [ 'is' ] },
				getValue: ( { item } ) => String( item.site ),
			},
			{
				id: 'hubTitle',
				type: 'text',
				label: __( 'Media hub item', 'windrose-anchors' ),
				enableGlobalSearch: true,
				render: ( { item } ) =>
					manyHubs
						? sprintf(
								/* translators: 1: media item title, 2: media hub name. */
								__( '%1$s (on %2$s)', 'windrose-anchors' ),
								item.hubTitle,
								item.hubName
							)
						: item.hubTitle,
			},
			{
				id: 'overridden',
				type: 'array',
				label: __( 'Changed here', 'windrose-anchors' ),
				elements: FIELD_NAMES,
				enableSorting: false,
				filterBy: { operators: [ 'isAny', 'isNone' ] },
				render: ( { item } ) =>
					item.overridden.length ? (
						labels( item.overridden, FIELD_NAMES )
					) : (
						<span className="windrose-anchors__muted">
							{ __( 'Nothing', 'windrose-anchors' ) }
						</span>
					),
			},
			{
				id: 'uses',
				type: 'integer',
				label: __( 'Used in', 'windrose-anchors' ),
				filterBy: false,
				render: ( { item } ) =>
					item.uses ? (
						sprintf(
							/* translators: %d: number of posts, featured images, logo or icon using the item. */
							_n(
								'%d place',
								'%d places',
								item.uses,
								'windrose-anchors'
							),
							item.uses
						)
					) : (
						<span className="windrose-anchors__muted">
							{ __( 'Not found in content', 'windrose-anchors' ) }
						</span>
					),
			},
			{
				id: 'notes',
				type: 'array',
				label: __( 'To look at', 'windrose-anchors' ),
				elements: PROXY_NOTES,
				enableSorting: false,
				filterBy: { operators: [ 'isAny', 'isNone' ] },
				render: ( { item } ) =>
					item.notes.length ? (
						labels( item.notes, PROXY_NOTES )
					) : (
						<span className="windrose-anchors__muted">
							{ __( 'Nothing', 'windrose-anchors' ) }
						</span>
					),
			},
		],
		[ sites, manyHubs ]
	);
	const { data: shown, paginationInfo } = useMemo(
		() => filterSortAndPaginate( proxies, view, fields ),
		[ proxies, view, fields ]
	);
	const actions = [
		{
			id: 'edit',
			label: __( 'Edit on its site', 'windrose-anchors' ),
			isPrimary: true,
			callback: ( [ item ] ) => {
				window.location.href = item.editUrl;
			},
		},
		{
			id: 'delete',
			label: __( 'Delete from its site', 'windrose-anchors' ),
			isDestructive: true,
			supportsBulk: true,
			isEligible: ( item ) => item.notes.includes( 'unused' ),
			modalHeader: __( 'Delete network items', 'windrose-anchors' ),
			RenderModal: ( { items, closeModal } ) => (
				<DeleteModal
					items={ items }
					closeModal={ closeModal }
					onDeleted={ onDeleted }
				/>
			),
		},
	];
	return (
		<DataViews
			data={ shown }
			fields={ fields }
			view={ view }
			onChangeView={ setView }
			actions={ actions }
			paginationInfo={ paginationInfo }
			defaultLayouts={ { table: {} } }
			getItemId={ ( item ) => item.key }
			searchLabel={ __( 'Search network items', 'windrose-anchors' ) }
			empty={
				<p>
					{ __( 'No site holds network items.', 'windrose-anchors' ) }
				</p>
			}
		/>
	);
}

export default function UsageScreen() {
	const [ report, setReport ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ notice, notify ] = useNotice();

	const load = useCallback( async () => {
		setBusy( true );
		try {
			setReport( await apiFetch( { path: PATH } ) );
		} catch ( error ) {
			setReport( { items: [], proxies: [], summary: {} } );
			notify( 'error', error.message );
		}
		setBusy( false );
	}, [ notify ] );

	useEffect( () => {
		load();
	}, [ load ] );

	const repair = async () => {
		setBusy( true );
		try {
			const result = await apiFetch( {
				path: `${ PATH }/repair`,
				method: 'POST',
			} );
			setReport( result.report );
			notify(
				'success',
				sprintf(
					/* translators: %d: number of media items. */
					_n(
						'The usage record of %d media item was repaired.',
						'The usage records of %d media items were repaired.',
						result.fixed,
						'windrose-anchors'
					),
					result.fixed
				)
			);
		} catch ( error ) {
			notify( 'error', error.message );
		}
		setBusy( false );
	};

	const deleted = ( results, items ) => {
		const done = results.filter( ( result ) => result.ok ).length;
		const failed = results
			.filter( ( result ) => ! result.ok )
			.map( ( result ) => {
				const item = items.find(
					( row ) => row.site === result.site && row.id === result.id
				);
				return `${ item?.title || result.id } (${ item?.siteName }): ${
					result.message
				}`;
			} );
		notify(
			failed.length ? 'warning' : 'success',
			[
				sprintf(
					/* translators: %d: number of network items. */
					_n(
						'%d network item deleted from its site.',
						'%d network items deleted from their sites.',
						done,
						'windrose-anchors'
					),
					done
				),
				...failed,
			].join( ' ' )
		);
		load();
	};

	if ( ! report ) {
		return <p>{ __( 'Reading the sites…', 'windrose-anchors' ) }</p>;
	}
	const summary = report.summary || {};
	const manyHubs = ( summary.hubs || 1 ) > 1;
	return (
		<>
			{ notice }
			<Card>
				<CardBody>
					<p>
						{ manyHubs
							? sprintf(
									/* translators: 1: number of media hubs, 2: number of shared items, 3: number of items used by other sites, 4: number of network items, 5: number of sites. */
									__(
										'%1$d media hubs share %2$d media items; other sites use %3$d of them. Those sites hold %4$d network items in all, on %5$d sites.',
										'windrose-anchors'
									),
									summary.hubs,
									summary.items || 0,
									summary.used || 0,
									summary.proxies || 0,
									summary.sites || 0
								)
							: sprintf(
									/* translators: 1: main site name and address, 2: number of shared items, 3: number of items used by other sites, 4: number of network items, 5: number of sites. */
									__(
										'%1$s shares %2$d media items; other sites use %3$d of them. Those sites hold %4$d network items in all, on %5$d sites.',
										'windrose-anchors'
									),
									data.hubName || '',
									summary.items || 0,
									summary.used || 0,
									summary.proxies || 0,
									summary.sites || 0
								) }
					</p>
					<p>
						{ __(
							'“Not found in content” means the item appears in no post, page, template, featured image, site logo or icon of its site, for example because it was picked in the media dialog and then not inserted. It is a hint: a reference typed by hand is not seen.',
							'windrose-anchors'
						) }
					</p>
					<Button
						__next40pxDefaultSize
						variant="secondary"
						onClick={ load }
						isBusy={ busy }
						disabled={ busy }
					>
						{ __( 'Check again', 'windrose-anchors' ) }
					</Button>
				</CardBody>
			</Card>
			{ summary.outOfSync > 0 && (
				<Notice
					status="warning"
					isDismissible={ false }
					className="windrose-anchors__notice"
					actions={ [
						{
							label: __(
								'Repair usage records',
								'windrose-anchors'
							),
							onClick: repair,
							variant: 'primary',
							disabled: busy,
						},
					] }
				>
					{ sprintf(
						/* translators: %d: number of media items. */
						_n(
							'The usage record of %d media item does not match what the sites hold. Windrose Anchors uses this record to refuse deleting items that sites use. Repairing rewrites it from the sites.',
							'The usage records of %d media items do not match what the sites hold. Windrose Anchors uses these records to refuse deleting items that sites use. Repairing rewrites them from the sites.',
							summary.outOfSync,
							'windrose-anchors'
						),
						summary.outOfSync
					) }
				</Notice>
			) }
			<TabPanel
				className="windrose-anchors__tabs"
				tabs={ [
					{
						name: 'items',
						title: __( 'Shared items', 'windrose-anchors' ),
					},
					{
						name: 'proxies',
						title: __(
							'Network items on sites',
							'windrose-anchors'
						),
					},
				] }
			>
				{ ( tab ) =>
					tab.name === 'items' ? (
						<ItemsTable
							items={ report.items }
							manyHubs={ manyHubs }
						/>
					) : (
						<ProxiesTable
							proxies={ report.proxies }
							onDeleted={ deleted }
							manyHubs={ manyHubs }
						/>
					)
				}
			</TabPanel>
		</>
	);
}
