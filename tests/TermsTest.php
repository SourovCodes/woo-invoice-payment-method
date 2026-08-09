<?php
/**
 * Tests for the invoice terms entity and its table.
 *
 * @package WooInvoicePayment
 */

namespace WooInvoicePayment\Tests;

use WooInvoicePayment\Data\Terms;
use WooInvoicePayment\Data\TermsRepository;

/**
 * Covers the two answers a set of terms holds, and the fact that each one has a
 * "nothing decided" state that is not the same as its zero.
 */
class TermsTest extends TestCase {

	/**
	 * A new set of terms decides nothing.
	 */
	public function testANewSetOfTermsIsAllDefaults() {
		$terms = new Terms( 7 );

		$this->assertSame( 7, $terms->get_organization_id() );
		$this->assertSame( Terms::STATUS_DEFAULT, $terms->get_status() );
		$this->assertNull( $terms->get_credit_limit() );
		$this->assertFalse( $terms->has_credit_limit() );
		$this->assertTrue( $terms->is_default() );
	}

	/**
	 * Zero is a real answer to the limit, and null is a different one.
	 *
	 * This is the whole reason the column is nullable: no credit at all is a limit of
	 * zero, and that is not "no limit".
	 */
	public function testZeroAndNullAreDifferentAnswers() {
		$terms = new Terms( 1 );

		$terms->set( 'credit_limit', 0 );

		$this->assertSame( 0.0, $terms->get_credit_limit() );
		$this->assertTrue( $terms->has_credit_limit(), 'A limit of zero is a limit.' );
		$this->assertFalse( $terms->is_default() );

		$terms->set( 'credit_limit', null );

		$this->assertNull( $terms->get_credit_limit() );
		$this->assertFalse( $terms->has_credit_limit() );
	}

	/**
	 * An empty string means "no answer", because that is what an empty form field is.
	 */
	public function testAnEmptyFieldIsNotZero() {
		$terms = new Terms( 1 );

		$terms->set_props( array( 'credit_limit' => '' ) );

		$this->assertNull( $terms->get_credit_limit() );
	}

	/**
	 * A status the plugin does not define falls back to the default.
	 */
	public function testAnUnknownStatusIsRefused() {
		$terms = new Terms( 1 );

		$terms->set( 'status', 'whatever-i-like' );

		$this->assertSame( Terms::STATUS_DEFAULT, $terms->get_status() );
	}

	/**
	 * A column the entity does not have is ignored rather than stored.
	 */
	public function testUnknownColumnsAreIgnored() {
		$terms = new Terms( 1 );

		$terms->set_props(
			array(
				'organization_id' => 99,
				'nonsense'        => 'x',
			)
		);

		$this->assertSame( 1, $terms->get_organization_id(), 'The key is not writable through set().' );
		$this->assertArrayNotHasKey( 'nonsense', $terms->to_array() );
	}

	/**
	 * Terms survive a round trip through the database with their nulls intact.
	 */
	public function testTermsRoundTripThroughTheDatabase() {
		$organization = $this->make_organization();

		$this->set_terms(
			$organization,
			array(
				'status'       => Terms::STATUS_ALLOWED,
				'credit_limit' => 2500.5,
				'note'         => 'Agreed with Ada.',
			)
		);

		$stored = TermsRepository::find( $organization->get_id() );

		$this->assertSame( Terms::STATUS_ALLOWED, $stored->get_status() );
		$this->assertSame( 2500.5, $stored->get_credit_limit() );
		$this->assertSame( 'Agreed with Ada.', $stored->get_note() );
	}

	/**
	 * A null stays null through the database rather than arriving back as zero.
	 *
	 * $wpdb has no format specifier for null, so the column is left out of the
	 * statement instead. Writing it as %f would turn "no limit" into "no credit",
	 * which is the opposite instruction.
	 */
	public function testNullsSurviveTheRoundTrip() {
		$organization = $this->make_organization();

		$this->set_terms( $organization, array( 'status' => Terms::STATUS_BLOCKED ) );

		$stored = TermsRepository::find( $organization->get_id() );

		$this->assertSame( Terms::STATUS_BLOCKED, $stored->get_status() );
		$this->assertNull( $stored->get_credit_limit() );
	}

	/**
	 * An organization nobody has decided about reads as defaults, not as null.
	 */
	public function testAnUnknownOrganizationReadsAsDefaults() {
		$terms = TermsRepository::find( 987654 );

		$this->assertInstanceOf( Terms::class, $terms );
		$this->assertTrue( $terms->is_default() );
	}

	/**
	 * Saving terms that say nothing removes the row.
	 *
	 * A row of defaults and no row at all mean the same thing, and keeping both would
	 * leave the screen reporting a decision nobody made.
	 */
	public function testSavingDefaultsRemovesTheRow() {
		$organization = $this->make_organization();

		$this->set_terms( $organization, array( 'status' => Terms::STATUS_ALLOWED ) );
		$this->assertSame( 1, $this->count_rows( $organization->get_id() ) );

		$this->set_terms( $organization, array( 'status' => Terms::STATUS_DEFAULT ) );

		$this->assertSame( 0, $this->count_rows( $organization->get_id() ) );
		$this->assertTrue( TermsRepository::find( $organization->get_id() )->is_default() );
	}

	/**
	 * Saving twice replaces the row rather than adding a second one.
	 *
	 * The organization is the primary key, so this is the database keeping the rule
	 * rather than the code remembering it.
	 */
	public function testSavingTwiceKeepsOneRow() {
		$organization = $this->make_organization();

		$this->set_terms(
			$organization,
			array(
				'status'       => Terms::STATUS_ALLOWED,
				'credit_limit' => 100,
			)
		);
		$this->set_terms(
			$organization,
			array(
				'status'       => Terms::STATUS_BLOCKED,
				'credit_limit' => 250,
			)
		);

		$this->assertSame( 1, $this->count_rows( $organization->get_id() ) );

		$stored = TermsRepository::find( $organization->get_id() );

		$this->assertSame( Terms::STATUS_BLOCKED, $stored->get_status() );
		$this->assertSame( 250.0, $stored->get_credit_limit() );
	}

	/**
	 * Several organizations come back from one query, defaulted where there is no row.
	 */
	public function testFindManyAnswersForEveryIdItWasGiven() {
		$allowed = $this->make_organization( array( 'name' => 'Allowed GmbH' ) );
		$unknown = $this->make_organization( array( 'name' => 'Unknown GmbH' ) );

		$this->set_terms( $allowed, array( 'status' => Terms::STATUS_ALLOWED ) );

		$found = TermsRepository::find_many( array( $allowed->get_id(), $unknown->get_id() ) );

		$this->assertCount( 2, $found );
		$this->assertSame( Terms::STATUS_ALLOWED, $found[ $allowed->get_id() ]->get_status() );
		$this->assertTrue( $found[ $unknown->get_id() ]->is_default() );
	}

	/**
	 * Count the rows one organization has, which should never be more than one.
	 *
	 * @param int $organization_id Organization ID.
	 * @return int Row count.
	 */
	private function count_rows( $organization_id ) {
		global $wpdb;

		$table = TermsRepository::table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from the repository; the value is a placeholder.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE organization_id = %d", $organization_id ) );
	}
}
