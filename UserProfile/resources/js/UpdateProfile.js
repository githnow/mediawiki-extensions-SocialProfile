/**
 * JavaScript used on Special:UpdateProfile
 * Displays the "State" dropdown menu if selected country is the "Россия"
 */
( function () {

	var countries = [];
	countries[ 0 ] = {
		country: 'Россия',
		name: 'Регион',
		sections: [
			'Центральный ФО', 'Южный ФО', 'Северо-Западный ФО', 'Дальневосточный ФО',
			'Сибирский ФО', 'Уральский ФО', 'Приволжский ФО', 'Северо-Кавказский ФО'
		]
	};

	function displaySection( id, country, section ) {
		var country_id = -1;
		for ( var x = 0; x <= countries.length - 1; x++ ) {
			if ( country === countries[ x ].country ) {
				country_id = x;
			}
		}

		var section_select = '';
		if ( countries[ country_id ] ) {
			document.getElementById( id + '_label' ).innerHTML = countries[ country_id ].name;
			section_select += '<select class="profile-form" name="' + id + '" id="' + id + '"><option></option>';
			for ( x = 0; x <= countries[ country_id ].sections.length - 1; x++ ) {
				section_select += '<option value="' + countries[ country_id ].sections[ x ] + '"' +
				( ( countries[ country_id ].sections[ x ] === section ) ? ' selected="selected"' : '' ) + '>' + countries[ country_id ].sections[ x ] + '</option>';
			}
			section_select += '</select>';
		}

		document.getElementById( id + '_form' ).innerHTML = section_select;
	}

	$( function () {
		$( '#birthday' ).datepicker( {
			changeYear: true,
			yearRange: '1970:c',
			dateFormat: $( '#birthday' ).hasClass( 'long-birthday' ) ? 'mm/dd/yy' : 'mm/dd'
		} );

		// state selector -- but only on [[Special:UpdateProfile]] and [[Special:EditProfile]],
		// *not* on [[Special:UpdateProfile/custom]] etc.!
		if (
			(
				mw.config.get( 'wgCanonicalSpecialPageName' ) === 'UpdateProfile' &&
				mw.config.get( 'wgTitle' ).indexOf( '/' ) === -1
			) ||
			mw.config.get( 'wgCanonicalSpecialPageName' ) === 'EditProfile'
		) {
			displaySection( 'location_state', $( '#location_country' ).val(), $( '#location_state_current' ).val() );
			$( '#location_country' ).on( 'change', function () {
				displaySection( 'location_state', this.value, '' );
			} );

			displaySection( 'hometown_state', $( '#hometown_country' ).val(), $( '#hometown_state_current' ).val() );
			$( '#hometown_country' ).on( 'change', function () {
				displaySection( 'hometown_state', this.value, '' );
			} );
		}

		// Profile visibility stuff
		// This flag skips updating the profile field visibilities by SpecialUpdateProfile#execute
		// if and when the user has JS enabled.
		// The existence of this flag is just meant as a fallback for no-JS users, hence why
		// it is _true_ and not false by default and we explicitly set it to false
		// for JS users here.
		if ( $( 'input[name="should_update_field_visibilities"]' ).length > 0 ) {
			$( 'input[name="should_update_field_visibilities"]' ).value = false;
		}

		$( 'body' ).on( 'mouseenter', '.eye-container', function () {
			/*if ( $( this ).css( 'position' ) !== 'absolute' ) {
				var offset = $( this ).offset();
				$( 'body' ).append( $( this ) );
				$( this ).css( {
					position: 'absolute',
					top: offset.top + 'px',
					left: offset.left + 'px'
				} );
			}*/

			$( this ).css( { zIndex: 1000 } );

			/*$( this ).animate( { height: 100 }, 100 );*/
		} );
		$( 'body' ).on( 'mouseleave', '.eye-container', function () {
			/*$( this ).animate( { height: 20 }, 100 );*/
			$( this ).css( { zIndex: 10 } );
		} );

		$( 'body' ).on( 'click', '.eye-container > .menu > .item', function () {
			/*$( this ).parent().parent().css( { height: 20 } );*/
			var field_key = $( this ).parent().parent().attr( 'fieldkey' );
			var priv = $( this ).attr( 'action' );
			var this_element = $( this ).parent().parent();

			$( this_element ).css( {
				opacity: 0.3,
				backgroundImage: 'none',
				backgroundColor: 'lightgray'
			} );

			$( this_element ).find( 'div.title' ).html( '...' );

			( new mw.Api() ).postWithToken( 'csrf', {
				action: 'smpuserprivacy',
				format: 'json',
				method: 'set',
				field_key: field_key,
				privacy: encodeURIComponent( priv )
			} ).done( function ( data ) {
				var offset = $( this_element ).offset();
				var newEl = $( data.smpuserprivacy.replace );
				// Find All elements classes is 'eye-container'
				let eyeContainers = document.querySelectorAll('.eye-container');
				// Searching the fieldkey in keys
				for (let i = 0; i < eyeContainers.length; i++) {
					let currentContainer = eyeContainers[i];
					let currentFieldkey = currentContainer.getAttribute('fieldkey');
					// If fieldkey same 'up_schools or someone' then add new element in old position
					if (currentFieldkey === field_key) {
						$( newEl ).css( {
							position: 'relative',
							// top: offset.top + 'px',
							// left: offset.left + 'px',
							// Apparently this is set inline, but it's not set anymore here
							// (after the user has changed the value), which makes the button
							// essentially invisible to the user. Fun!
							zIndex: 10
						} );					
						$(currentContainer.parentNode).append( $( newEl ) );
						$( this_element ).remove();
					}
				}
				/*$( 'body' ).append( $( newEl ) );*/
				} );
		} );
	} );

}() );
