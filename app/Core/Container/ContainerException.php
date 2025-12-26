<?php

namespace App\Core\Container;

use Psr\Container\ContainerExceptionInterface;
use RuntimeException;

/**
 * Extend RuntimeException so it behaves like a normal PHP error,
 * but implement the Interface so PSR-11 readers know what it is.
 */
class ContainerException extends RuntimeException implements ContainerExceptionInterface
{
}