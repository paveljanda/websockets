<?php
/**
 * Route.php
 *
 * @copyright      More in license.md
 * @license        http://www.ipublikuj.eu
 * @author         Adam Kadlec http://www.ipublikuj.eu
 * @package        iPublikuj:Ratchet!
 * @subpackage     Router
 * @since          1.0.0
 *
 * @date           14.02.17
 */

declare(strict_types = 1);

namespace IPub\Ratchet\Router;

use Nette;
use Nette\Utils;

use Guzzle\Http;

use IPub;
use IPub\Ratchet\Application;
use IPub\Ratchet\Exceptions;

/**
 * The bidirectional router for WebSockets
 *
 * @package        iPublikuj:Ratchet!
 * @subpackage     Router
 *
 * @author         Adam Kadlec <adam.kadlec@ipublikuj.eu>
 * @author         David Grudl (https://davidgrudl.com)
 */
class Route implements IRouter
{
	/**
	 * Implement nette smart magic
	 */
	use Nette\SmartObject;

	const CONTROLLER_KEY = 'controller';

	const MODULE_KEY = 'module';

	/**
	 * Url type
	 *
	 * @internal
	 */
	const HOST = 1;
	const PATH = 2;
	const RELATIVE = 3;

	/**
	 * Keys used in {@link Route::$styles} or metadata {@link Route::__construct}
	 */
	const VALUE = 'value';
	const PATTERN = 'pattern';
	const FILTER_IN = 'filterIn';
	const FILTER_OUT = 'filterOut';
	const FILTER_TABLE = 'filterTable';
	const FILTER_STRICT = 'filterStrict';

	/**
	 * Fixity types - how to handle default value? {@link Route::$metadata}
	 *
	 * @internal
	 */
	const OPTIONAL = 0;
	const PATH_OPTIONAL = 1;
	const CONSTANT = 2;

	/**
	 * @var array
	 */
	public static $styles = [
		'#'           => [
			// default style for path parameters
			self::PATTERN    => '[^/]+',
			self::FILTER_OUT => [__CLASS__, 'param2path'],
		],
		'?#'          => [
			// default style for query parameters
		],
		'module'      => [
			self::PATTERN    => '[a-z][a-z0-9.-]*',
			self::FILTER_IN  => [__CLASS__, 'path2controller'],
			self::FILTER_OUT => [__CLASS__, 'controller2path'],
		],
		'controller'  => [
			self::PATTERN    => '[a-z][a-z0-9.-]*',
			self::FILTER_IN  => [__CLASS__, 'path2controller'],
			self::FILTER_OUT => [__CLASS__, 'controller2path'],
		],
		'action'      => [
			self::PATTERN    => '[a-z][a-z0-9-]*',
			self::FILTER_IN  => [__CLASS__, 'dash2Camel'],
			self::FILTER_OUT => [__CLASS__, 'camel2Dash'],
		],
		'method'      => [
			self::PATTERN    => '[a-z][a-z0-9-]*',
			self::FILTER_IN  => [__CLASS__, 'dash2Camel'],
			self::FILTER_OUT => [__CLASS__, 'camel2Dash'],
		],
		'?module'     => [
		],
		'?controller' => [
		],
		'?action'     => [
		],
		'?method'     => [
		],
	];

	/**
	 * @var string
	 */
	private $mask;

	/**
	 * @var int HOST, PATH, RELATIVE
	 */
	private $type;

	/**
	 * @var array
	 */
	private $sequence = [];

	/**
	 * Regular expression pattern
	 *
	 * @var string
	 */
	private $re;

	/**
	 * Parameter aliases in regular expression
	 *
	 * @var string[]
	 */
	private $aliases = [];

	/**
	 * Array of [value & fixity, filterIn, filterOut]
	 *
	 * @var array
	 */
	private $metadata = [];

	/**
	 * @var array
	 */
	private $xlat = [];

	/**
	 * http | https
	 *
	 * @var string
	 */
	private $scheme;

	/**
	 * @var array
	 */
	private $allowedOrigins = [];

	/**
	 * @var string
	 */
	private $httpHost;

	/**
	 * @param string $mask
	 * @param string|array|callable $metadata
	 *
	 * @throws Exceptions\InvalidArgumentException
	 */
	public function __construct(string $mask, $metadata)
	{
		if (is_string($metadata)) {
			list($controller, $action) = Nette\Application\Helpers::splitName($metadata);

			if (!$controller) {
				throw new Exceptions\InvalidArgumentException('Second argument must be array or string in format Controller:action, "%s" given.', $metadata);
			}

			$metadata = [self::CONTROLLER_KEY => $controller];

			if ($action !== '') {
				$metadata['action'] = $action;
			}

		} elseif ($metadata instanceof \Closure) {
			$metadata = [
				self::CONTROLLER_KEY => 'IPub:Ratchet',
				'callback'           => $metadata,
			];
		}

		$this->setMask($mask, $metadata);
	}

