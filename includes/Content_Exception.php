<?php
/**
 * Error raised when content cannot be read or written.
 *
 * @package MCPContent
 */

declare(strict_types=1);

namespace Procoders\McpContent;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thrown from ability callbacks. The Abilities API surfaces the message.
 */
final class Content_Exception extends \RuntimeException {}
