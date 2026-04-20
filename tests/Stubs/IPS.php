<?php
/**
 * IPS Framework Stubs for Testing
 *
 * Provides minimal stubs of IPS framework classes so the moneymotion
 * plugin code can be loaded and tested without a running IPS install.
 * Each stub implements only the interface the plugin actually uses.
 */

namespace IPS;

if ( !\defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	\define( 'IPS\SUITE_UNIQUE_KEY', 'test_suite_key' );
}

/* ---------- \IPS\Application ---------- */
class Application
{
	public static function appIsEnabled( $app )
	{
		return true;
	}
}

/* ---------- \IPS\Settings ---------- */
class Settings
{
	protected static $instance;
	protected $data = array();

	public static function i()
	{
		if ( !static::$instance )
		{
			static::$instance = new static;
			static::$instance->data['cookie_login_key'] = 'test_cookie_key_abc123';
		}
		return static::$instance;
	}

	public function __get( $key )
	{
		return isset( $this->data[ $key ] ) ? $this->data[ $key ] : '';
	}

	public function __set( $key, $value )
	{
		$this->data[ $key ] = $value;
	}

	public static function reset()
	{
		static::$instance = null;
	}
}

/* ---------- \IPS\Log ---------- */
class Log
{
	/** @var array Captured log entries for test assertions */
	public static $logs = array();

	public static function log( $message, $category = 'error' )
	{
		static::$logs[] = array(
			'message'  => $message,
			'category' => $category,
		);
	}

	public static function reset()
	{
		static::$logs = array();
	}

	public static function getLastMessage()
	{
		$last = end( static::$logs );
		return $last ? $last['message'] : '';
	}

	public static function hasMessageContaining( $needle )
	{
		foreach ( static::$logs as $entry )
		{
			if ( mb_strpos( $entry['message'], $needle ) !== false )
			{
				return true;
			}
		}
		return false;
	}
}

/* ---------- \IPS\Member ---------- */
class Member
{
	public $member_id = 1;
	public $email = 'test@example.com';

	protected static $loggedIn;

	public static function loggedIn()
	{
		if ( !static::$loggedIn )
		{
			static::$loggedIn = new static;
		}
		return static::$loggedIn;
	}

	public function language()
	{
		return new Member\Language;
	}

	public static function reset()
	{
		static::$loggedIn = null;
	}
}

namespace IPS\Member;

class Language
{
	public function addToStack( $key )
	{
		return $key;
	}
}

/* ---------- \IPS\Db ---------- */
namespace IPS;

class Db
{
	protected static $instance;

	/** @var array Tracks all operations for assertions */
	public $operations = array();

	/** @var array Mock data for select queries: table => [rows] */
	public $mockData = array();

	public static function i()
	{
		if ( !static::$instance )
		{
			static::$instance = new static;
		}
		return static::$instance;
	}

	public function select( $columns, $table, $where = null )
	{
		$this->operations[] = array( 'type' => 'select', 'table' => $table, 'where' => $where );
		return new Db\SelectResult( $this->mockData, $table, $where );
	}

	public function insert( $table, $data )
	{
		$this->operations[] = array( 'type' => 'insert', 'table' => $table, 'data' => $data );
		return 1;
	}

	public function update( $table, $data, $where = null )
	{
		$this->operations[] = array( 'type' => 'update', 'table' => $table, 'data' => $data, 'where' => $where );

		/* Actually mutate the in-memory mockData so subsequent selects see
		   the new state. Also returns affected_rows so claim-pattern code
		   (UPDATE ... WHERE status='pending') can detect whether it won. */
		$affected = 0;
		if ( isset( $this->mockData[ $table ] ) )
		{
			foreach ( $this->mockData[ $table ] as $idx => $row )
			{
				if ( $this->matchesWhere( $row, $where ) )
				{
					$this->mockData[ $table ][ $idx ] = array_merge( $row, $data );
					$affected++;
				}
			}
		}
		return $affected;
	}

	public function delete( $table, $where = null )
	{
		$this->operations[] = array( 'type' => 'delete', 'table' => $table, 'where' => $where );

		$affected = 0;
		if ( isset( $this->mockData[ $table ] ) )
		{
			$kept = array();
			foreach ( $this->mockData[ $table ] as $row )
			{
				if ( $this->matchesWhere( $row, $where ) )
				{
					$affected++;
				}
				else
				{
					$kept[] = $row;
				}
			}
			$this->mockData[ $table ] = $kept;
		}
		return $affected;
	}

