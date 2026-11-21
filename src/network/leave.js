/**
 * Windrose Anchors network admin, Leave safely: turns network items into normal media items of their sites (files
 * copied into each site's uploads, content pointed to the copies), after a dry run. For one site or every site.
 */
import { useCallback, useEffect, useMemo, useState } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	CheckboxControl,
	Notice,
	ProgressBar,
	SelectControl,
} from '@wordpress/components';
import { DataViews, filterSortAndPaginate } from '@wordpress/dataviews/wp';
import { __, _n, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { speak } from '@wordpress/a11y';

import { data, formatBytes, useNotice } from './common';

const PATH = '/windrose-anchors/v1/leave';
const ALL = '0';

const STATUSES = [
	{ value: 'ready', label: __( 'Ready', 'windrose-anchors' ) },
	{
		value: 'problem',
		label: __( 'Cannot be converted', 'windrose-anchors' ),
	},
];

const fields = [
	{
		id: 'title',
		type: 'text',
		label: __( 'Network item', 'windrose-anchors' ),
		enableHiding: false,
		enableGlobalSearch: true,
		getValue: ( { item } ) =>
			item.title || __( '(no title)', 'windrose-anchors' ),
	},
	{
		id: 'siteName',
		type: 'text',
		label: __( 'Site', 'windrose-anchors' ),
		enableGlobalSearch: true,
	},
	{
		id: 'target',
		type: 'text',
		label: __( 'New file on its site', 'windrose-anchors' ),
		enableSorting: false,
		render: ( { item } ) =>
			item.target ? (
				<>
					<code className="windrose-anchors__path">
						{ item.target }
					</code>
					{ item.renamed && (
						<span className="windrose-anchors__muted">
							{ ' ' }
							{ __(
								'(renamed: the name exists there)',
								'windrose-anchors'
							) }
						</span>
					) }
				</>
			) : null,
	},
	{
		id: 'files',
		type: 'integer',
		label: __( 'Files to copy', 'windrose-anchors' ),
		filterBy: false,
		render: ( { item } ) =>
			item.files
				? sprintf(
						/* translators: 1: number of files, 2: total size. */
						_n(
							'%1$d file, %2$s',
							'%1$d files, %2$s',
							item.files,
							'windrose-anchors'
						),
						item.files,
						formatBytes( item.bytes )
					)
				: '',
	},
	{
		id: 'posts',
		type: 'integer',
		label: __( 'Content to update', 'windrose-anchors' ),
		filterBy: false,
		render: ( { item } ) =>
			sprintf(
				/* translators: %d: number of posts, pages, templates or patterns. */
				_n( '%d post', '%d posts', item.posts, 'windrose-anchors' ),
				item.posts
			),
	},
	{
		id: 'status',
		type: 'text',
		label: __( 'Status', 'windrose-anchors' ),
		elements: STATUSES,
		filterBy: { operators: [ 'is' ] },
		render: ( { item } ) => {
			const lines = [ ...item.problems, ...item.warnings ];
			return (
				<>
					<strong>
						{
							STATUSES.find( ( s ) => s.value === item.status )
								?.label
						}
					</strong>
					{ lines.map( ( line ) => (
						<span key={ line } className="windrose-anchors__line">
							{ line }
						</span>
					) ) }
				</>
			);
		},
	},
];

export default function LeaveScreen() {
	const [ plan, setPlan ] = useState( null );
	const [ scope, setScope ] = useState( ALL );
	const [ stopAllowing, setStopAllowing ] = useState( true );
	const [ backedUp, setBackedUp ] = useState( false );
	const [ progress, setProgress ] = useState( null );
	const [ outcome, setOutcome ] = useState( null );
	const [ notice, notify ] = useNotice();
	const [ view, setView ] = useState( {
		type: 'table',
		search: '',
		filters: [],
		page: 1,
		perPage: 20,
		titleField: 'title',
		fields: [ 'siteName', 'target', 'files', 'posts', 'status' ],
		layout: {},
	} );

	const check = useCallback( async () => {
		setPlan( null );
		try {
			setPlan( await apiFetch( { path: `${ PATH }?site=0` } ) );
		} catch ( error ) {
			setPlan( { items: [], summary: {}, allowed: [] } );
			notify( 'error', error.message );
		}
	}, [ notify ] );

	useEffect( () => {
		check();
	}, [ check ] );

	const sites = useMemo( () => {
		const all = new Map();
		( plan?.items || [] ).forEach( ( item ) => {
			const site = all.get( item.site ) || {
				name: item.siteName,
				count: 0,
			};
			site.count += 1;
			all.set( item.site, site );
		} );
		return all;
	}, [ plan ] );

	const inScope = useMemo(
		() =>
			( plan?.items || [] ).filter(
				( item ) => scope === ALL || String( item.site ) === scope
			),
		[ plan, scope ]
	);
	const ready = inScope.filter( ( item ) => item.status === 'ready' );
	const bytes = ready.reduce( ( sum, item ) => sum + item.bytes, 0 );
	const files = ready.reduce( ( sum, item ) => sum + item.files, 0 );
	const posts = ready.reduce( ( sum, item ) => sum + item.posts, 0 );
	const free = plan?.summary?.freeBytes;
	const noRoom = typeof free === 'number' && bytes > free;
	const running = progress !== null;

	const { data: shown, paginationInfo } = useMemo(
		() => filterSortAndPaginate( inScope, view, fields ),
		[ inScope, view ]
	);

	const convert = async () => {
		setOutcome( null );
		const done = { converted: 0, posts: 0, failed: [] };
		setProgress( 0 );
		speak( __( 'Converting network items…', 'windrose-anchors' ) );
		try {
			if ( stopAllowing ) {
				const allowed = ( plan.allowed || [] ).filter(
					( id ) => scope === ALL || String( id ) === scope
				);
				if ( allowed.length ) {
					await apiFetch( {
						path: '/windrose-anchors/v1/sites',
						method: 'POST',
						data: { sites: allowed, allowed: false },
					} );
				}
			}
			const bySite = new Map();
			ready.forEach( ( item ) =>
				bySite.set( item.site, [
					...( bySite.get( item.site ) || [] ),
					item,
				] )
			);
			let handled = 0;
			for ( const [ site, items ] of bySite ) {
				for ( let i = 0; i < items.length; i += data.batch || 20 ) {
					const batch = items.slice( i, i + ( data.batch || 20 ) );
					const response = await apiFetch( {
						path: PATH,
						method: 'POST',
						data: { site, ids: batch.map( ( item ) => item.id ) },
					} );
					response.results.forEach( ( result ) => {
						if ( result.ok ) {
							done.converted += 1;
							done.posts += result.posts;
						} else {
							const item = batch.find(
								( row ) => row.id === result.id
							);
							done.failed.push(
								`${ item?.siteName }: ${ item?.title } (${ result.message })`
							);
						}
					} );
					handled += batch.length;
					setProgress(
						Math.round( ( handled / ready.length ) * 100 )
					);
				}
			}
		} catch ( error ) {
			done.failed.push( error.message );
		}
		setProgress( null );
		setBackedUp( false );
		setOutcome( done );
		speak(
			sprintf(
				/* translators: %d: number of network items. */
				_n(
					'%d network item converted.',
					'%d network items converted.',
					done.converted,
					'windrose-anchors'
				),
				done.converted
			)
		);
		check();
	};

	if ( ! plan ) {
		return (
			<p>
				{ __(
					'Checking the network items of every site (dry run)…',
					'windrose-anchors'
				) }
			</p>
		);
	}

	const leftNetworkWide = plan.items.length;

	return (
		<>
			{ notice }
			<Card>
				<CardBody>
					<p>
						{ __(
							'Leave safely gives each network item a file of its own: the file and its sizes are copied from its media hub into the uploads folder of the site that uses it, the item becomes a normal media item there (same ID, title, alternative text, caption and description), and the content of that site points to the copies. The media hubs’ files are not changed.',
							'windrose-anchors'
						) }
					</p>
					<p>
						{ __(
							'Run it before deactivating Windrose Anchors (for every site), or before exporting one site alone or moving it out of the network (for that site). Without it, network items have no file once Windrose Anchors is off.',
							'windrose-anchors'
						) }
					</p>
				</CardBody>
			</Card>

			{ outcome && (
				<Notice
					status={ outcome.failed.length ? 'warning' : 'success' }
					onRemove={ () => setOutcome( null ) }
					className="windrose-anchors__notice"
				>
					<p>
						{ sprintf(
							/* translators: %d: number of network items. */
							_n(
								'%d network item is now a normal media item of its site.',
								'%d network items are now normal media items of their sites.',
								outcome.converted,
								'windrose-anchors'
							),
							outcome.converted
						) }{ ' ' }
						{ sprintf(
							/* translators: %d: number of posts, pages, templates or patterns. */
							_n(
								'Content updated in %d post.',
								'Content updated in %d posts.',
								outcome.posts,
								'windrose-anchors'
							),
							outcome.posts
						) }
					</p>
					{ outcome.failed.length > 0 && (
						<ul>
							{ outcome.failed.map( ( line ) => (
								<li key={ line }>{ line }</li>
							) ) }
						</ul>
					) }
				</Notice>
			) }

			{ leftNetworkWide === 0 ? (
				<Notice
					status="success"
					isDismissible={ false }
					className="windrose-anchors__notice"
				>
					{ __(
						'No site holds network items: nothing depends on Windrose Anchors. It can be deactivated without breaking any site.',
						'windrose-anchors'
					) }{ ' ' }
					<a href={ data.urls?.plugins }>
						{ __( 'Plugins', 'windrose-anchors' ) }
					</a>
				</Notice>
			) : (
				<Card className="windrose-anchors__leave">
					<CardBody>
						<SelectControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							label={ __(
								'Network items of',
								'windrose-anchors'
							) }
							value={ scope }
							onChange={ setScope }
							disabled={ running }
							options={ [
								{
									value: ALL,
									label: sprintf(
										/* translators: %d: number of network items. */
										__(
											'Every site (%d)',
											'windrose-anchors'
										),
										plan.items.length
									),
								},
								...[ ...sites ].map( ( [ id, site ] ) => ( {
									value: String( id ),
									label: sprintf(
										/* translators: 1: site name, 2: number of network items. */
										__( '%1$s (%2$d)', 'windrose-anchors' ),
										site.name,
										site.count
									),
								} ) ),
							] }
						/>
						<p>
							{ sprintf(
								/* translators: %d: number of network items. */
								_n(
									'Ready: %d network item.',
									'Ready: %d network items.',
									ready.length,
									'windrose-anchors'
								),
								ready.length
							) }{ ' ' }
							{ sprintf(
								/* translators: 1: number of files, 2: total size. */
								_n(
									'%1$d file to copy (%2$s).',
									'%1$d files to copy (%2$s).',
									files,
									'windrose-anchors'
								),
								files,
								formatBytes( bytes )
							) }{ ' ' }
							{ sprintf(
								/* translators: %d: number of posts, pages, templates or patterns. */
								_n(
									'Content to update in %d post.',
									'Content to update in %d posts.',
									posts,
									'windrose-anchors'
								),
								posts
							) }{ ' ' }
							{ inScope.length > ready.length &&
								sprintf(
									/* translators: %d: number of network items. */
									_n(
										'%d cannot be converted (see the table).',
										'%d cannot be converted (see the table).',
										inScope.length - ready.length,
										'windrose-anchors'
									),
									inScope.length - ready.length
								) }
						</p>
						{ noRoom && (
							<Notice
								status="error"
								isDismissible={ false }
								className="windrose-anchors__notice"
							>
								{ sprintf(
									/* translators: 1: size needed, 2: free space. */
									__(
										'Not enough disk space: %1$s needed, %2$s free.',
										'windrose-anchors'
									),
									formatBytes( bytes ),
									formatBytes( free )
								) }
							</Notice>
						) }
						<CheckboxControl
							__nextHasNoMarginBottom
							label={
								scope === ALL
									? __(
											'First remove the Network tab from every site (no new network items while converting)',
											'windrose-anchors'
										)
									: sprintf(
											/* translators: %s: site name. */
											__(
												'First remove the Network tab from %s (no new network items while converting)',
												'windrose-anchors'
											),
											sites.get( Number( scope ) )?.name
										)
							}
							checked={ stopAllowing }
							onChange={ setStopAllowing }
							disabled={ running }
						/>
						<CheckboxControl
							__nextHasNoMarginBottom
							label={ __(
								'I have a backup of the database and of the uploads folder',
								'windrose-anchors'
							) }
							checked={ backedUp }
							onChange={ setBackedUp }
							disabled={ running }
						/>
						<div className="windrose-anchors__buttons">
							<Button
								__next40pxDefaultSize
								variant="primary"
								onClick={ convert }
								disabled={
									running ||
									! backedUp ||
									noRoom ||
									ready.length === 0
								}
								accessibleWhenDisabled
							>
								{ sprintf(
									/* translators: %d: number of network items. */
									_n(
										'Convert %d network item',
										'Convert %d network items',
										ready.length,
										'windrose-anchors'
									),
									ready.length
								) }
							</Button>
							<Button
								__next40pxDefaultSize
								variant="secondary"
								onClick={ check }
								disabled={ running }
							>
								{ __( 'Check again', 'windrose-anchors' ) }
							</Button>
						</div>
						{ running && (
							<div className="windrose-anchors__progress">
								<ProgressBar
									value={ progress }
									aria-label={ __(
										'Converting network items',
										'windrose-anchors'
									) }
								/>
							</div>
						) }
					</CardBody>
				</Card>
			) }

			{ inScope.length > 0 && (
				<DataViews
					data={ shown }
					fields={ fields }
					view={ view }
					onChangeView={ setView }
					paginationInfo={ paginationInfo }
					defaultLayouts={ { table: {} } }
					getItemId={ ( item ) => item.key }
					searchLabel={ __(
						'Search network items',
						'windrose-anchors'
					) }
				/>
			) }
		</>
	);
}
