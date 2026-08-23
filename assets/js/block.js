/**
 * Editor del bloque "Formulario Lucuma CRM".
 *
 * Escrito con wp.element.createElement en vez de JSX a propósito: el plugin no lleva
 * paso de compilación, y añadir uno por una pantalla de ajustes no compensa. El día que
 * el plugin necesite un bundler, esto se reescribe en JSX sin cambiar el comportamiento.
 */
( function ( blocks, element, components, blockEditor, apiFetch ) {
	var el = element.createElement;
	var useState = element.useState;
	var useEffect = element.useEffect;

	blocks.registerBlockType( 'lucuma-crm/form', {
		edit: function ( props ) {
			var attrs = props.attributes;
			var estado = useState( { forms: [], cargando: true, error: null } );
			var datos = estado[ 0 ];
			var setDatos = estado[ 1 ];

			var previa = useState( '' );
			var html = previa[ 0 ];
			var setHtml = previa[ 1 ];

			// Lista de formularios: se pide al servidor, que la trae del CRM con la
			// secret key. Nunca se escribe el ID a mano.
			useEffect( function () {
				apiFetch( { path: '/lucuma-crm/v1/forms' } )
					.then( function ( r ) {
						setDatos( { forms: r.forms || [], cargando: false, error: null } );
					} )
					.catch( function ( e ) {
						setDatos( {
							forms: [],
							cargando: false,
							error: e.message || 'No se pudo consultar el CRM.',
						} );
					} );
			}, [] );

			// Vista previa real, pedida al servidor: lo que se ve en el editor es el
			// mismo HTML que verá el visitante.
			useEffect( function () {
				if ( ! attrs.formId ) {
					setHtml( '' );
					return;
				}
				apiFetch( {
					path: '/lucuma-crm/v1/preview?formId=' + encodeURIComponent( attrs.formId ),
				} )
					.then( function ( r ) {
						setHtml( r.html || '' );
					} )
					.catch( function () {
						setHtml( '' );
					} );
			}, [ attrs.formId ] );

			var opciones = [ { label: '— Elige un formulario —', value: '' } ].concat(
				datos.forms.map( function ( f ) {
					return { label: f.name, value: f.id };
				} )
			);

			var panel = el(
				blockEditor.InspectorControls,
				{},
				el(
					components.PanelBody,
					{ title: 'Formulario', initialOpen: true },
					datos.cargando
						? el( components.Spinner )
						: el( components.SelectControl, {
								label: 'Formulario del CRM',
								value: attrs.formId,
								options: opciones,
								onChange: function ( v ) {
									props.setAttributes( { formId: v } );
								},
						  } ),
					datos.error
						? el(
								components.Notice,
								{ status: 'warning', isDismissible: false },
								datos.error
						  )
						: null,
					el( components.ToggleControl, {
						label: 'Cargar los estilos mínimos del plugin',
						help: 'Desactívalo si el formulario ya hereda el diseño del sitio.',
						checked: !! attrs.estilo,
						onChange: function ( v ) {
							props.setAttributes( { estilo: !! v } );
						},
					} )
				)
			);

			var cuerpo;
			if ( ! attrs.formId ) {
				cuerpo = el(
					components.Placeholder,
					{
						icon: 'feedback',
						label: 'Formulario Lucuma CRM',
						instructions: 'Elige cuál mostrar. La lista viene del CRM.',
					},
					datos.cargando
						? el( components.Spinner )
						: el( components.SelectControl, {
								value: attrs.formId,
								options: opciones,
								onChange: function ( v ) {
									props.setAttributes( { formId: v } );
								},
						  } )
				);
			} else {
				cuerpo = el( 'div', {
					className: 'lcrm-block-preview',
					dangerouslySetInnerHTML: { __html: html },
				} );
			}

			return el( 'div', blockEditor.useBlockProps(), panel, cuerpo );
		},

		// El render lo hace PHP: el bloque es dinámico.
		save: function () {
			return null;
		},
	} );
} )(
	window.wp.blocks,
	window.wp.element,
	window.wp.components,
	window.wp.blockEditor,
	window.wp.apiFetch
);
