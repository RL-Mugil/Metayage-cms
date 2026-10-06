<?php

namespace App\Exceptions;

use RuntimeException;

/** Authentication failed before the Books POST could be dispatched. */
class ZohoWriteNotSent extends RuntimeException {}
