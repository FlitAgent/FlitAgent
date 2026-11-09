<?php
/**
 * Risk tiers of a write.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes;

defined( 'ABSPATH' ) || exit;

/**
 * Server-derived risk tiers. Only a tool's assess() sets a tier, from the tool, its arguments and the site's state; the
 * model never can.
 */
final class Risk {

	/**
	 * A rollback mechanism exists (revision, trash, snapshot, or derived data). May be auto-approved by policy.
	 */
	const REVERSIBLE = 'reversible';

	/**
	 * Irreversible or high blast radius. Always needs a human approval.
	 */
	const DESTRUCTIVE = 'destructive';

	/**
	 * Refused outright.
	 */
	const PROHIBITED = 'prohibited';

	/**
	 * Tier order, lowest risk first.
	 */
	const ORDER = array(
		self::REVERSIBLE  => 1,
		self::DESTRUCTIVE => 2,
		self::PROHIBITED  => 3,
	);

	/**
	 * Rank of a tier for comparisons. An unknown tier ranks as prohibited, so mistakes fail closed.
	 *
	 * @param string $tier Tier.
	 * @return int
	 */
	public static function rank( string $tier ): int {
		return self::ORDER[ $tier ] ?? self::ORDER[ self::PROHIBITED ];
	}
}
