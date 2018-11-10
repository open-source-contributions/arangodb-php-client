<?php
/**
 * Sandro Keil (https://sandro-keil.de)
 *
 * @link      http://github.com/sandrokeil/arangodb-php-client for the canonical source repository
 * @copyright Copyright (c) 2018 Sandro Keil
 * @license   http://github.com/sandrokeil/arangodb-php-client/blob/master/LICENSE.md New BSD License
 */

declare(strict_types=1);

namespace ArangoDb\Exception;

/**
 * Code is largely lifted from the Zend\Diactoros\Stream implementation in
 * Zend Diactoros, released with the copyright and license below.
 *
 * @see       https://github.com/zendframework/zend-diactoros for the canonical source repository
 * @copyright Copyright (c) 2015-2018 Zend Technologies USA Inc. (http://www.zend.com)
 * @license   https://github.com/zendframework/zend-diactoros/blob/master/LICENSE.md New BSD License
 */
class UntellableStreamException extends RuntimeException
{
    public static function dueToMissingResource() : self
    {
        return new self('No resource available; cannot tell position');
    }

    public static function dueToPhpError() : self
    {
        return new self('Error occurred during tell operation');
    }

    public static function forCallbackStream() : self
    {
        return new self('Callback streams cannot tell position');
    }
}
