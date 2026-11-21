/**
 * Windrose Anchors network admin, Sites: the media hubs, which sites may use network media, and the hub of each.
 */
import { useCallback, useEffect, useMemo, useState } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	ExternalLink,
	Notice,
	SelectControl,
	ToggleControl,
} from '@wordpress/components';
import { DataViews } from '@wordpress/dataviews/wp';
import { __, _n, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

import { data, useNotice } from './common';

const PATH = '/windrose-anchors/v1/sites';
const HUBS = '/windrose-anchors/v1/hubs';

const STATUSES = [
	{ value: 'hub', label: __( 'Media hub', 'windrose-anchors' ) },
	{ value: 'allowed', label: __( 'Allowed', 'windrose-anchors' ) },
	{ value: 'not-allowed', label: __( 'Not allowed', 'windrose-anchors' ) },
];

const ROLES = {
	main: __( 'Main site, always a media hub', 'windrose-anchors' ),
	marked: __( 'Media hub', 'windrose-anchors' ),
	group: __( 'Main site of a language group', 'windrose-anchors' ),
};

const DEFAULT_VIEW = {
	type: 'table',
	search: '',
	filters: [],
	page: 1,
	perPage: 20,
	titleField: 'name',
	fields: [ 'address', 'status', 'hub' ],
	layout: {},
};

/**
 * Chooses the media hub of sites.
 *
 * @param {Object}                          props            Props.
 * @param {Array}                           props.items      Sites.
 * @param {Array}                           props.hubs       Media hubs.
 * @param {() => void}                      props.closeModal Closes the dialog.
 * @param {(items, hub) => Promise<Object>} props.onChoose   Saves the choice.
 */
function ChooseHubModal( { items, hubs, closeModal, onChoose } ) {
	const [ hub, setHub ] = useState( String( items[ 0 ]?.hub || '' ) );
	const [ busy, setBusy ] = useState( false );
	return (
		<div className="windrose-anchors__choose-hub">
			<p>
				{ __(
					'New network items of these sites will come from this hub. Network items they already hold keep their own hub.',
					'windrose-anchors'
				) }
			</p>
			<SelectControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Media hub', 'windrose-anchors' ) }
				value={ hub }
				options={ hubs.map( ( item ) => ( {
					value: String( item.id ),
					label: item.name,
				} ) ) }
				onChange={ setHub }
			/>
			<div className="windrose-anchors__buttons">
				<Button
					__next40pxDefaultSize
					variant="tertiary"
					onClick={ closeModal }
				>
					{ __( 'Cancel', 'windrose-anchors' ) }
				</Button>
				<Button
					__next40pxDefaultSize
					variant="primary"
					isBusy={ busy }
					disabled={ busy }
					accessibleWhenDisabled
					onClick={ async () => {
						setBusy( true );
						await onChoose( items, Number( hub ) );
						closeModal();
					} }
				>
					{ __( 'Save', 'windrose-anchors' ) }
				</Button>
			</div>
		</div>
	);
}

export default function SitesScreen() {
	const [ view, setView ] = useState( DEFAULT_VIEW );
	const [ sites, setSites ] = useState( null );
	const [ total, setTotal ] = useState( { items: 0, pages: 1 } );
	const [ hubs, setHubs ] = useState( null );
	const [ notice, notify ] = useNotice();

	const loadHubs = useCallback( async () => {
		try {
			setHubs( await apiFetch( { path: HUBS } ) );
		} catch ( error ) {
			notify( 'error', error.message );
		}
	}, [ notify ] );

	const load = useCallback( async () => {
		const query = new URLSearchParams( {
			page: String( view.page ),
			per_page: String( view.perPage ),
		} );
		if ( view.search ) {
			query.set( 'search', view.search );
		}
		try {
			const response = await apiFetch( {
				path: `${ PATH }?${ query.toString() }`,
				parse: false,
			} );
			setSites( await response.json() );
			setTotal( {
				items: Number( response.headers.get( 'X-WP-Total' ) || 0 ),
				pages: Number( response.headers.get( 'X-WP-TotalPages' ) || 1 ),
			} );
		} catch ( error ) {
			setSites( [] );
			notify( 'error', error.message );
		}
	}, [ view.page, view.perPage, view.search, notify ] );

	useEffect( () => {
		load();
	}, [ load ] );
	useEffect( () => {
		loadHubs();
	}, [ loadHubs ] );

	const hubList = useMemo( () => hubs?.hubs ?? [], [ hubs ] );
	const hubName = useCallback(
		( id ) =>
			hubList.find( ( item ) => item.id === id )?.name ?? String( id ),
		[ hubList ]
	);

	/**
	 * Runs a change, reports it, reloads both lists.
	 *
	 * @param {() => Promise<Object>} request Request.
	 * @param {string}                success Message on success.
	 */
	const run = async ( request, success ) => {
		try {
			await request();
			notify( 'success', success );
		} catch ( error ) {
			notify( 'error', error.message );
		}
		await Promise.all( [ load(), loadHubs() ] );
	};

	const change = ( items, allowed ) =>
		run(
			() =>
				apiFetch( {
					path: PATH,
					method: 'POST',
					data: { sites: items.map( ( item ) => item.id ), allowed },
				} ),
			allowed
				? sprintf(
						/* translators: %d: number of sites. */
						_n(
							'%d site can now use network media.',
							'%d sites can now use network media.',
							items.length,
							'windrose-anchors'
						),
						items.length
					)
				: sprintf(
						/* translators: %d: number of sites. */
						_n(
							'%d site can no longer add network media. Network media already in use there keeps working.',
							'%d sites can no longer add network media. Network media already in use there keeps working.',
							items.length,
							'windrose-anchors'
						),
						items.length
					)
		);

	const setHub = ( item, hub ) =>
		run(
			() =>
				apiFetch( {
					path: HUBS,
					method: 'POST',
					data: { site: item.id, hub },
				} ),
			hub
				? sprintf(
						/* translators: %s: site name. */
						__(
							'%s is now a media hub: it can share items of its media library with the sites that use it.',
							'windrose-anchors'
						),
						item.name || item.address
					)
				: sprintf(
						/* translators: %s: site name. */
						__(
							'%s is no longer a media hub. Its sites now use the main site.',
							'windrose-anchors'
						),
						item.name || item.address
					)
		);

	const chooseHub = ( items, hub ) =>
		run(
			() =>
				apiFetch( {
					path: `${ HUBS }/choose`,
					method: 'POST',
					data: { sites: items.map( ( item ) => item.id ), hub },
				} ),
			sprintf(
				/* translators: 1: number of sites, 2: media hub name. */
				_n(
					'%1$d site now uses %2$s as media hub.',
					'%1$d sites now use %2$s as media hub.',
					items.length,
					'windrose-anchors'
				),
				items.length,
				hubName( hub )
			)
		);

	const follow = ( value ) =>
		run(
			() =>
				apiFetch( {
					path: `${ HUBS }/follow`,
					method: 'POST',
					data: { follow: value },
				} ),
			value
				? __(
						'Each site of a language group now uses its group’s main site as media hub.',
						'windrose-anchors'
					)
				: __(
						'Language groups are no longer followed for media hubs.',
						'windrose-anchors'
					)
		);

	const fields = [
		{
			id: 'name',
			type: 'text',
			label: __( 'Site', 'windrose-anchors' ),
			enableHiding: false,
			enableSorting: false,
			render: ( { item } ) => (
				<ExternalLink href={ item.url }>
					{ item.name || item.address }
				</ExternalLink>
			),
		},
		{
			id: 'address',
			type: 'text',
			label: __( 'Address', 'windrose-anchors' ),
			enableSorting: false,
		},
		{
			id: 'status',
			type: 'text',
			label: __( 'Network media', 'windrose-anchors' ),
			elements: STATUSES,
			filterBy: false,
			enableSorting: false,
		},
		{
			id: 'hub',
			type: 'text',
			label: __( 'Media hub', 'windrose-anchors' ),
			enableSorting: false,
			filterBy: false,
			render: ( { item } ) =>
				'hub' === item.status ? (
					<span className="windrose-anchors__muted">
						{ ROLES[ item.hubRole ] || ROLES.marked }
					</span>
				) : (
					hubName( item.hub )
				),
		},
	];

	const actions = [
		{
			id: 'allow',
			label: __( 'Allow', 'windrose-anchors' ),
			isPrimary: true,
			supportsBulk: true,
			isEligible: ( item ) => item.status === 'not-allowed',
			callback: ( items ) => change( items, true ),
		},
		{
			id: 'disallow',
			label: __( 'Stop allowing', 'windrose-anchors' ),
			isPrimary: true,
			supportsBulk: true,
			isEligible: ( item ) => item.status === 'allowed',
			callback: ( items ) => change( items, false ),
		},
		{
			id: 'choose-hub',
			label: __( 'Choose media hub', 'windrose-anchors' ),
			supportsBulk: true,
			isEligible: ( item ) => item.status !== 'hub' && hubList.length > 1,
			modalHeader: __( 'Choose media hub', 'windrose-anchors' ),
			RenderModal: ( { items, closeModal } ) => (
				<ChooseHubModal
					items={ items }
					hubs={ hubList }
					closeModal={ closeModal }
					onChoose={ chooseHub }
				/>
			),
		},
		{
			id: 'make-hub',
			label: __( 'Make media hub', 'windrose-anchors' ),
			isEligible: ( item ) => item.status !== 'hub',
			callback: ( [ item ] ) => setHub( item, true ),
		},
		{
			id: 'unmake-hub',
			label: __( 'Stop being a media hub', 'windrose-anchors' ),
			isEligible: ( item ) => item.hubRole === 'marked',
			callback: ( [ item ] ) => setHub( item, false ),
		},
	];

	return (
		<>
			{ notice }
			<Card className="windrose-anchors__card">
				<CardHeader>
					<h2 className="windrose-anchors__card-title">
						{ __( 'Media hubs', 'windrose-anchors' ) }
					</h2>
				</CardHeader>
				<CardBody>
					<p>
						{ __(
							'A media hub shares items of its own media library with the sites that use it: they appear in a Network tab of the media dialog. Each site keeps its own title, alternative text, caption and description; the file stays on the hub.',
							'windrose-anchors'
						) }
					</p>
					<p>
						{ __(
							'The main site is always a media hub, and the hub of every site by default. Other sites can be hubs too, for example the main site of each language project, so each project has its own shared media.',
							'windrose-anchors'
						) }
					</p>
					{ hubList.length > 0 && (
						<ul className="windrose-anchors__hubs">
							{ hubList.map( ( hub ) => (
								<li key={ hub.id }>
									<a href={ hub.media }>{ hub.name }</a>{ ' ' }
									<span className="windrose-anchors__muted">
										{ ROLES[ hub.role ] }
									</span>
								</li>
							) ) }
						</ul>
					) }
					{ hubs?.groupsAvailable &&
						! hubs.follow &&
						hubs.groupMains?.length > 0 && (
							<Notice
								status="info"
								isDismissible={ false }
								className="windrose-anchors__notice"
							>
								{ sprintf(
									/* translators: %s: list of sites (name and address), separated by commas. */
									_n(
										'A language group names %s as its main site. Turn on “Follow language groups” below so the sites of that group use it as their media hub.',
										'Language groups name these main sites: %s. Turn on “Follow language groups” below so the sites of each group use their group’s main site as media hub.',
										hubs.groupMains.length,
										'windrose-anchors'
									),
									hubs.groupMainsList
								) }
							</Notice>
						) }
					{ hubs?.groupsAvailable && (
						<ToggleControl
							__nextHasNoMarginBottom
							label={ __(
								'Follow language groups',
								'windrose-anchors'
							) }
							help={ __(
								'Each site of a language group (Windrose Sabir) uses its group’s main site as media hub, and that main site becomes a hub. Groups without a main site, and sites in no group, keep the hub chosen below.',
								'windrose-anchors'
							) }
							checked={ !! hubs.follow }
							onChange={ follow }
						/>
					) }
				</CardBody>
			</Card>
			<Card className="windrose-anchors__card">
				<CardHeader>
					<h2 className="windrose-anchors__card-title">
						{ __(
							'Sites using network media',
							'windrose-anchors'
						) }
					</h2>
				</CardHeader>
				<CardBody>
					<p>
						{ __(
							'A site that is no longer allowed loses the Network tab, but the network media it already uses keeps working. A site whose hub changes keeps the network items it holds; new ones come from its new hub.',
							'windrose-anchors'
						) }{ ' ' }
						{ __(
							'Before deactivating Windrose Anchors, or exporting one site alone, run Leave safely: it gives each network item a file of its own on its site.',
							'windrose-anchors'
						) }{ ' ' }
						<a href={ data.urls?.leave }>
							{ __( 'Leave safely', 'windrose-anchors' ) }
						</a>
					</p>
				</CardBody>
				<CardBody className="windrose-anchors__table">
					<DataViews
						data={ sites ?? [] }
						fields={ fields }
						view={ view }
						onChangeView={ setView }
						actions={ actions }
						paginationInfo={ {
							totalItems: total.items,
							totalPages: total.pages,
						} }
						defaultLayouts={ { table: {} } }
						getItemId={ ( item ) => String( item.id ) }
						isLoading={ sites === null }
						searchLabel={ __( 'Search sites', 'windrose-anchors' ) }
						empty={
							<p>
								{ __( 'No site found.', 'windrose-anchors' ) }
							</p>
						}
					/>
				</CardBody>
			</Card>
		</>
	);
}
