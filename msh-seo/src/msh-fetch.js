import apiFetch from '@wordpress/api-fetch';

/**
 * REST helper that always targets the WordPress host — never the public
 * front-end. On headless installs (public site served from a different
 * domain, WP on a subdomain) WordPress's default apiFetch root is
 * home_url()-based, which is cross-origin from the editor and fails.
 * The PHP-localized mshSeoData.restUrl is host-corrected to site_url().
 *
 * Uses an absolute `url` (not `path`) deliberately: apiFetch's built-in
 * root middleware rewrites `path`-based requests back to the wrong root,
 * so `path` can't be fixed by adding another middleware.
 */
export default function mshFetch( endpoint, data ) {
	const base = ( window.mshSeoData && window.mshSeoData.restUrl ) || '/wp-json/msh-seo/v1/';
	return apiFetch( { url: base + endpoint, method: 'POST', data } );
}
