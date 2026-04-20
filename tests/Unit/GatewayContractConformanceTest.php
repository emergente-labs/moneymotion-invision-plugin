<?php
/**
 * Verifies our gateway extension conforms to the \IPS\nexus\Gateway contract
 * as defined in IPS 4.7.20.
 *
 * Why this matters: PHP does NOT enforce signature compatibility on overrides
 * unless strict checks are on, and even then a missing pass-by-reference (&)
 * or a missing optional parameter can silently break IPS's call site without
 * a fatal error — IPS will pass arguments we don't accept, and they'll be
 * dropped on the floor. The first symptom is a customer who can't check out.
 *
 * Each expected signature in this file mirrors the IPS 4.7.20 source at:
 *   ips_4.7.20/applications/nexus/sources/Gateway/Gateway.php
 *
 * If you upgrade IPS and a signature changes upstream, this test file is the
 * single point that needs updating — alongside the implementation. Don't
 * "fix" the test by relaxing it; fix the implementation to match the
 * upstream contract.
 */

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class GatewayContractConformanceTest extends TestCase
{
	private const GATEWAY_CLASS = \IPS\moneymotion\extensions\nexus\Gateway\_moneymotion::class;

	/* ---------- class hierarchy ---------- */

	public function testExtendsIpsNexusGateway(): void
	{
		$this->assertTrue(
			is_subclass_of( self::GATEWAY_CLASS, \IPS\nexus\Gateway::class ),
			'Gateway extension must extend \\IPS\\nexus\\Gateway so IPS can dispatch to it.'
		);
	}

	/* ---------- supports() ----------
	   IPS contract: supports($feature): bool
	   Called by IPS to feature-detect this gateway (refunds, recurring, etc.).
	*/
	public function testSupportsSignature(): void
	{
		$method = new \ReflectionMethod( self::GATEWAY_CLASS, 'supports' );
		$params = $method->getParameters();

		$this->assertTrue( $method->isPublic(), 'supports() must be public.' );
		$this->assertCount( 1, $params, 'supports() takes exactly one argument: the feature name.' );
		$this->assertSame( 'feature', $params[0]->getName() );
		$this->assertFalse( $params[0]->isOptional(), 'feature parameter is required.' );
	}

	/* ---------- canStoreCards() ----------
	   IPS contract: canStoreCards($adminCreatableOnly = FALSE): bool
	*/
	public function testCanStoreCardsSignature(): void
	{
		$method = new \ReflectionMethod( self::GATEWAY_CLASS, 'canStoreCards' );
		$params = $method->getParameters();

		$this->assertTrue( $method->isPublic() );
		$this->assertCount( 1, $params );
		$this->assertSame( 'adminCreatableOnly', $params[0]->getName() );
		$this->assertTrue( $params[0]->isOptional(), 'adminCreatableOnly must default — IPS calls it without args.' );
		$this->assertFalse( $params[0]->getDefaultValue(), 'Default must be FALSE per parent contract.' );
	}

	/* ---------- canAdminCharge() ----------
	   IPS contract: canAdminCharge(\IPS\nexus\Customer $customer): bool
	   The Customer type-hint is mandatory — IPS passes a Customer and a
	   missing/wrong type-hint would be a runtime TypeError on dispatch.
	*/
	public function testCanAdminChargeSignature(): void
	{
		$method = new \ReflectionMethod( self::GATEWAY_CLASS, 'canAdminCharge' );
		$params = $method->getParameters();

		$this->assertTrue( $method->isPublic() );
		$this->assertCount( 1, $params );
		$this->assertSame( 'customer', $params[0]->getName() );
		$this->assertFalse( $params[0]->isOptional(), 'customer is required.' );

		$type = $params[0]->getType();
		$this->assertNotNull( $type, 'customer must be type-hinted as \\IPS\\nexus\\Customer.' );
		$this->assertSame( 'IPS\\nexus\\Customer', $type->getName() );
	}

	/* ---------- settings() — pass-by-reference!
	   IPS contract: settings(&$form): void
	   The form is mutated in place. If we drop the `&` PHP will copy the
	   form, our additions are silent no-ops, and the ACP settings page
	   renders empty. This is a class of bug Reflection catches cheaply.
	*/
	public function testSettingsTakesFormByReference(): void
	{
		$method = new \ReflectionMethod( self::GATEWAY_CLASS, 'settings' );
		$params = $method->getParameters();

		$this->assertTrue( $method->isPublic() );
		$this->assertCount( 1, $params );
		$this->assertSame( 'form', $params[0]->getName() );
		$this->assertTrue(
			$params[0]->isPassedByReference(),
			'settings($form) MUST be pass-by-reference. Dropping the & silently breaks the ACP settings page.'
		);
	}

	/* ---------- testSettings() ----------
	   IPS contract: testSettings($settings): array
	*/
	public function testTestSettingsSignature(): void
	{
		$method = new \ReflectionMethod( self::GATEWAY_CLASS, 'testSettings' );
		$params = $method->getParameters();

		$this->assertTrue( $method->isPublic() );
		$this->assertCount( 1, $params );
		$this->assertSame( 'settings', $params[0]->getName() );
		$this->assertFalse( $params[0]->isOptional() );
	}

	/* ---------- paymentScreen() ----------
	   IPS contract:
	     paymentScreen(
	       \IPS\nexus\Invoice $invoice,
	       \IPS\nexus\Money $amount,
	       \IPS\nexus\Customer $member = NULL,
	       $recurrings = array(),
	       $type = 'checkout'
	     )
	   IPS dispatches with all 5 args at checkout. Missing the optional
	   $type parameter would TypeError under strict mode.
	*/
	public function testPaymentScreenSignature(): void
	{
		$method = new \ReflectionMethod( self::GATEWAY_CLASS, 'paymentScreen' );
		$params = $method->getParameters();

		$this->assertTrue( $method->isPublic() );
		$this->assertCount( 5, $params, 'paymentScreen() must accept all 5 parent parameters.' );

		$this->assertSame( 'invoice', $params[0]->getName() );
		$this->assertSame( 'IPS\\nexus\\Invoice', $params[0]->getType()?->getName() );
		$this->assertFalse( $params[0]->isOptional() );

		$this->assertSame( 'amount', $params[1]->getName() );
		$this->assertSame( 'IPS\\nexus\\Money', $params[1]->getType()?->getName() );
		$this->assertFalse( $params[1]->isOptional() );

		$this->assertSame( 'member', $params[2]->getName() );
		$this->assertSame( 'IPS\\nexus\\Customer', $params[2]->getType()?->getName() );
		$this->assertTrue( $params[2]->isOptional() );
		$this->assertNull( $params[2]->getDefaultValue() );

		$this->assertSame( 'recurrings', $params[3]->getName() );
		$this->assertTrue( $params[3]->isOptional() );
		$this->assertSame( array(), $params[3]->getDefaultValue() );

		$this->assertSame( 'type', $params[4]->getName() );
		$this->assertTrue( $params[4]->isOptional() );
		$this->assertSame( 'checkout', $params[4]->getDefaultValue() );
	}

	/* ---------- auth() ----------
	   IPS contract:
	     auth(
	       \IPS\nexus\Transaction $transaction,
	       $values,
	       \IPS\nexus\Fraud\MaxMind\Request $maxMind = NULL,
	       $recurrings = array(),
	       $source = NULL
	     ): \IPS\DateTime|NULL
	   This is the heart of the gateway — IPS calls it on every checkout
	   submission. Signature mismatches here = checkout failure.
	*/
	public function testAuthSignature(): void
	{
		$method = new \ReflectionMethod( self::GATEWAY_CLASS, 'auth' );
		$params = $method->getParameters();

		$this->assertTrue( $method->isPublic() );
		$this->assertCount( 5, $params, 'auth() must accept all 5 parent parameters.' );

		/* $transaction — required, type-hinted */
		$this->assertSame( 'transaction', $params[0]->getName() );
		$this->assertSame( 'IPS\\nexus\\Transaction', $params[0]->getType()?->getName() );
		$this->assertFalse( $params[0]->isOptional() );

		/* $values — no type hint (can be array OR CreditCard per IPS docblock) */
		$this->assertSame( 'values', $params[1]->getName() );
		$this->assertNull(
			$params[1]->getType(),
			'$values must NOT be type-hinted: IPS passes either array or \\IPS\\nexus\\Customer\\CreditCard.'
		);
		$this->assertFalse( $params[1]->isOptional() );

		/* $maxMind — optional, type-hinted, defaults NULL */
		$this->assertSame( 'maxMind', $params[2]->getName() );
		$this->assertSame( 'IPS\\nexus\\Fraud\\MaxMind\\Request', $params[2]->getType()?->getName() );
		$this->assertTrue( $params[2]->isOptional() );
		$this->assertNull( $params[2]->getDefaultValue() );

		/* $recurrings — optional, defaults [] */
		$this->assertSame( 'recurrings', $params[3]->getName() );
		$this->assertTrue( $params[3]->isOptional() );
		$this->assertSame( array(), $params[3]->getDefaultValue() );

		/* $source — optional, defaults NULL */
		$this->assertSame( 'source', $params[4]->getName() );
		$this->assertTrue( $params[4]->isOptional() );
		$this->assertNull( $params[4]->getDefaultValue() );
	}

	/* ---------- capture() ----------
	   IPS contract: capture(\IPS\nexus\Transaction $transaction): void
	*/
	public function testCaptureSignature(): void
	{
		$method = new \ReflectionMethod( self::GATEWAY_CLASS, 'capture' );
		$params = $method->getParameters();

		$this->assertTrue( $method->isPublic() );
		$this->assertCount( 1, $params );
		$this->assertSame( 'transaction', $params[0]->getName() );
		$this->assertSame( 'IPS\\nexus\\Transaction', $params[0]->getType()?->getName() );
	}

	/* ---------- void() ----------
	   IPS contract: void(\IPS\nexus\Transaction $transaction): void
	*/
	public function testVoidSignature(): void
	{
		$method = new \ReflectionMethod( self::GATEWAY_CLASS, 'void' );
		$params = $method->getParameters();

		$this->assertTrue( $method->isPublic() );
		$this->assertCount( 1, $params );
		$this->assertSame( 'transaction', $params[0]->getName() );
		$this->assertSame( 'IPS\\nexus\\Transaction', $params[0]->getType()?->getName() );
	}

	/* ---------- extraData() ----------
	   IPS contract: extraData(\IPS\nexus\Transaction $transaction): string
	*/
	public function testExtraDataSignature(): void
	{
		$method = new \ReflectionMethod( self::GATEWAY_CLASS, 'extraData' );
		$params = $method->getParameters();

		$this->assertTrue( $method->isPublic() );
		$this->assertCount( 1, $params );
		$this->assertSame( 'transaction', $params[0]->getName() );
		$this->assertSame( 'IPS\\nexus\\Transaction', $params[0]->getType()?->getName() );
	}

	/* ---------- Methods we deliberately do NOT override ----------
	   These come from the IPS parent class. Listing them here documents
	   intent and forces a deliberate decision on future maintainers — if
	   the parent's behavior is ever wrong for moneymotion, the test below
	   will need updating alongside an explicit override.
	*/
	public function testDoesNotOverrideRefund(): void
	{
		/* IPS parent::refund() throws \Exception. We rely on webhook-driven
		   refunds (handleCheckoutRefunded). An admin clicking "Refund" in the
		   ACP will hit the parent and error — by design — because moneymotion
		   refunds must originate from the moneymotion dashboard. */
		$method = new \ReflectionMethod( self::GATEWAY_CLASS, 'refund' );
		$this->assertNotSame(
			self::GATEWAY_CLASS,
			$method->getDeclaringClass()->getName(),
			'refund() should be inherited, not overridden — refunds flow through webhooks only.'
		);
	}

	public function testDoesNotOverrideCheckValidity(): void
	{
		/* checkValidity() honors the gateway's $countries column. We rely on
		   IPS's standard country-restriction logic instead of reimplementing. */
		$method = new \ReflectionMethod( self::GATEWAY_CLASS, 'checkValidity' );
		$this->assertNotSame(
			self::GATEWAY_CLASS,
			$method->getDeclaringClass()->getName(),
			'checkValidity() should remain inherited so country restrictions work normally.'
		);
	}

	public function testDoesNotOverrideFraudCheck(): void
	{
		/* fraudCheck() returns STATUS_PAID by default — we trust moneymotion's
		   own fraud screening and do not run a second pass on our side. */
		$method = new \ReflectionMethod( self::GATEWAY_CLASS, 'fraudCheck' );
		$this->assertNotSame(
			self::GATEWAY_CLASS,
			$method->getDeclaringClass()->getName(),
			'fraudCheck() should remain inherited — moneymotion handles fraud screening upstream.'
		);
	}
}