	/**
	 * Evaluate a WHERE clause against a single row.
	 *
	 * Supports the formats the plugin actually uses:
	 *   - null                                          → match all
	 *   - array( 'col=?', val )                         → simple equality
	 *   - array( "col=? AND col2='literal'", val )      → claim-pattern (one bound
	 *       param + a literal comparison inline)
	 */
	protected function matchesWhere( array $row, $where )
	{
		if ( $where === null )
		{
			return true;
		}
		if ( !\is_array( $where ) || !isset( $where[0] ) )
		{
			return true;
		}

		$clause = (string) $where[0];
		$params = \array_slice( $where, 1 );

		/* Pull out 'col=?' bindings in order. */
		$paramIdx = 0;
		$clauseRemaining = $clause;

		/* Match simple-equality placeholders: col=? */
		if ( preg_match_all( '/(\w+)\s*=\s*\?/', $clause, $m ) )
		{
			foreach ( $m[1] as $col )
			{
				if ( !array_key_exists( $paramIdx, $params ) ) return false;
				$bound = $params[ $paramIdx++ ];
				if ( !isset( $row[ $col ] ) || (string) $row[ $col ] !== (string) $bound )
				{
					return false;
				}
			}
			/* Strip the bound parts so we can inspect literal predicates */
			$clauseRemaining = preg_replace( '/\w+\s*=\s*\?/', '', $clause );
		}

		/* Match literal-equality: col='literal' */
		if ( preg_match_all( "/(\w+)\s*=\s*'([^']*)'/", $clauseRemaining, $m ) )
		{
			foreach ( $m[1] as $i => $col )
			{
				$literal = $m[2][ $i ];
				if ( !isset( $row[ $col ] ) || (string) $row[ $col ] !== (string) $literal )
				{
					return false;
				}
			}
		}

		return true;
	}

	public function checkForTable( $table )
	{
		return isset( $this->mockData[ $table ] );
	}

	public function createTable( $schema )
	{
		$this->operations[] = array( 'type' => 'createTable', 'schema' => $schema );
	}

	public function getOperations( $type = null, $table = null )
	{
		$filtered = array_filter( $this->operations, function( $op ) use ( $type, $table ) {
			if ( $type && $op['type'] !== $type ) return false;
			if ( $table && isset( $op['table'] ) && $op['table'] !== $table ) return false;
			return true;
		});
		return array_values( $filtered );
	}

	public static function reset()
	{
		static::$instance = null;
	}
}

namespace IPS\Db;

class SelectResult
{
	protected $data;
	protected $table;
	protected $where;

	public function __construct( array $allData, $table, $where )
	{
		$this->table = $table;
		$this->where = $where;
		$this->data = isset( $allData[ $table ] ) ? $allData[ $table ] : array();
	}

	public function first()
	{
		if ( empty( $this->data ) )
		{
			throw new \UnderflowException( "No rows found in {$this->table}" );
		}

		/* Simple where matching: array('column=?', value) */
		if ( \is_array( $this->where ) && \count( $this->where ) >= 2 )
		{
			$pattern = $this->where[0];
			$value = $this->where[1];

			if ( preg_match( '/^(\w+)=\?$/', $pattern, $m ) )
			{
				$col = $m[1];
				foreach ( $this->data as $row )
				{
					if ( isset( $row[ $col ] ) && (string) $row[ $col ] === (string) $value )
					{
						return $row;
					}
				}
				throw new \UnderflowException( "No matching row for {$col}={$value} in {$this->table}" );
			}
		}

		return reset( $this->data );
	}
}

/* ---------- \IPS\Http\Url ---------- */
namespace IPS\Http;

class Url
{
	protected $url;

	public function __construct( $url = '' )
	{
		$this->url = $url;
	}

	public static function internal( $query, $base = 'front' )
	{
		return new static( "https://test.example.com/index.php?{$query}" );
	}

	public static function external( $url )
	{
		return new static( $url );
	}

	public function __toString()
	{
		return $this->url;
	}