	/**
	 * Maps command line arguments to a Request object
	 *
	 * @param Http\Message\RequestInterface $httpRequest
	 *
	 * @return Application\Request|NULL
	 *
	 * @throws Exceptions\InvalidStateException
	 */
	public function match(Http\Message\RequestInterface $httpRequest)
	{
		// Combine with precedence: mask (params in URL-path), fixity, query, (post,) defaults

		// 1) URL MASK
		$url = new Nette\Http\UrlScript($httpRequest->getUrl());
		$re = $this->re;

		if ($this->type === self::HOST) {
			$host = $url->getHost();
			$path = '//' . $host . $url->getPath();
			$parts = ip2long($host) ? [$host] : array_reverse(explode('.', $host));
			$re = strtr($re, [
				'/%basePath%/' => preg_quote($url->getBasePath(), '#'),
				'%tld%'        => preg_quote($parts[0], '#'),
				'%domain%'     => preg_quote(isset($parts[1]) ? "$parts[1].$parts[0]" : $parts[0], '#'),
				'%sld%'        => preg_quote(isset($parts[1]) ? $parts[1] : '', '#'),
				'%host%'       => preg_quote($host, '#'),
			]);

		} elseif ($this->type === self::RELATIVE) {
			$basePath = $url->getBasePath();

			if (strncmp($url->getPath(), $basePath, strlen($basePath)) !== 0) {
				return NULL;
			}

			$path = (string) substr($url->getPath(), strlen($basePath));

		} else {
			$path = $url->getPath();
		}

		if ($path !== '') {
			$path = rtrim(rawurldecode($path), '/') . '/';
		}

		if (!$matches = Utils\Strings::match($path, $re)) {
			// stop, not matched
			return NULL;
		}

		// Assigns matched values to parameters
		$params = [];

		foreach ($matches as $k => $v) {
			if (is_string($k) && $v !== '') {
				$params[$this->aliases[$k]] = $v;
			}
		}

		// 2) CONSTANT FIXITY
		foreach ($this->metadata as $name => $meta) {
			if (!isset($params[$name]) && isset($meta['fixity']) && $meta['fixity'] !== self::OPTIONAL) {
				$params[$name] = NULL; // cannot be overwriten in 3) and detected by isset() in 4)
			}
		}

		// 3) QUERY
		if ($this->xlat) {
			$params += self::renameKeys($httpRequest->getQuery()->getAll(), array_flip($this->xlat));

		} else {
			$params += $httpRequest->getQuery()->getAll();
		}

		// 4) APPLY FILTERS & FIXITY
		foreach ($this->metadata as $name => $meta) {
			if (isset($params[$name])) {
				if (!is_scalar($params[$name])) {

				} elseif (isset($meta[self::FILTER_TABLE][$params[$name]])) { // applies filterTable only to scalar parameters
					$params[$name] = $meta[self::FILTER_TABLE][$params[$name]];

				} elseif (isset($meta[self::FILTER_TABLE]) && !empty($meta[self::FILTER_STRICT])) {
					return NULL; // rejected by filterTable

				} elseif (isset($meta[self::FILTER_IN])) { // applies filterIn only to scalar parameters
					$params[$name] = call_user_func($meta[self::FILTER_IN], (string) $params[$name]);

					if ($params[$name] === NULL && !isset($meta['fixity'])) {
						return NULL; // rejected by filter
					}
				}

			} elseif (isset($meta['fixity'])) {
				$params[$name] = $meta[self::VALUE];
			}
		}

		if (isset($this->metadata[NULL][self::FILTER_IN])) {
			$params = call_user_func($this->metadata[NULL][self::FILTER_IN], $params);

			if ($params === NULL) {
				return NULL;
			}
		}

		// 5) BUILD Request
		if (!isset($params[self::CONTROLLER_KEY])) {
			throw new Exceptions\InvalidStateException('Missing controller in route definition.');

		} elseif (!is_string($params[self::CONTROLLER_KEY])) {
			return NULL;
		}

		$controller = $params[self::CONTROLLER_KEY];

		unset($params[self::CONTROLLER_KEY]);

		if (isset($this->metadata[self::MODULE_KEY])) {
			$controller = (isset($params[self::MODULE_KEY]) ? $params[self::MODULE_KEY] . ':' : '') . $controller;
			unset($params[self::MODULE_KEY]);
		}

		return new Application\Request(
			$controller,
			$params
		);
	}

