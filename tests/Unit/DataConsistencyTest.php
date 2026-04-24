<?php
/**
 * Data consistency tests
 *
 * Verifies that all the JSON config files are consistent with each other
 * and with the actual code. Catches version mismatches, missing schema
 * definitions, incorrect module mappings, etc.
 *
 * BUG FOUND: versions.json max version is 30016 (3.0.16) but
 * application.json says 3.0.17 (30017). Missing version entry.
 */

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class DataConsistencyTest extends TestCase
{
	private string $dataPath;

	protected function setUp(): void
	{
		$this->dataPath = __DIR__ . '/../../applications/moneymotion/data/';
	}

	/* --- application.json --- */

	public function testApplicationJsonIsValid(): void
	{
		$json = file_get_contents( $this->dataPath . 'application.json' );
		$data = json_decode( $json, true );
		$this->assertNotNull( $data, 'application.json must be valid JSON' );
	}

	public function testApplicationHasRequiredFields(): void
	{
		$data = json_decode( file_get_contents( $this->dataPath . 'application.json' ), true );
		$this->assertArrayHasKey( 'application_title', $data );
		$this->assertArrayHasKey( 'app_directory', $data );
		$this->assertArrayHasKey( 'app_version', $data );
		$this->assertArrayHasKey( 'app_long_version', $data );
	}

	public function testAppDirectoryIsMoneymotion(): void
	{
		$data = json_decode( file_get_contents( $this->dataPath . 'application.json' ), true );
		$this->assertSame( 'moneymotion', $data['app_directory'] );
	}

	/* --- versions.json consistency --- */

	public function testVersionsJsonIsValid(): void
	{
		$json = file_get_contents( $this->dataPath . 'versions.json' );
		$data = json_decode( $json, true );
		$this->assertNotNull( $data, 'versions.json must be valid JSON' );
	}

	/**
	 * BUG: application.json says 30017 but versions.json only goes to 30016
	 */
	public function testCurrentVersionExistsInVersionsJson(): void
	{
		$app = json_decode( file_get_contents( $this->dataPath . 'application.json' ), true );
		$versions = json_decode( file_get_contents( $this->dataPath . 'versions.json' ), true );

		$longVersion = (string) $app['app_long_version'];

		$this->assertArrayHasKey(
			$longVersion,
			$versions,
			"Current app_long_version {$longVersion} must have an entry in versions.json. " .
			"Max version in versions.json is " . max( array_keys( $versions ) )
		);
	}

	public function testVersionNumbersAreSequential(): void
	{
		$versions = json_decode( file_get_contents( $this->dataPath . 'versions.json' ), true );
		$keys = array_keys( $versions );
		sort( $keys, SORT_NUMERIC );

		for ( $i = 1; $i < count( $keys ); $i++ )
		{
			$this->assertGreaterThan(
				(int) $keys[ $i - 1 ],
				(int) $keys[ $i ],
				'Version numbers should be sequential'
			);
		}
	}

	/* --- schema.json --- */

	public function testSchemaJsonIsValid(): void
	{
		$json = file_get_contents( $this->dataPath . 'schema.json' );
		$data = json_decode( $json, true );
		$this->assertNotNull( $data, 'schema.json must be valid JSON' );
	}

	public function testSchemaHasSessionsTable(): void
	{
		$data = json_decode( file_get_contents( $this->dataPath . 'schema.json' ), true );
		$this->assertArrayHasKey( 'moneymotion_sessions', $data );
	}

	public function testSessionsTableHasAllRequiredColumns(): void
	{
		$data = json_decode( file_get_contents( $this->dataPath . 'schema.json' ), true );
		$columns = $data['moneymotion_sessions']['columns'];

		$required = array( 'session_id', 'transaction_id', 'invoice_id', 'amount_cents', 'currency', 'status', 'created_at', 'updated_at' );
		foreach ( $required as $col )
		{
			$this->assertArrayHasKey( $col, $columns, "moneymotion_sessions must have column: {$col}" );
		}
	}

	public function testSessionsTableHasPrimaryKey(): void
	{
		$data = json_decode( file_get_contents( $this->dataPath . 'schema.json' ), true );
		$indexes = $data['moneymotion_sessions']['indexes'];
		$this->assertArrayHasKey( 'PRIMARY', $indexes );
		$this->assertContains( 'session_id', $indexes['PRIMARY']['columns'] );
	}

	public function testSessionsTableHasTransactionIndex(): void
	{
		$data = json_decode( file_get_contents( $this->dataPath . 'schema.json' ), true );
		$indexes = $data['moneymotion_sessions']['indexes'];
		$this->assertArrayHasKey( 'transaction_id', $indexes );
	}

	public function testSchemaMatchesInstallScript(): void
	{
		// Verify schema.json defines the same columns as install.php creates
		$schema = json_decode( file_get_contents( $this->dataPath . 'schema.json' ), true );
		$schemaCols = array_keys( $schema['moneymotion_sessions']['columns'] );
		sort( $schemaCols );

		// The install script should match — we check that install.php
		// references the same table name
		$installCode = file_get_contents( __DIR__ . '/../../applications/moneymotion/setup/install.php' );
		$this->assertStringContainsString( 'moneymotion_sessions', $installCode );

		// Verify all schema columns appear in install code
		foreach ( $schemaCols as $col )
		{
			$this->assertStringContainsString(
				"'{$col}'",
				$installCode,
				"Install script must define column: {$col}"
			);
		}
	}

	/* --- modules.json --- */

	public function testModulesJsonIsValid(): void
	{
		$data = json_decode( file_get_contents( $this->dataPath . 'modules.json' ), true );
		$this->assertNotNull( $data );
	}

	public function testModulesHasFrontGateway(): void
	{
		$data = json_decode( file_get_contents( $this->dataPath . 'modules.json' ), true );
		$this->assertArrayHasKey( 'front', $data );
		$this->assertArrayHasKey( 'gateway', $data['front'] );
		$this->assertSame( 'webhook', $data['front']['gateway']['default_controller'] );
	}

	/* --- extensions.json --- */

	public function testExtensionsJsonIsValid(): void
	{
		$data = json_decode( file_get_contents( $this->dataPath . 'extensions.json' ), true );
		$this->assertNotNull( $data );
	}

	public function testExtensionsRegistersNexusGateway(): void
	{
		$data = json_decode( file_get_contents( $this->dataPath . 'extensions.json' ), true );
		$this->assertArrayHasKey( 'nexus', $data );
		$this->assertArrayHasKey( 'Gateway', $data['nexus'] );
		$this->assertContains( 'moneymotion', $data['nexus']['Gateway'] );
	}

	/* --- hooks.json --- */

	public function testHooksJsonIsValid(): void
	{
		$data = json_decode( file_get_contents( $this->dataPath . 'hooks.json' ), true );
		$this->assertNotNull( $data );
	}

	public function testHooksTargetsNexusGatewayClass(): void
	{
		$data = json_decode( file_get_contents( $this->dataPath . 'hooks.json' ), true );
		$this->assertArrayHasKey( 'Gateway', $data );
		$this->assertSame( 'C', $data['Gateway']['type'] );
		$this->assertSame( '\\IPS\\nexus\\Gateway', $data['Gateway']['class'] );
	}

	/* --- furl.json --- */

	public function testFurlJsonIsValid(): void
	{
		$data = json_decode( file_get_contents( $this->dataPath . 'furl.json' ), true );
		$this->assertNotNull( $data );
	}

	public function testFurlHasWebhookRoutes(): void
	{
		$data = json_decode( file_get_contents( $this->dataPath . 'furl.json' ), true );
		$this->assertArrayHasKey( 'pages', $data );
		$this->assertArrayHasKey( 'webhook', $data['pages'] );
		$this->assertArrayHasKey( 'webhook_success', $data['pages'] );
		$this->assertArrayHasKey( 'webhook_cancel', $data['pages'] );
		$this->assertArrayHasKey( 'webhook_failure', $data['pages'] );
	}

	/* --- settings.json --- */

	public function testSettingsJsonIsValid(): void
	{
		$data = json_decode( file_get_contents( $this->dataPath . 'settings.json' ), true );
		$this->assertNotNull( $data );
	}

	public function testSettingsHasApiKey(): void
	{
		$data = json_decode( file_get_contents( $this->dataPath . 'settings.json' ), true );
		$keys = array_column( $data, 'key' );
		$this->assertContains( 'moneymotion_api_key', $keys );
	}

	public function testSettingsHasWebhookSecret(): void
	{
		$data = json_decode( file_get_contents( $this->dataPath . 'settings.json' ), true );
		$keys = array_column( $data, 'key' );
		$this->assertContains( 'moneymotion_webhook_secret', $keys );
	}

	/* --- lang.php --- */

	public function testLangFileHasAllRequiredKeys(): void
	{
		$lang = array();
		include __DIR__ . '/../../applications/moneymotion/dev/lang.php';

		$required = array(
			'__app_moneymotion',
			'module__moneymotion_gateway',
			'gateway__moneymotion',
			'moneymotion_api_key',
			'moneymotion_webhook_secret',
			'moneymotion_pay_button',
			'moneymotion_redirect_message',
			'moneymotion_payment_processing',
			'moneymotion_payment_cancelled',
			'moneymotion_payment_failed',
			'moneymotion_payment_success',
			'moneymotion_error_api',
		);

		foreach ( $required as $key )
		{
			$this->assertArrayHasKey( $key, $lang, "Language file must have key: {$key}" );
			$this->assertNotEmpty( $lang[ $key ], "Language key '{$key}' must not be empty" );
		}
	}

	/* --- File structure --- */

	public function testAllRequiredFilesExist(): void
	{
		$base = __DIR__ . '/../../applications/moneymotion/';
		$required = array(
			'Application.php',
			'extensions/nexus/Gateway/moneymotion.php',
			'hooks/Gateway.php',
			'modules/front/gateway/webhook.php',
			'sources/Api/Client.php',
			'setup/install.php',
			'dev/lang.php',
			'dev/html/front/gateway/paymentScreen.phtml',
			'data/application.json',
			'data/versions.json',
			'data/settings.json',
			'data/schema.json',
			'data/modules.json',
			'data/extensions.json',
			'data/hooks.json',
			'data/furl.json',
		);

		foreach ( $required as $file )
		{
			$this->assertFileExists( $base . $file, "Required file missing: {$file}" );
		}
	}
}
