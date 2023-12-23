<?php
/**
 * Special page to list files with most global usages
 *
 * @file
 * @ingroup SpecialPage
 * @author Brian Wolff <bawolff+wn@gmail.com>
 */

namespace MediaWiki\Extension\GlobalUsage;

use Exception;
use ImageQueryPage;
use MediaWiki\Cache\LinkBatchFactory;
use MediaWiki\WikiMap\WikiMap;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\ILoadBalancer;

class SpecialMostGloballyLinkedFiles extends ImageQueryPage {

	public function __construct(
		ILoadBalancer $loadBalancer,
		LinkBatchFactory $linkBatchFactory
	) {
		parent::__construct( 'MostGloballyLinkedFiles' );
		$this->setDBLoadBalancer( $loadBalancer );
		$this->setLinkBatchFactory( $linkBatchFactory );
	}

	/**
	 * Main execution function. Use the parent if we're on the right wiki.
	 * If we're not on a shared repo, try to redirect there.
	 * @param string $par
	 */
	public function execute( $par ) {
		if ( GlobalUsage::onSharedRepo() ) {
			parent::execute( $par );
		} else {
			GlobalUsage::redirectSpecialPageToSharedRepo( $this->getContext() );
		}
	}

	public function isExpensive() {
		return true;
	}

	public function isSyndicated() {
		return false;
	}

	/**
	 * Don't want to do cached handling on non-shared repo, since we only redirect.
	 * @return bool
	 */
	public function isCacheable() {
		return GlobalUsage::onSharedRepo();
	}

	/**
	 * What query to do.
	 * @return array
	 */
	public function getQueryInfo() {
		$this->assertOnSharedRepo();
		return [
			'tables' => [ 'globalimagelinks' ],
			'fields' => [
				'namespace' => NS_FILE,
				'title' => 'gil_to',
				'value' => 'COUNT(*)'
			],
			'options' => [
				'GROUP BY' => 'gil_to',
				'HAVING' => 'COUNT(*) > 1'
			]
		];
	}

	protected function getCellHtml( $row ) {
		return $this->msg( 'nimagelinks' )->numParams( $row->value )->escaped() . '<br />';
	}

	/**
	 * Make sure we are on the shared repo.
	 *
	 * This function should only be used as a paranoia check, and should never actually be hit.
	 * There should be actual error handling for any code path a user could hit.
	 */
	protected function assertOnSharedRepo() {
		if ( !GlobalUsage::onSharedRepo() ) {
			throw new Exception(
				'Special:MostGloballyLinkedFiles should only be processed on the shared repo'
			);
		}
	}

	/**
	 * Only list this special page on the wiki that is the shared repo.
	 *
	 * @return bool Should this be listed in Special:SpecialPages
	 */
	public function isListed() {
		return GlobalUsage::onSharedRepo();
	}

	/**
	 * In most common configs (including WMF's), this wouldn't be needed. However
	 * for completeness support having the shared repo db be separate from the
	 * globalimagelinks db.
	 * @return IDatabase
	 */
	public function getRecacheDB() {
		global $wgGlobalUsageDatabase;

		// There's no reason why we couldn't make this special page work on all wikis,
		// it just doesn't really make sense to. We should be prevented from getting
		// to this point by $this->isCachable(), but just to be safe:
		$this->assertOnSharedRepo();

		if ( $wgGlobalUsageDatabase === false || $wgGlobalUsageDatabase === WikiMap::getCurrentWikiId() ) {
			// We are using the local wiki
			return parent::getRecacheDB();
		} else {
			// The global usage db could be on a different db
			return GlobalUsage::getGlobalDB(
				DB_REPLICA,
				[ $this->getName(), 'QueryPage::recache', 'vslow' ]
			);
		}
	}

	protected function getGroupName() {
		return 'highuse';
	}
}