	/**
	 * Parse mask and array of default values; initializes object
	 *
	 * @param string $mask
	 * @param array $metadata
	 *
	 * @return void
	 *
	 * @throws Exceptions\InvalidArgumentException
	 */
	private function setMask(string $mask, array $metadata)
	{
		$this->mask = $mask;

		// Detect '//host/path' vs. '/abs. path' vs. 'relative path'
		if (preg_match('#(?:(https?):)?(//.*)#A', $mask, $m)) {
			$this->type = self::HOST;
			list(, $this->scheme, $mask) = $m;

		} elseif (substr($mask, 0, 1) === '/') {
			$this->type = self::PATH;

		} else {
			$this->type = self::RELATIVE;
		}

		foreach ($metadata as $name => $meta) {
			if (!is_array($meta)) {
				$metadata[$name] = $meta = [self::VALUE => $meta];
			}

			if (array_key_exists(self::VALUE, $meta)) {
				if (is_scalar($meta[self::VALUE])) {
					$metadata[$name][self::VALUE] = (string) $meta[self::VALUE];
				}
				$metadata[$name]['fixity'] = self::CONSTANT;
			}
		}

		if (strpbrk($mask, '?<>[]') === FALSE) {
			$this->re = '#' . preg_quote($mask, '#') . '/?\z#A';
			$this->sequence = [$mask];
			$this->metadata = $metadata;

			return;
		}

		// PARSE MASK
		// <parameter-name[=default] [pattern]> or [ or ] or ?...
		$parts = Utils\Strings::split($mask, '/<([^<>= ]+)(=[^<> ]*)? *([^<>]*)>|(\[!?|\]|\s*\?.*)/');

		$this->xlat = [];
		$i = count($parts) - 1;

		// PARSE QUERY PART OF MASK
		if (isset($parts[$i - 1]) && substr(ltrim($parts[$i - 1]), 0, 1) === '?') {
			// name=<parameter-name [pattern]>
			$matches = Utils\Strings::matchAll($parts[$i - 1], '/(?:([a-zA-Z0-9_.-]+)=)?<([^> ]+) *([^>]*)>/');

			foreach ($matches as list(, $param, $name, $pattern)) { // $pattern is not used
				if (isset(static::$styles['?' . $name])) {
					$meta = static::$styles['?' . $name];

				} else {
					$meta = static::$styles['?#'];
				}

				if (isset($metadata[$name])) {
					$meta = $metadata[$name] + $meta;
				}

				if (array_key_exists(self::VALUE, $meta)) {
					$meta['fixity'] = self::OPTIONAL;
				}

				unset($meta['pattern']);

				$meta['filterTable2'] = empty($meta[self::FILTER_TABLE]) ? NULL : array_flip($meta[self::FILTER_TABLE]);

				$metadata[$name] = $meta;
				if ($param !== '') {
					$this->xlat[$name] = $param;
				}
			}

			$i -= 5;
		}

		// PARSE PATH PART OF MASK
		$brackets = 0; // optional level
		$re = '';
		$sequence = [];
		$autoOptional = TRUE;
		$aliases = [];

		do {
			$part = $parts[$i]; // part of path

			if (strpbrk($part, '<>') !== FALSE) {
				throw new Exceptions\InvalidArgumentException(sprintf('Unexpected "%s" in mask "%s".', $part, $mask));
			}

			array_unshift($sequence, $part);

			$re = preg_quote($part, '#') . $re;

			if ($i === 0) {
				break;
			}

			$i--;

			$part = $parts[$i]; // [ or ]

			if ($part === '[' || $part === ']' || $part === '[!') {
				$brackets += $part[0] === '[' ? -1 : 1;
				if ($brackets < 0) {
					throw new Exceptions\InvalidArgumentException(sprintf('Unexpected "%s" in mask "%s".', $part, $mask));
				}

				array_unshift($sequence, $part);

				$re = ($part[0] === '[' ? '(?:' : ')?') . $re;
				$i -= 4;

				continue;
			}

			$pattern = trim($parts[$i]);
			$i--; // validation condition (as regexp)
			$default = $parts[$i];
			$i--; // default value
			$name = $parts[$i];
			$i--; // parameter name
			array_unshift($sequence, $name);

			if ($name[0] === '?') { // "foo" parameter
				$name = substr($name, 1);
				$re = $pattern ? '(?:' . preg_quote($name, '#') . "|$pattern)$re" : preg_quote($name, '#') . $re;
				$sequence[1] = $name . $sequence[1];
				continue;
			}

			// pattern, condition & metadata
			if (isset(static::$styles[$name])) {
				$meta = static::$styles[$name];

			} else {
				$meta = static::$styles['#'];
			}

			if (isset($metadata[$name])) {
				$meta = $metadata[$name] + $meta;
			}

			if ($pattern == '' && isset($meta[self::PATTERN])) {
				$pattern = $meta[self::PATTERN];
			}

			if ($default !== '') {
				$meta[self::VALUE] = (string) substr($default, 1);
				$meta['fixity'] = self::PATH_OPTIONAL;
			}

			$meta['filterTable2'] = empty($meta[self::FILTER_TABLE]) ? NULL : array_flip($meta[self::FILTER_TABLE]);
			if (array_key_exists(self::VALUE, $meta)) {
				if (isset($meta['filterTable2'][$meta[self::VALUE]])) {
					$meta['defOut'] = $meta['filterTable2'][$meta[self::VALUE]];

				} elseif (isset($meta[self::FILTER_OUT])) {
					$meta['defOut'] = call_user_func($meta[self::FILTER_OUT], $meta[self::VALUE]);

				} else {
					$meta['defOut'] = $meta[self::VALUE];
				}
			}
			$meta[self::PATTERN] = "#(?:$pattern)\\z#A";

			// include in expression
			$aliases['p' . $i] = $name;
			$re = '(?P<p' . $i . '>(?U)' . $pattern . ')' . $re;
			if ($brackets) { // is in brackets?
				if (!isset($meta[self::VALUE])) {
					$meta[self::VALUE] = $meta['defOut'] = NULL;
				}
				$meta['fixity'] = self::PATH_OPTIONAL;

			} elseif (!$autoOptional) {
				unset($meta['fixity']);

			} elseif (isset($meta['fixity'])) { // auto-optional
				$re = '(?:' . $re . ')?';
				$meta['fixity'] = self::PATH_OPTIONAL;

			} else {
				$autoOptional = FALSE;
			}

			$metadata[$name] = $meta;

		} while (TRUE);

		if ($brackets) {
			throw new Exceptions\InvalidArgumentException(sprintf('Missing "[" in mask "%s".', $mask));
		}

		$this->aliases = $aliases;
		$this->re = '#' . $re . '/?\z#A';
		$this->metadata = $metadata;
		$this->sequence = $sequence;
	}

