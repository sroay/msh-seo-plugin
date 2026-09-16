/**
 * MSH SEO: newsletter signup.
 *
 * Posts the email address to the newsletter endpoint named on the form
 * (data-endpoint) and shows the result in the form's note. Without
 * JavaScript the form still posts to the same endpoint.
 */
( function () {
	var forms = document.querySelectorAll( '[data-msh-newsletter]' );

	Array.prototype.forEach.call( forms, function ( form ) {
		form.addEventListener( 'submit', function ( event ) {
			var input = form.querySelector( 'input[type=email]' );
			var note = form.querySelector( '[data-msh-newsletter-note]' );
			var button = form.querySelector( 'button' );
			var failed = 'That did not work. Please try again.';

			event.preventDefault();
			if ( ! input || ! input.value ) {
				return;
			}
			button.disabled = true;

			fetch( form.getAttribute( 'data-endpoint' ), {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify( {
					email: input.value,
					source: form.getAttribute( 'data-source' ),
				} ),
			} )
				.then( function ( response ) {
					return response
						.json()
						.catch( function () {
							return {};
						} )
						.then( function ( data ) {
							return { ok: response.ok, data: data };
						} );
				} )
				.then( function ( result ) {
					if ( note ) {
						note.textContent = result.ok
							? 'Check your inbox and click the confirmation link.'
							: ( result.data && result.data.error ) || failed;
					}
					if ( result.ok ) {
						input.value = '';
					}
					button.disabled = false;
				} )
				.catch( function () {
					if ( note ) {
						note.textContent = failed;
					}
					button.disabled = false;
				} );
		} );
	} );
} )();
