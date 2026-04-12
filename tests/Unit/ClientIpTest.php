<?php
/**
 * Tests for getClientIp()
 *
 * BUG FOUND: X-Forwarded-For header is trusted without any restriction.
 * An attacker can spoof their IP by sending a forged X-Forwarded-For header.
 * This is only used for logging (not security decisions), but worth noting.
 */

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Stubs\TestableWebhookController;

class ClientIpTest extends TestCase
{
	private TestableWebhookController $controller;

	protected function setUp(): void
	{
		$this->controller = new TestableWebhookController;
		// Clear any previous $_SERVER state
		unset( $_SERVER['REMOTE_ADDR'] );
		unset( $_SERVER['HTTP_X_FORWARDED_FOR'] );
		unset( $_SERVER['HTTP_CLIENT_IP'] );
	}

	protected function tearDown(): void
	{
		unset( $_SERVER['REMOTE_ADDR'] );
		unset( $_SERVER['HTTP_X_FORWARDED_FOR'] );
		unset( $_SERVER['HTTP_CLIENT_IP'] );
	}

	public function testReturnsRemoteAddr(): void
	{
		$_SERVER['REMOTE_ADDR'] = '192.168.1.100';
		$this->assertSame( '192.168.1.100', $this->controller->testGetClientIp() );
	}

	public function testPrefersXForwardedFor(): void
	{
		$_SERVER['REMOTE_ADDR'] = '10.0.0.1';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.50, 70.41.3.18';
		$this->assertSame( '203.0.113.50 (via 10.0.0.1)', $this->controller->testGetClientIp() );
	}

	public function testFallsToClientIp(): void
	{
		$_SERVER['REMOTE_ADDR'] = '10.0.0.1';
		$_SERVER['HTTP_CLIENT_IP'] = '198.51.100.25';
		$this->assertSame( '198.51.100.25 (via 10.0.0.1)', $this->controller->testGetClientIp() );
	}

	public function testXForwardedForTakesPriorityOverClientIp(): void
	{
		$_SERVER['REMOTE_ADDR'] = '10.0.0.1';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.50';
		$_SERVER['HTTP_CLIENT_IP'] = '198.51.100.25';
		$this->assertSame( '203.0.113.50 (via 10.0.0.1)', $this->controller->testGetClientIp() );
	}

	public function testOmitsViaWhenForwardedEqualsRemote(): void
	{
		$_SERVER['REMOTE_ADDR'] = '203.0.113.50';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.50';
		$this->assertSame( '203.0.113.50', $this->controller->testGetClientIp() );
	}

	public function testReturnsDefaultOnMissingRemoteAddr(): void
	{
		// No $_SERVER['REMOTE_ADDR'] set
		$this->assertSame( '0.0.0.0', $this->controller->testGetClientIp() );
	}

	public function testRejectsInvalidIpAddress(): void
	{
		$_SERVER['REMOTE_ADDR'] = 'not-an-ip';
		$this->assertSame( '0.0.0.0', $this->controller->testGetClientIp() );
	}

	public function testRejectsInvalidForwardedIp(): void
	{
		$_SERVER['REMOTE_ADDR'] = '10.0.0.1';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = 'definitely-not-an-ip';
		/* Invalid forwarded IP is discarded and we fall back to the raw REMOTE_ADDR. */
		$this->assertSame( '10.0.0.1', $this->controller->testGetClientIp() );
	}

	public function testHandlesIPv6(): void
	{
		$_SERVER['REMOTE_ADDR'] = '::1';
		$this->assertSame( '::1', $this->controller->testGetClientIp() );
	}

	public function testHandlesIPv6Forwarded(): void
	{
		$_SERVER['REMOTE_ADDR'] = '10.0.0.1';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '2001:db8::1, 10.0.0.1';
		$this->assertSame( '2001:db8::1 (via 10.0.0.1)', $this->controller->testGetClientIp() );
	}

	public function testTrimsWhitespaceFromForwardedIp(): void
	{
		$_SERVER['REMOTE_ADDR'] = '10.0.0.1';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '  203.0.113.50  , 70.41.3.18';
		$this->assertSame( '203.0.113.50 (via 10.0.0.1)', $this->controller->testGetClientIp() );
	}
}