	public function request()
	{
		return new Url\Request( $this->url );
	}
}

namespace IPS\Http\Url;

class Request
{
	protected $url;
	protected $headers = array();

	/** @var array Captured HTTP requests for assertions */
	public static $captured = array();

	/** @var \IPS\Http\Response|\Exception|null Next response to return */
	public static $nextResponse;

	public function __construct( $url )
	{
		$this->url = $url;
	}

	public function setHeaders( $headers )
	{
		$this->headers = $headers;
		return $this;
	}

	public function post( $data )
	{
		static::$captured[] = array(
			'method'  => 'POST',
			'url'     => $this->url,
			'headers' => $this->headers,
			'body'    => $data,
		);

		if ( static::$nextResponse instanceof \Exception )
		{
			throw static::$nextResponse;
		}

		if ( static::$nextResponse )
		{
			return static::$nextResponse;
		}

		return new \IPS\Http\Response( 200, '{"result":{"data":{"json":{"checkoutSessionId":"cs_mocked"}}}}' );
	}

	public function get()
	{
		static::$captured[] = array(
			'method'  => 'GET',
			'url'     => $this->url,
			'headers' => $this->headers,
		);
		return static::$nextResponse ?: new \IPS\Http\Response( 200, '{}' );
	}

	public static function reset()
	{
		static::$captured = array();
		static::$nextResponse = null;
	}
}

namespace IPS\Http;

class Response
{
	public $httpResponseCode;
	public $content;

	public function __construct( $code, $content )
	{
		$this->httpResponseCode = $code;
		$this->content = $content;
	}
}

/* ---------- \IPS\Request ---------- */
namespace IPS;

class Request
{
	protected static $instance;
	protected $data = array();

	public static function i()
	{
		if ( !static::$instance )
		{
			static::$instance = new static;
		}
		return static::$instance;
	}

	public function __get( $key )
	{
		return isset( $this->data[ $key ] ) ? $this->data[ $key ] : null;
	}

	public function __set( $key, $value )
	{
		$this->data[ $key ] = $value;
	}

	public static function reset()
	{
		static::$instance = null;
	}
}

/* ---------- \IPS\Output ---------- */
class Output
{
	protected static $instance;

	public $lastRedirect;
	public $lastOutput;
	public $lastOutputCode;
	public $cssFiles = array();

	public static function i()
	{
		if ( !static::$instance )
		{
			static::$instance = new static;
		}
		return static::$instance;
	}

	public function redirect( $url, $message = '' )
	{
		$this->lastRedirect = array( 'url' => (string) $url, 'message' => $message );
	}

	public function sendOutput( $body, $code = 200, $contentType = 'text/html' )
	{
		$this->lastOutput = $body;
		$this->lastOutputCode = $code;
	}

	public static function reset()
	{
		static::$instance = null;
	}
}

/* ---------- \IPS\Theme ---------- */
class Theme
{
	protected static $instance;

	public static function i()
	{
		if ( !static::$instance )
		{
			static::$instance = new static;
		}
		return static::$instance;
	}

	public function getTemplate( $group, $app, $location )
	{
		return new Theme\TemplateStub;
	}

	public static function reset()
	{
		static::$instance = null;
	}
}

namespace IPS\Theme;

class TemplateStub
{
	public function __call( $method, $args )
	{
		return "<stub template: {$method}>";
	}
}

/* ---------- \IPS\Lang ---------- */
namespace IPS;

class Lang
{
	public static function saveCustom( $app, $key, $value )
	{
		// no-op for tests
	}
}

/* ---------- \IPS\nexus namespace ---------- */
namespace IPS\nexus;

class Gateway
{
	public $id = 1;
	public $settings = '{}';

	public static function constructFromData( $data )
	{
		$obj = new static;
		$obj->id = isset( $data['m_id'] ) ? $data['m_id'] : 1;
		$obj->settings = isset( $data['m_settings'] ) ? $data['m_settings'] : '{}';
		return $obj;
	}

	public static function gateways()
	{
		return array();
	}
}

class Transaction
{
	const STATUS_PENDING = 'pend';
	const STATUS_PAID = 'okay';
	const STATUS_REFUSED = 'fail';
	const STATUS_REFUNDED = 'rfnd';

