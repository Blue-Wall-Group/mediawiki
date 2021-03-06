<?php
/**
 * Makes the required database updates for Special:ProtectedPages
 * to show all protected pages, even ones before the page restrictions
 * schema change. All remaining page_restriction column values are moved
 * to the new table.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 * @ingroup Maintenance
 */

require_once __DIR__ . '/Maintenance.php';

/**
 * Maintenance script that updates page_restrictions table from
 * old page_restriction column.
 *
 * @ingroup Maintenance
 */
class UpdateRestrictions extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Updates page_restrictions table from old page_restriction column' );
		$this->setBatchSize( 1000 );
	}

	public function execute() {
		$db = $this->getDB( DB_MASTER );
		$batchSize = $this->getBatchSize();
		if ( !$db->tableExists( 'page_restrictions' ) ) {
			$this->fatalError( "page_restrictions table does not exist" );
		}

		$start = $db->selectField( 'page', 'MIN(page_id)', false, __METHOD__ );
		if ( !$start ) {
			$this->fatalError( "Nothing to do." );
		}
		$end = $db->selectField( 'page', 'MAX(page_id)', false, __METHOD__ );

		# Do remaining chunk
		$end += $batchSize - 1;
		$blockStart = $start;
		$blockEnd = $start + $batchSize - 1;
		$encodedExpiry = 'infinity';
		while ( $blockEnd <= $end ) {
			$this->output( "...doing page_id from $blockStart to $blockEnd out of $end\n" );
			$cond = "page_id BETWEEN " . (int)$blockStart . " AND " . (int)$blockEnd .
				" AND page_restrictions !=''";
			$res = $db->select(
				'page',
				[ 'page_id', 'page_namespace', 'page_restrictions' ],
				$cond,
				__METHOD__
			);
			$batch = [];
			foreach ( $res as $row ) {
				$oldRestrictions = [];
				foreach ( explode( ':', trim( $row->page_restrictions ) ) as $restrict ) {
					$temp = explode( '=', trim( $restrict ) );
					// Make sure we are not settings restrictions to ""
					if ( count( $temp ) == 1 && $temp[0] ) {
						// old old format should be treated as edit/move restriction
						$oldRestrictions["edit"] = trim( $temp[0] );
						$oldRestrictions["move"] = trim( $temp[0] );
					} elseif ( $temp[1] ) {
						$oldRestrictions[$temp[0]] = trim( $temp[1] );
					}
				}
				# Clear invalid columns
				if ( $row->page_namespace == NS_MEDIAWIKI ) {
					$db->update( 'page', [ 'page_restrictions' => '' ],
						[ 'page_id' => $row->page_id ], __FUNCTION__ );
					$this->output( "...removed dead page_restrictions column for page {$row->page_id}\n" );
				}
				# Update restrictions table
				foreach ( $oldRestrictions as $action => $restrictions ) {
					$batch[] = [
						'pr_page' => $row->page_id,
						'pr_type' => $action,
						'pr_level' => $restrictions,
						'pr_cascade' => 0,
						'pr_expiry' => $encodedExpiry
					];
				}
			}
			# We use insert() and not replace() as Article.php replaces
			# page_restrictions with '' when protected in the restrictions table
			if ( count( $batch ) ) {
				$ok = $db->deadlockLoop( [ $db, 'insert' ], 'page_restrictions',
					$batch, __FUNCTION__, [ 'IGNORE' ] );
				if ( !$ok ) {
					throw new MWException( "Deadlock loop failed wtf :(" );
				}
			}
			$blockStart += $batchSize - 1;
			$blockEnd += $batchSize - 1;
			wfWaitForSlaves();
		}
		$this->output( "...removing dead rows from page_restrictions\n" );
		// Kill any broken rows from previous imports
		$db->delete( 'page_restrictions', [ 'pr_level' => '' ] );
		// Kill other invalid rows
		$db->deleteJoin(
			'page_restrictions',
			'page',
			'pr_page',
			'page_id',
			[ 'page_namespace' => NS_MEDIAWIKI ]
		);
		$this->output( "...Done!\n" );
	}
}

$maintClass = UpdateRestrictions::class;
require_once RUN_MAINTENANCE_IF_MAIN;