	/**
	 * Returns mask
	 *
	 * @return string
	 */
	public function getMask() : string
	{
		return $this->mask;
	}

	/**
	 * Returns allowed origins
	 *
	 * @return array
	 */
	public function getAllowedOrigins() : array
	{
		return $this->allowedOrigins;
	}

	/**
	 * Returns http host name
	 *
	 * @return string
	 */
	public function getHttpHost() : string
	{
		return $this->httpHost;
	}

	/**
	 * Rename keys in array
	 *
	 * @param array $xlat
	 * @param array $arr
	 *
	 * @return array
	 */
	private static function renameKeys($arr, $xlat)
	{
		if (empty($xlat)) {
			return $arr;
		}

		$res = [];
		$occupied = array_flip($xlat);

		foreach ($arr as $k => $v) {
			if (isset($xlat[$k])) {
				$res[$xlat[$k]] = $v;

			} elseif (!isset($occupied[$k])) {
				$res[$k] = $v;
			}
		}

		return $res;
	}

	/********************* Inflectors ******************/

	/**
	 * camelCaseAction name -> dash-separated
	 *
	 * @param string $s
	 *
	 * @return string
	 */
	protected static function camel2dash($s) : string
	{
		$s = preg_replace('#(.)(?=[A-Z])#', '$1-', $s);
		$s = strtolower($s);
		$s = rawurlencode($s);

		return $s;
	}

	/**
	 * dash-separated -> camelCaseAction name
	 *
	 * @param string $s
	 *
	 * @return string
	 */
	protected static function dash2Camel($s) : string
	{
		$s = preg_replace('#-(?=[a-z])#', ' ', $s);
		$s = lcfirst(ucwords($s));
		$s = str_replace(' ', '', $s);

		return $s;
	}

	/**
	 * PascalCase:Controller name -> dash-and-dot-separated
	 *
	 * @param string $s
	 *
	 * @return string
	 */
	protected static function controller2path($s) : string
	{
		$s = strtr($s, ':', '.');
		$s = preg_replace('#([^.])(?=[A-Z])#', '$1-', $s);
		$s = strtolower($s);
		$s = rawurlencode($s);

		return $s;
	}


	/**
	 * dash-and-dot-separated -> PascalCase:Controller name.
	 *
	 * @param string $s
	 *
	 * @return string
	 */
	protected static function path2controller($s) : string
	{
		$s = preg_replace('#([.-])(?=[a-z])#', '$1 ', $s);
		$s = ucwords($s);
		$s = str_replace('. ', ':', $s);
		$s = str_replace('- ', '', $s);

		return $s;
	}

	/**
	 * Url encode
	 *
	 * @param string $s
	 *
	 * @return string
	 */
	protected static function param2path($s) : string
	{
		return str_replace('%2F', '/', rawurlencode($s));
	}
}
