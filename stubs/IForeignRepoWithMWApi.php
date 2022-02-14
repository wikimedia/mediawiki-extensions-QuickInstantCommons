<?php
/**
 * A stub to make this extension work with MW < 1.38.
 *
 * In MW 1.38 and greater, this is included in MW core.
 *
 * This file should not be loaded if we are on MW 1.38+.
 */
interface IForeignRepoWithMWApi {
	/**
	 * Make an API query in the foreign repo, caching results
	 *
	 * @note action=query, format=json, redirects=true and uselang are automatically set.
	 * @param array $query Fields to pass to the query
	 * @return array|null
	 * @since 1.38
	 */
	public function fetchImageQuery( $query );
}