	public $id;
	public $gw_id;
	public $status = 'pend';
	public $amount;
	public $member;
	public $invoice;

	/** @var bool Track if approve() was called */
	public $wasApproved = false;

	/** @var \Exception|null Set to throw from approve() */
	public $approveException;

	protected static $store = array();

	public function __construct()
	{
		$this->member = new \IPS\Member;
		$this->amount = new Money( '10.00', 'EUR' );
	}

	public static function load( $id )
	{
		if ( isset( static::$store[ $id ] ) )
		{
			return static::$store[ $id ];
		}
		throw new \OutOfRangeException( "Transaction {$id} not found" );
	}

	public static function register( $id, Transaction $txn )
	{
		$txn->id = $id;
		static::$store[ $id ] = $txn;
	}

	public function save()
	{
		if ( !$this->id )
		{
			$this->id = rand( 1000, 9999 );
		}
		static::$store[ $this->id ] = $this;
	}

	public function approve()
	{
		if ( $this->approveException )
		{
			throw $this->approveException;
		}
		$this->wasApproved = true;
		$this->status = self::STATUS_PAID;
	}

	public static function reset()
	{
		static::$store = array();
	}
}

class Invoice
{
	const STATUS_PENDING = 'pend';
	const STATUS_PAID = 'paid';

	public $id = 100;
	public $title = 'Test Invoice';
	public $status = 'pend';

	public function url()
	{
		return new \IPS\Http\Url( "https://test.example.com/invoice/{$this->id}" );
	}

	public function checkoutUrl()
	{
		return new \IPS\Http\Url( "https://test.example.com/checkout/{$this->id}" );
	}

	public function recalculateTotal() {}

	public function summary()
	{
		$zero = new Money( '0.00', 'EUR' );
		return array(
			'subtotal'      => $this->_amountToPay ?? new Money( '10.00', 'EUR' ),
			'shippingTotal' => $zero,
			'taxTotal'      => $zero,
			'total'         => $this->_amountToPay ?? new Money( '10.00', 'EUR' ),
		);
	}

	public function amountToPay( $recalc = false )
	{
		return $this->_amountToPay ?? new Money( '10.00', 'EUR' );
	}

	public $_amountToPay;
}

class Money
{
	public $amount;
	public $currency;

	public function __construct( $amount, $currency )
	{
		$this->amount = new Money\Amount( $amount );
		$this->currency = $currency;
	}

	public function amountAsString()
	{
		return (string) $this->amount;
	}
}

namespace IPS\nexus\Money;

class Amount
{
	protected $value;

	public function __construct( $value )
	{
		$this->value = $value;
	}

	public function compare( $other )
	{
		$a = (float) $this->value;
		$b = (float) ( $other instanceof self ? $other->value : $other );
		if ( $a < $b ) return -1;
		if ( $a > $b ) return 1;
		return 0;
	}

	public function __toString()
	{
		return (string) $this->value;
	}
}

namespace IPS\nexus\Invoice\Item;

class Purchase
{
}

namespace IPS\nexus\Fraud\MaxMind;

class Request
{
}

/* ---------- \IPS\nexus\Customer ---------- */
namespace IPS\nexus;

class Customer extends \IPS\Member
{
}

/* ---------- \IPS\nexus\Customer\CreditCard ---------- */
namespace IPS\nexus\Customer;

class CreditCard
{
}

/* ---------- \IPS\nexus\Tax ---------- */
namespace IPS\nexus;

class Tax
{
	public static function load( $id )
	{
		return new static;
	}
}

/* ---------- \IPS\Dispatcher\Controller ---------- */
namespace IPS\Dispatcher;

class Controller
{
}

/* ---------- \IPS\Helpers\Form ---------- */
namespace IPS\Helpers;

class Form
{
	public function add( $field ) {}
}

namespace IPS\Helpers\Form;

class Text
{
	public function __construct( $name, $value = '', $required = false ) {}
}

class Url
{
	public function __construct( $name, $value = null, $required = false, $options = array(), $validation = null ) {}
}

/* ---------- \IPS\Application\Module ---------- */
namespace IPS\Application;

class Module
{
	public static function get( $app, $module, $area )
	{
		return new static;
	}
}
